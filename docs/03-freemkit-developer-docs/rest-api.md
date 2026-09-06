---
sections: ["03-freemkit-developer-docs"]
tags: [developer,freemkit,rest-api]
---

# REST API

FreemKit exposes one public endpoint: the Freemius webhook receiver. There are no other public REST routes. Internal AJAX actions (Kit search, connection test, key validation) are WordPress admin-only and not documented here.

---

## Webhook Endpoint

### Details

| | |
|---|---|
| **URL** | `https://yoursite.com/wp-json/freemkit/v1/webhook` |
| **Method** | `POST` |
| **Content-Type** | `application/json` |
| **Authentication** | HMAC-SHA256 signature in `x-signature` header |
| **Namespace** | `freemkit/v1` |

A query variable alternative is available when the REST API is disabled:

| | |
|---|---|
| **URL** | `https://yoursite.com/?freemkit_webhook` |
| **Method** | `POST` |

Both endpoints use identical validation and processing logic.

---

## Authentication

Every request must include an `x-signature` header. This is a Freemius-generated HMAC-SHA256 signature of the raw request body, using the webhook secret key configured for the plugin.

FreemKit recomputes the expected signature server-side:

```text
hex( HMAC-SHA256( raw_request_body, plugin_secret_key ) )
```

The signature is compared as a hex string using constant-time `hash_equals()`.

Requests that do not include a valid signature receive:

```text
HTTP 401 Unauthorized
```

You do not call this endpoint directly — Freemius signs and sends the request automatically after you configure the webhook URL in your Freemius dashboard.

---

## Request Headers

| Header | Required | Description |
|---|---|---|
| `Content-Type` | Yes | Must be `application/json` |
| `x-signature` | Yes | HMAC-SHA256 of the request body using the plugin secret key |

---

## Request Body

The request body is a JSON object sent by Freemius. FreemKit uses the following fields:

| Field | Type | Description |
|---|---|---|
| `type` | string | Event type, e.g. `license.created`, `install.installed` |
| `plugin_id` | string\|int | Freemius product ID. Used to match the plugin configuration in FreemKit settings |
| `timestamp` | int | Unix timestamp of the event (optional but recommended) |
| `user.email` | string | Subscriber email address |
| `user.first` | string | Subscriber first name |
| `user.last` | string | Subscriber last name |
| `user.id` | int | Freemius user ID |
| `user.is_marketing_allowed` | bool | Whether the user has consented to marketing |

Additional fields in the payload are stored but not acted upon by default. The full payload is available to code hooking into the webhook processing actions.

### Example payload

```json
{
    "type": "license.created",
    "plugin_id": "1234",
    "timestamp": 1712000000,
    "user": {
        "id": 56789,
        "email": "customer@example.com",
        "first": "Jane",
        "last": "Smith",
        "is_marketing_allowed": true
    },
    "license": {
        "id": 99001,
        "plan_id": 500,
        "quota": 1
    }
}
```

---

## Responses

### Success — event queued

```text
HTTP 202 Accepted
Content-Type: application/json

{
    "status": "queued",
    "message": "..."
}
```

Returned when the event passes all validation checks and has been queued for async processing.

### Success — event ignored (duplicate)

```text
HTTP 200 OK
Content-Type: application/json

{
    "status": "ignored",
    "message": "Duplicate event."
}
```

Returned when the event has already been processed within the deduplication window. A `200` response is used (rather than `4xx`) to prevent Freemius from retrying.

### Error — no matching plugin

```text
HTTP 400 Bad Request
```

Returned when the `plugin_id` in the payload does not match any configured plugin in FreemKit settings. The signature check also fails in this case because FreemKit cannot look up the secret key without a matching plugin.

### Error — invalid signature

```text
HTTP 401 Unauthorized
```

The `x-signature` header is missing or the signature does not match.

### Error — event too old

```text
HTTP 200 OK
Content-Type: application/json

{
    "status": "ignored",
    "message": "Event timestamp is too old."
}
```

The event timestamp predates the allowed window (default: 15 minutes). A `200` response is used to prevent retries.

---

## Registering the Webhook in Freemius

1. Log in to your [Freemius Developer Dashboard](https://dashboard.freemius.com/).
2. Open the plugin you want to connect.
3. Go to **Settings → Webhooks**.
4. Set the endpoint URL to the FreemKit webhook URL for your site.
5. Select the event types you want Freemius to send (or select all).
6. Save. Freemius will display a **Secret Key** — copy this and enter it into FreemKit's plugin configuration (Settings → FreemKit → Freemius → Secret Key).

---

## Verifying Delivery

Freemius logs all webhook deliveries in its dashboard under **Settings → Webhooks → Delivery Log**. You can replay failed deliveries from there.

On the WordPress side, enable the FreemKit audit log (**Settings → FreemKit → Subscribers → Enable Audit Log**) to see a record of every received event, including any processing errors.

---

## Security Considerations

- Always use HTTPS. Never configure the webhook URL with `http://`.
- Keep the Freemius secret key confidential. It is stored encrypted in the WordPress database but should not be shared or committed to source control.
- Replay prevention (default: 24-hour dedup window) is always active. The timestamp check only applies when a timestamp is present in the request; to also reject requests with no timestamp, set the `freemkit_webhook_require_timestamp` filter to return `true`.
- FreemKit does not expose subscriber data through the REST API. The webhook endpoint accepts data only; it does not return subscriber records.
