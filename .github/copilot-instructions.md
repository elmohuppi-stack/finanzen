# Copilot Instructions for Finanzen

This project is a finance application with strong emphasis on import correctness and explainability.

## Focus areas

- CSV import correctness over speed
- duplicate-safe ingestion
- link Visa and PayPal transactions before evaluating spending
- preserve raw data and derive analytical views separately
- prioritize readable, calm UI with good light/dark support
- treat the analysis tab as a critical workflow; keep transaction rows compact and move recategorization / rule creation into focused dialogs

## Coding preferences

- small verified commits
- test backend changes with `php artisan test`
- test frontend changes with `npm run build`
- do not add mock-only production behavior
- keep architecture simple and evolvable for later DKB / PSD2 integration
- for dense finance views, prefer compact lists with modal editing over large inline forms

## Sensitive data

Never commit:

- real CSV bank exports
- `.env` files
- credentials or tokens
