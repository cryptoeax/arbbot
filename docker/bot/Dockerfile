FROM hhvm/hhvm:3.21-lts-latest
RUN mkdir /home/arbbot
COPY config.ini /home/arbbot
ENTRYPOINT [ "hhvm", "/home/arbbot/main.php" ]
