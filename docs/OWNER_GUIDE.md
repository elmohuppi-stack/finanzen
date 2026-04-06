# Owner Guide

## Ziel dieses Dokuments

Diese Datei ist die kurze Arbeits- und Übergabeübersicht für dich als Projektinhaber.
Sie fasst zusammen, was bereits steht, wie du lokal testen kannst und welche nächsten Schritte sinnvoll sind.

## Was aktuell schon funktioniert

- Laravel-Backend ist angelegt
- Vue-Frontend ist angelegt
- Login via API funktioniert
- CSV-Vorschau erkennt aktuell:
  - `DKB Giro`
  - `DKB Visa`
  - `PayPal`
- Hell/Dunkel-Theme ist eingebaut
- lokale Start- und Testbefehle sind über `Makefile` verfügbar
- CSV-Imports werden in der Datenbank persistiert
- Normalisierung von Giro-, Visa- und PayPal-Zeilen
- Verknüpfungslogik für Visa- und PayPal-Transaktionen
- Standard-Kategorienregeln für häufige Ausgaben
- Behandlung von Bargeldabhebungen und Cashback
- Dashboard mit KPIs, Tabellen und Charts
- Split-Funktion für gemischte Zahlungen
- Kompakte UI und verbesserte Gegenparteien-Anzeige
- Umfassende Tests für Backend und Frontend

## Lokal arbeiten

### Setup

```bash
make setup
```

### Starten

```bash
make start
```

### Testen

```bash
make test
make check
```

## Was du im Browser testen kannst

- `http://127.0.0.1:5173/login`
- `http://127.0.0.1:5173/imports/preview`

Seed-Login:

- `test@example.com`
- `password`

## Wichtige fachliche Entscheidungen

- CSV ist der erste Importweg
- App ist mehrbenutzerfähig
- Kategorien kommen aus einem Standardkatalog, sind aber pro Nutzer erweiterbar
- Visa- und PayPal-Bewegungen sollen sinnvoll verknüpft werden
- Bargeld und Splits sind zentrale Features des MVP
- echte CSV-Dateien bleiben lokal und werden nicht versioniert

## Nächste sinnvolle Schritte

1. DKB-API/PSD2-Integration vorbereiten
2. Mobile-App entwickeln
3. OCR / Belegscan hinzufügen
4. KI-Kategorisierung implementieren
5. Budgetplanung einführen

## Vor GitHub-Push prüfen

```bash
make test
git status
```

Achte besonders darauf, dass keine echten CSV-Dateien oder `.env`-Dateien mit in den Commit geraten.
