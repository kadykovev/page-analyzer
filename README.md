### Hexlet tests and linter status:
[![Actions Status](https://github.com/kadykovev/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/kadykovev/php-project-9/actions)
![Github Actions Status](https://github.com/kadykovev/php-project-9/actions/workflows/workflow.yml/badge.svg)
<a href="https://codeclimate.com/github/kadykovev/php-project-9/maintainability"><img src="https://api.codeclimate.com/v1/badges/58b9a8f64edacd4c5a75/maintainability" /></a>

### Description:
A web application that executes queries over the network and saves data to a database.
---
Demo: https://project-page-analyzer.onrender.com/

### Requirements:
---
* PHP >= 8
* Composer
* Postgreql

### Usage:
---
To connect to the database, the application uses the DATABASE_URL environment variable. You need to import the environment variable in the following format:
```bash
export DATABASE_URL=postgresql://janedoe:mypassword@localhost:5432/mydb
```
```bash
make install
make start
```
Open in browser: http://0.0.0.0:8000

