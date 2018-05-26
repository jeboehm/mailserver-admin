FROM jeboehm/php-nginx-base:7.2
LABEL maintainer="jeff@ressourcenkonflikt.de"
LABEL vendor="https://github.com/jeboehm/mailserver-admin"

COPY nginx.conf /etc/nginx/sites-enabled/10-docker.conf
COPY . /var/www/html/

ENV APP_ENV=prod \
    TRUSTED_PROXIES=172.16.0.0/12 \
    MYSQL_HOST=db \
    MYSQL_DATABASE=mailserver \
    MYSQL_USER=mailserver \
    MYSQL_PASSWORD=changeme

RUN composer install --no-dev --prefer-dist -o --apcu-autoloader && \
    bin/console cache:clear --no-warmup --env=prod && \
    bin/console cache:warmup --env=prod && \
    bin/console assets:install public --env=prod && \
    rm -f nginx.conf
