# AGENTS.md

## Purpose

This repository contains a multi-user finance application built with `Laravel` and `Vue 3`.
The project focuses on importing and reconciling personal finance data from `DKB Giro`, `DKB Visa`, `PayPal`, and later `Bargeld` / asset accounts.

## Product context

Key business goals:

- import CSV files safely and repeatedly without duplicates
- resolve Visa monthly settlement blocks into individual card transactions
- link PayPal technical counter-bookings into one real purchase view
- support transaction splits for mixed payments, e.g. supermarket + cash withdrawal
- provide clean dashboard, categories, and charts
- keep the analysis tab optimized for spending review with compact rows and modal-based recategorization / rule creation
- keep the public legal pages (`Impressum`, `Datenschutz`) and env-based legal metadata consistent before a public relaunch

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
- `docs/plan.md` → feature and architecture plan
- `docs/OWNER_GUIDE.md` → handover and next steps for the project owner

## Immediate priorities

1. persist CSV imports in the database ✅
2. normalize Giro / Visa / PayPal rows into shared transaction records ✅
3. implement link logic for Visa and PayPal ✅
4. add split UI and dashboard tables/charts ✅
5. add default category rules for common expenses (Amazon, Prime, Netflix, cash withdrawals) ✅
6. handle cash withdrawals and cashback in transactions ✅
7. refine UI for better usability (compact category cards, improved counterparty display) ✅
8. add comprehensive tests and ensure builds pass ✅
9. improve analysis tab with compact transaction edit modal for recategorization and rule creation ✅
10. speed up analysis loading on live and keep month switching responsive ✅
11. add legal pages and env-driven legal contact data before public relaunch ✅
12. finalize legal wording and reactivate the public deployment 🔄

## Local workflow

```bash
make setup
make start
make test
make check
```

## Deployment

Production target is Hetzner using the conventions in `docs/hetzner-multi-app-template.md`.
