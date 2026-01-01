unlock:
	sudo chown -R ${USER}:${USER} ./app
	sudo chmod -R 775 ./app

test:
	docker compose exec php-fpm composer test

run:
	docker-compose up -d

restart:
	docker-compose restart

stop:
	docker-compose restart