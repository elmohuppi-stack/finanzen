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

1. Imports in der DB speichern
2. echte Zeilen aus Giro / Visa / PayPal normalisieren
3. Visa-Abrechnungen auflösen
4. PayPal-Zahlungen und Gegenbuchungen zusammenführen
5. Split-Funktion für einzelne Buchungen
6. Dashboard mit KPIs, Tabelle und Charts ausbauen

## Vor GitHub-Push prüfen

```bash
make test
git status
```

Achte besonders darauf, dass keine echten CSV-Dateien oder `.env`-Dateien mit in den Commit geraten.
