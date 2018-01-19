FROM php:7.0-fpm

RUN apt-get update && apt-get install -y apt-utils


RUN apt-get update && apt-get install -y \
    libicu-dev unzip ntpdate libmcrypt-dev zlib1g-dev g++ ntp \
    && rm -r /var/lib/apt/lists/*


RUN docker-php-ext-configure intl \
&& docker-php-ext-install -j$(nproc) mbstring zip mcrypt bcmath intl mysqli

COPY php.ini /usr/local/etc/php/php.ini

ARG uid
ARG username
ARG db
ARG db_username
ARG db_password

# Autogenerate config.inc.php
RUN (test -f /var/www/html/web/config.inc.php && rm -f /var/www/html/web/config.inc.php) || \
      echo Ignorinng config.inc.php because it does not exist
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

# Set UID
RUN (users | grep -q ${username}) || \
    (useradd -s /bin/bash -m -u ${uid} ${username} \
    && chown -R ${username}:${username} /home/${username} \
    && echo "Using user ${username} with UID ${uid}.")

CMD ["php-fpm"]
