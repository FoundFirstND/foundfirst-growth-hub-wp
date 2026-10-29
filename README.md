# FoundFirst Growth Hub (WordPress plugin)

Connects a client's WordPress site to [FoundFirst Growth Hub](https://app.foundfirst.us):

- **Leads**: an Elementor Pro form action, **Send to Growth Hub**, delivers form submissions to the business's Growth Hub inbox. If Growth Hub can't be reached, the lead is queued and retried, so no lead is lost.
- **Promo banner**: `[growthhub_promo]` and an Elementor widget show the business's current promo, and nothing when there isn't one.
- **Updates**: the plugin updates itself from this repo's GitHub releases through the normal WordPress update screen.

The API contract is in [API.md](API.md).

## Requirements

- PHP 8.1+, WordPress 6.4+
- Elementor Pro for the form action. Free Elementor is enough for the promo widget, and the shortcode works without Elementor.

## Install on a site

1. Download `foundfirst-growth-hub.zip` from the [latest release](https://github.com/FoundFirstND/foundfirst-growth-hub-wp/releases/latest). Don't use GitHub's "Source code" zip: it has no `vendor/` folder.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, then **Install Now** and **Activate**.
3. In Growth Hub, open **Businesses → (business) → Website connections → New connection** and copy the key. It's shown only once.
4. In WordPress, go to **Settings → Growth Hub**, paste the key, **Save settings**, then click **Test connection**.

### Send form leads

1. Edit the form in Elementor. Under **Actions After Submit**, add **Send to Growth Hub**. Keep **Email** too, so the owner still gets the usual email.
2. In the new **Growth Hub** section, enter the field ID of each field. You find it under each form field's **Advanced** tab (the defaults are `name`, `email`, `phone`, `message`, `sms_consent`). Leave a box blank if the form doesn't have that field. Each lead needs an email or a phone.
3. **SMS consent**: map an *Acceptance* (or single *Checkbox*) field. Consent is only sent when the visitor ticks it, and the exact wording they saw (label + checkbox text) goes along as `consent_text`. A ticked box with no wording is sent as *no consent*, and a warning shows on the settings page.
4. **Send all other fields as extra fields** (on by default) sends every other field as `Label: value`.

Each lead also carries the form name, the page URL, the visitor's IP, the submit time and a unique idempotency key. All of it is sent from the server: the site key never reaches the browser.

**Visitor IP behind Cloudflare or a proxy:** by default the plugin uses `REMOTE_ADDR`, because forwarded headers can be faked. On a site behind a trusted proxy, add a filter in a small mu-plugin:

```php
add_filter( 'ffgh_visitor_ip', fn ( $ip ) => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip );
```

### How leads are never lost

1. On submit, the lead is **saved to a queue table first**, then sent right away (5 second timeout).
2. Delivered (201/200): the lead is removed from the queue.
3. Timeout, connection error, 429 or 5xx: it's retried by WP-Cron after 1, 5, 15 and 60 minutes, then hourly, for up to 24 hours. Every retry reuses the same body and idempotency key, so Growth Hub never creates duplicates. Retries respect `Retry-After`.
4. 401 (bad or revoked key), 422 (invalid lead) and other 4xx: never retried (API.md says repeating can't succeed). They're marked failed and shown on the settings page.
5. If leads are failing, or one has been waiting more than 15 minutes, the site's admin email gets **at most one email a day**.
6. After fixing the key, **Retry failed leads now** on the settings page re-sends them. Saving a new key does this automatically. Leads rejected as invalid aren't retried. Failed leads are deleted after 30 days.

The visitor always sees the form's normal success message, and the form's other actions (email, etc.) always run.

**Low-traffic sites:** WP-Cron only runs when someone visits the site, so retries can run late. On hosts that allow it, add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php` and a real cron job every 5 minutes:

```
*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

### Promo banner

- Shortcode: `[growthhub_promo]`, or `[growthhub_promo class="my-class"]` to add classes.
- Elementor: drag in the **Growth Hub Promo** widget. In the editor it shows a placeholder when there's no promo, and visitors see nothing.
- The promo is fetched on the server and cached for 5 minutes. It's hidden after its `expires_at`. Page-cache plugins also cache the banner for their own cache lifetime.
- Styling inherits the theme. You can override these classes:

```
.ffgh-promo                 wrapper (.ffgh-promo--has-image when there's a photo)
  .ffgh-promo__link         <a>, when the promo has a link
  .ffgh-promo__inner        <div>, when it doesn't
    .ffgh-promo__image      <img>
    .ffgh-promo__text       <span>
```

### Updates

The plugin uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker). WordPress checks this repo's latest GitHub release twice a day and shows the normal "update available" notice. Only the `foundfirst-growth-hub.zip` attached to a release is installed. Git checkouts (the local symlinked copy) never update themselves.

### Uninstall

Deactivating stops the retry cron but keeps the settings and queued leads. **Deleting** the plugin removes its settings, cached promo, cron events and the queue table.

## Local development

Uses Laravel Herd, Homebrew `mysql@8.4` and WP-CLI (the setup script installs WP-CLI into `~/.local/bin`).

```bash
# Optional: put your Elementor Pro zip here first (local/ is git-ignored)
cp ~/Downloads/elementor-pro-*.zip local/elementor-pro.zip

bin/setup-local.sh
```

This starts MySQL, creates the `foundfirst_wp` database and installs WordPress in `~/Herd/foundfirst-wp`, served at **http://foundfirst-wp.test** (log in as `admin` / `password`). It also installs Elementor (and Elementor Pro from `local/elementor-pro.zip`) and **symlinks this repo** into `wp-content/plugins/foundfirst-growth-hub`, so code changes show up immediately. Activate your Elementor Pro licence in WP admin.

Point **Settings → Growth Hub** at `http://growth-hub.test` with a key from your local Growth Hub.

> **Never click "Delete" on the plugin in the local site's Plugins screen.** It's a symlink to this repo, and WordPress may delete the repo's files through it. Deactivate it instead. To test uninstall, run `uninstall.php` with WP-CLI (`wp eval 'define("WP_UNINSTALL_PLUGIN", 1); include WP_PLUGIN_DIR . "/foundfirst-growth-hub/uninstall.php";'`), or use the release zip on a separate site.

Useful commands (run in `~/Herd/foundfirst-wp`):

```bash
~/.local/bin/wp cron event list                       # see ffgh_process_queue / ffgh_queue_sweep
~/.local/bin/wp cron event run ffgh_process_queue     # run retries now
~/.local/bin/wp db query "SELECT id, status, attempts, last_http_code, last_error FROM wp_ffgh_lead_queue"
tail -f wp-content/debug.log
```

### Tests and lint

```bash
composer test   # PHPUnit + Brain Monkey: sending, queue, retries, payload, consent, promo (no WordPress needed)
composer lint   # WordPress coding standards + PHP 8.1 compatibility
```

## Release checklist

1. Bump the version in **both** places in `foundfirst-growth-hub.php`: the `Version:` header and `FFGH_VERSION`. Use semver: patch for fixes, minor for features.
2. If the queue table changed, bump `Plugin::DB_VERSION`.
3. Add a `CHANGELOG.md` entry.
4. Run `composer test && composer lint`.
5. Commit: `Release vX.Y.Z`.
6. `bin/build-zip.sh` builds `dist/foundfirst-growth-hub.zip` from the committed code.
7. **Install the zip on foundfirst.us first** (Plugins → Add New → Upload, replace current). Submit a real form, check it arrives in Growth Hub, check the email still arrives, check the settings page status and the promo.
8. Tag and push: `git tag vX.Y.Z && git push origin main --tags`.
9. Create the GitHub release from the tag, paste the changelog entry and **attach `dist/foundfirst-growth-hub.zip`** (keep that exact file name):
   `gh release create vX.Y.Z dist/foundfirst-growth-hub.zip --title vX.Y.Z --notes-file <notes>`
10. On a test site, **Dashboard → Updates → Check again** should show the update notice. Then roll out to client sites (or let auto-updates do it).
