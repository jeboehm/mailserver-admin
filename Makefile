image = jeboehm/mailserver-admin

dev:
	composer install
	bin/console server:run

build:
	docker build --pull -t $(image) .

push:
	docker push $(image)

commit:
	vendor/bin/php-cs-fixer fix --allow-risky=yes
