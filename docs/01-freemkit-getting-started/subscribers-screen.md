---
slug: subscribers-screen
title: "Subscribers Screen"
products: [freemkit]
sections: ["01-freemkit-getting-started"]
tags: [freemkit, subscribers]
status: publish
order: 0
toc: true
---

[toc]

The Subscribers screen shows every customer FreemKit has processed. It is a local record — it does not replace your Kit subscriber list, but it lets you see who FreemKit has acted on and when.

**Location:** WordPress Admin → Users → FreemKit Subscribers

---

## The Subscriber List

Each row represents one subscriber. The columns are:

| Column | Description |
|---|---|
| **Email** | The subscriber's email address. Clicking it opens the edit form. |
| **First Name** | First name as received from Freemius. |
| **Last Name** | Last name as received from Freemius. |
| **Status** | `active` or `opted_out`. Reflects the subscriber's marketing consent state. |
| **Marketing Opt-Out** | A flag indicating the subscriber has opted out of marketing. FreemKit will not subscribe opted-out users to Kit (if the Respect Marketing Opt-Out setting is enabled). |

---

## Searching and Filtering

Use the search box at the top right to find subscribers by email, first name, or last name. The list updates on submit.

---

## Adding a Subscriber Manually

Click **Add New Subscriber** at the top of the screen. Fill in the subscriber's details:

| Field | Required | Description |
|---|---|---|
| **Email** | Yes | Must be a valid email address. Must not already exist in the local list. |
| **First Name** | No | |
| **Last Name** | No | |
| **Status** | Yes | `active` or `opted_out` |

After saving, you can optionally trigger a Kit subscription from the edit form (see below).

---

## Editing a Subscriber

Click a subscriber's email address to open the edit form. From here you can:

- Update the subscriber's name, email, or status.
- **Subscribe to Kit** — Manually trigger a Kit form subscription for this subscriber. Select the form from the dropdown and click Subscribe.
- **Unsubscribe from Kit** — Manually unsubscribe this subscriber from Kit.

Changes to a subscriber's record here affect only the local FreemKit database unless you explicitly trigger a Kit action.

---

## Bulk Actions

Select one or more subscribers using the checkboxes, then choose an action from the **Bulk Actions** dropdown:

| Action | Description |
|---|---|
| **Delete** | Permanently removes the selected subscribers from the local FreemKit database. If **Unsubscribe from Kit on Delete** is enabled in settings, this will also unsubscribe them in Kit. |
| **Export** | Downloads a CSV file of the selected subscribers containing email, first name, last name, status, and opt-out flag. |

---

## What This Screen Does Not Show

The Subscribers screen shows local FreemKit records only. It does not show:

- Your full Kit subscriber list — log in to Kit for that.
- Which Kit forms or tags a subscriber was added to — check the audit log for per-event detail.
- Subscribers added directly to Kit outside of FreemKit.
