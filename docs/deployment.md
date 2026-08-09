# Deployment: Finanzen

Hier steht nur, was **für diese App** gilt. Alles Serverweite — Regeln, Portvergabe,
Prüfschritte nach dem Deploy, Störungstabelle, Backup-Strategie — steht zentral in
`~/workspace/optimize-hetzner` und wird hier verlinkt statt wiederholt:

| Dokument | Wofür |
|---|---|
| [DEPLOYMENT.md](../../optimize-hetzner/DEPLOYMENT.md) | Bedienung aller Apps, Prüfschritte nach jedem Deploy, Störungstabelle, Backup und Restore |
| [ARCHITEKTUR.md](../../optimize-hetzner/ARCHITEKTUR.md) | Regeln mit Begründung: Ports, Speicherbudget, Datenhaltung, Anti-Patterns |
| [NEUE-APP.md](../../optimize-hetzner/NEUE-APP.md) | Ablauf für eine neue App — löst die frühere Vorlage `hetzner-multi-app-template.md` ab |

## Eckdaten

| | |
|---|---|
| Frontend | `https://finanzen.elmarhepp.de`, Port `3021` |
| API | `https://finanzen-api.elmarhepp.de`, Port `3022` |
| Server-Verzeichnis | `/var/www/finanzen` |
| Compose-Datei | `docker-compose.prod.yml` (**Pflichtangabe**, `docker-compose.yml` ist die Dev-Variante) |
| Deploy-Weg | `git pull` auf dem Server |
| Persistenz | SQLite im Volume `finanzen_database`, gemountet unter `/app/storage/database` |
| Konfiguration | `backend/.env.production` und `frontend/.env.production` per `env_file` |
| SSH | `ssh elmarhepp` |

Die App hängt **nicht** an `pg-shared`. Ein Neustart der gemeinsamen Datenbank
betrifft sie nicht.

> **Keine Root-`.env` im Server-Verzeichnis.** Die Ports fallen bewusst auf die
> Defaults `3021`/`3022` aus der Compose-Datei zurück. Kommandos deshalb **ohne**
> `--env-file` ausführen — mit der Option bricht Compose ab, weil die Datei fehlt.

---

## Update deployen

```bash
git add . && git commit -m "<beschreibung>" && git push origin main
make deploy          # oder ./deploy.sh
```

`deploy.sh` macht der Reihe nach: Working Tree sauber? Stand gepusht? `make test`
grün? Danach Live-Datenbank sichern, auf dem Server `git pull` und Rebuild,
`php artisan migrate --force`, und zum Schluss beide Endpunkte prüfen. Bricht ein
Schritt ab, passiert nichts Weiteres.

Weitere Aktionen:

```bash
./deploy.sh status           # Container, Endpunkte, Stand auf dem Server
./deploy.sh logs api         # Logs folgen
./deploy.sh backup           # nur sichern
./deploy.sh migrate          # nur Migrationen, mit vorheriger Sicherung
./deploy.sh rollback <commit>
```

Ziel-Host und Branch lassen sich über `DEPLOY_HOST` und `DEPLOY_BRANCH`
überschreiben.

### Von Hand, ohne Skript

```bash
ssh elmarhepp '
  cd /var/www/finanzen &&
  git pull origin main &&
  docker compose -f docker-compose.prod.yml up -d --build &&
  docker compose -f docker-compose.prod.yml exec -T api php artisan migrate --force
'
```

**`-f docker-compose.prod.yml` ist Pflicht.** Ohne die Angabe nimmt Compose die
Dev-Datei `docker-compose.yml` — und die bringt einen eigenen Postgres und Mailpit
mit, die auf dem Produktionsserver nichts zu suchen haben.

Die weitergehenden Prüfschritte nach einem Deploy — Container-Status, Speicher,
doppelte Netzwerk-Aliase — stehen zentral in
[DEPLOYMENT.md, Abschnitt 4](../../optimize-hetzner/DEPLOYMENT.md#4-nach-jedem-deploy-prüfen).

### Einmalig: Server-Verzeichnis von rsync auf git umstellen

Bis August 2026 wurde `/var/www/finanzen` per `rsync` gepflegt und ist deshalb noch
kein Git-Checkout. Die Umstellung passiert einmal:

```bash
ssh elmarhepp '
  cd /var/www/finanzen &&
  git init -b main &&
  git remote add origin https://github.com/elmohuppi-stack/finanzen.git &&
  git fetch origin main &&
  git reset --hard origin/main &&
  git status --short
'
```

`git reset --hard` überschreibt nur versionierte Dateien. `backend/.env.production`
ist nicht im Repo und bleibt unangetastet. Was `git status` danach als untracked
meldet, sind Reste des alten rsync-Stands — vor dem Aufräumen mit
`git clean -nd` erst anschauen, `git clean -fd` löscht sonst auch die
Produktions-Env.

> `frontend/.env.production` **ist** versioniert (nur die API-URL steht darin) und
> wird beim Pull überschrieben. Nicht versionierte Werte wie `VITE_LEGAL_*` gehören
> deshalb in `frontend/.env.production.local`.

---

## Artisan-Befehle in Produktion

Alle Befehle laufen im `api`-Container:

```bash
ssh elmarhepp '
  cd /var/www/finanzen &&
  docker compose -f docker-compose.prod.yml exec -T api php artisan <befehl>
'
```

### Bargeld-Gegenbuchungen abgleichen

Einmalig nach dem Rollout des Bargeldkontos. Der Befehl schreibt in die
Live-Datenbank — **vorher sichern** (siehe unten).

```bash
ssh elmarhepp '
  cd /var/www/finanzen &&
  docker compose -f docker-compose.prod.yml exec -T api \
    php artisan cash:sync-mirrors --since=2026-01-01
'
```

Der Stichtag wird im Bargeldkonto gespeichert und gilt auch für alle späteren
automatischen Gegenbuchungen. Ohne `--since` würden **alle** historischen
Abhebungen gespiegelt und der Kontostand um Beträge steigen, die längst
ausgegeben und nie erfasst wurden. Spätere Läufe brauchen die Option nicht mehr;
der Befehl ist idempotent und leert den Dashboard-Cache selbst.

---

## Datenbank sichern und zurückrollen

`php artisan db:backup` schreibt per `VACUUM INTO` einen konsistenten Stand nach
`storage/database/backups/` — auch während die App schreibt — und behält die
letzten zehn. `deploy.sh` ruft den Befehl vor jedem Deploy, jeder Migration und
jedem Rollback selbst auf. Von Hand:

```bash
./deploy.sh backup
make db-backup     # lokal
```

Die Sicherungen liegen im selben Volume wie die Datenbank und überstehen damit
Rebuilds, aber keinen Plattenausfall. Eine Kopie herunterholen:

```bash
ssh elmarhepp '
  cd /var/www/finanzen &&
  docker compose -f docker-compose.prod.yml exec -T api ls -1t /app/storage/database/backups
'

ssh elmarhepp '
  cd /var/www/finanzen &&
  docker compose -f docker-compose.prod.yml cp \
    api:/app/storage/database/backups/<datei> ./
'
```

Zurückspielen:

```bash
ssh elmarhepp '
  cd /var/www/finanzen &&
  docker compose -f docker-compose.prod.yml exec -T api \
    cp /app/storage/database/backups/<datei> /app/storage/database/database.sqlite &&
  docker compose -f docker-compose.prod.yml restart api
'
```

Das tägliche `pg-shared`-Backup deckt diese App **nicht** ab — es sichert nur
PostgreSQL. Off-site abgedeckt ist sie über das Hetzner-Image-Backup (7 Slots,
ganze Platte), das aber nur den **ganzen Server** zurückrollt, siehe
[DEPLOYMENT.md, Abschnitt 7](../../optimize-hetzner/DEPLOYMENT.md#7-backup-und-restore).

---

## Code-Rollback

```bash
ssh elmarhepp '
  cd /var/www/finanzen &&
  git checkout <commit> &&
  docker compose -f docker-compose.prod.yml up -d --build
'
```

Danach wieder auf `main` zurück (`git checkout main && git pull`), sonst läuft der
Server dauerhaft auf einem losgelösten Stand.

---

## Rechtliche Angaben im Frontend

`Impressum` und `Datenschutz` lesen ihre Kontaktdaten aus `VITE_LEGAL_*`. Die Werte
sind Build-Zeit-Variablen, gehören also **vor** den Container-Build gesetzt und
nicht ins Repo:

```bash
ssh elmarhepp 'cat >/var/www/finanzen/frontend/.env.production.local <<EOF
VITE_API_BASE_URL=https://finanzen-api.elmarhepp.de
VITE_LEGAL_NAME="<name>"
VITE_LEGAL_EMAIL="<email>"
VITE_LEGAL_ADDRESS_LINE_1="<strasse hausnummer>"
VITE_LEGAL_ADDRESS_LINE_2="<plz ort>"
VITE_LEGAL_COUNTRY="Deutschland"
VITE_LEGAL_CONTENT_RESPONSIBLE="<name>"
EOF'
```

Vorlage: `frontend/.env.example`. Welche Angaben rechtlich verpflichtend sind, steht
in [NEUE-APP.md, Abschnitt 3](../../optimize-hetzner/NEUE-APP.md#3-impressum-und-datenschutz).

---

## Regeln für diese App

- niemals echte CSV-Dateien deployen
- niemals `backend/.env.production` committen
- `-f docker-compose.prod.yml` ist bei jedem Compose-Kommando Pflicht
- vor jedem Deploy lokal `make test`
