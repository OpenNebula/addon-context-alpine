#!/bin/sh -e


vrouter_addons()
{
  apk add quagga
  rc-update add keepalived boot
}


vm_tools()
{
  apk add rsync
  apk add udev
  apk add iptables
  apk add open-vm-tools
  apk add sfdisk
  apk add e2fsprogs-extra
  apk add util-linux
}


vm_services()
{
  rc-update add udev boot
  rc-update add iptables boot
  rc-update add open-vm-tools boot
  rc-update add acpid boot
  rc-update add one-context boot
}


deploy_files()
{
  rsync -ar etc /
  rsync -ar usr /
}


vrouter_or_not()
{
# if VROUTER is set to no the ONE virtual router parts are skipped.
  if [ "x${VROUTER}" = "xno" ]; then 
    # delete vrouter files
    rm /etc/one-context.d/02-keepalived /etc/sysctl.d/01-one.conf
    else
    vrouter_addons
  fi
}


cleanup()
{
  echo '' > /etc/resolv.conf
  apk cache clean
}


main()
{
  # start, just fetch fresh info
  apk update
  
  vm_tools
  deploy_files
  vm_services
  vrouter_or_not
}

main
