---
sections: ["03-freemkit-developer-docs"]
tags: [developer,freemkit,hooks]
---

# Hooks Reference

All FreemKit actions and filters are listed here. Hooks follow the `freemkit_` prefix convention. All hooks can be registered using standard WordPress `add_action()` / `add_filter()` calls, or via `Hook_Registry::add_action()` / `Hook_Registry::add_filter()` if extending from within the plugin.

---

## Actions

### Kit OAuth

#### `freemkit_api_get_access_token`

Fires after a Kit access token is successfully obtained.

```php
do_action( 'freemkit_api_get_access_token', $result, $client_id );
```

| Parameter | Type | Description |
|---|---|---|
| `$result` | array | Token response from the Kit API |
| `$client_id` | string | Kit OAuth client ID |

---

#### `freemkit_api_refresh_token`

Fires after a Kit access token is successfully refreshed.

```php
do_action( 'freemkit_api_refresh_token', $result, $client_id, $prev_access_token, $prev_refresh_token );
```

| Parameter | Type | Description |
|---|---|---|
| `$result` | array | New token response |
| `$client_id` | string | Kit OAuth client ID |
| `$prev_access_token` | string | The access token that was replaced |
| `$prev_refresh_token` | string | The refresh token that was replaced |

---

#### `freemkit_api_get_access_token_error`

Fires when obtaining a Kit access token fails.

```php
do_action( 'freemkit_api_get_access_token_error', $error, $client_id );
```

| Parameter | Type | Description |
|---|---|---|
| `$error` | WP_Error | The error returned |
| `$client_id` | string | Kit OAuth client ID |

---

#### `freemkit_api_refresh_token_error`

Fires when refreshing a Kit access token fails.

```php
do_action( 'freemkit_api_refresh_token_error', $error, $client_id );
```

| Parameter | Type | Description |
|---|---|---|
| `$error` | WP_Error | The error returned |
| `$client_id` | string | Kit OAuth client ID |

---

### Webhook Processing

#### `freemkit_process_webhook_event`

WP-Cron hook. Fires asynchronously to process a queued webhook event. Not intended to be called directly.

```php
do_action( 'freemkit_process_webhook_event', $event_key );
```

| Parameter | Type | Description |
|---|---|---|
| `$event_key` | string | Unique key identifying the queued event (used to retrieve its transient payload) |

---

### Subscriber Lifecycle

#### `freemkit_after_add_subscriber`

Fires after a new subscriber is inserted into the local database.

```php
do_action( 'freemkit_after_add_subscriber', $id, $subscriber );
```

| Parameter | Type | Description |
|---|---|---|
| `$id` | int | New subscriber ID |
| `$subscriber` | Subscriber | The subscriber object |

---

#### `freemkit_after_update_subscriber`

Fires after an existing subscriber record is updated.

```php
do_action( 'freemkit_after_update_subscriber', $id, $subscriber, $existing );
```

| Parameter | Type | Description |
|---|---|---|
| `$id` | int | Subscriber ID |
| `$subscriber` | Subscriber | The updated subscriber object |
| `$existing` | Subscriber | The subscriber object before the update |

---

#### `freemkit_delete_subscriber`

Fires when a subscriber is deleted from the local database.

```php
do_action( 'freemkit_delete_subscriber', $id, $subscriber );
```

| Parameter | Type | Description |
|---|---|---|
| `$id` | int | The ID of the deleted subscriber |
| `$subscriber` | Subscriber | The subscriber object at time of deletion |

---

### Admin UI

#### `freemkit_subscribers_page_header`

Fires at the top of the Subscribers admin screen, before the list table. Use to inject custom notices or content.

```php
do_action( 'freemkit_subscribers_page_header' );
```

---

#### `freemkit_settings_form_buttons`

Fires inside the settings form button area. Use to add custom buttons to the settings page.

```php
do_action( 'freemkit_settings_form_buttons', $tab_id, $tab_name, $sections );
```

| Parameter | Type | Description |
|---|---|---|
| `$tab_id` | string | Current tab slug |
| `$tab_name` | string | Current tab display name |
| `$sections` | array | Settings sections on the current tab |

---

#### `freemkit_wizard_completed`

Fires when the setup wizard is completed or skipped.

```php
do_action( 'freemkit_wizard_completed', $prefix );
```

| Parameter | Type | Description |
|---|---|---|
| `$prefix` | string | Plugin prefix (`freemkit`) |

---

#### `freemkit_wizard_step_processed`

Fires after each wizard step is saved.

```php
do_action( 'freemkit_wizard_step_processed', $step, $settings );
```

| Parameter | Type | Description |
|---|---|---|
| `$step` | int | The step number that was saved |
| `$settings` | array | The settings values saved in this step |

---

---

## Filters

### Settings: Reading

#### `freemkit_get_settings`

Filters the entire settings array.

```php
$settings = apply_filters( 'freemkit_get_settings', $settings );
```

---

#### `freemkit_get_option`

Filters the value of any single option.

```php
$value = apply_filters( 'freemkit_get_option', $value, $key, $default );
```

| Parameter | Type | Description |
|---|---|---|
| `$value` | mixed | The option value |
| `$key` | string | The option key |
| `$default` | mixed | The default value |

---

#### `freemkit_get_option_{$key}`

Filters the value of a specific option. Replace `{$key}` with the option key.

```php
// Example: filter the kit_form_id option
add_filter( 'freemkit_get_option_kit_form_id', function( $value ) {
    return '12345';
} );
```

---

#### `freemkit_settings_defaults`

Filters the default values for all settings.

```php
$defaults = apply_filters( 'freemkit_settings_defaults', $defaults );
```

---

### Settings: Writing

#### `freemkit_update_option`

Filters an option value before it is saved.

```php
$value = apply_filters( 'freemkit_update_option', $value, $key );
```

---

### Settings: Registration

#### `freemkit_registered_settings`

Filters the complete registered settings definition array (used to build the settings form).

```php
$settings = apply_filters( 'freemkit_registered_settings', $settings );
```

---

#### `freemkit_settings_sections`

Filters the list of settings tabs/sections.

```php
$sections = apply_filters( 'freemkit_settings_sections', $sections );
```

---

#### `freemkit_settings_kit`

Filters the settings fields on the Kit tab.

```php
$fields = apply_filters( 'freemkit_settings_kit', $fields );
```

---

#### `freemkit_settings_general`

Filters the settings fields on the Freemius tab. Despite the name, this filter controls the Freemius tab settings (the tab registered under the `freemius` section key).

```php
$fields = apply_filters( 'freemkit_settings_general', $fields );
```

---

#### `freemkit_settings_subscribers`

Filters the settings fields on the Subscribers tab.

```php
$fields = apply_filters( 'freemkit_settings_subscribers', $fields );
```

---

### Webhook Behavior

#### `freemkit_webhook_require_timestamp`

Whether to reject incoming webhook payloads that have no timestamp. By default, missing timestamps are accepted. Return `true` to reject them.

```php
$require = apply_filters( 'freemkit_webhook_require_timestamp', false, $request );
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$require` | bool | `false` | Whether to reject requests with no timestamp |
| `$request` | WP_REST_Request\|null | — | The incoming request object |

---

#### `freemkit_webhook_max_age`

Maximum age in seconds for an incoming webhook event.

```php
$max_age = apply_filters( 'freemkit_webhook_max_age', 900, $timestamp );
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$max_age` | int | `900` | Maximum allowed age in seconds |
| `$timestamp` | int | — | The timestamp extracted from the request |

Default: `900` (15 minutes).

---

#### `freemkit_webhook_replay_ttl`

How long (in seconds) to retain the deduplication record for a processed event.

```php
$ttl = apply_filters( 'freemkit_webhook_replay_ttl', DAY_IN_SECONDS, $event_key );
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$ttl` | int | `86400` | Dedup window in seconds (minimum enforced: 1 hour) |
| `$event_key` | string | — | The unique key for this event |

Default: `86400` (24 hours).

---

#### `freemkit_webhook_max_retries`

Maximum number of async retry attempts before an event is discarded.

```php
$max = apply_filters( 'freemkit_webhook_max_retries', 3, $event_key );
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$max` | int | `3` | Maximum attempts |
| `$event_key` | string | — | The unique key for this event |

---

### Event Types

#### `freemkit_default_free_event_types`

Filters the default event types that identify a free user, used when no per-plugin override is set.

```php
$types = apply_filters( 'freemkit_default_free_event_types', $defaults, $plugin_config );
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$defaults` | array | `['install.installed']` | Default free event types |
| `$plugin_config` | array | — | The plugin configuration array |

```php
add_filter( 'freemkit_default_free_event_types', function( $types, $config ) {
    $types[] = 'install.activated';
    return $types;
}, 10, 2 );
```

---

#### `freemkit_default_paid_event_types`

Filters the default event types that identify a paid user.

```php
$types = apply_filters( 'freemkit_default_paid_event_types', $defaults, $plugin_config );
```

Default: `['license.created']`.

---

#### `freemkit_freemius_events`

Filters the full list of available Freemius event types shown in autocomplete fields.

```php
$events = apply_filters( 'freemkit_freemius_events', $events );
```

---

### Wizard

#### `freemkit_wizard_steps`

Filters the array of setup wizard steps. Use to add, remove, or reorder steps.

```php
$steps = apply_filters( 'freemkit_wizard_steps', $steps );
```

| Parameter | Type | Description |
|---|---|---|
| `$steps` | array | Associative array of wizard step definitions |

---

### Audit Log

#### `freemkit_audit_log_max_entries`

Maximum number of entries to retain in the audit log.

```php
$max = apply_filters( 'freemkit_audit_log_max_entries', 200 );
```

---

### Kit OAuth: Token Refresh

#### `freemkit_refresh_advance_seconds`

How many seconds before a Kit token expires to schedule a proactive refresh.

```php
$advance = apply_filters( 'freemkit_refresh_advance_seconds', $advance_secs, $ttl_secs );
```

| Parameter | Type | Description |
|---|---|---|
| `$advance_secs` | int | Seconds before expiry to refresh |
| `$ttl_secs` | int | Total token TTL in seconds |
