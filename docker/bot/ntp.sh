#!/bin/bash
rm /etc/localtime
ln -s /usr/share/zoneinfo/UTC /etc/localtime
service ntp stop
ntpdate ntp.ubuntu.com
# || date -s "$(wget -qSO- --max-redirect=0 google.com 2>&1 | grep Date: | cut -d' ' -f5-8)Z"
service ntp start
update-rc.d ntp enable
sleep 5
echo "Checking for Peers"
ntpq -c lpeer

