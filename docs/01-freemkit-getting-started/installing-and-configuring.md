---
sections: ["01-freemkit-getting-started"]
tags: [freemkit,installation]
---

# Installing and Configuring FreemKit

This guide covers installing FreemKit, connecting your Kit account, and adding your Freemius products.

---

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- An active [Kit](https://partners.kit.com/z6ppnmz4nhnb) (aff.) account
- One or more plugins sold through [Freemius](https://freemius.com/)

---

## Step 1 — Install and Activate

1. Upload the `freemkit` folder to `/wp-content/plugins/`, or install via the WordPress plugin screen.
2. Activate the plugin under **Plugins → Installed Plugins**.
3. The setup wizard launches automatically on first activation. Reach it at any time via **Settings → FreemKit → Start Setup Wizard**.

---

## Step 2 — Connect Kit

FreemKit uses Kit's OAuth flow to connect your account. No API keys are needed.

### If the Kit plugin is already active

FreemKit can share credentials with the official [Kit WordPress plugin](https://wordpress.org/plugins/convertkit/). If the Kit plugin is connected, FreemKit will detect this automatically and use the same access token — you can skip the OAuth step.

### Connecting via OAuth

1. Go to **Settings → FreemKit** and open the **Kit** tab (or proceed through the wizard).
2. Click **Connect with Kit**.
3. You will be redirected to Kit's authorization page. Log in and approve access.
4. You will be redirected back to WordPress. A green "Connected" status confirms the connection.

To verify the connection at any time, click **Test Kit Connection** on the Kit settings tab.

---

## Step 3 — Configure the Webhook Endpoint

FreemKit receives events from Freemius via a webhook. You need to decide which endpoint type to use and then register the URL in your Freemius developer dashboard.

### Endpoint types

| Type | URL format | When to use |
|---|---|---|
| **REST API** (default) | `https://yoursite.com/wp-json/freemkit/v1/webhook` | Works on most sites |
| **Query variable** | `https://yoursite.com/?freemkit_webhook` | Use if the REST API is disabled |

To change the endpoint type, go to **Settings → FreemKit → Freemius** tab and select your preference under **Webhook Endpoint**.

### Registering the webhook in Freemius

1. Log in to your [Freemius Developer Dashboard](https://dashboard.freemius.com/).
2. Open the plugin you want to connect.
3. Go to **Settings → Webhooks**.
4. Add the webhook URL shown in FreemKit's Freemius settings tab.
5. Freemius will display a **Secret Key** after saving. Copy it — you will need it in the next step.

---

## Step 4 — Add Your Freemius Products

Each Freemius plugin you sell is configured separately in FreemKit.

1. Go to **Settings → FreemKit → Freemius** tab.
2. Under **Plugins**, click **Add Plugin**.
3. Fill in the following fields:

| Field | Where to find it | Notes |
|---|---|---|
| **Plugin Name** | Your own label | For reference only |
| **Product ID** | Freemius dashboard → your plugin → Settings | Numeric ID |
| **Public Key** | Freemius dashboard → your plugin → Settings → API | Starts with `pk_` |
| **Secret Key** | Freemius webhook settings (see Step 3) | Stored encrypted |

4. Click **Validate Keys** to confirm the credentials are correct.
5. Save settings.

Repeat for each plugin you want to connect.

---

## Step 5 — Map Events to Forms and Tags

After adding a product, you need to tell FreemKit which Kit forms and tags to use for free and paid users, and which Freemius events should trigger them.

See [Event Mapping](event-mapping.md) for a full walkthrough.

---

## Step 6 — Configure Subscriber Behavior (Optional)

These settings control how FreemKit handles edge cases like marketing opt-outs and name changes. The defaults work for most sites; adjust them as needed.

Go to **Settings → FreemKit → Subscribers** tab. Key options:

- **Respect marketing opt-out** — When enabled (default), FreemKit will not subscribe users to Kit if they have opted out of marketing.
- **Sync name on change** — When enabled (default), FreemKit updates the subscriber's name in Kit when Freemius sends a `user.name.changed` event.
- **Unsubscribe on delete** — When enabled, deleting a subscriber from the local FreemKit list will also unsubscribe them in Kit. Disabled by default.
- **Enable audit log** — Keeps a log of all FreemKit actions for debugging. Enabled by default.

For a full description of every setting, see the [Settings Reference](settings.md).

---

## Verifying the Setup

Once configured, you can test the integration by triggering a Freemius event (for example, installing your plugin on a test site) and checking:

1. The **Subscribers** screen (**Users → FreemKit Subscribers**) to confirm a record was created.
2. Your Kit account to confirm the subscriber was added to the expected form or tag.
3. The **Audit Log** (Settings → FreemKit → Subscribers tab, scroll to Audit Log) for a trace of what happened.
