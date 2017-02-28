<?php

function get_interface_mac_addresses()
{
    exec('ip link show | awk \'/^[0-9]+: [A-Za-z0-9]+:/ { device=$2; gsub(/:/, "",device)} /link\/ether/ { print device " " $2 }\'', $result);
    
    $interfaces = array();
    foreach($result as $line)
    {
        $parts                 = explode(' ', $line);
        $interfaces[$parts[1]] = $parts[0];
    }

    return $interfaces;
}

function get_context_interfaces()
{
    $mac_addresses = get_interface_mac_addresses();
    $interfaces    = array();

    foreach($_SERVER as $key => $val)
    {
        if(preg_match('/^(ETH[0-9])_(.+)$/', $key, $matches))
        {
            if($matches[2] == 'MAC')
            {
                $interfaces[$matches[1]]['DEV'] = $mac_addresses[$val];
            }            

            $interfaces[$matches[1]][$matches[2]] = $val;
        }
    }

    return $interfaces;
}

function get_context_virtual_servers()
{
	$servers = array();
	
	foreach($_SERVER as $key => $val)
    {
        if(preg_match('/^(VROUTER_LB[0-9])_(.+)$/', $key, $matches))
        {   
	        if(preg_match('/^SERVER([0-9])_(.+)$/', $matches[2], $matches_server))
	        {
		        if(preg_match('/^CHECK([0-9])_(.+)$/', $matches_server[2], $matches_server_check))
		        {
			        $servers[$matches[1]]['SERVERS'][$matches_server[1]]['CHECKS'][$matches_server_check[1]][$matches_server_check[2]] = trim($val);
			    }
			    else
			    {
				    $servers[$matches[1]]['SERVERS'][$matches_server[1]][$matches_server[2]] = trim($val);
			    }
	        }
	        else
	        {
		        $servers[$matches[1]][$matches[2]] = trim($val);
	        }
	    }
	}
	
	return $servers;
}

function get_mask_bits($if)
{
	$long = ip2long($if['MASK']);
	$base = ip2long('255.255.255.255');
	
	return 32-log(($long ^ $base)+1,2);
}

function generate_groups()
{
	$conf = 'vrrp_sync_group router {
  group {
';

	$interfaces = get_context_interfaces();
	
	foreach($interfaces as $if)
	{
		if($if['VROUTER_IP'] || $if['VROUTER_IP6'])
		{
			$conf .= '    VI_'.$if['DEV']."\n";
		}
	}

	$conf .= '  }
}
';

	return $conf;
}

function  generate_globals()
{
	if(empty($_SERVER['VROUTER_KEEPALIVED_NOTIFY_EMAIL'])) return;
	
	$conf = 'global_defs {
  notification_email {
';
	
	$emails = explode(' ', $_SERVER['VROUTER_KEEPALIVED_NOTIFY_EMAIL']);
	
	foreach($emails as $email)
	{
		$conf .= '    '.$email."\n";
	}
	
	$conf .= '  }'."\n";
	
	if( ! empty($_SERVER['VROUTER_KEEPALIVED_FROM_EMAIL']))
	{
		$conf .= '  notification_email_from '.$_SERVER['VROUTER_KEEPALIVED_FROM_EMAIL']."\n";
	}
	else
	{
		$conf .= '  notification_email_from '.$_SERVER['VROUTER_KEEPALIVED_NOTIFY_EMAIL']."\n";
	}
	
	$conf .= '  smtp_server 127.0.0.1
  smtp_connect_timeout 30
  router_id Vrouter_'.$_SERVER['VROUTER_ID'].'_'.$_SERVER['ETH0_IP'].'
}
';
	
	return $conf;
}

function generate_instances()
{
	$conf = '';
	
	$interfaces = get_context_interfaces();
	
	$vrouter_id = (isset($_SERVER['VROUTER_KEEPALIVED_ID']) && $_SERVER['VROUTER_KEEPALIVED_ID']) ? $_SERVER['VROUTER_KEEPALIVED_ID'] : $_SERVER['VROUTER_ID'];
	
	foreach($interfaces as $if)
	{
		if($if['VROUTER_IP'] || $if['VROUTER_IP6'])
		{
			$conf .= 'vrrp_instance VI_'.$if['DEV'].' {
  state MASTER
  priority 100
  advert_int 1
  nopreempt'."\n";

  			// Alerts
  			if( ! empty($_SERVER['VROUTER_KEEPALIVED_NOTIFY_EMAIL']))
  			{
	  			$conf .= '  smtp_alert'."\n";
  			}

  			// Sync Interface
  			if(isset($if['VROUTER_KEEPALIVED_INF']) && $if['VROUTER_KEEPALIVED_INF'])
  			{	
  				$conf .= '  interface '.$if['VROUTER_KEEPALIVED_INF']."\n";
  			}
  			else
  			{
	  			$conf .= '  interface '.$if['DEV']."\n";
  			}
  			
  			// Router ID
  			if($vrouter_id)
  			{
  				$conf .= '  virtual_router_id '.$vrouter_id."\n";
  				$vrouter_id++;
  			}
		
  			// Auth
  			if(isset($_SERVER['VROUTER_KEEPALIVED_PASSWORD']) && $_SERVER['VROUTER_KEEPALIVED_PASSWORD'])
  			{
	  			$conf .= '  authentication {
    auth_type PASS
    auth_pass '.$_SERVER['VROUTER_KEEPALIVED_PASSWORD'].'
  }'."\n";
  			}
  			
  			// IPv4
  			if($if['VROUTER_IP'])
  			{
	  			$conf .= '  virtual_ipaddress {'."\n";
	  			$conf .= '    '.$if['VROUTER_IP'].'/'.get_mask_bits($if).' dev '.$if['DEV']."\n";
	  			$conf .= '  }'."\n";
  			}
  			
  			// IPv6
  			// Only works with conjunction with IPv4, VRRP Sync is over IPv4
  			if($if['VROUTER_IP6'])
  			{
	  			$conf .= '  virtual_ipaddress_excluded {'."\n";
	  			$conf .= '    '.$if['VROUTER_IP6'].'/64 dev '.$if['DEV']."\n";
	  			$conf .= '  }'."\n";
  			}
			
			$conf .= '}'."\n";
		}
	}
	
	return $conf;
}

function get_server_name($server)
{
	if($pos = strpos($server['PORT'], ' '))
	{
		$first_port = substr($server['PORT'], 0, $pos);
		
		// looks like as FTP service
		if($first_port == '21') exec('modprobe ip_vs_ftp');
		
		return 'fwmark '.$first_port;
	}
	
	$interfaces = get_context_interfaces();
	
	$ip = $interfaces[$server['DEV']]['VROUTER_IP'];
	
	return $ip.' '.$server['PORT'];
}

function generate_virtual_servers()
{
	$conf = '';
	
	$servers = get_context_virtual_servers();
	
	foreach($servers as $server)
	{
		$server_name = get_server_name($server);
		
		// defaults
		$delay_loop = (empty($server['DELAY_LOOP'])) ? 6 : $server['DELAY_LOOP'];
		$algo       = (empty($server['ALGO'])) ? 'rr' : $server['ALGO'];
		$kind       = (empty($server['KIND'])) ? 'NAT' : $server['KIND'];
		$protocol   = (empty($server['PROTOCOL'])) ? 'TCP' : $server['PROTOCOL'];
		
		$conf .= 'virtual_server '.$server_name.' {
  delay_loop '.$delay_loop.'
  lb_algo '.$algo.'
  lb_kind '.$kind.'
  protocol '.$protocol.'
';
		
		// servers
		foreach($server['SERVERS'] as $real_server)
		{
			// is not mutiport?
			$port = '';
			if( ! strpos($server['PORT'], ' '))
			{
				$port = ( ! empty($real_server['PORT'])) ? ' '.$real_server['PORT'] : ' '.$server['PORT'];
			}
			
			$conf .= '  real_server '.$real_server['IP'].$port.' {'."\n";
			
			// weight
			if( ! empty($real_server['WEIGHT'])) $conf .= '    weight '.$real_server['WEIGHT']."\n";
			
			// checks
			if( ! empty($real_server['CHECKS']))
			{
				foreach($real_server['CHECKS'] as $check)
				{
					// check type, defaults to TCP_CHECK
					$type = ( ! empty($check['TYPE'])) ? $check['TYPE'] : 'TCP_CHECK';
					
					$conf .= '    '.$type.' {'."\n";
					
					// connect port
					if( ! empty($check['PORT'])) $conf .= '      connect_port '.$check['PORT']."\n";
					// Default HTTP port
					if(empty($check['PORT']) && $type == 'HTTP_GET') $conf .= '      connect_port 80'."\n";
					// Default SSL port
					if(empty($check['PORT']) && $type == 'SSL_GET') $conf .= '      connect_port 443'."\n";
					
					// connect timeout
					$timeout = ( ! empty($check['TIMEOUT'])) ? $check['TIMEOUT'] : 10;
					$conf .= '      connect_timeout '.$timeout."\n";
					
					
					// *_GET types
					if($type == 'HTTP_GET' || $type == 'SSL_GET')
					{
						$conf .= '      url {
        path /
        status_code 200
      }
';
					}
					
					$conf .= '    }'."\n";
				}
			}
			else
			{
				$conf .= '    TCP_CHECK {
      connect_timeout 10
    }
';
			}
			
			$conf .= '  }'."\n";
		}
		
		$conf .= '}'."\n";
	}
	
	return $conf;
}

function service_reload()
{
	exec('service keepalived reload');
}

$action = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : 'none';

// generate only if is VROUTER instance
if( ! empty($_SERVER['VROUTER_ID']))
{
	$conf = generate_globals();
	$conf .= generate_groups();
	$conf .= generate_instances();
	$conf .= generate_virtual_servers();

	exec('echo "'.$conf.'" > /etc/keepalived/keepalived.conf');
	
	if($action == 'reload') service_reload();
}