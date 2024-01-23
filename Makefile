PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

create database:
	psql -a -d $DATABASE_URL -f database.sql