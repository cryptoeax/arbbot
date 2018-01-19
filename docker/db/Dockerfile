FROM mysql:5.7

ARG db
ARG db_username
ARG db_password

RUN echo -n 'GRANT ALL ON ' > /docker-entrypoint-initdb.d/00-privileges.sql
RUN echo -n ${db} >> /docker-entrypoint-initdb.d/00-privileges.sql
RUN echo -n '.* TO ' >> /docker-entrypoint-initdb.d/00-privileges.sql
RUN echo -n ${db_username} >> /docker-entrypoint-initdb.d/00-privileges.sql
RUN echo -n "@'%' IDENTIFIED BY '" >> /docker-entrypoint-initdb.d/00-privileges.sql
RUN echo -n ${db_password} >> /docker-entrypoint-initdb.d/00-privileges.sql
RUN echo "';" >> /docker-entrypoint-initdb.d/00-privileges.sql
