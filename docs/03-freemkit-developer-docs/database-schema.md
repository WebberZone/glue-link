---
slug: database-schema
title: "Database Schema"
products: [freemkit]
sections: ["03-freemkit-developer-docs"]
tags: [database, developer, freemkit]
status: publish
order: 0
---

FreemKit creates two tables on activation, both using the standard WordPress table prefix. The schema version is stored in the `freemkit_db_version` option and migrations run automatically on plugin update.

---

## `{prefix}freemkit_subscribers`

Stores one row per unique subscriber email address. This is the primary record of every customer FreemKit has processed.

```sql
CREATE TABLE {prefix}freemkit_subscribers (
    id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    email            VARCHAR(100)        NOT NULL,
    first_name       VARCHAR(50)         DEFAULT '',
    last_name        VARCHAR(50)         DEFAULT '',
    status           VARCHAR(20)         NOT NULL DEFAULT 'active',
    marketing        TINYINT(1)          NOT NULL DEFAULT 1,
    freemius_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    freemius_created DATETIME            DEFAULT NULL,
    meta             LONGTEXT            DEFAULT NULL,
    created          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY email (email),
    KEY status (status),
    KEY marketing (marketing),
    KEY freemius_user_id (freemius_user_id)
);
```

### Column Descriptions

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment primary key |
| `email` | `VARCHAR(100)` | Subscriber email address. Unique — one row per address |
| `first_name` | `VARCHAR(50)` | First name as received from Freemius |
| `last_name` | `VARCHAR(50)` | Last name as received from Freemius |
| `status` | `VARCHAR(20)` | `active` or `opted_out` |
| `marketing` | `TINYINT(1)` | `1` if the user has opted in to marketing. Maps to Freemius `is_marketing_allowed`. FreemKit will not subscribe users with `marketing = 0` when the Respect Marketing Opt-Out setting is enabled |
| `freemius_user_id` | `BIGINT UNSIGNED` | Freemius user ID for cross-referencing |
| `freemius_created` | `DATETIME` | Account creation date from Freemius |
| `meta` | `LONGTEXT` | JSON catch-all for fields like `gross`, `email_status`, `is_verified`, `last_login_at` |
| `created` | `DATETIME` | Timestamp of first record insertion |
| `modified` | `DATETIME` | Timestamp of last update (auto-managed by MySQL) |

### Notes

- The `email` unique key means records are matched and upserted by email. A subscriber who triggers multiple events is stored as a single row — only their data is updated, not duplicated.
- `status` and `marketing` are separate fields because `opted_out` status is a user-visible list-management state, whereas `marketing` reflects Freemius's marketing consent flag (`is_marketing_allowed`).

---

## `{prefix}freemkit_subscriber_events`

Stores one row per processed webhook event per subscriber/plugin combination. This is the event history.

```sql
CREATE TABLE {prefix}freemkit_subscriber_events (
    id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    subscriber_id    BIGINT(20) UNSIGNED NOT NULL,
    plugin_id        VARCHAR(50)         NOT NULL DEFAULT '',
    plugin_slug      VARCHAR(100)        NOT NULL DEFAULT '',
    event_type       VARCHAR(100)        NOT NULL DEFAULT '',
    user_type        VARCHAR(20)         NOT NULL DEFAULT '',
    form_ids         TEXT                DEFAULT NULL,
    tag_ids          TEXT                DEFAULT NULL,
    freemius_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    created          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY subscriber_id (subscriber_id),
    KEY plugin_id (plugin_id),
    KEY event_type (event_type),
    KEY user_type (user_type),
    KEY created (created)
);
```

### Column Descriptions

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment primary key |
| `subscriber_id` | `BIGINT UNSIGNED` | Foreign key to `freemkit_subscribers.id` |
| `plugin_id` | `VARCHAR(50)` | Freemius numeric product ID |
| `plugin_slug` | `VARCHAR(100)` | Plugin name as a slug (for human-readable reference) |
| `event_type` | `VARCHAR(100)` | Freemius event type (e.g. `license.created`) |
| `user_type` | `VARCHAR(20)` | User classification: `free`, `paid`, `opted_out`, or empty string for opt-in and name-change events |
| `form_ids` | `TEXT` | Comma-separated list of Kit form IDs the subscriber was added to |
| `tag_ids` | `TEXT` | Comma-separated list of Kit tag IDs that were applied |
| `freemius_user_id` | `BIGINT UNSIGNED` | Freemius user ID as provided in the webhook payload. `0` when not present. |
| `created` | `DATETIME` | Timestamp when the event was processed |

### Notes

- Each webhook that results in a Kit action produces one row here. Multiple events from the same subscriber across different plugins each produce separate rows.
- `form_ids` and `tag_ids` store the IDs that were used at time of processing. If you later change the form mapping in settings, older event rows are unaffected.
- There is no foreign key constraint defined at the database level (WordPress convention), but `subscriber_id` always references a valid `freemkit_subscribers.id` row.

---

## WordPress Options

FreemKit stores the following entries in `wp_options`:

| Option Key | Autoloaded | Description |
|---|---|---|
| `freemkit_settings` | Yes | Serialized array containing all plugin settings |
| `freemkit_audit_log` | No | Serialized audit log entries array (max 200) |
| `freemkit_db_version` | Yes | Installed database schema version |
| `freemkit_wizard_completed` | Yes | `1` when the setup wizard has been completed |
| `freemkit_wizard_current_step` | Yes | Current wizard step number (integer) |
| `freemkit_show_wizard` | Yes | Whether to show the wizard on next admin load |

The `freemkit_settings` option contains all user-facing configuration. Sensitive values (`secret_key`, `kit_access_token`, `kit_refresh_token`) are encrypted at rest using AES-256-CBC (OpenSSL) or libsodium, depending on what is available on the server.

Access settings via `Options_API::get_option( $key )` rather than reading the option directly. This applies the `freemkit_get_option` filter chain and handles decryption transparently.
