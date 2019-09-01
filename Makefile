dev:
	docker-compose up -d
	composer install
	bin/console server:run

build:
	docker build --pull -t $(image) .

commit:
	vendor/bin/php-cs-fixer fix --allow-risky=yes
