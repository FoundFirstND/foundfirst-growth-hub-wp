# FoundFirst Growth Hub — WordPress plugin

WordPress plugin (slug `foundfirst-growth-hub`) installed on FoundFirst client sites. It:

- sends Elementor Pro form leads to Growth Hub (`POST /api/v1/leads`) and never loses one (queue + WP-Cron retries),
- shows the Growth Hub promo banner (`GET /api/v1/promo`) via `[growthhub_promo]` and an Elementor widget,
- updates itself from this repo's GitHub releases (plugin-update-checker).

`API.md` is the contract with the Growth Hub app (https://app.foundfirst.us, locally http://growth-hub.test). It is the source of truth.

Requirements: PHP 8.1+, WordPress 6.4+. Elementor / Elementor Pro are optional (integrations only load when present).

## Layout

- `foundfirst-growth-hub.php` — plugin header, constants, requirement checks, bootstrap
- `uninstall.php` — removes options, transients, cron events and the queue table
- `src/` — PSR-4 `FoundFirst\GrowthHub\` (Api, Leads, Promo, Settings, Elementor, Updates)
- `assets/` — front-end CSS
- `tests/` — PHPUnit + Brain Monkey (no WordPress install needed)
- `bin/setup-local.sh` — local Herd WordPress site; `bin/build-zip.sh` — release zip

## Commands

- `composer install` — dev dependencies (needed for the symlinked local copy)
- `composer test` — PHPUnit
- `composer lint` — phpcs (WordPress-Extra + PHPCompatibilityWP for PHP 8.1+)
- `bin/build-zip.sh` — builds `dist/foundfirst-growth-hub.zip` (top folder `foundfirst-growth-hub/`, includes `vendor/`)
- Local site: http://foundfirst-wp.test (`~/Herd/foundfirst-wp`), WP-CLI at `~/.local/bin/wp`

## Rules

### Security
- Every admin page and handler: `current_user_can( 'manage_options' )` + nonce (`check_admin_referer`).
- Sanitize all input (`wp_unslash` then `sanitize_*`), escape all output late (`esc_html`, `esc_attr`, `esc_url`).
- All SQL through `$wpdb->prepare`.
- Every PHP file starts with `defined( 'ABSPATH' ) || exit;`.
- The site key is never printed in HTML, JS, notices, emails or logs. It's stored with autoload off.
- Growth Hub is only called from the server (`wp_remote_*`), never from browser JavaScript.

### Never break client sites
- No fatals: the main file checks PHP/WP versions and that `vendor/autoload.php` exists before loading anything.
- Anything that runs in a visitor request (form action, promo) is wrapped in `try/catch (\Throwable)` and fails silently.
- Never show a visitor an error because of Growth Hub. Never stop Elementor's other actions (e.g. email) from running.
- Short timeouts on visitor-facing calls.

### Test on foundfirst.us before client sites
Every release zip is installed and checked on foundfirst.us (form submit + promo) before it is rolled out to client sites.

### Keep API v1 compatibility
- Only send fields documented in `API.md`; respect its limits.
- Retry only timeouts/connection errors, 429 and 5xx, with the same body and `idempotency_key`. Never retry 401, 422 or other 4xx.
- If the API changes, update `API.md` first.

### Code
- PHP 8.1 syntax only (no 8.2+ features); WordPress 6.4 APIs. `composer lint` enforces it.
- Match the existing style: WordPress coding standards, tabs, `ffgh_` prefix for options/hooks/transients.

### Workflow
- Small, focused commits; run `composer test` and `composer lint` before committing.
- Work on a branch, not `main`.
- **Stop before pushing, tagging or publishing a release** — hand the commands to Tyler.
