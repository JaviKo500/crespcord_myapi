# Local development environment. Full guide: docs/desarrollo-local.md
#
#   make up         start the containers
#   make pull-code  bring core + sites/all from production (read-only there)
#   make pull-db    dump the production database (read-only there)
#   make import     load the newest dump, sanitize it, updb + cc all
#
# pull-code and pull-db connect to PRODUCTION and ask for confirmation.

L := scripts/local

.PHONY: help up down build restart ps logs logs-php pull-code pull-db import \
        reset refresh settings drush-status cc updb cron queue shell shell-root \
        shell-db test stan integration e2e manual mail-test watchdog clean-dumps destroy

help:
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-13s %s\n", $$1, $$2}'

up: ## Start the containers (web, db, mailpit)
	docker compose up -d

down: ## Stop the containers (the database volume is kept)
	docker compose down

build: ## Rebuild the web image
	docker compose build web

restart: ## Restart Apache + PHP-FPM
	docker compose restart web

ps: ## Show container status
	docker compose ps

logs: ## Follow the container logs
	docker compose logs -f --tail=50

logs-php: ## Follow the PHP error log (must stay empty)
	docker compose exec web tail -n 50 -f /var/log/php8.3-fpm-errors.log

pull-code: ## Rsync core + sites/all FROM production
	$(L)/pull-code.sh

pull-db: ## Dump the production database to .local/dumps
	$(L)/pull-db.sh

import: ## Load the newest dump, sanitize, updb, cc all
	$(L)/import-db.sh

reset: ## Recreate the database from the sanitized dump (no production access)
	$(L)/import-db.sh .local/dumps/local-sanitized.sql.gz

refresh: pull-db import ## New dump from production + import

settings: ## Regenerate the local settings.php from .env
	$(L)/gen-settings.sh

drush-status: ## drush status
	$(L)/drush.sh status

cc: ## drush cc all
	$(L)/drush.sh cc all

updb: ## drush updb -y && drush cc all (local "deploy")
	$(L)/deploy.sh

cron: ## Run Drupal cron once
	$(L)/drush.sh cron

queue: ## Drain the mail and push queues once (production does it every minute)
	$(L)/drush.sh queue-run myapi_mail_send
	$(L)/drush.sh queue-run myapi_onesignal_push

shell: ## Shell in the web container as www-data
	$(L)/shell.sh web

shell-root: ## Shell in the web container as root
	$(L)/shell.sh root

shell-db: ## MariaDB client on the local database
	$(L)/shell.sh db

test: ## PHPUnit on PHP 8.3 (container)
	$(L)/run-unit-tests.sh

stan: ## PHPStan on PHP 8.3 (container)
	$(L)/run-static-analysis.sh

integration: ## SimpleTest integration tests against the local site
	$(L)/run-integration-tests.sh

e2e: ## Postman collection + password reset roundtrip against the local site
	$(L)/run-e2e-tests.sh

manual: ## Build and publish the user manual locally
	$(L)/deploy-manual.sh

mail-test: ## Send two test mails; they must show up in Mailpit
	$(L)/drush.sh php-script /var/www/html/sites/all/modules/myapi/$(L)/send-test-mail.php

watchdog: ## Last warnings and errors in Drupal's log
	$(L)/drush.sh watchdog-show --severity=warning --count=30

clean-dumps: ## Delete every dump in .local/dumps
	rm -f .local/dumps/*.sql.gz .local/dumps/*.partial

destroy: ## Remove containers AND the database volume
	docker compose down -v
