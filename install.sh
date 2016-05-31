apk update
apk add open-vm-tools
apk add quagga
apk cache clean

rc-update add keepalived boot
rc-update add udev boot
rc-update add iptables boot
rc-update add open-vm-tools boot
rc-update add acpid boot

rc-update add one-context boot

echo '' > /etc/resolv.conf
