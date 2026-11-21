#!/bin/sh
# Prepares the bind-mounted application before FrankenPHP starts. Any other
# command (composer, bin/console, phpunit, …) runs as it is.
set -e

if [ "$1" = 'frankenphp' ]; then
	if [ ! -f vendor/autoload_runtime.php ]; then
		composer install --no-interaction --no-progress --no-scripts
	fi

	bin/console system:check --wait --allow-empty-database
	bin/console importmap:install
	bin/console assets:install --symlink --relative public

	bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

	# The test database carries the suffix `_test`, see config/packages/doctrine.yaml.
	bin/console doctrine:database:create --if-not-exists --env=test
	bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

	# The data CI seeds before the tests run. The dev database gets it as well,
	# for a login. Only an empty database is seeded, so nothing created by hand
	# is touched.
	for env in dev test; do
		if bin/console dbal:run-sql --env="${env}" 'SELECT COUNT(*) AS domains FROM mail_domains' | grep -Eq '^[[:space:]]*0[[:space:]]*$'; then
			echo "Seeding the ${env} database with example.com and admin@example.com / changeme"
			bin/console domain:add --env="${env}" example.com
			bin/console user:add --env="${env}" --password=changeme --admin --enable admin example.com
			bin/console dkim:setup --env="${env}" --no-interaction --enable --selector dkim example.com
		fi
	done

	bin/console redis:sync
fi

exec docker-php-entrypoint "$@"
