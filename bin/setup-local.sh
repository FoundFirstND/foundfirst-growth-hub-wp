#!/usr/bin/env bash
# Local WordPress site for developing this plugin, served by Laravel Herd:
#   http://foundfirst-wp.test  (admin / password)
# Safe to run again: each step skips what's already done.
#
# Env overrides: SITE_DIR, SITE_URL, DB_NAME, DB_USER, DB_PASS, DB_HOST, HERD_CA
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE_DIR="${SITE_DIR:-$HOME/Herd/foundfirst-wp}"
SITE_URL="${SITE_URL:-http://foundfirst-wp.test}"
DB_NAME="${DB_NAME:-foundfirst_wp}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
WP_PHAR="$HOME/.local/bin/wp"
PRO_ZIP="$ROOT/local/elementor-pro.zip"
HERD_CA="${HERD_CA:-$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem}"

step() { printf '\n==> %s\n' "$*"; }

# WP-CLI nightly: the 2.x stable release floods PHP 8.5 with deprecation notices. Unpacking
# WordPress also needs more than PHP's default 128M.
wp() { php -d memory_limit=1G "$WP_PHAR" "$@"; }

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
if [[ ! -x "$WP_PHAR" ]]; then
	mkdir -p "$(dirname "$WP_PHAR")"
	curl -fsSL -o "$WP_PHAR" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli-nightly.phar
	chmod +x "$WP_PHAR"
fi
wp --version

step "WordPress in $SITE_DIR"
mkdir -p "$SITE_DIR"
cd "$SITE_DIR"
[[ -f wp-load.php ]] || wp core download --quiet
if [[ ! -f wp-config.php ]]; then
	wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --quiet
	wp config set WP_DEBUG true --raw
	wp config set WP_DEBUG_LOG true --raw
	wp config set WP_DEBUG_DISPLAY false --raw
	wp config set WP_ENVIRONMENT_TYPE local
fi
if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$SITE_URL" --title="FoundFirst (local)" \
		--admin_user=admin --admin_password=password --admin_email=admin@foundfirst-wp.test --skip-email
fi

step "Elementor"
wp plugin is-installed elementor || wp plugin install elementor
wp plugin activate elementor

step "Elementor Pro"
if wp plugin is-installed elementor-pro; then
	wp plugin activate elementor-pro
elif [[ -f "$PRO_ZIP" ]]; then
	wp plugin install "$PRO_ZIP" --activate
else
	echo "Skipped: put your Elementor Pro zip at $PRO_ZIP and run this again."
fi

step "FoundFirst Growth Hub (symlinked from $ROOT)"
(cd "$ROOT" && composer install --no-interaction --quiet)
LINK="$SITE_DIR/wp-content/plugins/foundfirst-growth-hub"
[[ -L "$LINK" ]] || ln -s "$ROOT" "$LINK"
wp plugin activate foundfirst-growth-hub

step "Trust Herd's certificate for https://growth-hub.test (local only)"
# WordPress checks HTTPS certificates against its own CA bundle, which doesn't include Herd's
# local CA. This mu-plugin adds Herd's CA for growth-hub.test only. SSL verification stays on.
MU_DIR="$SITE_DIR/wp-content/mu-plugins"
if [[ -f "$HERD_CA" ]]; then
	mkdir -p "$MU_DIR/ffgh-local"
	cat "$SITE_DIR/wp-includes/certificates/ca-bundle.crt" "$HERD_CA" > "$MU_DIR/ffgh-local/ca-bundle-with-herd.crt"
	cat > "$MU_DIR/ffgh-local-ca.php" <<'PHP'
<?php
/**
 * Local dev only: verify the local Growth Hub (growth-hub.test) against WordPress's CA bundle
 * plus Herd's local CA. SSL verification stays on. Written by bin/setup-local.sh.
 */
add_filter(
	'http_request_args',
	function ( $args, $url ) {
		if ( 'growth-hub.test' === wp_parse_url( $url, PHP_URL_HOST ) ) {
			$args['sslcertificates'] = __DIR__ . '/ffgh-local/ca-bundle-with-herd.crt';
		}
		return $args;
	},
	10,
	2
);
PHP
else
	echo "Skipped: Herd's CA not found at $HERD_CA (set HERD_CA). Calls to https://growth-hub.test will fail with cURL error 60."
fi

printf '\nDone: %s/wp-admin (admin / password)\n' "$SITE_URL"
printf 'Settings → Growth Hub: use https://growth-hub.test and a key from your local Growth Hub.\n'
