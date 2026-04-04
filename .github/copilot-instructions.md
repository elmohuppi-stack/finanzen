# Copilot Instructions for Finanzen

This project is a finance application with strong emphasis on import correctness and explainability.

## Focus areas

- CSV import correctness over speed
- duplicate-safe ingestion
- link Visa and PayPal transactions before evaluating spending
- preserve raw data and derive analytical views separately
- prioritize readable, calm UI with good light/dark support

## Coding preferences

- small verified commits
- test backend changes with `php artisan test`
- test frontend changes with `npm run build`
- do not add mock-only production behavior
- keep architecture simple and evolvable for later DKB / PSD2 integration

## Sensitive data

Never commit:

- real CSV bank exports
- `.env` files
- credentials or tokens
