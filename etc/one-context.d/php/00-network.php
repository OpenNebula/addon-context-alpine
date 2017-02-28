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

function get_iface_network($if)
{
	if( ! $if['NETWORK'])
	{
		return substr($if['IP'], 0, strrpos($if['IP'], '.')).'.0';
	}
	
	return $if['NETWORK'];
}

function get_iface_mask($if)
{
	if( ! $if['MASK']) return '255.255.255.0';
	
	return $if['MASK'];
}

function get_iface_config($if)
{
    $conf = 'iface '.$if['DEV'].' inet static
    address '.$if['IP'].'
    network '.get_iface_network($if).'
    netmask '.get_iface_mask($if);

    if($if['GATEWAY'])
    {
        $conf .= '
    gateway '.$if['GATEWAY'];
    }

    if($if['MTU'])
    {
        $conf .= '
    mtu '.$if['MTU'];
    }

    return $conf."\n\n";
}

function get_iface6_config($if)
{
    $conf = 'iface '.$if['DEV'].' inet6 static
    address '.$if['IP6'].'
    netmask 64
    pre-up echo 0 > /proc/sys/net/ipv6/conf/'.$if['DEV'].'/accept_ra';

    if($if['GATEWAY6'])
    {
        $conf .= '
    gateway '.$if['GATEWAY6'];
    }

    if($if['MTU'])
    {
        $conf .= '
    mtu '.$if['MTU'];
    }

    return $conf."\n";
}

function configure_network()
{
    // clear DNS
    exec('echo "" > /etc/resolv.conf');

    $conf = '
auto lo
iface lo inet loopback
';

    $interfaces = get_context_interfaces();

    $ipv6 = false;
    foreach($interfaces as $if)
    {
        $conf .= '
auto '.$if['DEV']."\n";

        // IPv4
        if( ! $if['IP6'] || $if['CONTEXT_FORCE_IPV4'] == 'yes')
        {
            $conf .= get_iface_config($if);
        }

        // IPv6
        if($if['IP6'])
        {
            $ipv6 = true;
            $conf .= get_iface6_config($if);
        }

        // DNS
        if($if['DNS'])
        {
            $dns_servers = explode(' ', $if['DNS']);
            foreach($dns_servers as $dns_server)
            {
                exec('echo "nameserver '.$dns_server.'" >> /etc/resolv.conf');
            }
        }

        // Search Domain
        if($if['SEARCH_DOMAIN'])
        {
            exec('echo "search '.$if['SEARCH_DOMAIN'].'" >> /etc/resolv.conf');
        }
    }

    if($ipv6)
    {
        exec('modprobe ipv6');
    }

    exec('echo "'.$conf.'" > /etc/network/interfaces');
}

function deactivate_network()
{
    exec('service networking stop');
}

function activate_network()
{
    exec('service networking start');
}

$action = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : 'none';

// deactivate if is reload
if($action == 'reload') deactivate_network();

// configure
configure_network();

// activate if is reload
if($action == 'reload') activate_network();
