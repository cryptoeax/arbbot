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

RUN (users | grep -q ${username}) || \
    (useradd -s /bin/bash -m -u ${uid} ${username} \
    && chown -R ${username}:${username} /home/${username} \
    && echo "Using user ${username} with UID ${uid}.")

RUN mkdir -p /var/arbbot && chown -R ${username}:${username} /var/arbbot

CMD ["php-fpm"]
