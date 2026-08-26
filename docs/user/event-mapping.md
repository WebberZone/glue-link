---
tags: [event-mapping,freemkit]
---

# Event Mapping

Event mapping tells FreemKit what to do when Freemius sends a webhook. You define which events represent a "free" user, which represent a "paid" user, and which Kit forms and tags each user type should be subscribed to.

---

## How It Works

When Freemius fires a webhook for one of your plugins, FreemKit:

1. Checks the event type against your configured **free event types** and **paid event types**.
2. If the event matches, subscribes (or updates) the user in Kit using the corresponding **form IDs** and **tag IDs**.
3. If no per-plugin mapping is set, falls back to the **global Kit defaults**.

---

## Default Event Types

FreemKit ships with sensible defaults so you can get started quickly:

| User type | Default trigger event |
|---|---|
| Free | `install.installed` |
| Paid | `license.created` |

You can replace these defaults with any combination of the Freemius events listed below.

---

## Configuring Per-Plugin Mapping

1. Go to **Settings → FreemKit → Freemius** tab.
2. Find the plugin entry and expand it.
3. Fill in the mapping fields:

### Free User Mapping

| Field | Description |
|---|---|
| **Free Event Types** | Freemius events that identify a free user. When any of these fires, the user is treated as free. |
| **Free Form IDs** | Kit form IDs to subscribe the user to. Comma-separated if more than one. |
| **Free Tag IDs** | Kit tag IDs to apply. Comma-separated if more than one. |

### Paid User Mapping

| Field | Description |
|---|---|
| **Paid Event Types** | Freemius events that identify a paid user (license purchase, subscription, etc.). |
| **Paid Form IDs** | Kit form IDs for paid users. |
| **Paid Tag IDs** | Kit tag IDs for paid users. |

All form and tag fields have autocomplete — start typing a name and FreemKit will search your Kit account.

---

## Global Defaults

If a plugin's form or tag fields are left empty, FreemKit falls back to the **global Kit defaults** set on the Kit tab:

- **Default Form** — Applied to all users when no per-plugin form is set.
- **Default Tag** — Applied to all users when no per-plugin tag is set.

Event type fields do not fall back to a global default — if left empty, FreemKit uses the built-in defaults (`install.installed` for free, `license.created` for paid).

---

## Available Freemius Events

The following events can be used in free or paid event type fields.

### Install Events

| Event | Description |
|---|---|
| `install.installed` | Plugin installed for the first time |
| `install.activated` | Plugin activated |
| `install.connected` | User connected to Freemius |
| `install.deactivated` | Plugin deactivated |
| `install.deleted` | Install record deleted |
| `install.disconnected` | User disconnected from Freemius |
| `install.uninstalled` | Plugin uninstalled |
| `install.premium.activated` | Premium version activated |
| `install.premium.deactivated` | Premium version deactivated |
| `install.plan.changed` | User's plan changed |
| `install.plan.downgraded` | User's plan downgraded |
| `install.trial.started` | Trial started on a specific install |
| `install.trial.cancelled` | Trial canceled |
| `install.trial.expired` | Trial expired without converting |

### Trial Events

| Event | Description |
|---|---|
| `user.trial.started` | User started a trial (user-level) |

### Payment and License Events

| Event | Description |
|---|---|
| `cart.completed` | Checkout completed |
| `payment.created` | Payment recorded |
| `payment.refund` | Payment refunded |
| `plan.lifetime.purchase` | Lifetime plan purchased |
| `subscription.created` | Subscription created |
| `subscription.cancelled` | Subscription canceled |
| `subscription.renewal.failed.last` | Final subscription renewal attempt failed |
| `license.created` | License issued |
| `license.activated` | License activated |
| `license.cancelled` | License canceled |
| `license.expired` | License expired |

### User Events

| Event | Description |
|---|---|
| `user.name.changed` | User updated their name |
| `user.marketing.opted_out` | User opted out of marketing emails |
| `user.marketing.opted_in` | User opted back in to marketing emails |

---

## Unsubscribe Events

Some events should trigger an **unsubscribe** from Kit rather than a subscription. Configure these under **Settings → FreemKit → Subscribers → Unsubscribe Event Types**.

The default unsubscribe event is `user.marketing.opted_out`. You can add others — for example `install.uninstalled` or `license.cancelled` — depending on your list management strategy.

---

## Example Configurations

### SaaS plugin — free tier and paid tier

| Field | Value |
|---|---|
| Free Event Types | `install.installed` |
| Free Form | "Free Users" form in Kit |
| Free Tag | "Free Plan" tag |
| Paid Event Types | `license.created`, `subscription.created` |
| Paid Form | "Customers" form in Kit |
| Paid Tag | "Paid Plan" tag |

### Trial-to-paid funnel

| Field | Value |
|---|---|
| Free Event Types | `install.trial.started` |
| Free Form | "Trial Users" form |
| Paid Event Types | `payment.created` |
| Paid Form | "Customers" form |
| Paid Tag | "Converted from Trial" tag |
