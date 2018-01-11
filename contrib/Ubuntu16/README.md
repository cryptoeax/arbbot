# Ubuntu Setup Scripts/Files

## systemd startup with logging to syslog

Either copy/paste the following into `/etc/systemd/system/arbbot.service` or copy the file included in this directory to there.

> Note, you may want to add a user/group below also or change the syslog destination to a file.

###### /etc/systemd/system/arbbot.service

```shell
[Unit]
Description=Arbbot - Arbitrage Trading Bot
After=syslog.target network.target mysql.service

[Service]
ExecStart=/usr/bin/php main.php
WorkingDirectory=/var/www/arbbot
Type=simple
InaccessibleDirectories=/home /root /boot /opt /mnt /media
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=arbbot
ProtectHome=true
PrivateTmp=true
PrivateDevices=true
NoNewPrivileges=true
Restart=always

[Install]
WantedBy=multi-user.target
```

next, enable the service, start it, then check to see if it started.

```shell
sudo systemctl enable arbbot
sudo systemctl start arbbot
sudo systemctl status arbbot
```

## NTP setup

NTP is **vital** to the functionality of this tool. Make sure it works. Here's a copy/paste to make it easy:

###### ntp setup script
```shell
#!/bin/bash
apt update
apt -y install ntp
service ntp stop
ntpdate ntp.ubuntu.com
service ntp start
update-rc.d ntp enable
sleep 5
echo "Checking for Peers"
ntpq -c lpeer
```

