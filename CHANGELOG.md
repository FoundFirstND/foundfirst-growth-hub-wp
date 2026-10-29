# Changelog

## 0.1.0 (2026-10-28)

First version.

- Elementor Pro form action "Send to Growth Hub" with per-form field mapping, SMS consent and extra fields.
- Leads are queued before sending and retried via WP-Cron (1, 5, 15, 60 min, then hourly, up to 24 h). 401 and 422 are never retried. The admin gets at most one alert email a day.
- Settings → Growth Hub: API URL, masked site key, test connection, delivery status, retry failed leads.
- Promo banner: `[growthhub_promo]` shortcode and Elementor widget, cached for 5 minutes.
- Self-updates from GitHub releases (plugin-update-checker).
- Clean uninstall.
