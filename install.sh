#!/bin/sh -e


vrouter_addons()
{
  # ipvsadm requires bash
  apk add bash
  
  # ipvsadm need for load ballancing
  apk add ipvsadm
  rc-update add ipvsadm boot
  sed -i 's/#!\/sbin\/runscript/#!\/sbin\/openrc-run/' /etc/init.d/ipvsadm
  if [ ! -d /var/lib/ipvsadm ]; then
    mkdir /var/lib/ipvsadm
  fi
  touch /var/lib/ipvsadm/rules-save
  
  apk add keepalived
  rc-update add keepalived boot
  sed -i 's/use net/need sshd/' /etc/init.d/keepalived
  
  # postfix need for sending smtp allerts from keepalived
  apk add postfix
  rc-update add postfix boot
  sed -i 's/#inet_interfaces = all/inet_interfaces = localhost/' /etc/postfix/main.cf
}


vm_tools()
{
  apk add php5-cli
  apk add nano
  apk add rsync
  apk add udev
  apk add iptables
  apk add ip6tables
  apk add open-vm-tools
  apk add sfdisk
  apk add e2fsprogs-extra
  apk add util-linux
}


vm_services()
{
  rc-update add udev sysinit
  rc-update add udev-postmount default
  rc-update add iptables boot
  rc-update add ip6tables boot
  rc-update add open-vm-tools boot
  rc-update add acpid boot
  rc-update add one-context boot
}


deploy_files()
{
  rsync -ar etc /
  rsync -ar usr /
}


cleanup()
{
  service iptables stop
  service ip6tables stop
  echo '' > /etc/resolv.conf
  echo '' > /etc/iptables/rules-save
  echo '' > /etc/iptables/rules6-save
  echo 'auto lo' > /etc/network/interfaces
  echo 'iface lo inet loopback' >> /etc/network/interfaces
  rm -rf /tmp/*
  apk cache clean
}


main()
{
  # enable edge repo - need for keepalived
  sed -i 's/#http:\/\/repository.fit.cvut.cz\/mirrors\/alpine\/edge\/main/http:\/\/repository.fit.cvut.cz\/mirrors\/alpine\/edge\/main/' /etc/apk/repositories
  sed -i 's/#http:\/\/repository.fit.cvut.cz\/mirrors\/alpine\/edge\/community/http:\/\/repository.fit.cvut.cz\/mirrors\/alpine\/edge\/community/' /etc/apk/repositories
	
  # start, just fetch fresh info
  apk update
  
  vm_tools
  vrouter_addons
  deploy_files
  vm_services
  
  cleanup
}

main
