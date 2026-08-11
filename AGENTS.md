# AGENTS.md

## Purpose

This repository contains a multi-user finance application built with `Laravel` and `Vue 3`.
The project focuses on importing and reconciling personal finance data from `DKB Giro`, `DKB Visa`, `PayPal` and `Bargeld`; asset accounts may follow.

## Product context

Key business goals:

- import CSV files safely and repeatedly without duplicates
- resolve Visa monthly settlement blocks into individual card transactions
- link PayPal technical counter-bookings into one real purchase view
- support transaction splits for mixed payments, e.g. supermarket + cash withdrawal
- provide clean dashboard, categories, and charts
- keep the analysis tab optimized for spending review with compact rows and modal-based recategorization / rule creation
- track cash as its own account: withdrawals mirror into it, cash spending is entered by hand
- keep the public legal pages (`Impressum`, `Datenschutz`) and their env-based contact data correct — the app is publicly reachable

## Working rules for agents

- prefer small, verified steps
- always preserve the raw imported transaction data
- build analytical layers through `transaction_links` and `transaction_splits`
- treat transfers separately from real spending
- never commit real CSV financial data
- keep money values as `DECIMAL`, never float
- when changing backend behavior, verify with `php artisan test`
- when changing frontend behavior, verify with `npm run build`

## Current architecture

- `backend/` → Laravel API, auth, imports, domain models
- `frontend/` → Vue UI, auth state, import preview, dashboard shell, legal pages and privacy notice banner
- `deploy.sh`, `deploy/` → deployment and the server-side backup cron
- `README.md` → what the app does today and what is next (the single status source)
- `docs/plan.md` → domain concept: data model, import rules, category catalogue
- `docs/deployment.md` → how to deploy and operate this app

Keep each statement in exactly one of those files and link instead of repeating —
the status list used to exist in four places and had already drifted apart.

## Where the project stands

Live at `finanzen.elmarhepp.de` since 9 August 2026. Imports, categories, rules,
analysis, splits, transfer linking, the cash wallet and the legal pages are done.
The current list of what comes next is in `README.md` — do not duplicate it here.

## Local workflow

```bash
make setup
make start
make test
make check
```

## Deployment

Production runs on the Hetzner server `helsinki-80gb`. Deploy with `make deploy`
(tests, backup, `git pull`, rebuild, migrate, verify). App-specific steps are in
`docs/deployment.md`; the server-wide rules live outside this repo in
`~/workspace/optimize-hetzner`.

Two things that bit us and are easy to repeat:

- `-f docker-compose.prod.yml` is mandatory on the server. Until 2026-08-11 a
  missing `-f` picked up the dev file with its own Postgres and Mailpit; the dev
  file is now `docker-compose.dev.yml`, so a missing `-f` fails loudly instead.
  Local dev goes through `make`, which passes `-f docker-compose.dev.yml`.
- `VITE_*` values are baked in at build time and `frontend/.dockerignore` excludes
  `.env.local`. Production legal data lives in `frontend/.env.production.local` on
  the server, and changing it requires a rebuild.

## Before pushing

```bash
make test
git status
```

Never commit real CSV files or `.env` files with production values.
