#!/usr/bin/env bash
# One-command PROMOFY dev runner.  Usage: scripts/dev.sh [up|setup|start|stop|status|demo|demo-off|all]  (up = fast resume)   (default: all)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; B="$ROOT/backend"; F="$ROOT/frontend"; RUN="$ROOT/.run"; mkdir -p "$RUN"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@promofy.test}"; ADMIN_PASSWORD="${ADMIN_PASSWORD:-Promofy-Admin-2026!}"

setup() {
  cd "$B"
  if ! php -m | grep -qix gd; then  # mpdf needs ext-gd; the Codespaces PHP image ships without it
    (sudo apt-get update -qq && sudo apt-get install -y -qq libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev \
      && sudo docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && sudo docker-php-ext-install -j2 gd) >/dev/null 2>&1 || echo "warn: could not install ext-gd (image optimisation/PDF images limited)"
  fi
  # reinstall whenever the lock file changed since the last install (new dependencies after a git pull)
  H=$(sha1sum composer.lock | cut -d' ' -f1); if [ ! -d vendor ] || [ "$(cat "$RUN/composer.hash" 2>/dev/null)" != "$H" ]; then composer install --no-interaction --prefer-dist || composer install --no-interaction --prefer-dist --ignore-platform-req=ext-gd; echo "$H" > "$RUN/composer.hash"; fi
  [ -f .env ] || cp .env.example .env
  grep -q '^APP_KEY=.\+' .env || php artisan key:generate --force
  mkdir -p database && touch database/database.sqlite
  # Codespaces / tunnels: allow the forwarded hostnames for Sanctum cookie auth
  if [ -n "${CODESPACE_NAME:-}" ]; then
    D="${CODESPACE_NAME}-5173.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
    sed -i '/^SANCTUM_STATEFUL_DOMAINS=/d;/^FRONTEND_URL=/d;/^APP_URL=/d' .env
    printf 'SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1,%s\nFRONTEND_URL=https://%s\nAPP_URL=https://%s\n' "$D" "$D" "${CODESPACE_NAME}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}" >> .env
    sed -i 's/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=true/' .env
  fi
  php artisan migrate --force --seed
  php artisan promofy:create-admin "$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" >/dev/null 2>&1 || true
  # first run only: tagged demo records so every dashboard card/chart has something to show (remove: scripts/dev.sh demo-off)
  if [ "${SEED_DEMO:-1}" = 1 ] && [ ! -f "$RUN/demo-seeded" ]; then php artisan promofy:seed-demo >/dev/null 2>&1 && touch "$RUN/demo-seeded" || true; fi
  cd "$F"; H=$(sha1sum package-lock.json | cut -d' ' -f1); if [ ! -d node_modules ] || [ "$(cat "$RUN/npm.hash" 2>/dev/null)" != "$H" ]; then npm ci --no-audit --no-fund && echo "$H" > "$RUN/npm.hash"; fi
  echo "setup done — admin: $ADMIN_EMAIL / $ADMIN_PASSWORD (change it)"
}

start() {
  stop >/dev/null 2>&1 || true
  cd "$B"; setsid nohup bash -c 'while true; do PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=8000; sleep 2; done' >"$RUN/backend.log" 2>&1 < /dev/null & echo $! >"$RUN/backend.pid"
  cd "$F"; setsid nohup bash -c 'while true; do npx vite --host 0.0.0.0 --port 5173 --strictPort; sleep 2; done' >"$RUN/frontend.log" 2>&1 < /dev/null & echo $! >"$RUN/frontend.pid"
  for i in $(seq 1 40); do curl -fs localhost:8000/up >/dev/null 2>&1 && curl -fs localhost:5173 >/dev/null 2>&1 && break; sleep 1; done
  status
}

stop() {
  for n in backend frontend; do [ -f "$RUN/$n.pid" ] && kill -- -"$(cat "$RUN/$n.pid")" 2>/dev/null || true; rm -f "$RUN/$n.pid"; done
  pkill -f "artisan serve" 2>/dev/null || true; pkill -f "vite --host" 2>/dev/null || true
}

# Fast path for every Codespace resume: no reinstall when deps exist, just migrate + start (~10 s).
up() {
  if [ -d "$B/vendor" ] && [ -d "$F/node_modules" ] && [ -f "$B/.env" ] && [ "$(cat "$RUN/npm.hash" 2>/dev/null)" = "$(sha1sum "$F/package-lock.json" | cut -d' ' -f1)" ] && [ "$(cat "$RUN/composer.hash" 2>/dev/null)" = "$(sha1sum "$B/composer.lock" | cut -d' ' -f1)" ]; then (cd "$B" && php artisan migrate --force >/dev/null 2>&1 || true); else setup; fi
  start
}

status() {
  printf 'API  :8000/up  -> %s\n' "$(curl -s -o /dev/null -w '%{http_code}' localhost:8000/up || true)"
  printf 'Admin:5173     -> %s\n' "$(curl -s -o /dev/null -w '%{http_code}' localhost:5173 || true)"
  [ -n "${CODESPACE_NAME:-}" ] && echo "Open: https://${CODESPACE_NAME}-5173.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}/admin"
  [ -z "${CODESPACE_NAME:-}" ] && echo "Open: http://localhost:5173/admin"
  true
}

case "${1:-all}" in up) up;; demo) (cd "$B" && php artisan promofy:seed-demo && touch "$RUN/demo-seeded");; demo-off) (cd "$B" && php artisan promofy:seed-demo --purge && rm -f "$RUN/demo-seeded");; setup) setup;; start) start;; stop) stop;; status) status;; all) setup; start;; *) echo "usage: $0 [up|setup|start|stop|status|demo|demo-off|all]"; exit 1;; esac
