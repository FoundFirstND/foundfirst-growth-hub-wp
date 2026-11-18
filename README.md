# FoundFirst Growth Hub (WordPress plugin)

Connects a client's WordPress site to [FoundFirst Growth Hub](https://app.foundfirst.us):

- **Leads**: an Elementor Pro form action, **Send to Growth Hub**, delivers form submissions to the business's Growth Hub inbox. If Growth Hub can't be reached, the lead is queued and retried, so no lead is lost.
- **Promo banner**: `[growthhub_promo]` and an Elementor widget show the business's current promo, and nothing when there isn't one.
- **Google reviews**: `[growthhub_reviews]` and an Elementor widget show the business's recent Google reviews with its overall rating, and nothing when it doesn't show reviews.
- **Google Analytics events**: `click_to_call` for phone-link clicks and `generate_lead` for successful Growth Hub form submissions, so Growth Hub Reports can show call clicks and website form leads. No personal data is sent.
- **Updates**: the plugin updates itself from this repo's GitHub releases through the normal WordPress update screen.

The API contract is in [API.md](API.md).

## Requirements

- PHP 8.1+, WordPress 6.4+
- Elementor Pro for the form action. Free Elementor is enough for the promo and reviews widgets, and the shortcode works without Elementor.

## Install on a site

1. Download `foundfirst-growth-hub.zip` from the [latest release](https://github.com/FoundFirstND/foundfirst-growth-hub-wp/releases/latest). Don't use GitHub's "Source code" zip: it has no `vendor/` folder.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, then **Install Now** and **Activate**.
3. In Growth Hub, open **Businesses → (business) → Website connections → New connection** and copy the key. It's shown only once.
4. In WordPress, go to **Settings → Growth Hub**, paste the key, **Save settings**, then click **Test connection**.

### Send form leads

1. Edit the form in Elementor. Under **Actions After Submit**, add **Send to Growth Hub**. Keep **Email** too, so the owner still gets the usual email.
2. In the new **Growth Hub** section, enter the field ID of each field. You find it under each form field's **Advanced** tab (the defaults are `name`, `email`, `phone`, `message`, `sms_consent`). Leave a box blank if the form doesn't have that field. Each lead needs an email or a phone.
3. **SMS consent**: map an *Acceptance* (or single *Checkbox*) field. Consent is only sent when the visitor ticks it, and the exact wording they saw (label + checkbox text) goes along as `consent_text`. A ticked box with no wording is sent as *no consent*, and a warning shows on the settings page.
4. **Text club (VIP) and age (21+) boxes** (optional, blank by default). Map them only on forms that offer the business's text club:
   - **Text club (VIP) consent field ID**: a separate *Acceptance* (or single *Checkbox*) field, **unticked by default**, below the SMS consent box. When ticked, the lead carries `marketing_consent: true` and the box's exact wording as `marketing_consent_text`, and Growth Hub joins the visitor to the VIP list (if the business has VIP texts turned on). The SMS consent box never counts as VIP consent.
   - **Age confirmation (21+) field ID**: for age-gated (21+) businesses (ForksOnTap, FargoOnTap), a second separate *Acceptance* field, unticked by default, e.g. "I confirm I'm 21 or older." When ticked, the lead carries `age_confirmed: true` and its exact wording as `age_confirmation_text`. Those businesses refuse a VIP join without it (the lead itself still arrives). Other businesses ignore it.
   - **The VIP box's wording** must name the business, state the message frequency, and say "Msg & data rates may apply. Reply STOP to opt out." with a link to the SMS terms (the privacy page's `#sms` section). For example: "Yes, text me happy hour deals from ForksOnTap. Up to 4 msgs/week. Msg & data rates may apply. Reply HELP for help, STOP to opt out. Terms: https://forksontap.com/privacy/#sms". **The frequency wording is only an example: it must match the business's approved A2P campaign.** ForksOnTap's is "Up to 4 msgs/week"; where a campaign was approved with "Msg frequency varies.", use that.
   - Each box is sent as ticked **only when the visitor ticked it in this submission**, with its label plus acceptance text (HTML removed, wording unchanged). Unticked or unmapped boxes are sent as `false` with no wording. Nothing is inferred from other fields: a mapped field that isn't an Acceptance or Checkbox field always counts as not ticked, and a warning shows on the settings page. A ticked box with no wording is also sent as `false`, with a warning.
   - The boxes aren't repeated as extra fields.
   - **A text club–only form is fine**: name, phone and the two boxes, with no email or message field. Leave the Email and Message field IDs as they are (a missing field is just skipped) or blank. Each lead still needs a phone or an email.
5. **Send all other fields as extra fields** (on by default) sends every other field as `Label: value`.

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
- **Photo**: off by default, so the banner shows only the text. Turn on **Settings → Growth Hub → Show photo on the banner** to show the post's photo next to the text. Override it per banner:
  - Shortcode: `[growthhub_promo image="yes"]` or `[growthhub_promo image="no"]`. Without `image`, the setting decides.
  - Elementor widget: the **Show photo** switch. A new widget starts with the switch matching the setting. Widgets saved with v0.1.0 follow the setting until someone changes the switch.
  - When the photo is off, no `<img>` is output at all, so it isn't downloaded either. The text always shows, with or without the photo.
- The promo is fetched on the server and cached for 5 minutes. It's hidden after its `expires_at`. Page-cache plugins also cache the banner for their own cache lifetime.
- Styling inherits the theme. You can override these classes:

```
.ffgh-promo                 wrapper (.ffgh-promo--has-image when there's a photo)
  .ffgh-promo__link         <a>, when the promo has a link
  .ffgh-promo__inner        <div>, when it doesn't
    .ffgh-promo__image      <img>
    .ffgh-promo__text       <span>
```

#### Video posts

When the owner posts a video with Text to Post, the banner shows a video player instead of a photo. This is **whatever "Show photo on the banner" says**, unless the shortcode has `image="no"` or the widget's **Show photo** switch was turned off on that widget.

- **The player** uses the video's thumbnail as its poster and has controls. It loads nothing until a visitor presses play (`preload="none"`), so it doesn't slow the page. It never autoplays with sound.
- **Autoplay muted and loop** is off by default. Turn it on with `[growthhub_promo autoplay="yes"]` or the widget's **Autoplay video (muted, looping)** switch. Visitors can still pause it.
- **Only the caption is a link.** Clicking the player plays the video instead of following the link.
- **Fallbacks:**
  - Once Growth Hub has cleaned up the video file, or if the video fails to play, the thumbnail shows instead. It's linked to the video on Facebook when there is one, with a play badge.
  - The swap after a failure is done by `assets/promo.js`, a tiny footer script. It loads only on pages with a video banner (or a Promo widget).
- **Extra classes:** `.ffgh-promo--video` (wrapper), `.ffgh-promo__video` (`<video>`), `.ffgh-promo__video-link` (the thumbnail link), `.ffgh-promo__play` (the badge) and `.ffgh-promo__text-link` (the linked caption).

### Google reviews

Shows the business's newest Google reviews from Growth Hub (`GET /reviews` in [API.md](API.md)). Nothing shows until FoundFirst turns on **Show reviews on website** for the business in Growth Hub.

- Shortcode: `[growthhub_reviews]`, with any of these (defaults first):
  - `layout="carousel|grid|list"`
  - `count="6"` (1 to 20)
  - `min_rating="5|4"` (5 stars only, or 4 and up; the business's own minimum in Growth Hub can be stricter)
  - `photos="yes|no"` (reviewers' Google photos; with `no` only their initials show and nothing loads from Google)
  - `dates="yes|no"`
  - `style="light|dark"`
  - `class="my-class"`
- Elementor: drag in the **Growth Hub Reviews** widget. It has the same options. In the editor it shows a placeholder when there's nothing to show, and visitors see nothing.
- **What it shows:** a heading that says exactly what's shown ("Recent 5-star reviews from Google" or "Recent 4+ star reviews from Google") with the Google logo, the business's real overall rating and total count on Google, and **See all reviews on Google** (the business on Google Maps). Each card has the name, photo or initials, stars, the text (long ones get **Read more**) and the date.
- **No review schema markup**, on purpose: Google ignores self-served review stars for local businesses.
- **Caching:** fetched on the server and cached for an hour (one cache per minimum rating, whatever the count). If Growth Hub can't be reached, the last good copy (up to 7 days old) is shown, or nothing. Never an error. When the business turns reviews off, they disappear within the hour (plus any page cache's own lifetime).
- **Dates** are printed as dates and turned into "2 weeks ago" in the visitor's browser, so a cached page never shows a stale age.
- **Without JavaScript** it still works: dates show as dates, long reviews show in full, and the carousel scrolls sideways.
- **Accessibility:** the carousel is a labelled region with Previous/Next buttons and ←/→/Home/End keys on the focused list. It never autoplays. Stars have text for screen readers. Photos have `alt=""` because the name is right next to them.
- `assets/reviews.css` and `assets/reviews.js` (no dependencies, footer) load only on pages that show reviews. Fonts inherit from the theme. Colors are custom properties you can override:

```
.ffgh-reviews                      section (.ffgh-reviews--carousel|grid|list, --light|dark)
  --ffgh-bg --ffgh-card --ffgh-text --ffgh-muted --ffgh-border --ffgh-star --ffgh-star-empty --ffgh-avatar --ffgh-gap
  .ffgh-reviews__header            heading, rating summary, link to Google
  .ffgh-reviews__list > .ffgh-review
    .ffgh-review__avatar           .ffgh-review__initials + img.ffgh-review__photo
    .ffgh-review__name, .ffgh-review__date, .ffgh-stars, .ffgh-review__text, .ffgh-review__more
```

### Google Analytics events

**Settings → Growth Hub → Send call clicks and form leads to Google Analytics** (on by default, also on sites that updated from 0.2.x). When it's off, the script isn't loaded at all.

| Event | When | Parameters |
|---|---|---|
| `click_to_call` | A visitor clicks any `tel:` link, including Elementor buttons, popups and links added after the page loaded | `link_location`: `header`, `footer`, `popup`, the `aria-label` of the closest labelled section, or `page`; `page_path` |
| `generate_lead` | An Elementor Pro form with **Send to Growth Hub** in its actions is submitted **successfully** (Elementor's `submit_success`) | `form_name`: the form's name, or `form-<element id>` if it has none; `page_path` |

- **Never sent:** the phone number, names, emails, messages, any other field value, or the query string (`page_path` is the path only).
- Forms without **Send to Growth Hub**, validation errors and failed submissions (including another action failing) send nothing. The server marks only Growth Hub forms' success responses, and the script listens only for `submit_success`.
- A repeat click on the same link, or a repeat success for the same form, within 1 second counts once.
- **How it's sent:** always `dataLayer.push({ event: 'click_to_call', ... })`. If the site has `gtag()` (Site Kit, a theme setting) **and no GTM container** (`GTM-...`), the event also goes through `gtag('event', ...)`. With GTM present, it only goes to the data layer, to avoid double counting, so GTM sites need the one-time setup below.
- No requests, cookies or storage of its own. Whatever consent mode the site's gtag/GTM uses applies.
- `assets/tracking.js` loads in the footer (deferred), is about 4 KB unminified (mostly comments) and has no dependencies. Elementor fires `submit_success` with jQuery, which doesn't dispatch a normal DOM event, so the form part uses `window.jQuery` when the page already has it (every Elementor Pro form page does). The script never adds jQuery to a page.
- Page-cache plugins keep serving pages without the script (or with it, after turning it off) until their cache is cleared.

#### GTM sites: one-time setup

1. **Variables → New → Data Layer Variable**, three times: `link_location`, `form_name`, `page_path` (Data Layer Variable Name exactly as written).
2. **Triggers → New → Custom Event**. Event name `click_to_call|generate_lead`, tick **Use regex matching**, fires on **All Custom Events**.
3. **Tags → New → Google Analytics: GA4 Event**. Measurement ID: the site's `G-...` ID (or the existing Google tag). Event name: `{{Event}}`. Event parameters: `link_location` → `{{link_location}}`, `form_name` → `{{form_name}}`, `page_path` → `{{page_path}}`. Triggering: the trigger from step 2.
4. **Submit / Publish** the container.
5. In GA4, go to **Admin → Data display → Events** (or **Key events**) and mark `click_to_call` and `generate_lead` as **key events**. Events show up after their first occurrence; you can also create the key events by name right away.

On gtag-only sites, only step 5 is needed.

### Updates

The plugin uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker). WordPress checks this repo's latest GitHub release twice a day and shows the normal "update available" notice. Only the `foundfirst-growth-hub.zip` attached to a release is installed. Git checkouts (the local symlinked copy) never update themselves.

### Uninstall

Deactivating stops the retry cron but keeps the settings and queued leads. **Deleting** the plugin removes its settings, cached promo and reviews, cron events and the queue table.

## Local development

Uses Laravel Herd, Homebrew `mysql@8.4` and WP-CLI (the setup script installs WP-CLI into `~/.local/bin`).

```bash
# Optional: put your Elementor Pro zip here first (local/ is git-ignored)
cp ~/Downloads/elementor-pro-*.zip local/elementor-pro.zip

bin/setup-local.sh
```

This starts MySQL, creates the `foundfirst_wp` database and installs WordPress in `~/Herd/foundfirst-wp`, served at **http://foundfirst-wp.test** (log in as `admin` / `password`). It also installs Elementor (and Elementor Pro from `local/elementor-pro.zip`) and **symlinks this repo** into `wp-content/plugins/foundfirst-growth-hub`, so code changes show up immediately. Activate your Elementor Pro licence in WP admin.

Point **Settings → Growth Hub** at `https://growth-hub.test` with a key from your local Growth Hub. Herd serves Growth Hub over HTTPS only (`http://` redirects, and the plugin doesn't follow redirects).

WordPress checks HTTPS certificates against its own CA bundle, which doesn't know Herd's local CA, so calls would fail with `cURL error 60`. The setup script therefore writes a local-only mu-plugin, `wp-content/mu-plugins/ffgh-local-ca.php`, that adds Herd's CA (from `~/Library/Application Support/Herd/config/valet/CA/`, override with `HERD_CA`) for `growth-hub.test` only. Certificate checks stay on. Run the script again after a WordPress update or if Herd's CA changes, to rebuild the bundle in `wp-content/mu-plugins/ffgh-local/`.

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
composer test      # PHPUnit + Brain Monkey: sending, queue, retries, payload, consent, promo, reviews, GA setting (no WordPress needed)
composer test:js   # node --test: assets/tracking.js, promo.js and reviews.js in a vm with a fake DOM (Node 18+, no npm install)
composer lint      # WordPress coding standards + PHP 8.1 compatibility
```

The JS tests live in `tests/js/` and, like the rest of `tests/`, are left out of the release zip.

### Manual test: Google Analytics events

On http://foundfirst-wp.test (and on foundfirst.us before each release), with DevTools open. In the Console, `dataLayer.filter(e => /click_to_call|generate_lead/.test(e.event))` shows what was sent.

1. Click a `tel:` link in the header and one in the page body. Expect exactly one `click_to_call` each, with `link_location` `header` / `page`, the right `page_path`, and **no phone number** anywhere.
2. Double-click a `tel:` link quickly. Still one event.
3. Submit a form that has **Send to Growth Hub** successfully. Expect one `generate_lead` with only `form_name` and `page_path` (no name, email, phone or message).
4. Submit it with a validation error (e.g. empty required field). No event.
5. Submit a form **without** Send to Growth Hub. No event.
6. Simulate GTM: run `window.google_tag_manager = { 'GTM-TEST': {} }; window.gtag = (...a) => console.log('gtag', a);`, then click a `tel:` link. It's pushed to `dataLayer`, but nothing is logged for gtag.
7. Run `delete window.google_tag_manager`, then click a `tel:` link. `gtag ['event', 'click_to_call', ...]` is logged once.
8. Turn the setting off and save. View the page source: no `assets/tracking.js`. Turn it back on.
9. Network tab: no new requests besides `tracking.js` itself (and GA's own hits on gtag sites).

## Release checklist

1. Bump the version in **both** places in `foundfirst-growth-hub.php`: the `Version:` header and `FFGH_VERSION`. Use semver: patch for fixes, minor for features.
2. If the queue table changed, bump `Plugin::DB_VERSION`.
3. Add a `CHANGELOG.md` entry.
4. Run `composer test && composer test:js && composer lint`.
5. Commit: `Release vX.Y.Z`.
6. `bin/build-zip.sh` builds `dist/foundfirst-growth-hub.zip` from the committed code.
7. **Install the zip on foundfirst.us first** (Plugins → Add New → Upload, replace current). Submit a real form, check it arrives in Growth Hub, check the email still arrives, check the settings page status and the promo, and a page with Google reviews. Run the Google Analytics manual test above (at least items 1, 3 and 8).
8. Tag and push: `git tag vX.Y.Z && git push origin main --tags`.
9. Create the GitHub release from the tag, paste the changelog entry and **attach `dist/foundfirst-growth-hub.zip`** (keep that exact file name):
   `gh release create vX.Y.Z dist/foundfirst-growth-hub.zip --title vX.Y.Z --notes-file <notes>`
10. On a test site, **Dashboard → Updates → Check again** should show the update notice. Then roll out to client sites (or let auto-updates do it).
