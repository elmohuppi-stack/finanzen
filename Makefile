SHELL := /bin/zsh

BACKEND_DIR := backend
FRONTEND_DIR := frontend
COMPOSE := docker compose
BACKEND_URL := http://127.0.0.1:8000
FRONTEND_URL := http://127.0.0.1:5173

.PHONY: help install setup backend-install frontend-install up down restart logs dev start dev-backend dev-frontend start-backend start-frontend migrate migrate-fresh seed test test-backend test-frontend check build backend-shell frontend-shell open

help:
	@echo "Available targets:"
	@echo "  make setup          - install deps, prepare env, run migrations + seed"
	@echo "  make install        - install backend and frontend dependencies"
	@echo "  make start          - open backend and frontend in separate macOS Terminal windows"
	@echo "  make test           - run backend tests and frontend build check"
	@echo "  make check          - ping the local backend health endpoint"
	@echo "  make open           - print local URLs"

setup: install migrate-fresh seed
	@echo "Local setup finished."
	@echo "Backend:  $(BACKEND_URL)"
	@echo "Frontend: $(FRONTEND_URL)"

install: backend-install frontend-install

backend-install:
	@if [ ! -d "$(BACKEND_DIR)" ]; then echo "Missing $(BACKEND_DIR) directory. Scaffold the backend first."; exit 1; fi
	cd $(BACKEND_DIR) && composer install
	@if [ ! -f "$(BACKEND_DIR)/.env" ] && [ -f "$(BACKEND_DIR)/.env.example" ]; then cp $(BACKEND_DIR)/.env.example $(BACKEND_DIR)/.env; fi
	@if [ -f "$(BACKEND_DIR)/artisan" ]; then cd $(BACKEND_DIR) && php artisan key:generate --force; fi
	@if [ ! -f "$(BACKEND_DIR)/database/database.sqlite" ]; then touch $(BACKEND_DIR)/database/database.sqlite; fi

frontend-install:
	@if [ ! -d "$(FRONTEND_DIR)" ]; then echo "Missing $(FRONTEND_DIR) directory. Scaffold the frontend first."; exit 1; fi
	cd $(FRONTEND_DIR) && npm install

dev:
	@echo "Use two terminals or run: make start"

start:
	@echo "Starting backend and frontend in separate Terminal windows..."
	@osascript -e 'tell application "Terminal" to do script "cd /Users/elmarhepp/workspace/finanzen && make start-backend"'
	@osascript -e 'tell application "Terminal" to do script "cd /Users/elmarhepp/workspace/finanzen && make start-frontend"'
	@echo "Backend:  $(BACKEND_URL)"
	@echo "Frontend: $(FRONTEND_URL)"

start-backend:
	cd $(BACKEND_DIR) && php artisan serve --host=127.0.0.1 --port=8000

start-frontend:
	cd $(FRONTEND_DIR) && npm run dev -- --host 127.0.0.1 --port 5173

dev-backend:
	cd $(BACKEND_DIR) && composer run dev

dev-frontend:
	cd $(FRONTEND_DIR) && npm run dev

migrate:
	cd $(BACKEND_DIR) && php artisan migrate

migrate-fresh:
	cd $(BACKEND_DIR) && php artisan migrate:fresh

seed:
	cd $(BACKEND_DIR) && php artisan db:seed

test: test-backend test-frontend

test-backend:
	cd $(BACKEND_DIR) && php artisan test

test-frontend:
	cd $(FRONTEND_DIR) && npm run build

check:
	@curl -fsS $(BACKEND_URL)/api/health && echo

build:
	cd $(FRONTEND_DIR) && npm run build

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

restart: down up

logs:
	$(COMPOSE) logs -f

backend-shell:
	cd $(BACKEND_DIR) && $$SHELL

frontend-shell:
	cd $(FRONTEND_DIR) && $$SHELL

open:
	@echo "Backend:  $(BACKEND_URL)"
	@echo "Frontend: $(FRONTEND_URL)"
