FROM mysql:5.7
COPY init.sh /init.sh
COPY database.sql /database.sql
RUN chmod +x /init.sh
ENTRYPOINT "/init.sh"
