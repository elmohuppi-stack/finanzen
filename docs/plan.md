# Plan: Finanz-App MVP auf Laravel + Vue

## Ziel

Ziel ist eine **mehrbenutzerfähige Finanz-App** auf Basis von `Laravel`, `Vue 3` und Datenbank, die lokal entwickelt und getestet und anschließend auf einem **Hetzner-Produktionsserver** betrieben wird.

Der erste produktive Fokus liegt auf:

- Benutzerregistrierung und Login
- CSV-Import für Finanzdaten
- einer konsolidierten Umsatzansicht
- Kategorien, Charts und Auswertungen
- sauberer Integration von `Giro`, `Visa`, `PayPal` und `Bargeld`

## MVP-Umfang

### Enthalten in Version 1

- mehrbenutzerfähige Anwendung ✅
- Registrierung, Login, Passwort-Reset ✅
- zunächst ein primäres Girokonto pro Benutzer ✅
- CSV-Import für `Girokonto`, `Visa` und `PayPal` ✅
- Dubletten-sicherer Import bei mehrfach importierten oder überlappenden Dateien ✅
- Kategorien aus Standardkatalog, pro Benutzer erweiterbar ✅
- Dashboard mit KPI-Karten, Timeline, Charts und Basis-Auswertungen ✅
- manuelle Split-Funktion für Buchungen ✅
- Bargeld als eigene auswertbare Finanzquelle ✅
- Standard-Kategorienregeln für häufige Ausgaben (z.B. Amazon, Prime, Netflix) ✅
- Behandlung von Bargeldabhebungen und Cashback ✅
- Kompakte UI-Elemente und verbesserte Anzeige von Gegenparteien ✅
- Auswertung mit direkter Umbuchung und Regelanlage pro Transaktion über ein kompaktes Modal ✅

### Nicht Teil von V1

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

### Deployment

Gemäß `docs/hetzner-multi-app-template.md`:

- Frontend und API auf getrennten Subdomains
- `docker compose`
- Host-`nginx` als Reverse Proxy
- HTTPS via `certbot`
- lokal entwickeln, produktiv auf Hetzner deployen

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

## Dashboard-Vorschlag

### KPI-Karten

- Giro-Kontostand
- offener Visa-Saldo
- Bargeldbestand
- Einnahmen im Monat
- Ausgaben im Monat
- Sparquote

### Konsolidierte Timeline

Eine gemeinsame Umsatzliste mit Badges wie:

- `Giro`
- `Visa 1`
- `Visa 2`
- `PayPal`
- `Bargeld`

### Charts

1. Saldoverlauf
2. Einnahmen vs. Ausgaben pro Monat
3. Ausgaben nach Kategorie

## Kategorien

### Privat

- Wohnen
- Lebensmittel
- Drogerie
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
- Fachliteratur
- Büromaterial
- Weiterbildung
- Reisekosten beruflich
- Telefon / Internet beruflich
- Steuern / Gebühren beruflich

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
