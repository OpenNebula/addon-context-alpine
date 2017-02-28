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

function get_iface_network($if)
{
	if( ! $if['NETWORK'])
	{
		return substr($if['IP'], 0, strrpos($if['IP'], '.')).'.0';
	}
	
	return $if['NETWORK'];
}

function get_filter_rules()
{
	$interfaces = get_context_interfaces();
	
	$rules = array();
	
	$rules[4] = '*filter
:INPUT ACCEPT [0:0]
:FORWARD DROP [0:0]
:OUTPUT ACCEPT [0:0]
';
	
	foreach($interfaces as $interface)
	{
		foreach($interfaces as $destination)
		{
			if($interface['DEV'] == $destination['DEV']) continue;
			
			$rules[4] .= '-A FORWARD -i '.$interface['DEV'].' -o '.$destination['DEV'].' -j ACCEPT'."\n";
		}
		
		// close non management interfaces
		if($interface['VROUTER_MANAGEMENT'] != 'YES')
		{
			$rules[4] .= '-A INPUT -i '.$interface['DEV'].' -p tcp --dport 443 -j DROP'."\n";
			$rules[4] .= '-A OUTPUT -o '.$interface['DEV'].' -p tcp --sport 443 -j DROP'."\n";
			$rules[4] .= '-A INPUT -i '.$interface['DEV'].' -p tcp --dport 22 -j DROP'."\n";
			$rules[4] .= '-A OUTPUT -o '.$interface['DEV'].' -p tcp --sport 22 -j DROP'."\n";
		}
	}

	$rules[4] .= 'COMMIT'."\n";
	
	// IPv6 are same as IPv4
	$rules[6] = $rules[4];
	
	return $rules;
}

function get_nat_rules()
{
	$interfaces = get_context_interfaces();
	
	$rules = array();
	
	$rules[4] = '*nat
:PREROUTING ACCEPT [0:0]
:INPUT ACCEPT [0:0]
:OUTPUT ACCEPT [0:0]
:POSTROUTING ACCEPT [0:0]
';

	foreach($interfaces as $interface)
	{
		if(isset($interface['VROUTER_GATEWAY']) && $interface['VROUTER_GATEWAY'] == 'YES')
		{
			foreach($interfaces as $source)
			{
				if($interface['DEV'] == $source['DEV']) continue;
				
				$rules[4] .= '-A POSTROUTING -o '.$interface['DEV'].' -s '.get_iface_network($source).'/'.get_mask_bits($source).' -j MASQUERADE'."\n";
			}
		}	
	}

	$rules[4] .= 'COMMIT'."\n";

	// There are no IPv6 Rules
	$rules[6] = '';

	return $rules;
}

function get_mangle_rules()
{
	$interfaces = get_context_interfaces();
	$servers    = get_context_virtual_servers();
	
	$rules = array();
	
	$rules[4] = $rules[6] = '*mangle
:PREROUTING ACCEPT [0:0]
:INPUT ACCEPT [0:0]
:FORWARD ACCEPT [0:0]
:OUTPUT ACCEPT [0:0]
:POSTROUTING ACCEPT [0:0]
';

	foreach($servers as $server)
	{
		// just to fw marking for multiport services
		if($pos = strpos($server['PORT'], ' '))
		{
			$mark = substr($server['PORT'], 0, $pos);
			$ip4  = $interfaces[$server['DEV']]['VROUTER_IP'].'/32';
			$ip6  = ( ! empty($interfaces[$server['DEV']]['VROUTER_IP6'])) ? $interfaces[$server['DEV']]['VROUTER_IP6'].'/128' : false;
			
			// check if not contains ranges
			if(strpos($server['PORT'], ':') === false)
			{
				$rules[4] .= '-A PREROUTING -p tcp -d '.$ip4.' -m multiport --dports '.str_replace(' ', ',', $server['PORT']).' -j MARK --set-mark '.$mark."\n";
				if($ip6) $rules[6] .= '-A PREROUTING -p tcp -d '.$ip6.' -m multiport --dports '.str_replace(' ', ',', $server['PORT']).' -j MARK --set-mark '.$mark."\n";
				continue;
			}
			
			$ports = explode(' ', $server['PORT']);
				
			foreach($ports as $port)
			{
				$rules[4] .= '-A PREROUTING -p tcp -d '.$ip4.' --dport '.$port.' -j MARK --set-mark '.$mark."\n";
				if($ip6) $rules[6] .= '-A PREROUTING -p tcp -d '.$ip6.' --dport '.$port.' -j MARK --set-mark '.$mark."\n";
			}
		}
	}

	$rules[4] .= 'COMMIT'."\n";
	$rules[6] .= 'COMMIT'."\n";

	return $rules;
}

function service_reload()
{
	exec('service iptables reload');
	exec('service ip6tables reload');
}

$action = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : 'none';

// generate only if is VROUTER instance
if(isset($_SERVER['VROUTER_ID']))
{
	$filter_rules = get_filter_rules();
	$nat_rules    = get_nat_rules();
	$mangle_rules = get_mangle_rules();
	
	$ip4_rules = $filter_rules[4].$nat_rules[4].$mangle_rules[4];
	$ip6_rules = $filter_rules[6].$nat_rules[6].$mangle_rules[6];

	exec('echo "'.$ip4_rules.'" > /etc/iptables/rules-save');
	exec('echo "'.$ip6_rules.'" > /etc/iptables/rules6-save');
	
	if($action == 'reload') service_reload();
}