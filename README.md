# Finanzen

Moderne, mehrbenutzerfähige Finanz-App mit `Laravel`, `Vue 3`, `Pinia` und Datenbank.

## Überblick

Die App soll private Finanzen verständlicher machen als klassische Banking-Tools. Der Fokus liegt darauf, Zahlungsquellen wie `Giro`, `Visa`, `PayPal` und `Bargeld` in **einer konsolidierten Sicht** zusammenzuführen.

Besonders wichtig:

- **Visa-Sammelabbuchungen** werden nicht als Blackbox angezeigt, sondern auf Einzelumsätze zurückgeführt.
- **PayPal-Buchungen** und technische Gegenbewegungen werden logisch verknüpft.
- **Bargeldabhebungen** können aufgesplittet und später gegen manuelle Barausgaben verrechnet werden.
- Kategorien kommen aus einem Standardkatalog, sind aber pro Benutzer erweiterbar.
- Im **Auswertung**-Tab lassen sich Buchungen direkt in andere Kategorien verschieben oder per Regel automatisieren – kompakt über ein Bearbeitungs-Modal.

---

## Aktueller Stand

Bereits umgesetzt:

- Laravel-Backend mit API-Grundgerüst
- Vue-Frontend mit Login und Import-Vorschau
- Token-Auth via `Laravel Sanctum`
- CSV-Format-Erkennung für:
  - `DKB Giro`
  - `DKB Visa`
  - `PayPal`
- erste Finanzmodelle für Konten, Umsätze, Splits und Verknüpfungen
- modernes Hell-/Dunkel-Theme im Frontend
- Persistierung von CSV-Imports in der Datenbank
- Normalisierung von Giro-, Visa- und PayPal-Zeilen in gemeinsame Transaktionsdatensätze
- Verknüpfungslogik für Visa- und PayPal-Transaktionen
- Standard-Kategorienregeln für häufige Ausgaben (z.B. Amazon, Prime, Netflix)
- Behandlung von Bargeldabhebungen und Cashback
- Bargeldkonto: manuelle Bar-Buchungen, automatische Gegenbuchung bei Abhebungen, Stichtag für den Altbestand
- Kompakte UI für Kategorie-Karten
- Korrektur der Gegenparteien-Anzeige für eingehende Zahlungen
- Dashboard mit KPIs, Tabellen und Charts
- Split-Funktion für gemischte Zahlungen
- Kompakte Bearbeitung direkt im `Auswertung`-Tab: Transaktionen umhängen und Regeln per Modal anlegen
- Performance-Optimierungen für schnellen Monatswechsel und Analyseabrufe
- Basis-Rechtstexte im Frontend: `Impressum`, `Datenschutz` und Datenschutzhinweis-Banner
- Rechtliche Kontaktangaben im Frontend über `VITE_LEGAL_*`-Umgebungsvariablen statt fest im Code
- Umfassende Tests für Backend und Frontend

---

## MVP-Ziele

Version 1 fokussiert auf:

- Registrierung und Login
- CSV-Import für `Giro`, `Visa` und `PayPal`
- Dubletten-sicheren Import bei mehrfachen oder überlappenden Dateien
- konsolidierte Umsatzliste mit Kategorien, Splits und Auswertungen
- lokales Testen und späteres Deployment auf Hetzner-Produktion

Nicht Teil von V1:

- direkte DKB-API / PSD2-Integration
- Mobile-App
- OCR / Belegscan
- KI-Kategorisierung
- Budgetplanung

---

## Projektstruktur

```text
backend/   Laravel API, Auth, Importlogik, Datenmodell
frontend/  Vue 3 Frontend mit Dashboard und Import-Vorschau
csv/       lokale Beispieldaten, nicht versioniert
docs/      Architektur-, Planungs- und Übergabedokumentation
```

Wichtige Dateien:

- `docs/plan.md` – fachlicher Projektplan
- `docs/OWNER_GUIDE.md` – Projektkontext und nächste Schritte für dich
- `docs/deployment.md` – Deploy- und Update-Ablauf für diese App
- `AGENTS.md` – Arbeitskontext und Regeln für Copilot/Agenten

Die serverweiten Regeln – Portvergabe, Speicherbudget, Prüfschritte, Backup – stehen nicht in diesem Repo, sondern zentral in `~/workspace/optimize-hetzner`.

---

## Voraussetzungen

- PHP `8.4+`
- Composer `2.8+`
- Node.js `22+`
- npm `10+`
- Docker + `docker compose` optional für lokale Dienste

---

## Schnellstart lokal

### 1. Einmaliges Setup

```bash
make setup
```

Das erledigt:

- Backend- und Frontend-Abhängigkeiten installieren
- `.env` vorbereiten
- lokale SQLite-Datei bereitstellen
- Migrationen frisch ausführen
- Seeder ausführen

### 2. App starten

```bash
make start
```

Alternativ einzeln:

```bash
make start-backend
make start-frontend
```

Danach typischerweise:

- Backend: `http://127.0.0.1:8000`
- Frontend: `http://127.0.0.1:5173`

### 3. Lokalen Stand prüfen

```bash
make test
make check
```

### Frontend-Rechtsangaben per Umgebungsvariable

Die öffentlichen Seiten `Impressum` und `Datenschutz` lesen ihre Kontaktdaten aus Vite-Env-Werten statt aus festem Code.

Wichtige Variablen:

- `VITE_LEGAL_NAME`
- `VITE_LEGAL_EMAIL`
- `VITE_LEGAL_ADDRESS_LINE_1`
- `VITE_LEGAL_ADDRESS_LINE_2`
- `VITE_LEGAL_COUNTRY`
- `VITE_LEGAL_CONTENT_RESPONSIBLE`

Für lokal empfiehlt sich `frontend/.env.local` als nicht versionierte Datei. Als Vorlage dient `frontend/.env.example`.
Für Produktion sollten die Werte in einer nicht eingecheckten Build-Umgebung oder z. B. in `frontend/.env.production.local` gesetzt werden.

---

## Make-Targets

- `make help` – zeigt alle wichtigen Targets an
- `make setup` – vollständiges lokales Setup
- `make install` – installiert Backend- und Frontend-Abhängigkeiten
- `make start` – startet Backend und Frontend in separaten Terminalfenstern
- `make start-backend` – startet Laravel lokal auf Port `8000`
- `make start-frontend` – startet Vue/Vite lokal auf Port `5173`
- `make dev-backend` – startet den Laravel-Dev-Stack per Composer
- `make dev-frontend` – startet Vue/Vite im Dev-Modus
- `make migrate` – führt Migrationen aus
- `make migrate-fresh` – setzt die DB zurück und migriert neu
- `make seed` – lädt Seed-Daten
- `make cash-sync` – gleicht die Bargeld-Gegenbuchungen ab
- `make test` – führt Backend-Tests und Frontend-Build-Check aus
- `make check` – prüft den lokalen Health-Endpoint
- `make build` – baut das Frontend für Produktion
- `make open` – zeigt die lokalen URLs an

---

## Lokale Testdaten

Für den aktuellen Stand gibt es einen Seed-User:

- **E-Mail:** `test@example.com`
- **Passwort:** `password`

Damit lassen sich bereits testen:

- Login im Frontend
- Auth-API
- CSV-Import-Vorschau mit Format-Erkennung

---

## CSV-Import-Regeln

Die Importlogik ist von Anfang an auf Nachvollziehbarkeit und Idempotenz ausgelegt:

- identische Dateien dürfen mehrfach importiert werden
- überlappende Zeiträume sind erlaubt
- Dubletten werden über Datei-Hash und Transaktions-Hash vermieden
- Originalbuchungen bleiben erhalten
- Splits und Verknüpfungen erzeugen nur die analytische Sicht

Beispiele:

- Visa-Sammelabbuchung im Giro → später als **Transfer** markieren
- PayPal-Zahlung + Gegenbuchung → später als **ein echter Kauf** auswerten
- Supermarkt + Bargeld → in mehrere Teilposten splitten

---

## Deployment

Die App läuft auf dem Hetzner-Server `helsinki-80gb` hinter Host-`nginx`, je ein Container für Frontend und API, TLS via `certbot`. Ausgerollt wird per `git pull` auf dem Server:

```bash
make test
git push origin main
ssh elmarhepp 'cd /var/www/finanzen && git pull origin main && docker compose -f docker-compose.prod.yml up -d --build'
```

Domains:

- `finanzen.elmarhepp.de` (Port `3021`)
- `finanzen-api.elmarhepp.de` (Port `3022`)

Bequemer ist `make deploy`: prüft sauberen Working Tree, gepushten Stand und Tests, sichert die Live-Datenbank, baut auf dem Server, migriert und verifiziert die Endpunkte. Die Datenbank wird zusätzlich nächtlich gesichert (`./deploy.sh install-cron`, 14 Tage).

Der vollständige Ablauf inklusive Restore und Rollback steht in `docs/deployment.md`. Die serverweiten Regeln und die Prüfschritte nach jedem Deploy stehen zentral in `~/workspace/optimize-hetzner`.

---

## Hinweise zu CSV-Dateien

> Echte CSV-Dateien mit Finanzdaten werden **nicht** eingecheckt. Die relevanten Pfade sind in `.gitignore` ausgeschlossen.

---

## Roadmap

### Als Nächstes

1. Rechtstexte final prüfen und den öffentlichen Livegang sauber wieder aktivieren
2. Budgetplanung und Sparziele auf Basis der vorhandenen Auswertungen ergänzen
3. Regelvorschläge und Bulk-Kategorisierung weiter verfeinern
4. Vermögenswerte wie Aktien / Gold ergänzen
5. DKB-/PSD2-Integration vorbereiten

### Später

- Mobile-Optimierung
- OCR / Belegscan
- weitergehende Automatisierungen für wiederkehrende Zahlungen

---

## Planung

Der aktuelle fachliche Projektplan liegt in `docs/plan.md`.
