#!/usr/bin/env bash
# Local WordPress site for developing this plugin, served by Laravel Herd:
#   http://foundfirst-wp.test  (admin / password)
# Safe to run again: each step skips what's already done.
#
# Env overrides: SITE_DIR, SITE_URL, DB_NAME, DB_USER, DB_PASS, DB_HOST
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE_DIR="${SITE_DIR:-$HOME/Herd/foundfirst-wp}"
SITE_URL="${SITE_URL:-http://foundfirst-wp.test}"
DB_NAME="${DB_NAME:-foundfirst_wp}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
WP="$HOME/.local/bin/wp"
PRO_ZIP="$ROOT/local/elementor-pro.zip"

step() { printf '\n==> %s\n' "$*"; }

step "MySQL (Homebrew mysql@8.4)"
if ! mysqladmin --host="$DB_HOST" --user="$DB_USER" ${DB_PASS:+--password="$DB_PASS"} ping >/dev/null 2>&1; then
	brew services start mysql@8.4
	for _ in $(seq 1 30); do
		mysqladmin --host="$DB_HOST" --user="$DB_USER" ${DB_PASS:+--password="$DB_PASS"} ping >/dev/null 2>&1 && break
		sleep 1
	done
fi
mysql --host="$DB_HOST" --user="$DB_USER" ${DB_PASS:+--password="$DB_PASS"} \
	-e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

step "WP-CLI"
if [[ ! -x "$WP" ]]; then
	mkdir -p "$(dirname "$WP")"
	curl -fsSL -o "$WP" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x "$WP"
fi
"$WP" --version

step "WordPress in $SITE_DIR"
mkdir -p "$SITE_DIR"
cd "$SITE_DIR"
[[ -f wp-load.php ]] || "$WP" core download --quiet
if [[ ! -f wp-config.php ]]; then
	"$WP" config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --quiet
	"$WP" config set WP_DEBUG true --raw
	"$WP" config set WP_DEBUG_LOG true --raw
	"$WP" config set WP_DEBUG_DISPLAY false --raw
	"$WP" config set WP_ENVIRONMENT_TYPE local
fi
if ! "$WP" core is-installed 2>/dev/null; then
	"$WP" core install --url="$SITE_URL" --title="FoundFirst (local)" \
		--admin_user=admin --admin_password=password --admin_email=admin@foundfirst-wp.test --skip-email
fi

step "Elementor"
"$WP" plugin is-installed elementor || "$WP" plugin install elementor
"$WP" plugin activate elementor

step "Elementor Pro"
if "$WP" plugin is-installed elementor-pro; then
	"$WP" plugin activate elementor-pro
elif [[ -f "$PRO_ZIP" ]]; then
	"$WP" plugin install "$PRO_ZIP" --activate
else
	echo "Skipped: put your Elementor Pro zip at $PRO_ZIP and run this again."
fi

step "FoundFirst Growth Hub (symlinked from $ROOT)"
(cd "$ROOT" && composer install --no-interaction --quiet)
LINK="$SITE_DIR/wp-content/plugins/foundfirst-growth-hub"
[[ -L "$LINK" ]] || ln -s "$ROOT" "$LINK"
"$WP" plugin activate foundfirst-growth-hub

printf '\nDone: %s/wp-admin (admin / password)\n' "$SITE_URL"
printf 'Settings → Growth Hub: use http://growth-hub.test and a key from your local Growth Hub.\n'
