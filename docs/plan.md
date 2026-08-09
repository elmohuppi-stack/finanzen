# Fachliches Konzept

Was die App inhaltlich leisten soll und wie die Finanzdaten modelliert sind.
**Kein Statusdokument** — was fertig ist, steht im `README.md`, der Deploy in
`docs/deployment.md`.

## Ziel

Ziel ist eine **mehrbenutzerfähige Finanz-App** auf Basis von `Laravel`, `Vue 3` und Datenbank, die lokal entwickelt und getestet und anschließend auf einem **Hetzner-Produktionsserver** betrieben wird.

Der erste produktive Fokus liegt auf:

- Benutzerregistrierung und Login
- CSV-Import für Finanzdaten
- einer konsolidierten Umsatzansicht
- Kategorien, Charts und Auswertungen
- sauberer Integration von `Giro`, `Visa`, `PayPal` und `Bargeld`
- einem öffentlichen Basisauftritt mit `Impressum`, `Datenschutz` und sauber konfigurierbaren Anbieterangaben

## Abgrenzung

### Bewusst nicht Teil der Anwendung

- direkte DKB-API/PSD2-Integration
- Mobile-App
- OCR / Belegscan
- KI-Kategorisierung
- Budgetplanung

## Technische Architektur

### Backend

- `Laravel`
- Authentifizierung und Nutzertrennung
- CSV-Importservices
- Datenbank mit sauberem Finanzmodell

### Frontend

- `Vue 3`
- Dashboard, Listen, Detailansichten
- Zeitfilter: `aktueller Zeitraum`, `Monat`, `Quartal`, `Jahr`, freier Zeitraum
- Auswertung mit Kategorienbalken und kompaktem Transaktions-Edit-Modal
- öffentliche Rechtstext-Seiten und Datenschutzhinweis, rechtliche Angaben per `VITE_LEGAL_*` konfigurierbar

## Finanzquellen / Kontotypen

- `checking_account` → Giro
- `credit_card` → Visa 1, Visa 2
- `paypal_account` → PayPal
- `cash_wallet` → Bargeld
- später `asset_account` → Aktien, Gold, weitere Vermögenswerte

## Datenmodell

### Zentrale Objekte

- `users`
- `accounts`
- `transactions`
- `transaction_splits`
- `transaction_links`
- `imports`
- `import_rows`
- `categories`
- `category_rules`

### Regeln

- Beträge immer als `DECIMAL`, nie als `float`
- Originalbuchungen bleiben erhalten
- Splits und Verknüpfungen erzeugen die analytische Sicht
- jeder Nutzer sieht nur seine eigenen Daten

## CSV-Import-Konzept

Der Import muss **idempotent** sein:

- dieselbe Datei darf mehrfach importiert werden
- überlappende Zeiträume sind erlaubt
- bekannte Umsätze werden nicht doppelt gezählt

### Erkennung

- `file_hash` für identische Dateien
- `transaction_hash` pro Einzelumsatz
- Priorität für Referenzen wie `external_id`, `Kundenreferenz`, `Transaktionscode`

## Spezielle Logik

### Visa-Abrechnung auflösen

Die Giro-Sammelabbuchung der Kreditkarte wird als **Transfer / Abrechnung** behandelt und mit den Visa-Einzelumsätzen verknüpft.

### PayPal verknüpfen

Zusammengehörige Bewegungen wie `PayPal Express-Zahlung` und `Bankgutschrift auf PayPal-Konto` werden logisch verbunden, sodass nur der echte Kauf in den Auswertungen zählt.

### Buchungen splitten

Jede Buchung kann in mehrere Teilposten aufgeteilt werden.

Beispiel:

- `120 € Supermarkt`
  - `70 € Lebensmittel`
  - `50 € Bargeldabhebung`

### Bargeld-Wallet

Bargeldabhebungen werden als Transfer in ein `cash_wallet` behandelt. Manuelle Bargeldausgaben reduzieren anschließend den Bargeldbestand.

Umsetzung (`app/Services/CashWalletService.php`):

- das Konto `Bargeld` (`account_type = cash_wallet`) wird pro Nutzer beim ersten Bedarf automatisch angelegt
- zu jeder Bankbuchung mit `transfer_kind = cash_withdrawal` entsteht eine gespiegelte Gegenbuchung im Bargeldkonto (`source_system = cash_mirror`, `is_transfer`, `is_hidden_from_cashflow`)
- Bargeldauszahlungen beim Einkauf (`metadata.cash_withdrawal_amount`) werden mit ihrem Teilbetrag gespiegelt
- `TransactionTransferService` verknüpft beide Seiten anschließend über `transaction_links`
- manuelle Buchungen laufen über `POST/PATCH/DELETE /api/transactions` mit `source_system = manual`; importierte Umsätze bleiben unveränderlich
- `current_balance` und `metadata.balance_as_of` des Bargeldkontos werden nach jeder Änderung neu berechnet, damit Kontostand und Verlaufschart stimmen
- das Bargeldkonto trägt einen Stichtag (`metadata.mirror_start_date`); ältere Abhebungen werden nicht gespiegelt, damit der Bestand nicht durch nicht erfasste Alt-Ausgaben aufgebläht wird
- Bestandsdaten lassen sich einmalig nachziehen mit `php artisan cash:sync-mirrors --since=YYYY-MM-DD` (optional `--email=`, `--since=none` hebt den Stichtag auf)

## Kategorien

### Privat

- Wohnen
- Lebensmittel
- Drogerie
- Haushalt und Kleidung
- Mobilität
- Gesundheit
- Freizeit
- Familie / Kinder
- Versicherungen
- Reisen
- Abos
- Sonstiges

### Beruflich / freiberuflich

- Software / SaaS
- Reisekosten beruflich
- Telefon / Internet beruflich

### Einnahmen / Sonderfälle

- Gehalt
- Nebeneinkünfte
- Transfer

## Verifikation

1. Registrierung/Login prüfen
2. Nutzertrennung validieren
3. gleiche CSV mehrfach importieren → keine Dubletten
4. überlappende CSVs importieren → nur neue Umsätze
5. Visa-Sammelbuchung korrekt auflösen
6. PayPal-Zahlung + Gegenbuchung korrekt verknüpfen
7. Splits korrekt kategorisieren und auswerten
8. lokal testen, danach Deployment auf Hetzner prüfen
9. Kategorieänderung und Regelanlage direkt aus der Auswertung prüfen
10. `Impressum` und `Datenschutz` mit korrekten Env-Werten lokal und vor Livegang prüfen
