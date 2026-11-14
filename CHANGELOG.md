# Changelog

## 0.4.0 (2026-11-13)

- **Text club (VIP) consent and age (21+) confirmation** from Elementor forms. The **Send to Growth Hub** action has two new optional field IDs, blank by default: **Text club (VIP) consent field ID** and **Age confirmation (21+) field ID**.
  - A mapped box that the visitor ticked in this submission sends `marketing_consent: true` with `marketing_consent_text`, or `age_confirmed: true` with `age_confirmation_text`. The wording is the box's label and acceptance text exactly as shown, with HTML removed.
  - Unticked or unmapped boxes send `false` and no wording. The two boxes are independent of each other and of `sms_consent`.
  - Only Acceptance or Checkbox fields count. A ticked box with no wording, or a mapped field of another type, is sent as `false` with a warning on the settings page (Growth Hub would otherwise reject the lead with a 422, which is never retried).
  - Text club forms with only a name, phone and the boxes (no email or message) work.
- Every lead now includes `marketing_consent` and `age_confirmed` (`false` unless a mapped box was ticked). `sms_consent` behaves as before.
- `API.md` updated to the current Growth Hub leads API.

## 0.3.0 (2026-11-13)

- **Google Analytics events** on every site, with no per-site GTM work on gtag sites:
  - `click_to_call` when a visitor clicks any `tel:` link (including Elementor buttons and links added later). Params: `link_location` (header, footer, popup, a labelled section, or page) and `page_path`.
  - `generate_lead` when an Elementor Pro form with **Send to Growth Hub** is submitted successfully. Forms without that action, validation errors and failed submissions send nothing. Params: `form_name` and `page_path`.
  - Always pushed to `dataLayer`. Also sent with `gtag()` when the site has gtag.js but no GTM container, so GTM sites don't get double sends. GTM sites need one GA4 Event tag (see the README, "GTM sites: one-time setup").
  - No phone numbers, names, emails, messages or query strings are ever sent. No new requests, cookies or storage: the site's consent mode applies as configured. Duplicate clicks or submissions within 1 second count once.
- New setting **Settings → Growth Hub → Send call clicks and form leads to Google Analytics**, **on by default, including on sites updating from 0.2.x**. When it's off, the script isn't loaded.
- Small footer script (`assets/tracking.js`, no dependencies; it uses jQuery only if the page already has it, for Elementor's `submit_success`).

## 0.2.0 (2026-10-30)

- New setting **Settings → Growth Hub → Show photo on the banner**, off by default. When it's off the banner shows only the text and outputs no image at all.
- Override per banner with `[growthhub_promo image="yes"]` / `image="no"`, or the Elementor widget's new **Show photo** switch.
- **Behavior change:** banners that showed a photo in 0.1.0 show text only until the setting (or `image="yes"`) is turned on.

## 0.1.0 (2026-10-28)

First version.

- Elementor Pro form action "Send to Growth Hub" with per-form field mapping, SMS consent and extra fields.
- Leads are queued before sending and retried via WP-Cron (1, 5, 15, 60 min, then hourly, up to 24 h). 401 and 422 are never retried. The admin gets at most one alert email a day.
- Settings → Growth Hub: API URL, masked site key, test connection, delivery status, retry failed leads.
- Promo banner: `[growthhub_promo]` shortcode and Elementor widget, cached for 5 minutes.
- Self-updates from GitHub releases (plugin-update-checker).
- Clean uninstall.
