---
slug: settings
title: "Settings Reference"
products: [freemkit]
sections: ["01-freemkit-getting-started"]
tags: [freemkit, settings]
status: publish
order: 0
toc: true
---

[toc]

All FreemKit settings are found at **Settings → FreemKit** in the WordPress admin. Settings are organized across three tabs: **Kit**, **Freemius**, and **Subscribers**.

---

## Kit Tab

### Kit Connection

| Setting | Description |
|---|---|
| **Connect with Kit** | Launches the OAuth flow to authorize FreemKit with your Kit account. After authorizing, a "Connected" status is shown. |
| **Test Kit Connection** | Sends a test request to the Kit API to verify the current credentials are valid. |
| **Disconnect** | Revokes the stored tokens and disconnects from Kit. |

If the official Kit WordPress plugin is installed and connected, FreemKit will automatically use its credentials. The connection status area will indicate this.

### Global Defaults

These are fallback values used when a plugin entry does not specify its own form or tag.

| Setting | Description |
|---|---|
| **Global Form ID** | The Kit form to subscribe users to when no per-plugin form is set. Autocomplete field — start typing a form name to search. |
| **Tag ID** | The Kit tag to apply when no per-plugin tag is set. Autocomplete field. |

---

## Freemius Tab

### Webhook Endpoint

| Setting | Options | Description |
|---|---|---|
| **Webhook Endpoint Type** | REST API (default), Query Variable | Determines the URL format FreemKit listens on for Freemius events. |

**REST API URL:** `https://yoursite.com/wp-json/freemkit/v1/webhook`
**Query variable URL:** `https://yoursite.com/?freemkit_webhook`

Use the query variable option only if the WordPress REST API is unavailable on your site.

### Plugins

A repeating block where each entry represents one Freemius product. Click **Add Plugin** to create a new entry.

#### Identity Fields

| Field | Description |
|---|---|
| **Plugin Name** | A label for your own reference. Not sent to Kit or Freemius. |
| **Product ID** | The numeric ID of your plugin in the Freemius dashboard (Settings → your plugin). |
| **Public Key** | Your Freemius public API key for this plugin. Starts with `pk_`. |
| **Secret Key** | The webhook secret key from Freemius. Used to verify webhook signatures. Stored encrypted. |

Click **Validate Keys** after entering credentials to confirm they are correct.

#### Free User Mapping

| Field | Description |
|---|---|
| **Free Trigger Events** | Freemius events that should trigger a free-user subscription in Kit. Defaults to `install.installed` if left empty. |
| **Free Form** | Kit form(s) to subscribe free users to. Autocomplete. Falls back to the global default form if empty. |
| **Free Tag** | Kit tag(s) to apply to free users. Autocomplete. Falls back to the global default tag if empty. |

#### Paid User Mapping

| Field | Description |
|---|---|
| **Paid Trigger Events** | Freemius events that should trigger a paid-user subscription in Kit. Defaults to `license.created` if left empty. |
| **Paid Form** | Kit form(s) to subscribe paid users to. Falls back to the global default form if empty. |
| **Paid Tag** | Kit tag(s) to apply to paid users. Falls back to the global default tag if empty. |

See [Event Mapping](event-mapping.md) for a full list of available Freemius event types and example configurations.

---

## Subscribers Tab

### Opt-Out Handling

| Setting | Default | Description |
|---|---|---|
| **Respect Marketing Opt-Out** | Enabled | When enabled, FreemKit will not subscribe a user to Kit if they have opted out of marketing in Freemius. Recommended. |
| **Unsubscribe Event Types** | `user.marketing.opted_out` | Freemius events that should trigger an unsubscribe in Kit. Separate multiple events with commas or use the autocomplete field. |

### Subscriber Lifecycle

| Setting | Default | Description |
|---|---|---|
| **Sync Name on Change** | Enabled | When Freemius sends a `user.name.changed` event, FreemKit updates the subscriber's name in Kit. |
| **Unsubscribe from Kit on Delete** | Disabled | When a subscriber is deleted from the local FreemKit list, they are also unsubscribed in Kit. Use with caution — this cannot be undone from FreemKit. |

### Audit Log

| Setting | Default | Description |
|---|---|---|
| **Enable Audit Log** | Enabled | Records all FreemKit activity (webhook received, Kit subscribed, errors, etc.) for debugging. The log holds a maximum of 200 entries; older entries are removed automatically. |

The audit log is displayed at the bottom of the Subscribers tab. Each entry shows a timestamp, action, and relevant details. Sensitive data such as email addresses are partially masked in the log.

---

## Toolbar and Utility Buttons

These buttons appear at the top of the settings page regardless of tab:

| Button | Description |
|---|---|
| **Save Settings** | Saves all settings on the current tab. |
| **Clear Cache** | Clears FreemKit's cached Kit data (forms, tags, custom fields). Use this if Kit data appears stale. |
| **Start Setup Wizard** | Launches the guided setup wizard. |
