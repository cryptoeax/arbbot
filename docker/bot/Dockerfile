FROM hhvm/hhvm:3.21-lts-latest

RUN apt-get update && apt-get install -y apt-utils

RUN apt-get install -y \
    libicu-dev unzip ntpdate libmcrypt-dev zlib1g-dev g++ mysql-client ntp \
    && rm -r /var/lib/apt/lists/*

ADD wait-for-db.sh /bin/wait-for-db.sh
RUN chmod +x /bin/wait-for-db.sh
ADD ntp.sh /bin/ntp.sh
RUN /bin/ntp.sh

COPY php.ini /usr/local/etc/php/php.ini

ARG uid
ARG username
ARG password

RUN (users | grep -q ${username}) || \
    (useradd -s /bin/bash -m -u ${uid} ${username} \
    && chown -R ${username}:${username} /home/${username} \
    && echo "Using user ${username} with UID ${uid}.")

WORKDIR "/home/${username}"
