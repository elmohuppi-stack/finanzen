# Deployment: Finanzen

Hier steht nur, was **für diese App** gilt. Alles Serverweite — Regeln, Portvergabe,
Prüfschritte nach dem Deploy, Störungstabelle, Backup-Strategie — steht zentral in
`~/workspace/platform` und wird hier verlinkt statt wiederholt:

| Dokument | Wofür |
|---|---|
| [DEPLOYMENT.md](../../platform/DEPLOYMENT.md) | Bedienung aller Apps, Prüfschritte nach jedem Deploy, Störungstabelle, Backup und Restore |
| [ARCHITEKTUR.md](../../platform/ARCHITEKTUR.md) | Regeln mit Begründung: Ports, Speicherbudget, Datenhaltung, Anti-Patterns |
| [NEUE-APP.md](../../platform/NEUE-APP.md) | Ablauf für eine neue App — löst die frühere Vorlage `hetzner-multi-app-template.md` ab |

## Eckdaten

| | |
|---|---|
| Frontend | `https://finanzen.elmarhepp.de`, Port `3021` |
| API | `https://finanzen-api.elmarhepp.de`, Port `3022` |
| Server-Verzeichnis | `/var/www/finanzen` |
| Compose-Datei | `docker-compose.prod.yml` (**Pflichtangabe**, `docker-compose.dev.yml` ist die Dev-Variante) |
| Deploy-Weg | `make deploy` → `git pull` auf dem Server |
| Live seit | Bargeldkonto und nächtliche Sicherung: 9. August 2026 |
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

**`-f docker-compose.prod.yml` ist Pflicht.** Ohne die Angabe sucht Compose eine
Datei namens `docker-compose.yml` — und die gibt es seit dem 11. August 2026 nicht
mehr: die Dev-Variante heißt jetzt `docker-compose.dev.yml`. Ein vergessenes `-f`
bricht damit ab, statt einen eigenen Postgres und ein Mailpit neben der Produktion
hochzuziehen. Vorher war genau das der Fall, und es ist der Grund für die
Umbenennung.

Die weitergehenden Prüfschritte nach einem Deploy — Container-Status, Speicher,
doppelte Netzwerk-Aliase — stehen zentral in
[DEPLOYMENT.md, Abschnitt 4](../../platform/DEPLOYMENT.md#4-nach-jedem-deploy-prüfen).

### Konfiguration: welche Datei wo lebt

Eine Regel: **öffentliche Build-Werte im Repo, alles Personenbezogene oder
Geheime nur auf dem Server.**

| Datei | Ort | Inhalt |
|---|---|---|
| `frontend/.env.production` | **Repo** | nur `VITE_API_BASE_URL`. Muss versioniert sein, weil der Docker-Build sie braucht — sonst baut ein frischer Checkout ein Frontend ohne API-Ziel, ohne Fehler |
| `frontend/.env.production.local` | Server | die `VITE_LEGAL_*`-Werte für Impressum und Datenschutz, `chmod 600` |
| `backend/.env.production` | Server | App-Key, DB-Pfad, Mail, alles Backend-seitige |
| `*.example` | Repo | Vorlagen ohne echte Werte |
| Root-`.env` | — | gibt es bewusst **nicht**, siehe oben |

Die Server-Dateien überstehen `git pull` und `git reset --hard`, weil git sie
nicht kennt. `frontend/.env.production` dagegen **wird überschrieben** — sie
gehört dem Repo. Wer die API-URL auf dem Server ändern will, trägt sie in
`.env.production.local` ein: Vite lädt die Datei später und sie gewinnt für
denselben Schlüssel.

Die `.gitignore` benennt diese Ausnahme ausdrücklich, damit der Zustand nicht
mehr im Widerspruch zu den Regeln steht.

> **Fallstrick, der einmal zugeschlagen hat:** `frontend/.dockerignore` schließt
> `.env.local` aus. Wer die Rechtsangaben dort einträgt, bekommt live die
> Platzhalter zu sehen — die Datei landet nie im Build-Kontext. Für Produktion ist
> `frontend/.env.production.local` der richtige Ort; Vite lädt sie beim Build im
> Modus `production`, und `.dockerignore` filtert sie nicht heraus. Nach einer
> Änderung ist ein Rebuild nötig, weil `VITE_*` Build-Zeit-Werte sind.

Prüfen, was tatsächlich im ausgelieferten Bundle steht:

```bash
ssh elmarhepp '
  cd /var/www/finanzen &&
  docker compose -f docker-compose.prod.yml exec -T web grep -rl "in .env.local setzen" dist
'
```

Findet der Befehl nur `legal-*.js`, ist alles in Ordnung — dort stehen die
Platzhalter als ungenutzter Fallback. Erscheint der Text im gerenderten
Impressum, fehlen die Werte im Build.

### Historie: Umstellung von rsync auf git

Bis zum 9. August 2026 wurde `/var/www/finanzen` per `rsync` gepflegt. Seitdem ist
es ein Checkout auf `main`. Zwei Dinge sind dabei aufgefallen und gelten für einen
Neuaufbau weiter:

- Das Verzeichnis gehörte durch `rsync -a` der lokalen UID `501:staff`, worauf git
  mit *dubious ownership* abbricht. Gelöst über
  `git config --global --add safe.directory /var/www/finanzen` — dieselbe Lösung
  nutzt der Server für zwei weitere Apps. Sauberer wäre `chown -R root:root`, wie
  bei knora.
- Der erste Deploy lief mit `SKIP_BACKUP=1`, weil der laufende Container den Befehl
  `db:backup` noch nicht kannte. Die Sicherung wurde vorher von Hand gezogen. Ab
  dem zweiten Deploy ist das nicht mehr nötig.

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

### Nächtliche Sicherung

Auf dem Server läuft täglich um 3:45 dieselbe Sicherung, mit 14 Tagen
Aufbewahrung — bewusst versetzt zum `pg-shared`-Backup um 3:30. Beide Dateien
liegen versioniert im Repo unter `deploy/` und werden von dort installiert:

```bash
./deploy.sh install-cron
```

| Datei | Zweck |
|---|---|
| `/usr/local/sbin/finanzen-db-backup` | ruft `artisan db:backup --keep=14` im `api`-Container auf |
| `/etc/cron.d/finanzen-db-backup` | `45 3 * * *`, `MAILTO=root` |

Das Skript gibt im Erfolgsfall nichts aus; jeder Fehler geht als Mail an root.
Läuft der `api`-Container nicht, bricht es ab, statt eine leere Sicherung zu
schreiben. Prüfen lässt es sich jederzeit von Hand:

```bash
ssh elmarhepp '/usr/local/sbin/finanzen-db-backup && echo "Sicherung in Ordnung"'
```

### Die drei Schichten

| Schicht | Deckt ab | Granularität |
|---|---|---|
| `artisan db:backup`, nächtlich + vor jedem Deploy | die Datenbank dieser App | einzelner Stand, 14 Tage |
| `sqlite`-Sicherung um 3:45 | die Datenbank dieser App, aus dem Volume heraus | einzelner Stand |
| restic auf eine Hetzner Storage Box | ganzer Server, off-site | Datei oder Verzeichnis, nicht nur alles |
| `pg_dumpall` um 3:30 | **nicht diese App** — nur die sechs Postgres-DBs | — |

> **Das Hetzner-Image mit seinen sieben Slots stand hier bis zum 16. August und gibt
> es nicht mehr.** Es fiel mit dem Anbieterwechsel am 15. August weg; die Off-Site-Rolle
> hat seit dem 11. August restic. Der Unterschied ist keine Formsache: das Image
> konnte nur den *ganzen Server* zurückholen, restic eine einzelne Datei.

Details zu den beiden serverweiten Schichten:
[DEPLOYMENT.md, Abschnitt 7](../../platform/DEPLOYMENT.md#7-backup-und-restore).

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
stehen seit dem 9. August in `/var/www/finanzen/frontend/.env.production.local`
(`chmod 600`) und gehören nicht ins Repo. Ändern heißt: Datei anpassen, dann
`make deploy` — der Rebuild ist Pflicht, weil `VITE_*` beim Build eingesetzt wird.

```bash
ssh elmarhepp 'cat >/var/www/finanzen/frontend/.env.production.local <<EOF
VITE_LEGAL_NAME="<name>"
VITE_LEGAL_EMAIL="<email>"
VITE_LEGAL_ADDRESS_LINE_1="<strasse hausnummer>"
VITE_LEGAL_ADDRESS_LINE_2="<plz ort>"
VITE_LEGAL_COUNTRY="Deutschland"
VITE_LEGAL_CONTENT_RESPONSIBLE="<name>"
EOF'
```

`VITE_API_BASE_URL` steht bereits in `frontend/.env.production` und gehört
deshalb nicht in diese Datei — Vite mischt beide, doppelte Pflege führt nur zu
widersprüchlichen Ständen. Lokal übernimmt `frontend/.env.local` dieselbe Rolle,
Vorlage ist `frontend/.env.example`.

Welche Angaben rechtlich verpflichtend sind, steht in
[NEUE-APP.md, Abschnitt 3](../../platform/NEUE-APP.md#3-impressum-und-datenschutz).

---

## SQLite-Einstellungen

Die App bleibt bewusst bei SQLite: 20 MB, ein Schreiber, keine Nebenläufigkeit —
der Zweig aus dem [Entscheidungsbaum](../../platform/ARCHITEKTUR.md#3-entscheidungsbaum-wo-liegen-die-daten).
Zwei Einstellungen in `config/database.php` sorgen dafür, dass das auch unter
parallelen Requests von frankenphp trägt:

| Einstellung | Wert | Warum |
|---|---|---|
| `journal_mode` | `WAL` | Leser blockieren Schreiber nicht mehr — sonst sperrt ein CSV-Import die parallelen Requests aus |
| `busy_timeout` | `5000` | wartet 5 s auf die Sperre, statt sofort mit „database is locked" abzubrechen |
| `synchronous` | `NORMAL` | mit WAL der übliche Kompromiss; `FULL` kostet Schreibleistung ohne echten Gewinn |

Alle drei sind über `DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT` und `DB_SYNCHRONOUS`
überschreibbar. `journal_mode` ist eine Eigenschaft der Datei: einmal gesetzt,
bleibt WAL bestehen, auch für spätere Verbindungen.

---

## Regeln für diese App

- niemals echte CSV-Dateien deployen
- niemals `backend/.env.production` committen
- `-f docker-compose.prod.yml` ist bei jedem Compose-Kommando Pflicht
- vor jedem Deploy lokal `make test`
