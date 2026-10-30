# Changelog

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
