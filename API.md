# Growth Hub API (v1)

The API the FoundFirst WordPress plugin uses to send website form leads to Growth Hub and fetch the promo banner. It's for that plugin only. There's no public signup and no user accounts.

- Base URL: `https://app.foundfirst.us/api/v1` (locally `http://growth-hub.test/api/v1`)
- JSON in, JSON out. Always send `Accept: application/json`.
- Call it **from the WordPress server (PHP, `wp_remote_post`)**, never from browser JavaScript. The site key must never reach a visitor's browser.

## Authentication

Every request needs the site's key:

```
Authorization: Bearer ghk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

- Keys are created in Growth Hub under **Businesses → (business) → Website connections → New connection**. The key is shown once; paste it into the plugin settings.
- The key decides which business the lead belongs to. Nothing in the request body can change that.
- A business can have several connections (one per site). Revoking one stops that key straight away.

A missing, wrong or revoked key gets:

```http
HTTP/1.1 401 Unauthorized

{"message": "Invalid or revoked site key."}
```

## Rate limits

- **30 requests a minute per key.** Every response has `X-RateLimit-Limit` and `X-RateLimit-Remaining`.
- **20 wrong keys a minute per IP address.** After that, every request from that IP (even with a good key) gets 429 for up to a minute. Client sites often share a server IP, so the plugin must **not retry a 401**.

Over the limit:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 42

{"message": "Too Many Attempts."}
```

## POST /leads

Sends one form submission.

### Request

```bash
curl -X POST https://app.foundfirst.us/api/v1/leads \
  -H "Authorization: Bearer ghk_..." \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "0b8f2c36-6a4e-4d0e-9d55-2f1f0c6f8a11",
    "name": "Jane Doe",
    "email": "jane@example.com",
    "phone": "(701) 555-0100",
    "message": "Do you plow driveways on Saturday?",
    "fields": {"Service": "Driveway plowing", "Address": "123 17th Ave S"},
    "page_url": "https://redriversnow.com/contact",
    "form_name": "Contact Us",
    "sms_consent": true,
    "consent_text": "Yes, text me about my request. Msg & data rates may apply. Reply STOP to opt out.",
    "submitted_at": "2026-10-28T14:30:00-05:00",
    "ip": "203.0.113.9"
  }'
```

| Field | Type | Rules |
| --- | --- | --- |
| `idempotency_key` | string | **Required**, max 100. A new UUID per submission, reused on every retry of that submission (see below). |
| `name` | string | Optional, max 255. |
| `email` | string | Valid email, max 255. **`email` or `phone` is required.** |
| `phone` | string | Max 50, as the visitor typed it. US numbers are normalized: `(701) 555-0100`, `701.555.0100` and `1-701-555-0100` all become `+17015550100`. Non-US numbers must start with `+` and the country code. |
| `message` | string | Optional, max 5000. |
| `fields` | object | Optional extra form fields as `{"Label": "value"}`. Max 50 entries, names max 100 characters, values must be text, a number or true/false (max 2000 characters). Join multi-select values into one string first (`"Plowing, Salting"`). |
| `page_url` | string | Optional URL of the page the form was on, max 2048. |
| `form_name` | string | Optional, max 255. Shown in the inbox ("Website form: Contact Us"). |
| `sms_consent` | boolean | Optional, default `false`. `true` only when the visitor ticked an SMS consent checkbox. Never `true` by default. |
| `consent_text` | string | **Required when `sms_consent` is `true`**, max 1000. The exact checkbox wording the visitor saw. |
| `submitted_at` | string | Optional ISO 8601 date with its offset. Defaults to when Growth Hub received it. |
| `ip` | string | Optional visitor IP address (IPv4 or IPv6). Stored on the consent record. |

Unknown fields are ignored.

**A phone Growth Hub can't read** (like `555-01`): if there's an email, the lead is still accepted and the owner sees the phone as typed, but the contact gets no phone and no text. With no email, it's a 422.

### What happens

- The lead is matched to an existing contact of that business by phone, then by email, or a new contact is created. Details the contact is missing (name, email, phone) are filled in, but nothing is overwritten.
- It shows up in the business's inbox as an unread message with a **Website form** badge, and the owners get an email alert (Reply-To is the lead's email, if there is one).
- If `sms_consent` is `true`, a consent record is stored (source "website form", page, wording, time, IP).
- If the business has **Text form leads back** turned on, the lead consented and gave a valid phone, texting is approved and they haven't opted out (STOP), they get the business's thank-you text right away.

### Responses

New lead:

```http
HTTP/1.1 201 Created

{
  "data": {
    "id": 412,
    "contact_id": 87,
    "duplicate": false,
    "received_at": "2026-10-28T19:30:02+00:00"
  }
}
```

Same `idempotency_key` sent again by the same site (a retry). Nothing new is created, and the original lead comes back:

```http
HTTP/1.1 200 OK

{
  "data": {
    "id": 412,
    "contact_id": 87,
    "duplicate": true,
    "received_at": "2026-10-28T19:30:02+00:00"
  }
}
```

Validation error:

```http
HTTP/1.1 422 Unprocessable Content

{
  "message": "A phone number or an email address is required. (and 1 more error)",
  "errors": {
    "phone": ["A phone number or an email address is required."],
    "email": ["A phone number or an email address is required."]
  }
}
```

Other messages you may see: `"The phone number is not a valid US phone number."` and `"Send the exact consent checkbox wording when sms_consent is true."`.

### Idempotency and retries (for the plugin)

1. When the form is submitted, generate a UUID (`wp_generate_uuid4()`) and save it with the queued submission.
2. Send it. On **201 or 200**, it's done.
3. On a **timeout, a connection error, a 429 or a 5xx**, retry later with the **same** body and `idempotency_key`. Wait longer each time (e.g. 1, 5, 15, 60 minutes), honor `Retry-After` on a 429, and give up after about a day.
4. **Never retry a 401 or 422.** The request won't succeed by repeating it. Log it and show it in the plugin's admin screen.

The key is unique per site connection, so two different sites using the same UUID by chance don't collide.

## GET /promo

The promo banner for the site. **For now it always returns `null`.** Text to Post (a later session) will fill it.

```bash
curl https://app.foundfirst.us/api/v1/promo \
  -H "Authorization: Bearer ghk_..." \
  -H "Accept: application/json"
```

Today:

```http
HTTP/1.1 200 OK

{"data": null}
```

When there's a promo, it will look like this. Build the banner for this shape, and hide it when `data` is `null`:

```json
{
  "data": {
    "text": "20% off driveway plowing this weekend only!",
    "link": "https://redriversnow.com/deals",
    "image_url": "https://app.foundfirst.us/storage/promos/412.jpg",
    "expires_at": "2026-11-01T05:59:59+00:00"
  }
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `text` | string | The banner text. Always present. |
| `link` | string or null | Where the banner links. Null means no link. |
| `image_url` | string or null | Optional photo. |
| `expires_at` | string or null | ISO 8601 (UTC). Hide the banner after this time even if a cached response says otherwise. |

Cache the response on the WordPress side (e.g. a 5-minute transient) rather than calling on every page view. It counts toward the 30 requests a minute.
