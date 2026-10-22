#!/usr/bin/env bash
# asrms-update: update the live ASRMS site from GitHub main in one command (#108).
#
#   sudo asrms-update             update to origin/main
#   sudo asrms-update --rebuild   run every step even if the code is already
#                                 current (e.g. right after a manual git pull)
#
# Backs up the database first, puts the API in maintenance mode, fast-forwards
# /var/www/asrms to origin/main, rebuilds the backend and the frontend, fixes
# ownership, reloads PHP-FPM and checks the site answers. It stops at the first
# error and prints the exact rollback steps; it never rolls back by itself.
# It never creates, edits, prints or copies any .env file.
# Install once with deploy/install-asrms-update.sh. Team steps: deploy/UPDATE.md.

set -Eeuo pipefail

APP_DIR=/var/www/asrms
BACKEND="$APP_DIR/backend"
FRONTEND="$APP_DIR/frontend"
BACKUP_DIR=/var/backups/asrms
LOG=/var/log/asrms-update.log
SITE=https://asrms-tmcc.duckdns.org
PHP_FPM=php8.2-fpm
COMPOSER=/usr/local/bin/composer

REBUILD=0
case "${1:-}" in
    "") ;;
    --rebuild) REBUILD=1 ;;
    *) echo "Usage: sudo asrms-update [--rebuild]" >&2; exit 2 ;;
esac

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo asrms-update" >&2
    exit 1
fi

# Everything goes to the screen and, timestamped, to the log.
exec > >(tee >(while IFS= read -r line; do printf '%s %s\n' "$(date '+%F %T')" "$line"; done >> "$LOG")) 2>&1

OLD=""
ZIP=""
SITE_DOWN=0
CODE_CHANGED=0
MIGRATIONS_STARTED=0

step() { printf '\n==> %s\n' "$*"; }

on_error() {
    local status=$? line=$1 command=$2
    trap - ERR
    set +e
    echo
    echo "!! FAILED (exit $status) at line $line: $command"
    if [[ $SITE_DOWN -eq 1 ]]; then
        echo "!! Bringing the site back up (php artisan up)."
        (cd "$BACKEND" && php artisan up)
    fi
    echo
    echo "Nothing was rolled back. The backup taken first is: ${ZIP:-(no backup was made)}"
    if [[ $CODE_CHANGED -eq 0 ]]; then
        echo "The code was not changed, so there is nothing to roll back. Fix the problem above and run sudo asrms-update again."
    elif [[ -n $OLD ]]; then
        echo "To roll back to the version that was running ($(git -C "$APP_DIR" rev-parse --short "$OLD" 2>/dev/null || echo "$OLD")):"
        echo
        echo "  git -C $APP_DIR reset --hard $OLD"
        if [[ $MIGRATIONS_STARTED -eq 1 && -n $ZIP ]]; then
            echo "  cd $BACKEND && sudo -u www-data php artisan asrms:restore $ZIP --force"
        else
            echo "  (no database restore needed: migrations did not run)"
        fi
        echo "  then re-run the backend and frontend build steps, chown, and reload $PHP_FPM:"
        echo "  cd $BACKEND && COMPOSER_ALLOW_SUPERUSER=1 $COMPOSER install --no-dev --optimize-autoloader --no-interaction"
        echo "  php artisan config:cache && php artisan route:cache && php artisan view:cache"
        echo "  cd $FRONTEND && npm ci && npm run build"
        echo "  chown -R www-data:www-data $APP_DIR && systemctl reload $PHP_FPM"
    fi
    echo
    echo "Full log: $LOG"
    exit "$status"
}
trap 'on_error $LINENO "$BASH_COMMAND"' ERR

# A check that failed: report it and stop through on_error, like any command.
fail() {
    echo "$*" >&2
    return 1
}

echo "asrms-update started by ${SUDO_USER:-root}"

# ── 1. Preflight ───────────────────────────────────────────────────────────
step "Checking the server copy"
git config --global --get-all safe.directory | grep -qx "$APP_DIR" \
    || git config --global --add safe.directory "$APP_DIR"

if [[ -n $(git -C "$APP_DIR" status --porcelain) ]]; then
    git -C "$APP_DIR" status --short
    echo "The server copy has local changes; nothing was updated."
    exit 1
fi

git -C "$APP_DIR" fetch origin main
OLD=$(git -C "$APP_DIR" rev-parse HEAD)
NEW=$(git -C "$APP_DIR" rev-parse origin/main)

if [[ $OLD == "$NEW" && $REBUILD -eq 0 ]]; then
    echo "Already up to date ($(git -C "$APP_DIR" rev-parse --short HEAD))."
    echo "If the code was pulled by hand and not built yet, run: sudo asrms-update --rebuild"
    exit 0
fi

if [[ $OLD == "$NEW" ]]; then
    echo "Code is current ($(git -C "$APP_DIR" rev-parse --short HEAD)); rebuilding anyway (--rebuild)."
else
    echo "Will deploy:"
    git -C "$APP_DIR" --no-pager log --oneline "HEAD..origin/main"
fi

# ── 2. Backup first ────────────────────────────────────────────────────────
step "Backing up the database (asrms:backup --label=pre-update)"
cd "$BACKEND"
backup_output=$(sudo -u www-data php artisan asrms:backup --label=pre-update)
echo "$backup_output"
ZIP=$(printf '%s\n' "$backup_output" | sed -n 's/^Backup completed: //p' | tail -n 1)
if [[ -z $ZIP || ! -f $ZIP ]]; then
    ZIP=$(find "$BACKUP_DIR" -type f -name '*.zip' -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -n 1 | cut -d' ' -f2-)
fi
[[ -n $ZIP && -f $ZIP ]] || fail "The backup did not produce a zip in $BACKUP_DIR; nothing was updated."
echo "Backup: $ZIP"

# ── 3. Maintenance mode ────────────────────────────────────────────────────
step "Maintenance mode on (the API answers 503 while updating)"
php artisan down --retry=60
SITE_DOWN=1

# ── 4. Code ────────────────────────────────────────────────────────────────
step "Updating the code to origin/main"
# With --rebuild on current code there is nothing to merge, but the rebuild
# itself (composer, migrations, build) can still need undoing.
CODE_CHANGED=1
git -C "$APP_DIR" merge --ff-only origin/main

# ── 5. Backend ─────────────────────────────────────────────────────────────
step "Backend: composer install"
cd "$BACKEND"
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction </dev/null

step "Backend: migrations"
MIGRATIONS_STARTED=1
php artisan migrate --force

step "Backend: caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ── 6. Frontend ────────────────────────────────────────────────────────────
step "Frontend: npm ci and build"
cd "$FRONTEND"
npm ci --no-audit --no-fund
npm run build

# ── 7. Ownership, PHP-FPM, back online ─────────────────────────────────────
step "Ownership, PHP-FPM reload, maintenance mode off"
chown -R www-data:www-data "$APP_DIR"
systemctl reload "$PHP_FPM"
cd "$BACKEND"
php artisan up
SITE_DOWN=0

# ── 8. Smoke check ─────────────────────────────────────────────────────────
step "Checking the site"
home=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE/" || true)
api=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H 'Accept: application/json' "$SITE/api/settings/current" || true)
echo "Home page: $home (want 200). API without a login: $api (want 401)."
[[ $home == 200 ]] || fail "The home page answered $home, not 200."
[[ $api == 401 ]] || fail "The API answered $api, not 401."

echo
echo "Update complete: $(git -C "$APP_DIR" rev-parse --short "$OLD") → $(git -C "$APP_DIR" rev-parse --short HEAD)"
