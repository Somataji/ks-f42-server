sudo tee /var/www/html/boot/fedora42/ks.php << 'EOF'
<?php
$cfg = [
    "e8:ff:1e:dd:fc:b3" => [ "name" => "eqi0", "ip_fe" => "120", "ip_be" => "120" ],
    "e8:ff:1e:dc:ef:a8" => [ "name" => "eqr0", "ip_fe" => "121", "ip_be" => "121" ],
    "e0:51:d8:1c:03:97" => [ "name" => "g2plus", "ip_fe" => "122", "ip_be" => "122" ],
    "00:e0:4c:74:35:a1" => [ "name" => "qazipo", "ip_fe" => "123", "ip_be" => "123" ],
    "00:e0:4c:71:6c:46" => [ "name" => "hi12", "ip_fe" => "124", "ip_be" => "124" ],
    "00:e0:4c:f8:e1:36" => [ "name" => "awowj", "ip_fe" => "125", "ip_be" => "125" ],
    "58:47:ca:7d:b3:d5" => [ "name" => "nab9", "ip_fe" => "126", "ip_be" => "126" ],
];

// Get client IP and try to detect MAC from ARP table
$client_ip = $_SERVER['REMOTE_ADDR'];
$mac0 = null;

// Try to get MAC from ARP table
$arp_output = shell_exec("arp -n " . escapeshellarg($client_ip) . " 2>/dev/null");
if (preg_match('/[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}/', $arp_output, $matches)) {
    $mac0 = strtolower($matches[0]);
}

// If not found in ARP, try to get from DHCP leases
if (!$mac0) {
    $leases_output = shell_exec("grep -i " . escapeshellarg($client_ip) . " /var/lib/dhcpd/dhcpd.leases 2>/dev/null | grep -o -E '([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}' | head -1");
    if ($leases_output) {
        $mac0 = strtolower(trim($leases_output));
    }
}

header('Content-Type: text/plain');

if ($mac0 && array_key_exists($mac0, $cfg)) {
    $my_cfg = $cfg[$mac0];
    $my_name = $my_cfg["name"];
    $ip_fe = $my_cfg["ip_fe"];
    $ip_be = $my_cfg["ip_be"];
    
    echo "# Custom kickstart for $my_name\n";
    echo "lang en_US.UTF-8\n";
    echo "keyboard us\n";
    echo "timezone America/New_York --isUtc\n";
    echo "network --hostname=$my_name\n";
    
    echo "%packages\n";
    echo "@^server-product-environment\n";
    echo "%end\n";
    
    echo "firstboot --enable\n";
    
    echo "%pre\n";
    echo "lvremove -f /dev/fedora/root /dev/fedora/home /dev/fedora/swap 2>/dev/null || true\n";
    echo "vgremove -f fedora 2>/dev/null || true\n";
    echo "pvremove /dev/nvme0n1* 2>/dev/null || true\n";
    echo "echo LVM cleanup completed\n";
    echo "%end\n";
    
    echo "%post\n";
    echo "echo Start network setup > /root/network.log\n";
    echo "ip link show >> /root/network.log\n";
    echo "INTERFACES=(\$(ls /sys/class/net/ | grep -v lo))\n";
    echo "FRONTEND_IF=\"\"\n";
    echo "for iface in \${INTERFACES[@]}; do\n";
    echo "  iface_mac=\$(cat /sys/class/net/\$iface/address 2>/dev/null)\n";
    echo "  if [ \"\$iface_mac\" = \"$mac0\" ]; then\n";
    echo "    FRONTEND_IF=\"\$iface\"\n";
    echo "    break\n";
    echo "  fi\n";
    echo "done\n";
    echo "BACKEND_IF=\"\"\n";
    echo "for iface in \${INTERFACES[@]}; do\n";
    echo "  if [ \"\$iface\" != \"\$FRONTEND_IF\" ] && [ -n \"\$iface\" ]; then\n";
    echo "    BACKEND_IF=\"\$iface\"\n";
    echo "    break\n";
    echo "  fi\n";
    echo "done\n";
    echo "echo \"FRONTEND_IF=\$FRONTEND_IF\" > /root/network-interfaces.txt\n";
    echo "echo \"BACKEND_IF=\$BACKEND_IF\" >> /root/network-interfaces.txt\n";
    echo "cat > /etc/rc.d/rc.local << 'RC_EOF'\n";
    echo "#!/bin/bash\n";
    echo "source /root/network-interfaces.txt\n";
    echo "sleep 10\n";
    echo "nmcli con delete \"\$FRONTEND_IF\" 2>/dev/null || true\n";
    echo "nmcli con delete \"\$BACKEND_IF\" 2>/dev/null || true\n";
    echo "nmcli con delete 'Wired Connection' 2>/dev/null || true\n";
    echo "nmcli con add type ethernet con-name \"\$FRONTEND_IF\" ifname \"\$FRONTEND_IF\" ipv4.method manual ipv4.addresses 192.168.68.$ip_fe/24 ipv4.gateway 192.168.68.1 ipv4.dns 1.1.1.1\n";
    echo "nmcli con mod \"\$FRONTEND_IF\" connection.autoconnect yes\n";
    echo "nmcli con add type ethernet con-name \"\$BACKEND_IF\" ifname \"\$BACKEND_IF\" ipv4.method manual ipv4.addresses 192.168.3.$ip_be/24\n";
    echo "nmcli con mod \"\$BACKEND_IF\" connection.autoconnect yes\n";
    echo "nmcli con up \"\$FRONTEND_IF\"\n";
    echo "nmcli con up \"\$BACKEND_IF\"\n";
    echo "systemctl disable rc-local.service\n";
    echo "rm -f /etc/rc.d/rc.local\n";
    echo "RC_EOF\n";
    echo "chmod +x /etc/rc.d/rc.local\n";
    echo "systemctl enable rc-local.service\n";
    echo "hostnamectl set-hostname $my_name\n";
    echo "%end\n";
    
    echo "ignoredisk --only-use=nvme0n1\n";
    echo "clearpart --all --initlabel --drives=nvme0n1\n";
    echo "part /boot/efi --fstype=efi --ondisk=nvme0n1 --size=200 --fsoptions=umask=0077,shortname=winnt\n";
    echo "part /boot --fstype=xfs --ondisk=nvme0n1 --size=1024\n";
    echo "part pv.1126 --fstype=lvmpv --ondisk=nvme0n1 --size=69634\n";
    echo "volgroup fedora --pesize=4096 pv.1126\n";
    echo "logvol /home --fstype=xfs --size=30720 --name=home --vgname=fedora\n";
    echo "logvol swap --fstype=swap --size=8192 --name=swap --vgname=fedora\n";
    echo "logvol / --fstype=xfs --size=30720 --name=root --vgname=fedora\n";
    
    echo "timesource --ntp-server=ultimate.mattnordhoffdns.net\n";
    echo "rootpw --iscrypted \$y\$j9T\$bFqIJV8oOxxRRZSeEz2PSOes\$DKxY6HzV4wiBcyGXUfwjiAx5XsyRUHN8/uRXz43tBI7\n";
    echo "user --groups=wheel --name=kyle --password=\$y\$j9T\$Xs8MAYIz6xXvv8DRgQMIHooL\$ha4wJ0gB5wSYGO1Os8otzuOtQsGPZRoG92UPyrHU422 --iscrypted --gecos=kyle\n";
    echo "reboot\n";
    
} else {
    // Fallback with MAC discovery in kickstart
    echo "# Auto-detecting MAC address\n";
    echo "lang en_US.UTF-8\n";
    echo "keyboard us\n";
    echo "timezone America/New_York --isUtc\n";
    echo "network --bootproto=dhcp\n";
    
    echo "%packages\n";
    echo "@^server-product-environment\n";
    echo "%end\n";
    
    echo "firstboot --enable\n";
    
    echo "%pre\n";
    echo "lvremove -f /dev/fedora/root /dev/fedora/home /dev/fedora/swap 2>/dev/null || true\n";
    echo "vgremove -f fedora 2>/dev/null || true\n";
    echo "pvremove /dev/nvme0n1* 2>/dev/null || true\n";
    echo "echo LVM cleanup completed\n";
    echo "%end\n";
    
    echo "%post\n";
    echo "# Try to detect which machine this is based on available MAC addresses\n";
    echo "declare -A mac_map=(\n";
    foreach ($cfg as $mac => $data) {
        echo "  [$mac]=\"$data[name] $data[ip_fe] $data[ip_be]\"\n";
    }
    echo ")\n";
    echo "INTERFACES=(\$(ls /sys/class/net/ | grep -v lo))\n";
    echo "for iface in \${INTERFACES[@]}; do\n";
    echo "  iface_mac=\$(cat /sys/class/net/\$iface/address 2>/dev/null)\n";
    echo "  if [ -n \"\${mac_map[\$iface_mac]}\" ]; then\n";
    echo "    read name ip_fe ip_be <<< \"\${mac_map[\$iface_mac]}\"\n";
    echo "    echo \"Found configured machine: \$name\"\n";
    echo "    FRONTEND_IF=\"\$iface\"\n";
    echo "    break\n";
    echo "  fi\n";
    echo "done\n";
    echo "if [ -n \"\$FRONTEND_IF\" ]; then\n";
    echo "  BACKEND_IF=\"\"\n";
    echo "  for iface in \${INTERFACES[@]}; do\n";
    echo "    if [ \"\$iface\" != \"\$FRONTEND_IF\" ] && [ -n \"\$iface\" ]; then\n";
    echo "      BACKEND_IF=\"\$iface\"\n";
    echo "      break\n";
    echo "    fi\n";
    echo "  done\n";
    echo "  echo \"FRONTEND_IF=\$FRONTEND_IF\" > /root/network-interfaces.txt\n";
    echo "  echo \"BACKEND_IF=\$BACKEND_IF\" >> /root/network-interfaces.txt\n";
    echo "  cat > /etc/rc.d/rc.local << 'RC_EOF'\n";
    echo "#!/bin/bash\n";
    echo "source /root/network-interfaces.txt\n";
    echo "sleep 10\n";
    echo "nmcli con delete \"\$FRONTEND_IF\" 2>/dev/null || true\n";
    echo "nmcli con delete \"\$BACKEND_IF\" 2>/dev/null || true\n";
    echo "nmcli con delete 'Wired Connection' 2>/dev/null || true\n";
    echo "nmcli con add type ethernet con-name \"\$FRONTEND_IF\" ifname \"\$FRONTEND_IF\" ipv4.method manual ipv4.addresses 192.168.68.\$ip_fe/24 ipv4.gateway 192.168.68.1 ipv4.dns 1.1.1.1\n";
    echo "nmcli con mod \"\$FRONTEND_IF\" connection.autoconnect yes\n";
    echo "nmcli con add type ethernet con-name \"\$BACKEND_IF\" ifname \"\$BACKEND_IF\" ipv4.method manual ipv4.addresses 192.168.3.\$ip_be/24\n";
    echo "nmcli con mod \"\$BACKEND_IF\" connection.autoconnect yes\n";
    echo "nmcli con up \"\$FRONTEND_IF\"\n";
    echo "nmcli con up \"\$BACKEND_IF\"\n";
    echo "hostnamectl set-hostname \$name\n";
    echo "systemctl disable rc-local.service\n";
    echo "rm -f /etc/rc.d/rc.local\n";
    echo "RC_EOF\n";
    echo "  chmod +x /etc/rc.d/rc.local\n";
    echo "  systemctl enable rc-local.service\n";
    echo "fi\n";
    echo "%end\n";
    
    echo "ignoredisk --only-use=nvme0n1\n";
    echo "clearpart --all --initlabel --drives=nvme0n1\n";
    echo "part /boot/efi --fstype=efi --ondisk=nvme0n1 --size=200 --fsoptions=umask=0077,shortname=winnt\n";
    echo "part /boot --fstype=xfs --ondisk=nvme0n1 --size=1024\n";
    echo "part pv.1126 --fstype=lvmpv --ondisk=nvme0n1 --size=69634\n";
    echo "volgroup fedora --pesize=4096 pv.1126\n";
    echo "logvol /home --fstype=xfs --size=30720 --name=home --vgname=fedora\n";
    echo "logvol swap --fstype=swap --size=8192 --name=swap --vgname=fedora\n";
    echo "logvol / --fstype=xfs --size=30720 --name=root --vgname=fedora\n";
    
    echo "timesource --ntp-server=ultimate.mattnordhoffdns.net\n";
    echo "rootpw --iscrypted \$y\$j9T\$bFqIJV8oOxxRRZSeEz2PSOes\$DKxY6HzV4wiBcyGXUfwjiAx5XsyRUHN8/uRXz43tBI7\n";
    echo "user --groups=wheel --name=kyle --password=\$y\$j9T\$Xs8MAYIz6xXvv8DRgQMIHooL\$ha4wJ0gB5wSYGO1Os8otzuOtQsGPZRoG92UPyrHU422 --iscrypted --gecos=kyle\n";
    echo "reboot\n";
}
?>
EOF
