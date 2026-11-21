# Development image of mailserver-admin. The repository is bind-mounted at /app,
# so nothing of it is copied in here; production images are built in
# docker-mailserver.
FROM composer:2@sha256:50ea4d33fdcde1adb0f045547295faa1548b33872e5f789dae5b02ce2fb5de93 AS composer

FROM dunglas/frankenphp:1-php8.5-alpine@sha256:45b44e677ae199015523e2e4d4a3543cf852d15e9c2aa6dca0f441a959f749dc AS dev

ARG UID=1000
ARG GID=1000

# git and unzip for composer, openssl for `composer run create-tls`.
RUN apk add --no-cache git openssl unzip

RUN install-php-extensions \
	intl \
	pdo_mysql \
	pdo_pgsql \
	redis \
	xdebug \
	zip

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY conf.d/app.dev.ini $PHP_INI_DIR/conf.d/zz-app.dev.ini
COPY Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/app-entrypoint

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Runs as the host's user so that files written to the bind mount (vendor/,
# var/, tls/) stay the developer's. The group may already exist in Alpine,
# e.g. GID 20 of macOS.
RUN <<-EOF
	set -e
	group="$(getent group "${GID}" | cut -d: -f1)"
	if [ -z "${group}" ]; then
		addgroup -g "${GID}" app
		group=app
	fi
	adduser -D -u "${UID}" -G "${group}" app
	mkdir -p /home/app/.composer/cache
	chown -R "${UID}:${GID}" /home/app /app /config/caddy /data/caddy
EOF

ENV COMPOSER_HOME=/home/app/.composer \
	XDEBUG_MODE=off

USER app
WORKDIR /app

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
