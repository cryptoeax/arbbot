FROM jwilder/docker-gen

# Create nginx.tmpl as a file to ensure it won't get mapped as a directory
RUN mkdir -p /etc/docker-gen/templates
ADD nginx.tmpl /etc/docker-gen/templates/nginx.tmpl
