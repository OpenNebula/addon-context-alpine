# OpenNebula context for Alpine Linux

__This repository is obsolete__.    
Contextualization scripts for Alpine Linux are now part of the   
OpenNebula [Linux VM Contextualization](https://github.com/OpenNebula/addon-context-linux) repository.

---

These are the context scripts for Alpine Linux.
They are not yet available in a premade package but work in this form.

Currently, the scripts are used by the OpenNebula Virtual Router.
Based on the context info, special settings are made for the Virtual Router.

What they do:
 - setup SSH Key
 - setup net interfaces
 - setup management interface
 - enable IPv4 routing
 - Firewall access to vrouter web interface
 - enable VRRP in keepalived for high availability

Further tooling is included for:
 - vmware-tools (ensure correct command for shutdown)
 - udev (allow hot-add of networking devices)
