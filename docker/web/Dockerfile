FROM nginx:1.13

RUN apt-get update && apt-get install -y apt-utils

RUN apt-get install -y openssl

# Remove the default Nginx coniguration file
RUN rm -v /etc/nginx/nginx.conf && rm -v /etc/nginx/conf.d/*

# Copy a configuration file from the current directory
ADD ./nginx.conf /etc/nginx/nginx.conf
ADD ./config/* /etc/nginx/conf.d/

ARG http_auth_user
ARG http_auth_password

RUN echo -n ${http_auth_user}:$(openssl passwd -apr1 ${http_auth_password}) > /etc/nginx/arbitrage.passwd

ARG uid
ARG username

RUN (users | grep -q ${username}) || \
    (useradd -s /bin/bash -m -u ${uid} ${username} \
    && chown -R ${username}:${username} /home/${username} \
    && echo "Using user ${username} with UID ${uid}.")

