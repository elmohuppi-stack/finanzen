# Deployment auf Hetzner

Diese Datei beschreibt den **konkreten, aktuell funktionierenden Produktions-Deploy** fuer `Finanzen` auf dem bestehenden Hetzner-Multi-App-Server.

## Live-Setup

- **SSH-Zugang:** `ssh elmarhepp`
- **Server-Pfad:** `/var/www/finanzen`
- **Frontend:** `https://finanzen.elmarhepp.de`
- **API:** `https://finanzen-api.elmarhepp.de`
- **Frontend-Port intern:** `3021`
- **API-Port intern:** `3022`
- **Nginx-Site:** `/etc/nginx/sites-available/finanzen.conf`

> Wichtig: Auf dem Server laufen bereits andere Apps. `Finanzen` darf nur seine eigenen Ports, Domains und Dateien verwenden.

## Relevante Dateien im Repo

- `docker-compose.prod.yml` – Produktions-Compose fuer Web + API
- `backend/Dockerfile` – Laravel-Container
- `frontend/Dockerfile` – Vue-Container
- `.env.example` – Root-Werte fuer Domain + Ports
- `backend/.env.production.example` – Vorlage fuer das Backend in Produktion
- `frontend/.env.example` – Vorlage fuer API-URL und rechtliche Frontend-Angaben
- `frontend/.env.production` bzw. besser `frontend/.env.production.local` – Produktionswerte fuer API und `VITE_LEGAL_*`

---

## Einmaliges Setup auf dem Server

Der initiale Rollout ist bereits erledigt. Fuer neue Server oder Neuaufbau gilt dieser Ablauf:

1. DNS muss auf den Hetzner-Server zeigen
   - `finanzen.elmarhepp.de`
   - `finanzen-api.elmarhepp.de`
2. Zielverzeichnis anlegen:

```bash
ssh elmarhepp 'mkdir -p /var/www/finanzen'
```

3. Root-Env auf dem Server anlegen:

```bash
cat >/var/www/finanzen/.env <<'EOF'
APP_DOMAIN=finanzen.elmarhepp.de
API_DOMAIN=finanzen-api.elmarhepp.de
WEB_PORT=3021
API_PORT=3022
EOF
```

4. Backend-Env auf dem Server anlegen (aus `backend/.env.production.example` ableiten)
5. Nginx-Site einrichten und Certbot ausfuehren

---

## Standard-Update-Workflow

Das ist der empfohlene Ablauf fuer **spaetere Updates**.

### 1. Lokal pruefen

```bash
cd /Users/elmarhepp/workspace/finanzen/frontend && npm run build
cd /Users/elmarhepp/workspace/finanzen/backend && php artisan test
```

### 1b. Rechtliche Frontend-Werte fuer Produktion setzen

Vor einem oeffentlichen Relaunch sollten die Anbieterangaben fuer `Impressum` und `Datenschutz` in einer nicht versionierten Produktionsdatei oder direkt in der Build-Umgebung gesetzt werden, z. B.:

```bash
cat >/var/www/finanzen/frontend/.env.production.local <<'EOF'
VITE_API_BASE_URL=https://finanzen-api.elmarhepp.de
VITE_LEGAL_NAME="<name>"
VITE_LEGAL_EMAIL="<email>"
VITE_LEGAL_ADDRESS_LINE_1="<strasse hausnummer>"
VITE_LEGAL_ADDRESS_LINE_2="<plz ort>"
VITE_LEGAL_COUNTRY="Deutschland"
VITE_LEGAL_CONTENT_RESPONSIBLE="<name>"
EOF
```

### 2. Aenderungen committen

```bash
cd /Users/elmarhepp/workspace/finanzen
git status
git add .
git commit -m "<beschreibung>"
```

### 3. Code auf den Server synchronisieren

```bash
rsync -az --delete \
  --exclude '.git/' \
  --exclude 'csv/' \
  --exclude 'backend/vendor/' \
  --exclude 'backend/.env' \
  --exclude 'backend/.env.production' \
  --exclude 'backend/database/database.sqlite' \
  --exclude 'backend/storage/logs/' \
  --exclude 'backend/storage/framework/' \
  --exclude 'frontend/node_modules/' \
  --exclude 'frontend/dist/' \
  /Users/elmarhepp/workspace/finanzen/ \
  elmarhepp:/var/www/finanzen/
```

### 4. Container neu bauen und starten

```bash
ssh elmarhepp '
  cd /var/www/finanzen && \
  docker compose --env-file .env -f docker-compose.prod.yml up -d --build
'
```

### 5. Ergebnis verifizieren

```bash
ssh elmarhepp '
  cd /var/www/finanzen && \
  docker compose --env-file .env -f docker-compose.prod.yml ps
'

curl -I https://finanzen.elmarhepp.de/
curl -I https://finanzen.elmarhepp.de/impressum
curl -I https://finanzen.elmarhepp.de/datenschutz
curl -i https://finanzen-api.elmarhepp.de/api/health
```

---

## Copy/Paste fuer schnelle Deployments

Wenn nur ein normales Update ausgerollt werden soll, reicht meistens genau das:

```bash
cd /Users/elmarhepp/workspace/finanzen/frontend && npm run build && \
cd ../backend && php artisan test && \
rsync -az --delete \
  --exclude '.git/' \
  --exclude 'csv/' \
  --exclude 'backend/vendor/' \
  --exclude 'backend/.env' \
  --exclude 'backend/.env.production' \
  --exclude 'backend/database/database.sqlite' \
  --exclude 'backend/storage/logs/' \
  --exclude 'backend/storage/framework/' \
  --exclude 'frontend/node_modules/' \
  --exclude 'frontend/dist/' \
  /Users/elmarhepp/workspace/finanzen/ \
  elmarhepp:/var/www/finanzen/ && \
ssh elmarhepp 'cd /var/www/finanzen && docker compose --env-file .env -f docker-compose.prod.yml up -d --build' && \
curl -I https://finanzen.elmarhepp.de/ && \
curl -i https://finanzen-api.elmarhepp.de/api/health
```

---

## Logs und Diagnose

### Container-Status

```bash
ssh elmarhepp 'cd /var/www/finanzen && docker compose --env-file .env -f docker-compose.prod.yml ps'
```

### API-Logs

```bash
ssh elmarhepp 'cd /var/www/finanzen && docker compose --env-file .env -f docker-compose.prod.yml logs -f api'
```

### Frontend-Logs

```bash
ssh elmarhepp 'cd /var/www/finanzen && docker compose --env-file .env -f docker-compose.prod.yml logs -f web'
```

### Nginx pruefen

```bash
ssh elmarhepp 'nginx -t && systemctl reload nginx'
```

---

## Rollback

Falls ein Update schiefgeht:

1. lokal auf den letzten funktionierenden Commit zurueckgehen
2. denselben `rsync`- und `docker compose up -d --build`-Ablauf erneut ausfuehren

Beispiel:

```bash
cd /Users/elmarhepp/workspace/finanzen
git checkout <funktionierender-commit>
# danach erneut deployen
```

> Da das Server-Verzeichnis aktuell per `rsync` gepflegt wird, ist der einfachste Rollback ebenfalls ein erneuter Sync eines bekannten guten lokalen Commits.

---

## Sicherheits- und Serverregeln

- niemals echte CSV-Dateien deployen
- niemals `backend/.env.production` committen
- keine Ports anderer Apps wiederverwenden
- keine bestehenden Nginx-Dateien anderer Projekte ueberschreiben
- vor jedem Deploy lokal verifizieren

Aktuell belegte Nachbar-Apps auf dem Server:

- `benzin-preise` → `3001/3002`
- `elmo-scanner` → `3011/3012`
- `finanzen` → `3021/3022`
