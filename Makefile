.PHONY: help build dev install test quality clean

DOCKER_COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
DEV_SERVICE := docker-vol-dev

help: ## Mostra questo messaggio di aiuto
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Costruisce l'immagine Docker
	$(DOCKER_COMPOSE) build

dev: ## Avvia l'ambiente di sviluppo
	$(DOCKER_COMPOSE) up -d
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) bash

install: ## Installa le dipendenze Composer
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) composer install --dev

test: ## Esegue i test PHPUnit
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) composer test

quality: ## Esegue controlli di qualità del codice
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) composer quality

clean: ## Pulisce i container e i volumi
	$(DOCKER_COMPOSE) down

# Comandi CLI
console: ## Avvia la console CLI
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) php bin/console

backup-volumes: ## Esempio backup volumi
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) php bin/console backup:volumes --list

# Build commands
build-phar: ## Crea il file .phar
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) box compile

build-standalone: ## Crea eseguibili standalone
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) /usr/local/bin/build.sh

# Sviluppo
shell: ## Accedi alla shell del container
	$(DOCKER_COMPOSE) exec $(DEV_SERVICE) bash
