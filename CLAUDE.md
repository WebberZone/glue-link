# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository.

## Response Rules

- Return only the changed function or section, not the full file
- No explanation unless asked
- No out-of-scope suggestions
- Skip preamble and trailing summaries

## Links

- GitHub: <https://github.com/WebberZone/freemkit>

Internal tooling plugin — not distributed on WordPress.org or webberzone.com/plugins.

## Plugin Overview

FreemKit (WordPress plugin, v1.0.0) bridges Freemius software licensing with Kit (formerly ConvertKit) email marketing: receives Freemius webhook events and subscribes customers to Kit forms/tags based on free vs paid status. Namespace: `WebberZone\FreemKit`. Prefix: `freemkit_`. Text domain: `freemkit`. Requires WordPress 6.6+, PHP 7.4+.

## Commands

### PHP

```bash
composer phpcs            # Lint PHP (WordPress coding standards)
composer phpcbf           # Auto-fix PHP code style
composer phpstan          # Static analysis (level configured in phpstan.neon.dist)
composer phpstan-baseline # Generate a PHPStan baseline
composer phpcompat        # Check PHP 7.4–8.6 compatibility
composer test             # Run all checks (phpcs + phpcompat + phpstan)
composer build:vendor     # Install production-only dependencies
composer zip              # Create distribution zip
```

### JavaScript/CSS

```bash
pnpm run build             # Build JS/CSS assets with wp-scripts
pnpm run build:assets      # Minify CSS/JS and generate RTL CSS (node build-assets.js)
pnpm start                 # Watch mode
pnpm run lint:js           # ESLint
pnpm run lint:css          # Stylelint
pnpm run format            # Format with wp-scripts
pnpm run packages-update   # Update wp-scripts packages
pnpm run zip               # Create plugin zip via wp-scripts
ncu -u && pnpm install   # Update dependencies to latest and reinstall
```

## Architecture

### Entry Point & Bootstrap

`freemkit.php` defines constants (`FREEMKIT_VERSION`, `FREEMKIT_PLUGIN_FILE`, `FREEMKIT_PLUGIN_DIR`, `FREEMKIT_PLUGIN_URL`, `FREEMKIT_KIT_OAUTH_CLIENT_ID`, `FREEMKIT_KIT_OAUTH_REDIRECT_URI`), loads Kit shared library classes from `vendor/convertkit/convertkit-wordpress-libraries/`, registers the autoloader, and calls `\WebberZone\FreemKit\load()` on `plugins_loaded`.

**Autoloader convention:** Namespace segments become path segments under `includes/`; underscores → hyphens, lowercase, last segment prefixed with `class-`. e.g. `WebberZone\FreemKit\Admin\Settings` → `includes/admin/class-settings.php`.

### Core Components

- **`Main`** (`includes/class-main.php`) — Singleton. Instantiates `Runtime`, `Kit_Credential_Hooks`, `Language_Handler`; registers hooks via `Hook_Registry`. Registers activation hook (`Main::activate` → `Runtime::activate`).
- **`Runtime`** (`includes/class-runtime.php`) — Instantiates `Database` in constructor. On `init`, creates `Kit_API` and `Webhook_Handler`. Creates `Admin` in admin context (via `init_admin`). Builds per-plugin configs from the `plugins` settings array.
- **`Webhook_Handler`** (`includes/class-webhook-handler.php`) — Core logic. Registers REST endpoint at `freemkit/v1/webhook` (or query-var fallback). Validates HMAC-SHA256 signatures (`x-signature` header) and webhook freshness (15-minute window). Queues events as WP transients, processes asynchronously via WP-Cron (`freemkit_process_webhook_event`). Deduplicates via `freemkit_webhook_seen_*` transients; linear-backoff retry (default max 3 attempts, delay capped at 5 minutes; filterable via `freemkit_webhook_max_retries`).
- **`Database`** (`includes/class-database.php`) — Manages two custom tables: `{prefix}freemkit_subscribers` (subscriber records), `{prefix}freemkit_subscriber_events` (per-plugin webhook interactions). Uses object caching and `dbDelta()` for schema.
- **`Options_API`** (`includes/class-options-api.php`) — All settings stored as a single `freemkit_settings` array in `wp_options`; access via `Options_API::get_option($key)` / `get_settings()`. Sensitive keys (access/refresh tokens) encrypted at rest via AES-256-CBC (OpenSSL) or libsodium.
- **`Audit_Log`** (`includes/class-audit-log.php`) — Plugin-wide audit log stored as a non-autoloaded `freemkit_audit_log` WP option. Entries capped at 200 (filterable via `freemkit_audit_log_max_entries`); email addresses masked. Toggleable via `enable_audit_log` setting.
- **`Freemius`** (`includes/class-freemius.php`) — Static helpers: normalizes event types, validates Freemius API credentials against the product endpoint, returns Freemius event choices for selectors.
- **`Freemius_API_Client`** (`includes/class-freemius-api-client.php`) — Fetches users/licenses from the Freemius REST API for a single product (used by Sync admin).
- **`Subscriber`** / **`Subscriber_Event`** (`includes/class-subscriber.php`, `includes/class-subscriber-event.php`) — Value objects for a subscriber and a per-plugin webhook event.
- **`Language_Handler`** (`includes/class-language-handler.php`) — Loads `freemkit` textdomain on `init`.

### Kit Integration (`includes/kit/`)

- **`Kit_API`** — Extends `ConvertKit_API_V4` to subscribe users to forms and apply tags.
- **`Kit_Settings`** — Manages OAuth tokens (`kit_access_token`, `kit_refresh_token`, `kit_token_expires`); falls back to the official Kit WordPress plugin's stored credentials (`_wp_convertkit_settings`) if available. Schedules token refresh via `freemkit_refresh_token` cron hook.
- **`Kit_Credential_Hooks`** — Listens for `freemkit_api_get_access_token` / `freemkit_api_refresh_token` / `convertkit_api_access_token_invalid` actions to keep tokens in sync. Deletes local credentials after 3 invalid-token failures within a 10-minute window.

### Admin (`includes/admin/`)

- **`Admin`** — Admin loader. Instantiates `Settings`, `Settings_Wizard`, `Subscribers_List`, `Sync_Admin`, `Admin_Notices_API`, `Admin_Banner`.
- **`Settings`** / **`Settings_Wizard`** — Settings pages; wizard shown on first activation. Settings: global Kit form/tag defaults, per-plugin overrides (free/paid form IDs, tag IDs, event types), custom field mappings, webhook endpoint type (REST vs query-var).
- **`Kit_OAuth`** — OAuth flow for connecting to Kit.
- **`Subscribers_List`** / **`Subscribers_List_Table`** — Admin screen displaying the local `freemkit_subscribers` table.
- **`Subscriber_Form`** — Add/edit subscriber form.
- **`Sync_Admin`** — Sync admin page and two-phase AJAX wizard for backfilling subscribers from Freemius.
- **`Admin_Notices_API`** / **`Admin_Banner`** — Reusable admin notices and banner helpers.
- **Settings subfolder** (`includes/admin/settings/`) — `Settings_API`, `Settings_Form`, `Settings_Sanitize`, `Metabox_API`, `Settings_Wizard_API`.

### Utilities (`includes/util/`)

- **`Hook_Registry`** — Static registry for all registered actions/filters; prevents duplicates (same pattern as CRP).

## Key Patterns

- **Webhook event routing:** Freemius sends a `type` field (e.g. `install.installed`, `license.created`). Default free events: `['install.installed']`; default paid events: `['license.created']`. Overridable per-plugin config or via `freemkit_default_free_event_types` / `freemkit_default_paid_event_types` filters. Unsubscribe events (default `user.marketing.opted_out`) trigger Kit unsubscription; `user.marketing.opted_in` re-subscribes; `user.name.changed` updates subscriber name.
- **Per-plugin config:** Settings store a `plugins` array, each entry keyed by Freemius plugin ID, with separate free/paid form IDs, tag IDs, event types. Falls back to global kit form/tag settings when per-plugin values are empty.
- **Settings access:** Always use `Options_API::get_option($key)` rather than reading `freemkit_settings` directly.
- **Hook registration:** Use `Hook_Registry::add_action()` / `add_filter()` rather than WordPress functions directly.
- **Async processing:** Webhooks never processed synchronously (except when WP-Cron is disabled or scheduling fails). Always go through `queue_webhook_event()` → `process_queued_webhook()`.

## Shared framework files: `@since` convention

The Settings API (`includes/admin/settings/*.php`) and Admin Banner (`includes/admin/class-admin-banner.php`) are copy-pasted, shared framework files whose canonical source is the `Settings_API` repo. To keep `@since` tags meaningful and stable across syncs:

- Each file carries **exactly one** `@since` tag, on its **class docblock**, set to the plugin version at which that class was **first introduced into this plugin** — per-file (wizard, metabox and banner classes were generally added later than the core Settings API classes).
- **Do not** add `@since` to methods, functions or properties in these files.
- When syncing/updating these files from another plugin or the canonical `Settings_API` repo, **do not overwrite the class-level `@since`** — it's plugin-specific. Re-apply the values below after any sync.

| File | `@since` |
|---|---|
| `includes/admin/settings/class-settings-api.php` | 1.0.0 |
| `includes/admin/settings/class-settings-form.php` | 1.0.0 |
| `includes/admin/settings/class-settings-sanitize.php` | 1.0.0 |
| `includes/admin/settings/class-settings-wizard-api.php` | 1.0.0 |
| `includes/admin/settings/class-metabox-api.php` | 1.0.0 |
| `includes/admin/class-admin-banner.php` | 1.0.0 |

