# Fedora 43 Automated Installation
# HTTPS Boot with GitHub Kickstart

# System configuration
install
url --url=https://download.fedoraproject.org/pub/fedora/linux/releases/43/Server/x86_64/os/
lang en_US.UTF-8
keyboard us
timezone America/New_York --isUtc

# Network - use DHCP
network --bootproto=dhcp --device=link --activate --onboot=on

# Authentication
auth --enableshadow --passalgo=sha512

# SELinux
selinux --enforcing

# Firewall
firewall --enabled --service=ssh

# Bootloader
bootloader --location=mbr --boot-drive=sda

# Storage - completely automated partitioning
clearpart --all --initlabel
autopart --type=lvm --fstype=xfs

# User configuration
rootpw --plaintext temporaryroot123
user --name=kyle --password=tekkio2025 --plaintext --groups=wheel --shell=/bin/bash --gecos="Kyle User"

# Services
services --enabled=sshd,chronyd,crond,cockpit.socket

# Skip X
skipx

# Reboot automatically after installation
reboot

# Package selection
%packages
@^server-product-environment
@core
vim-enhanced
curl
wget
git
tmux
cockpit
cockpit-podman
dnf-utils
htop
net-tools
bind-utils
-*firefox*
-*libreoffice*
%end

# Post-installation script
%post
#!/bin/bash

# Set hostname based on MAC address or random
HOSTNAME_PREFIX="fedora43-"
MAC=$(cat /sys/class/net/$(ip route show default | awk '/default/ {print $5}')/address | sed 's/://g' | tail -c 6)
echo "${HOSTNAME_PREFIX}${MAC}" > /etc/hostname

# Configure sudo for wheel group
echo "%wheel ALL=(ALL) NOPASSWD: ALL" > /etc/sudoers.d/wheel
chmod 440 /etc/sudoers.d/wheel

# Update system
dnf -y update

# Enable RPM Fusion
dnf -y install \
    https://download1.rpmfusion.org/free/fedora/rpmfusion-free-release-$(rpm -E %fedora).noarch.rpm \
    https://download1.rpmfusion.org/nonfree/fedora/rpmfusion-nonfree-release-$(rpm -E %fedora).noarch.rpm

# Set up automatic updates
dnf -y install dnf-automatic
sed -i 's/apply_updates = no/apply_updates = yes/' /etc/dnf/automatic.conf
sed -i 's/upgrade_type = default/upgrade_type = security/' /etc/dnf/automatic.conf
systemctl enable --now dnf-automatic.timer

# Lock root account for security
passwd -l root

# Create log file
echo "Automated Fedora 43 installation completed on $(date)" >> /home/kyle/install.log
chown kyle:kyle /home/kyle/install.log

%end
