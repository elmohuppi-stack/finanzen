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
- Bearbeitungs-Modal im `Auswertung`-Tab zum Umhängen von Kategorien und Erstellen neuer Regeln
- Performance-Optimierungen für die Auswertung auf Live und lokal
- Rechtstexte im Frontend: `Impressum`, `Datenschutz` und schlanker Datenschutzhinweis
- Rechtliche Kontaktdaten werden per `VITE_LEGAL_*`-Umgebungsvariablen gesetzt
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
- `http://127.0.0.1:5173/analysis` → Kategorie auswählen, `✎` klicken, Kategorie ändern oder Regel anlegen
- `http://127.0.0.1:5173/impressum` und `http://127.0.0.1:5173/datenschutz`
- `http://127.0.0.1:5173/help` → aktualisierter Projektstatus und FAQ

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

1. Rechtstexte inhaltlich final prüfen und die öffentlichen Anbieterangaben in der Produktions-Umgebung setzen
2. Danach den öffentlichen Livegang wieder aktivieren und kurz End-to-End verifizieren
3. Budgetplanung und Sparziele auf Basis der vorhandenen Auswertungen ergänzen
4. Regelvorschläge und Bulk-Kategorisierung weiter verbessern
5. Vermögenswerte wie Aktien / Gold ergänzen
6. DKB-API/PSD2-Integration vorbereiten
7. Mobile-Optimierung für die wichtigsten Flows verbessern

## Deployment und spaetere Updates

Der produktive Rollout auf Hetzner ist jetzt dokumentiert in:

- `docs/deployment.md` – exakte Copy/Paste-Befehle fuer Updates, Verifikation und Rollback
- `docs/hetzner-multi-app-template.md` – allgemeiner Multi-App-Standard fuer kuenftige Projekte

Wenn du spaeter neue Versionen ausrollen willst, nutze am besten den dort beschriebenen Ablauf aus:

1. lokal bauen und Tests laufen lassen
2. vor dem öffentlichen Relaunch die `VITE_LEGAL_*`-Werte in der Produktions-Umgebung setzen
3. per `rsync` nach `/var/www/finanzen` synchronisieren
4. `docker compose --env-file .env -f docker-compose.prod.yml up -d --build`
5. Frontend + API sowie `/impressum` und `/datenschutz` verifizieren

## Vor GitHub-Push prüfen

```bash
make test
git status
```

Achte besonders darauf, dass keine echten CSV-Dateien oder `.env`-Dateien mit in den Commit geraten.
