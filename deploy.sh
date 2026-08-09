#!/usr/bin/env bash
# ============================================================
# Deploy Finanzen auf helsinki-80gb
#
#   ./deploy.sh              lokal prüfen, pullen, bauen, migrieren, verifizieren
#   ./deploy.sh backup       SQLite-Datei aus dem Volume sichern
#   ./deploy.sh status       Container und Endpunkte
#   ./deploy.sh logs [api|web]
#   ./deploy.sh migrate      nur Migrationen nachziehen
#   ./deploy.sh rollback <commit>
#
# Serverweite Regeln: ~/workspace/optimize-hetzner
# App-Details:        docs/deployment.md
# ============================================================
set -euo pipefail

SERVER="${DEPLOY_HOST:-elmarhepp}"
DEPLOY_PATH="/var/www/finanzen"
BRANCH="${DEPLOY_BRANCH:-main}"
REPO_URL="https://github.com/elmohuppi-stack/finanzen.git"
FRONTEND_URL="https://finanzen.elmarhepp.de"
API_URL="https://finanzen-api.elmarhepp.de"
KEEP_BACKUPS=10

# Ohne -f startet Compose die Dev-Variante mit eigenem Postgres und Mailpit.
COMPOSE="docker compose -f docker-compose.prod.yml"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info() { echo -e "${YELLOW}▸ $*${NC}"; }
ok()   { echo -e "${GREEN}✔ $*${NC}"; }
fail() { echo -e "${RED}✖ $*${NC}" >&2; exit 1; }

cd "$(dirname "$0")"

remote() { ssh "$SERVER" "cd $DEPLOY_PATH && $*"; }

require_checkout() {
    if ! remote "git rev-parse --git-dir >/dev/null 2>&1"; then
        echo
        fail "$DEPLOY_PATH ist kein Git-Checkout. Einmalig umstellen:

  ssh $SERVER '
    cd $DEPLOY_PATH &&
    git init -b $BRANCH &&
    git remote add origin $REPO_URL &&
    git fetch origin $BRANCH &&
    git reset --hard origin/$BRANCH &&
    git status --short
  '

Details in docs/deployment.md."
    fi
}

backup_database() {
    if [ "${SKIP_BACKUP:-0}" = "1" ]; then
        info "Sicherung übersprungen (SKIP_BACKUP=1)"
        return 0
    fi

    info "Datenbank sichern"
    remote "$COMPOSE exec -T api php artisan db:backup --keep=$KEEP_BACKUPS" \
        || fail "Sicherung fehlgeschlagen — abgebrochen, bevor etwas verändert wird.
Läuft der Container gar nicht, hilft SKIP_BACKUP=1 ./deploy.sh — dann aber ohne Netz."
    ok "Sicherung liegt im Volume unter storage/database/backups (die letzten $KEEP_BACKUPS bleiben)"
}

check_endpoints() {
    local code_api code_web
    code_api="$(curl -s -o /dev/null -w '%{http_code}' "$API_URL/api/health" || true)"
    code_web="$(curl -s -o /dev/null -w '%{http_code}' "$FRONTEND_URL/" || true)"

    printf '   %-40s %s\n' "$API_URL/api/health" "$code_api"
    printf '   %-40s %s\n' "$FRONTEND_URL/" "$code_web"

    [ "$code_api" = "200" ] && [ "$code_web" = "200" ]
}

action_deploy() {
    info "Lokalen Stand prüfen"
    [ -z "$(git status --porcelain)" ] || fail "Working Tree ist nicht sauber. Erst committen."

    git fetch --quiet origin "$BRANCH"
    [ -z "$(git rev-list "origin/$BRANCH..HEAD")" ] \
        || fail "Lokale Commits sind nicht gepusht — der Server würde anderen Code bauen als du getestet hast."
    ok "$(git rev-parse --short HEAD) ist auf origin/$BRANCH"

    info "Tests und Build"
    local testLog
    testLog="$(mktemp)"
    if ! make test >"$testLog" 2>&1; then
        tail -30 "$testLog" >&2
        rm -f "$testLog"
        fail "make test ist rot. Nichts deployt."
    fi
    rm -f "$testLog"
    ok "Tests grün"

    require_checkout
    backup_database

    info "Auf dem Server ziehen und bauen"
    remote "git fetch origin $BRANCH && git checkout $BRANCH && git pull --ff-only origin $BRANCH"
    remote "$COMPOSE up -d --build"

    info "Migrationen"
    remote "$COMPOSE exec -T api php artisan migrate --force"

    info "Endpunkte prüfen"
    for attempt in 1 2 3 4 5; do
        if check_endpoints; then
            ok "Deploy abgeschlossen: $(git rev-parse --short HEAD) läuft"
            return 0
        fi
        if [ "$attempt" -lt 5 ]; then
            sleep 5
        fi
    done

    fail "Endpunkte antworten nicht mit 200. Logs: ./deploy.sh logs api"
}

action_status() {
    info "Container"
    remote "$COMPOSE ps"
    info "Endpunkte"
    check_endpoints || true
    info "Stand auf dem Server"
    remote "git log --oneline -1"
}

action_logs() {
    remote "$COMPOSE logs -f --tail=100 ${1:-}"
}

action_migrate() {
    require_checkout
    backup_database
    remote "$COMPOSE exec -T api php artisan migrate --force"
    ok "Migrationen durch"
}

action_rollback() {
    local target="${1:-}"
    [ -n "$target" ] || fail "Ziel fehlt: ./deploy.sh rollback <commit>"

    require_checkout
    backup_database

    info "Auf $target zurückgehen"
    remote "git fetch origin $BRANCH && git checkout $target"
    remote "$COMPOSE up -d --build"

    check_endpoints || true
    echo
    info "Der Server steht jetzt auf einem losgelösten Stand."
    info "Zurück auf die Spur: ./deploy.sh (baut wieder $BRANCH)."
}

case "${1:-deploy}" in
    deploy)   action_deploy ;;
    backup)   require_checkout; backup_database ;;
    status)   action_status ;;
    logs)     action_logs "${2:-}" ;;
    migrate)  action_migrate ;;
    rollback) action_rollback "${2:-}" ;;
    help|-h|--help)
        sed -n '3,13p' "$0" | sed 's/^# \{0,1\}//'
        ;;
    *) fail "Unbekannte Aktion: $1 (siehe ./deploy.sh help)" ;;
esac
