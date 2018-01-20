FROM hhvm/hhvm:3.21-lts-latest

RUN apt-get update && apt-get install -y apt-utils

RUN apt-get install -y \
    libicu-dev unzip ntpdate libmcrypt-dev zlib1g-dev g++ mysql-client ntp \
    && rm -r /var/lib/apt/lists/*

ADD arbbot.sh /bin/arbbot.sh
ADD wait-for-db.sh /bin/wait-for-db.sh
RUN chmod +x /bin/arbbot.sh
RUN chmod +x /bin/wait-for-db.sh
ADD ntp.sh /bin/ntp.sh
RUN /bin/ntp.sh

COPY php.ini /usr/local/etc/php/php.ini

ARG uid
ARG username
ARG password
ARG db
ARG db_username
ARG db_password

# Autogenerate config.inc.php
RUN mkdir -p /etc/arbbot \
     && echo '<?php' > /etc/arbbot/config.inc.php \
     && echo '' >> /etc/arbbot/config.inc.php \
     && echo '$dbHost = "db";' >> /etc/arbbot/config.inc.php \
     && echo -n '$dbName = "'>> /etc/arbbot/config.inc.php \
     && echo -n ${db} >> /etc/arbbot/config.inc.php \
     && echo '";' >> /etc/arbbot/config.inc.php \
     && echo -n '$dbUser = "' >> /etc/arbbot/config.inc.php \
     && echo -n ${db_username} >> /etc/arbbot/config.inc.php \
     && echo '";' >> /etc/arbbot/config.inc.php \
     && echo -n '$dbPass = "' >> /etc/arbbot/config.inc.php \
     && echo -n ${db_password} >> /etc/arbbot/config.inc.php \
     && echo '";' >> /etc/arbbot/config.inc.php

RUN (users | grep -q ${username}) || \
    (useradd -s /bin/bash -m -u ${uid} ${username} \
    && chown -R ${username}:${username} /home/${username} \
    && echo "Using user ${username} with UID ${uid}.")

RUN mkdir -p /var/arbbot && chown -R ${username}:${username} /var/arbbot

WORKDIR "/home/${username}"
