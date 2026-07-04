SHELL := /bin/zsh

BACKEND_DIR := backend
FRONTEND_DIR := frontend
COMPOSE := docker compose
BACKEND_URL := http://127.0.0.1:8000
FRONTEND_URL := http://127.0.0.1:5173
BACKEND_PORT := 8000
FRONTEND_PORT := 5173

.PHONY: help install setup backend-install frontend-install start start-backend start-frontend stop stop-backend stop-frontend restart restart-backend restart-frontend dev dev-backend dev-frontend build build-backend build-frontend migrate migrate-fresh seed test test-backend test-frontend check up down restart-docker logs backend-shell frontend-shell open

help:
	@echo "Available targets:"
	@echo ""
	@echo "── Setup ──"
	@echo "  make setup           install deps, prepare env, run migrations + seed"
	@echo "  make install         install backend and frontend dependencies"
	@echo ""
	@echo "── Local Dev (Host) ──"
	@echo "  make start           open backend + frontend in separate Terminal windows"
	@echo "  make start-backend   php artisan serve on port $(BACKEND_PORT)"
	@echo "  make start-frontend  Vite dev server on port $(FRONTEND_PORT)"
	@echo "  make stop            stop both dev servers"
	@echo "  make stop-backend    stop php artisan serve on port $(BACKEND_PORT)"
	@echo "  make stop-frontend   stop Vite on port $(FRONTEND_PORT)"
	@echo "  make restart         stop + start both"
	@echo "  make restart-backend stop + start backend"
	@echo "  make restart-frontend stop + start frontend"
	@echo ""
	@echo "── Docker ──"
	@echo "  make up              docker compose up -d --build"
	@echo "  make down            docker compose down"
	@echo "  make restart-docker  docker compose down + up"
	@echo "  make logs            docker compose logs -f"
	@echo ""
	@echo "── Build & Test ──"
	@echo "  make build           build frontend + backend"
	@echo "  make build-backend   composer install --optimize"
	@echo "  make build-frontend  npm run build"
	@echo "  make test            backend tests + frontend build check"
	@echo "  make test-backend    php artisan test"
	@echo "  make test-frontend   npm run build"
	@echo ""
	@echo "── Database ──"
	@echo "  make migrate         php artisan migrate"
	@echo "  make migrate-fresh   php artisan migrate:fresh"
	@echo "  make seed            php artisan db:seed"
	@echo ""
	@echo "── Other ──"
	@echo "  make check           curl backend health endpoint"
	@echo "  make open            print local URLs"
	@echo "  make backend-shell   open shell in backend directory"
	@echo "  make frontend-shell  open shell in frontend directory"

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
	cd $(BACKEND_DIR) && php artisan serve --host=127.0.0.1 --port=$(BACKEND_PORT)

start-frontend:
	cd $(FRONTEND_DIR) && npm run dev -- --host 127.0.0.1 --port $(FRONTEND_PORT)

stop-backend:
	@-lsof -ti:$(BACKEND_PORT) | xargs kill -9 2>/dev/null; echo "  ✔ Backend stopped (port $(BACKEND_PORT))"

stop-frontend:
	@-lsof -ti:$(FRONTEND_PORT) | xargs kill -9 2>/dev/null; echo "  ✔ Frontend stopped (port $(FRONTEND_PORT))"

stop: stop-backend stop-frontend

restart-backend: stop-backend
	@echo "  Starting backend…"
	@osascript -e 'tell application "Terminal" to do script "cd $(PWD) && make start-backend"' > /dev/null
	@sleep 1
	@echo "  ✔ Backend started on port $(BACKEND_PORT)"

restart-frontend: stop-frontend
	@echo "  Starting frontend…"
	@osascript -e 'tell application "Terminal" to do script "cd $(PWD) && make start-frontend"' > /dev/null
	@sleep 1
	@echo "  ✔ Frontend started on port $(FRONTEND_PORT)"

restart: restart-backend restart-frontend

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

build: build-frontend build-backend

build-backend:
	cd $(BACKEND_DIR) && composer install --optimize

build-frontend:
	cd $(FRONTEND_DIR) && npm run build

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

restart-docker: down up

logs:
	$(COMPOSE) logs -f

backend-shell:
	cd $(BACKEND_DIR) && $$SHELL

frontend-shell:
	cd $(FRONTEND_DIR) && $$SHELL

open:
	@echo "Backend:  $(BACKEND_URL)"
	@echo "Frontend: $(FRONTEND_URL)"
