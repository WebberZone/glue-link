<?php
/**
 * Subscriber sync service.
 *
 * @package WebberZone\FreemKit
 * @since 1.0.0
 */

namespace WebberZone\FreemKit;

use WebberZone\FreemKit\Admin\Settings\Settings_API;
use WebberZone\FreemKit\Kit\Kit_API;
use WebberZone\FreemKit\Options_API;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Shared Freemius / local subscriber sync used by admin and WP-CLI.
 *
 * @since 1.0.0
 */
class Sync {

	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var Database
	 */
	protected Database $database;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Database|null $database Optional database instance.
	 */
	public function __construct( ?Database $database = null ) {
		$this->database = $database ?? new Database();
	}

	/**
	 * List a page of users from Freemius or the local database.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $source       `freemius` or `local`.
	 * @param string   $plugin_id    Freemius product ID, or empty for all.
	 * @param string[] $user_types   User type filter.
	 * @param int      $offset       Pagination offset.
	 * @param int      $count        Page size.
	 * @param int      $plugin_index Plugin index when syncing all Freemius products.
	 * @return array|\WP_Error
	 */
	public function list_users( string $source, string $plugin_id, array $user_types, int $offset, int $count, int $plugin_index = 0 ) {
		$count = min( 50, max( 1, $count ) );

		if ( 'freemius' === $source ) {
			return $this->list_freemius_users( $plugin_id, $user_types, $offset, $count, $plugin_index );
		}

		return $this->list_local_users( $plugin_id, $user_types, $offset, $count );
	}

	/**
	 * Process a batch of sync tasks.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $raw_tasks Raw task data.
	 * @return array<int, array<string, mixed>>
	 */
	public function process_batch( array $raw_tasks ): array {
		$destination = isset( $raw_tasks[0]['destination'] ) ? sanitize_key( (string) $raw_tasks[0]['destination'] ) : 'both';

		if ( 'both' === $destination && count( $raw_tasks ) > 1 ) {
			return $this->process_bulk_tasks( $raw_tasks );
		}

		$results = array();
		foreach ( $raw_tasks as $raw_task ) {
			$results[] = $this->process_single_task( $raw_task );
		}

		return $results;
	}

	/**
	 * Build plugin configurations from settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_plugin_configs(): array {
		$settings       = Options_API::get_settings();
		$plugin_configs = array();

		if ( empty( $settings['plugins'] ) || ! is_array( $settings['plugins'] ) ) {
			return $plugin_configs;
		}

		foreach ( $settings['plugins'] as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}

			$plugin_fields = isset( $plugin['fields'] ) && is_array( $plugin['fields'] ) ? $plugin['fields'] : $plugin;

			if ( empty( $plugin_fields['id'] ) ) {
				continue;
			}

			$plugin_id = sanitize_text_field( (string) $plugin_fields['id'] );

			$public_key = (string) ( $plugin_fields['public_key'] ?? '' );

			$secret_raw = (string) ( $plugin_fields['secret_key'] ?? '' );
			$secret_key = Settings_API::decrypt_api_key( $secret_raw );
			if ( '' === $secret_key ) {
				$secret_key = trim( $secret_raw, " \t\n\r\0\x0B" );
			}

			$plugin_configs[ $plugin_id ] = array(
				'name'          => sanitize_text_field( (string) ( $plugin_fields['name'] ?? '' ) ),
				'slug'          => sanitize_title( (string) ( $plugin_fields['name'] ?? '' ) ),
				'public_key'    => $public_key,
				'secret_key'    => $secret_key,
				'free_form_ids' => (string) ( $plugin_fields['free_form_ids'] ?? '' ),
				'free_tag_ids'  => (string) ( $plugin_fields['free_tag_ids'] ?? '' ),
				'paid_form_ids' => (string) ( $plugin_fields['paid_form_ids'] ?? '' ),
				'paid_tag_ids'  => (string) ( $plugin_fields['paid_tag_ids'] ?? '' ),
			);
		}

		return $plugin_configs;
	}

	/**
	 * Fetch a page of Freemius users for the list step.
	 *
	 * Supports a single plugin (plugin_id set) or all plugins in sequence (plugin_id empty,
	 * advancing via plugin_index once a plugin's pages are exhausted).
	 *
	 * @since 1.0.0
	 *
	 * @param string   $plugin_id    Freemius product ID, or empty for all.
	 * @param string[] $user_types   User type filter.
	 * @param int      $offset       Pagination offset within the current plugin.
	 * @param int      $count        Page size (max 50).
	 * @param int      $plugin_index Index into plugin list (only used when plugin_id is empty).
	 */
	public function list_freemius_users( string $plugin_id, array $user_types, int $offset, int $count, int $plugin_index ) {
		$plugin_configs = $this->get_plugin_configs();

		$paid_only = array( 'paid' ) === $user_types;
		$free_only = array( 'free' ) === $user_types;

		if ( ! empty( $plugin_id ) ) {
			if ( ! isset( $plugin_configs[ $plugin_id ] ) ) {
				return new \WP_Error( 'freemkit_sync_error', __( 'Plugin ID not found in settings.', 'freemkit' ) );
			}

			$config = $plugin_configs[ $plugin_id ];
			$client = new Freemius_API_Client( $plugin_id, $config['public_key'], $config['secret_key'] );

			if ( $paid_only ) {
				$result = $client->get_users( $offset, $count, 'paid' );
			} elseif ( $free_only ) {
				$result = $client->get_users( $offset, $count, 'never_paid' );
			} else {
				$result = $client->get_users( $offset, $count );
			}

			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'freemkit_sync_error', $result->get_error_message() );
			}

			$task_type = $paid_only ? 'paid' : '';
			$tasks     = $this->build_freemius_tasks( $result['users'], $plugin_id, $config, $task_type );
			$raw_count = count( $result['users'] );

			return array(
				'tasks'             => $tasks,
				'total'             => 0,
				'offset'            => $offset + $raw_count,
				'next_plugin_index' => 0,
				'has_more'          => $result['has_more'],
			);
		}

		// All plugins: iterate by index, advancing to the next plugin when pages are exhausted.
		if ( empty( $plugin_configs ) ) {
			return new \WP_Error( 'freemkit_sync_error', __( 'No plugins configured.', 'freemkit' ) );
		}

		$plugin_ids = array_keys( $plugin_configs );

		if ( ! isset( $plugin_ids[ $plugin_index ] ) ) {
			return array(
				'tasks'             => array(),
				'total'             => 0,
				'offset'            => 0,
				'next_plugin_index' => $plugin_index,
				'has_more'          => false,
			);
		}

		$current_id = $plugin_ids[ $plugin_index ];
		$config     = $plugin_configs[ $current_id ];
		$client     = new Freemius_API_Client( $current_id, $config['public_key'], $config['secret_key'] );

		if ( $paid_only ) {
			$result = $client->get_users( $offset, $count, 'paid' );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'freemkit_sync_error',
					sprintf(
							/* translators: 1: plugin ID 2: error message */
						__( 'Error fetching paid users for plugin %1$s: %2$s', 'freemkit' ),
						$current_id,
						$result->get_error_message()
					)
				);
			}
			$tasks     = $this->build_freemius_tasks( $result['users'], $current_id, $config, 'paid' );
			$raw_count = count( $result['users'] );
		} elseif ( $free_only ) {
			$result = $client->get_users( $offset, $count, 'never_paid' );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'freemkit_sync_error',
					sprintf(
							/* translators: 1: plugin ID 2: error message */
						__( 'Error fetching free users for plugin %1$s: %2$s', 'freemkit' ),
						$current_id,
						$result->get_error_message()
					)
				);
			}
			$tasks     = $this->build_freemius_tasks( $result['users'], $current_id, $config );
			$raw_count = count( $result['users'] );
		} else {
			$result = $client->get_users( $offset, $count );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'freemkit_sync_error',
					sprintf(
							/* translators: 1: plugin ID 2: error message */
						__( 'Error fetching users for plugin %1$s: %2$s', 'freemkit' ),
						$current_id,
						$result->get_error_message()
					)
				);
			}
			$tasks     = $this->build_freemius_tasks( $result['users'], $current_id, $config );
			$raw_count = count( $result['users'] );
		}

		$page_has_more = $result['has_more'];

		if ( $page_has_more ) {
			// More pages remain for this plugin.
			$next_offset       = $offset + $raw_count;
			$next_plugin_index = $plugin_index;
			$has_more          = true;
		} elseif ( isset( $plugin_ids[ $plugin_index + 1 ] ) ) {
			// This plugin exhausted; move to the next one.
			$next_offset       = 0;
			$next_plugin_index = $plugin_index + 1;
			$has_more          = true;
		} else {
			// Last plugin and last page.
			$next_offset       = 0;
			$next_plugin_index = $plugin_index;
			$has_more          = false;
		}

		return array(
			'tasks'             => $tasks,
			'total'             => 0,
			'offset'            => $next_offset,
			'next_plugin_index' => $next_plugin_index,
			'has_more'          => $has_more,
		);
	}

	/**
	 * Convert raw Freemius user data into task objects.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $raw_users        Raw user arrays from the Freemius API.
	 * @param string $plugin_id        Freemius product ID.
	 * @param array  $config           Plugin config row.
	 * @param string $default_user_type Optional default user type when the API response lacks payment flags.
	 * @return array[]
	 */
	public function build_freemius_tasks( array $raw_users, string $plugin_id, array $config, string $default_user_type = '' ): array {
		$tasks = array();

		foreach ( $raw_users as $user ) {
			if ( ! is_array( $user ) ) {
				continue;
			}

			$user_type = Freemius_API_Client::get_user_type( $user );
			if ( '' !== $default_user_type && 'free' === $user_type ) {
				$user_type = $default_user_type;
			}

			$email = isset( $user['email'] ) ? sanitize_email( (string) $user['email'] ) : '';
			if ( empty( $email ) ) {
				continue;
			}

			$first = isset( $user['first'] ) ? sanitize_text_field( (string) $user['first'] ) : '';
			$last  = isset( $user['last'] ) ? sanitize_text_field( (string) $user['last'] ) : '';

			if ( 0 === strcasecmp( $first, 'Admin' ) ) {
				$first = '';
			}
			if ( 0 === strcasecmp( $last, 'Admin' ) ) {
				$last = '';
			}

			$tasks[] = array(
				'source'           => 'freemius',
				'email'            => $email,
				'first_name'       => $first,
				'last_name'        => $last,
				'user_type'        => $user_type,
				'plugin_id'        => $plugin_id,
				'plugin_name'      => $config['name'],
				'plugin_slug'      => $config['slug'],
				'freemius_user_id' => isset( $user['id'] ) ? (int) $user['id'] : 0,
				'freemius_created' => isset( $user['created'] ) ? sanitize_text_field( (string) $user['created'] ) : '',
				'marketing'        => ! empty( $user['is_marketing_allowed'] ) ? 1 : 0,
				'is_verified'      => ! empty( $user['is_verified'] ) ? 1 : 0,
				'email_status'     => isset( $user['email_status'] ) ? sanitize_text_field( (string) $user['email_status'] ) : '',
				'meta'             => array(),
			);
		}

		return $tasks;
	}

	/**
	 * Fetch a page of local DB subscribers for the list step.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $plugin_id  Filter by plugin ID (empty = all).
	 * @param string[] $user_types User type filter.
	 * @param int      $offset     Pagination offset.
	 * @param int      $count      Page size.
	 */
	public function list_local_users( string $plugin_id, array $user_types, int $offset, int $count ) {
		$result = $this->query_local_subscribers( $plugin_id, $user_types, $offset, $count );
		$rows   = $result['rows'];
		$total  = $result['total'];

		$tasks = array();
		foreach ( $rows as $row ) {
			$tasks[] = array(
				'source'           => 'local',
				'email'            => sanitize_email( (string) $row->email ),
				'first_name'       => sanitize_text_field( (string) $row->first_name ),
				'last_name'        => sanitize_text_field( (string) $row->last_name ),
				'user_type'        => sanitize_key( (string) $row->user_type ),
				'plugin_id'        => sanitize_text_field( (string) $row->plugin_id ),
				'plugin_slug'      => sanitize_text_field( (string) $row->plugin_slug ),
				'freemius_user_id' => (int) $row->freemius_user_id,
				'marketing'        => (int) $row->marketing,
			);
		}

		return array(
			'tasks'             => $tasks,
			'total'             => $total,
			'offset'            => $offset + count( $rows ),
			'next_plugin_index' => 0,
			'has_more'          => count( $rows ) === $count,
		);
	}

	/**
	 * Process a single sync task and return a result row.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_task Raw task data.
	 * @return array Result row.
	 */
	public function process_single_task( array $raw_task ): array {
		$task_meta = isset( $raw_task['meta'] ) && is_array( $raw_task['meta'] ) ? array_map( 'sanitize_text_field', $raw_task['meta'] ) : array();
		$task      = $this->sanitize_task_scalars( $raw_task );

		$email       = sanitize_email( $task['email'] ?? '' );
		$first       = sanitize_text_field( $task['first_name'] ?? '' );
		$last        = sanitize_text_field( $task['last_name'] ?? '' );
		$user_type   = sanitize_key( $task['user_type'] ?? 'free' );
		$plugin_id   = sanitize_text_field( $task['plugin_id'] ?? '' );
		$plugin_name = sanitize_text_field( $task['plugin_name'] ?? $plugin_id );
		$slug        = sanitize_text_field( $task['plugin_slug'] ?? '' );
		$fs_uid      = (int) ( $task['freemius_user_id'] ?? 0 );
		$source      = sanitize_key( $task['source'] ?? 'local' );
		$destination = sanitize_key( $task['destination'] ?? 'both' );

		$override_form_ids  = wp_parse_list( $task['override_form_ids'] ?? '' );
		$override_tag_ids   = wp_parse_list( $task['override_tag_ids'] ?? '' );
		$allowed_user_types = array( 'free', 'paid', 'trial' );
		$user_types_raw     = isset( $raw_task['user_types'] ) && is_array( $raw_task['user_types'] )
			? $raw_task['user_types']
			: array();
		$user_types_filter  = array_values( array_intersect( array_map( 'sanitize_key', $user_types_raw ), $allowed_user_types ) );

		if ( ! empty( $user_types_filter ) && ! in_array( $user_type, $user_types_filter, true ) ) {
			return array(
				'action'      => 'skipped',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
			);
		}

		if ( empty( $email ) ) {
			return array(
				'action'      => 'error',
				'email'       => '',
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => '',
				'error'       => __( 'Invalid or empty email address.', 'freemkit' ),
			);
		}

		// Opted-out subscribers: still record/update them locally, but skip the Kit push.
		$respect_optout = (bool) Options_API::get_option( 'respect_marketing_optout' );
		if ( $respect_optout ) {
			$existing = $this->database->get_subscriber_by_email( $email );
			if ( ! is_wp_error( $existing ) && empty( $existing->marketing ) ) {
				$subscriber = new Subscriber(
					array(
						'email'            => $email,
						'first_name'       => $first,
						'last_name'        => $last,
						'marketing'        => 0,
						'freemius_user_id' => $fs_uid,
						'freemius_created' => sanitize_text_field( $task['freemius_created'] ?? '' ),
						'is_verified'      => isset( $task['is_verified'] ) ? (int) $task['is_verified'] : 0,
						'email_status'     => isset( $task['email_status'] ) ? sanitize_text_field( $task['email_status'] ) : '',
						'meta'             => $task_meta,
					)
				);
				$this->database->upsert_subscriber_by_email( $subscriber );

				return array(
					'action'      => 'opted_out',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => __( 'Saved locally; not synced to Kit — subscriber has opted out of marketing.', 'freemkit' ),
				);
			}
		}

		/**
		 * Filter the subscriber data before syncing.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $subscriber_data { email, first_name, last_name, user_type }.
		 * @param string $plugin_id       Freemius plugin ID.
		 * @param string $source          'freemius' or 'local'.
		 */
		$subscriber_data = apply_filters(
			'freemkit_sync_subscriber_data',
			array(
				'email'      => $email,
				'first_name' => $first,
				'last_name'  => $last,
				'user_type'  => $user_type,
			),
			$plugin_id,
			$source
		);

		$email     = sanitize_email( $subscriber_data['email'] );
		$first     = sanitize_text_field( $subscriber_data['first_name'] );
		$last      = sanitize_text_field( $subscriber_data['last_name'] );
		$user_type = sanitize_key( $subscriber_data['user_type'] );

		// Check if subscriber already exists to set action label later.
		$was_existing = ! is_wp_error( $this->database->get_subscriber_by_email( $email ) );

		$freemius_created = sanitize_text_field( $task['freemius_created'] ?? '' );
		$marketing        = isset( $task['marketing'] ) ? (int) $task['marketing'] : 1;
		$is_verified      = isset( $task['is_verified'] ) ? (int) $task['is_verified'] : 0;
		$email_status     = isset( $task['email_status'] ) ? sanitize_text_field( $task['email_status'] ) : '';
		$meta             = $task_meta;

		// -----------------------------------------------------------------------
		// Local-only destination: save to DB, skip Kit entirely.
		// -----------------------------------------------------------------------
		if ( 'local' === $destination ) {
			$subscriber = new Subscriber(
				array(
					'email'            => $email,
					'first_name'       => $first,
					'last_name'        => $last,
					'marketing'        => $marketing,
					'freemius_user_id' => $fs_uid,
					'freemius_created' => $freemius_created,
					'is_verified'      => $is_verified,
					'email_status'     => $email_status,
					'meta'             => $meta,
				)
			);

			$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
			if ( is_wp_error( $db_result ) ) {
				return array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'destination' => $destination,
					'forms'       => '',
					'error'       => $db_result->get_error_message(),
				);
			}

			$event = new Subscriber_Event(
				array(
					'subscriber_id'    => $db_result,
					'plugin_id'        => $plugin_id,
					'plugin_slug'      => $slug,
					'event_type'       => 'sync.local_import',
					'user_type'        => $user_type,
					'form_ids'         => '',
					'tag_ids'          => '',
					'freemius_user_id' => $fs_uid,
				)
			);

			$this->database->add_subscriber_event( $event );
			$action = $was_existing ? 'updated' : 'synced';

			do_action( 'freemkit_sync_user_complete', $email, $user_type, $plugin_id, $source, $action );

			return array(
				'action'      => $action,
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => '',
				'error'       => '',
			);
		}

		// -----------------------------------------------------------------------
		// Kit or Both destination: resolve forms/tags and push to Kit.
		// -----------------------------------------------------------------------
		$plugin_configs = $this->get_plugin_configs();
		$plugin_config  = isset( $plugin_configs[ $plugin_id ] ) ? $plugin_configs[ $plugin_id ] : array();

		$global_form_id = Options_API::get_option( 'kit_form_id' );
		$global_tag_id  = Options_API::get_option( 'kit_tag_id' );

		// Trial falls back to free forms/tags.
		$type_key = ( 'paid' === $user_type ) ? 'paid' : 'free';

		if ( ! empty( $override_form_ids ) ) {
			$active_form_ids = $override_form_ids;
		} elseif ( ! empty( $plugin_config[ $type_key . '_form_ids' ] ) ) {
			$active_form_ids = wp_parse_list( $plugin_config[ $type_key . '_form_ids' ] );
		} else {
			$active_form_ids = wp_parse_list( $global_form_id );
		}

		if ( ! empty( $override_tag_ids ) ) {
			$active_tag_ids = $override_tag_ids;
		} elseif ( ! empty( $plugin_config[ $type_key . '_tag_ids' ] ) ) {
			$active_tag_ids = wp_parse_list( $plugin_config[ $type_key . '_tag_ids' ] );
		} else {
			$active_tag_ids = wp_parse_list( $global_tag_id );
		}

		if ( empty( $active_form_ids ) ) {
			return array(
				'action'      => 'error',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => '',
				'error'       => __( 'No Kit forms configured for this user type.', 'freemkit' ),
			);
		}

		// marketing=0: write to local DB to record the subscriber, but skip Kit — unless
		// the admin has disabled opt-out enforcement, in which case Kit sync proceeds below.
		if ( 0 === $marketing && $respect_optout ) {
			$subscriber = new Subscriber(
				array(
					'email'            => $email,
					'first_name'       => $first,
					'last_name'        => $last,
					'marketing'        => 0,
					'freemius_user_id' => $fs_uid,
					'freemius_created' => $freemius_created,
					'is_verified'      => $is_verified,
					'email_status'     => $email_status,
					'meta'             => $meta,
				)
			);
			$this->database->upsert_subscriber_by_email( $subscriber );
			return array(
				'action'      => 'opted_out',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => '',
				'error'       => __( 'Saved locally; not synced to Kit — subscriber has opted out of marketing.', 'freemkit' ),
			);
		}

		$api        = new Kit_API();
		$api_result = null;
		foreach ( $active_form_ids as $form_id ) {
			if ( empty( $form_id ) ) {
				continue;
			}
			$api_result = $api->subscribe_to_form( (int) $form_id, $email, $first, array(), $active_tag_ids );
			if ( is_wp_error( $api_result ) ) {
				break;
			}
		}

		if ( is_wp_error( $api_result ) ) {
			return array(
				'action'      => 'error',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => implode( ', ', $active_form_ids ),
				'error'       => $api_result->get_error_message(),
			);
		}

		$subscriber = new Subscriber(
			array(
				'email'            => $email,
				'first_name'       => $first,
				'last_name'        => $last,
				'marketing'        => $marketing,
				'freemius_user_id' => $fs_uid,
				'freemius_created' => $freemius_created,
				'is_verified'      => $is_verified,
				'email_status'     => $email_status,
				'meta'             => $meta,
			)
		);

		$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
		if ( is_wp_error( $db_result ) ) {
			return array(
				'action'      => 'error',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => implode( ', ', $active_form_ids ),
				'error'       => $db_result->get_error_message(),
			);
		}

		$event_type = ( 'local' === $source ) ? 'sync.resynced' : 'sync.imported';
		$event      = new Subscriber_Event(
			array(
				'subscriber_id'    => $db_result,
				'plugin_id'        => $plugin_id,
				'plugin_slug'      => $slug,
				'event_type'       => $event_type,
				'user_type'        => $user_type,
				'form_ids'         => implode( ',', $active_form_ids ),
				'tag_ids'          => implode( ',', $active_tag_ids ),
				'freemius_user_id' => $fs_uid,
			)
		);

		$this->database->add_subscriber_event( $event );
		$action = $was_existing ? 'updated' : 'synced';

		/**
		 * Fires after a subscriber is successfully synced.
		 *
		 * @since 1.0.0
		 *
		 * @param string $email       Subscriber email.
		 * @param string $user_type   User type (free|paid|trial).
		 * @param string $plugin_id   Freemius plugin ID.
		 * @param string $source      'freemius' or 'local'.
		 * @param string $action      'synced' or 'updated'.
		 */
		do_action( 'freemkit_sync_user_complete', $email, $user_type, $plugin_id, $source, $action );

		return array(
			'action'      => $action,
			'email'       => $email,
			'first_name'  => $first,
			'last_name'   => $last,
			'user_type'   => $user_type,
			'plugin_name' => $plugin_name,
			'destination' => $destination,
			'forms'       => implode( ', ', $active_form_ids ),
			'error'       => '',
		);
	}

	/**
	 * Process a batch of sync tasks using Kit's bulk API.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $raw_tasks Raw task data.
	 * @return array<int, array<string, mixed>> Result rows.
	 */
	public function process_bulk_tasks( array $raw_tasks ): array {
		$results   = array();
		$kit_tasks = array();
		$task_meta = array();

		$plugin_configs = $this->get_plugin_configs();
		$global_form_id = Options_API::get_option( 'kit_form_id' );
		$global_tag_id  = Options_API::get_option( 'kit_tag_id' );
		$respect_optout = (bool) Options_API::get_option( 'respect_marketing_optout' );
		$allowed_types  = array( 'free', 'paid', 'trial' );

		foreach ( $raw_tasks as $raw_task ) {
			$task_meta_data = isset( $raw_task['meta'] ) && is_array( $raw_task['meta'] ) ? array_map( 'sanitize_text_field', $raw_task['meta'] ) : array();
			$task           = $this->sanitize_task_scalars( $raw_task );

			$email       = sanitize_email( $task['email'] ?? '' );
			$first       = sanitize_text_field( $task['first_name'] ?? '' );
			$last        = sanitize_text_field( $task['last_name'] ?? '' );
			$user_type   = sanitize_key( $task['user_type'] ?? 'free' );
			$plugin_id   = sanitize_text_field( $task['plugin_id'] ?? '' );
			$plugin_name = sanitize_text_field( $task['plugin_name'] ?? $plugin_id );
			$slug        = sanitize_text_field( $task['plugin_slug'] ?? '' );
			$fs_uid      = (int) ( $task['freemius_user_id'] ?? 0 );
			$source      = sanitize_key( $task['source'] ?? 'local' );
			$destination = sanitize_key( $task['destination'] ?? 'both' );
			$marketing   = isset( $task['marketing'] ) ? (int) $task['marketing'] : 1;

			$override_form_ids = wp_parse_list( $task['override_form_ids'] ?? '' );
			$override_tag_ids  = wp_parse_list( $task['override_tag_ids'] ?? '' );
			$user_types_raw    = isset( $raw_task['user_types'] ) && is_array( $raw_task['user_types'] )
				? $raw_task['user_types']
				: array();
			$user_types_filter = array_values( array_intersect( array_map( 'sanitize_key', $user_types_raw ), $allowed_types ) );

			// User type filter.
			if ( ! empty( $user_types_filter ) && ! in_array( $user_type, $user_types_filter, true ) ) {
				$results[] = array(
					'action'      => 'skipped',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => '',
				);
				continue;
			}

			// Email check.
			if ( empty( $email ) ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => '',
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => __( 'Invalid or empty email address.', 'freemkit' ),
				);
				continue;
			}

			// Opted-out subscribers: still record/update them locally, but skip the Kit push.
			if ( $respect_optout ) {
				$existing = $this->database->get_subscriber_by_email( $email );
				if ( ! is_wp_error( $existing ) && empty( $existing->marketing ) ) {
					$subscriber = new Subscriber(
						array(
							'email'            => $email,
							'first_name'       => $first,
							'last_name'        => $last,
							'marketing'        => 0,
							'freemius_user_id' => $fs_uid,
							'freemius_created' => sanitize_text_field( $task['freemius_created'] ?? '' ),
							'is_verified'      => isset( $task['is_verified'] ) ? (int) $task['is_verified'] : 0,
							'email_status'     => isset( $task['email_status'] ) ? sanitize_text_field( $task['email_status'] ) : '',
							'meta'             => $task_meta_data,
						)
					);
					$this->database->upsert_subscriber_by_email( $subscriber );

					$results[] = array(
						'action'      => 'opted_out',
						'email'       => $email,
						'first_name'  => $first,
						'last_name'   => $last,
						'user_type'   => $user_type,
						'plugin_name' => $plugin_name,
						'destination' => $destination,
						'forms'       => '',
						'error'       => __( 'Saved locally; not synced to Kit — subscriber has opted out of marketing.', 'freemkit' ),
					);
					continue;
				}
			}

			// marketing=0: write to local DB to record the subscriber, but skip Kit — unless
			// the admin has disabled opt-out enforcement, in which case Kit sync proceeds below.
			if ( 0 === $marketing && $respect_optout ) {
				$subscriber = new Subscriber(
					array(
						'email'            => $email,
						'first_name'       => $first,
						'last_name'        => $last,
						'marketing'        => 0,
						'freemius_user_id' => $fs_uid,
						'freemius_created' => sanitize_text_field( $task['freemius_created'] ?? '' ),
						'is_verified'      => isset( $task['is_verified'] ) ? (int) $task['is_verified'] : 0,
						'email_status'     => isset( $task['email_status'] ) ? sanitize_text_field( $task['email_status'] ) : '',
						'meta'             => $task_meta_data,
					)
				);
				$this->database->upsert_subscriber_by_email( $subscriber );
				$results[] = array(
					'action'      => 'opted_out',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => __( 'Saved locally; not synced to Kit — subscriber has opted out of marketing.', 'freemkit' ),
				);
				continue;
			}

			/**
			 * Filter the subscriber data before syncing.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $subscriber_data { email, first_name, last_name, user_type }.
			 * @param string $plugin_id       Freemius plugin ID.
			 * @param string $source          'freemius' or 'local'.
			 */
			$subscriber_data = apply_filters(
				'freemkit_sync_subscriber_data',
				array(
					'email'      => $email,
					'first_name' => $first,
					'last_name'  => $last,
					'user_type'  => $user_type,
				),
				$plugin_id,
				$source
			);

			$email     = sanitize_email( $subscriber_data['email'] );
			$first     = sanitize_text_field( $subscriber_data['first_name'] );
			$last      = sanitize_text_field( $subscriber_data['last_name'] );
			$user_type = sanitize_key( $subscriber_data['user_type'] );

			// Resolve forms/tags.
			$plugin_config = isset( $plugin_configs[ $plugin_id ] ) ? $plugin_configs[ $plugin_id ] : array();
			$type_key      = ( 'paid' === $user_type ) ? 'paid' : 'free';

			if ( ! empty( $override_form_ids ) ) {
				$active_form_ids = $override_form_ids;
			} elseif ( ! empty( $plugin_config[ $type_key . '_form_ids' ] ) ) {
				$active_form_ids = wp_parse_list( $plugin_config[ $type_key . '_form_ids' ] );
			} else {
				$active_form_ids = wp_parse_list( $global_form_id );
			}

			if ( ! empty( $override_tag_ids ) ) {
				$active_tag_ids = $override_tag_ids;
			} elseif ( ! empty( $plugin_config[ $type_key . '_tag_ids' ] ) ) {
				$active_tag_ids = wp_parse_list( $plugin_config[ $type_key . '_tag_ids' ] );
			} else {
				$active_tag_ids = wp_parse_list( $global_tag_id );
			}

			if ( empty( $active_form_ids ) ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => __( 'No Kit forms configured for this user type.', 'freemkit' ),
				);
				continue;
			}

			// Store for bulk processing.
			$freemius_created = sanitize_text_field( $task['freemius_created'] ?? '' );
			$is_verified      = isset( $task['is_verified'] ) ? (int) $task['is_verified'] : 0;
			$email_status     = isset( $task['email_status'] ) ? sanitize_text_field( $task['email_status'] ) : '';
			$meta             = $task_meta_data;

			$kit_tasks[] = array(
				'email'      => $email,
				'first_name' => $first,
				'form_ids'   => array_values( array_filter( array_map( 'intval', $active_form_ids ) ) ),
				'tag_ids'    => array_values( array_filter( array_map( 'intval', $active_tag_ids ) ) ),
			);

			$task_meta[ $email ] = array(
				'email'            => $email,
				'first_name'       => $first,
				'last_name'        => $last,
				'user_type'        => $user_type,
				'plugin_id'        => $plugin_id,
				'plugin_name'      => $plugin_name,
				'plugin_slug'      => $slug,
				'freemius_user_id' => $fs_uid,
				'source'           => $source,
				'destination'      => $destination,
				'marketing'        => $marketing,
				'freemius_created' => $freemius_created,
				'is_verified'      => $is_verified,
				'email_status'     => $email_status,
				'meta'             => $meta,
				'form_ids'         => $active_form_ids,
				'tag_ids'          => $active_tag_ids,
			);
		}

		if ( empty( $kit_tasks ) ) {
			return $results;
		}

		// Bulk subscribe.
		$api          = new Kit_API();
		$bulk_results = $api->bulk_subscribe_to_kit( $kit_tasks );
		if ( is_wp_error( $bulk_results ) ) {
			$error_msg = $bulk_results->get_error_message();
			foreach ( $task_meta as $email => $meta ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $meta['first_name'],
					'last_name'   => $meta['last_name'],
					'user_type'   => $meta['user_type'],
					'plugin_name' => $meta['plugin_name'],
					'destination' => $meta['destination'],
					'forms'       => implode( ', ', $meta['form_ids'] ),
					'error'       => $error_msg,
				);
			}
			return $results;
		}

		// Process each result.
		foreach ( $task_meta as $email => $meta ) {
			if ( ! isset( $bulk_results[ $email ] ) ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $meta['first_name'],
					'last_name'   => $meta['last_name'],
					'user_type'   => $meta['user_type'],
					'plugin_name' => $meta['plugin_name'],
					'destination' => $meta['destination'],
					'forms'       => implode( ', ', $meta['form_ids'] ),
					'error'       => __( 'No response for this subscriber from Kit.', 'freemkit' ),
				);
				continue;
			}

			$bulk_result = $bulk_results[ $email ];
			if ( 'error' === $bulk_result['status'] ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $meta['first_name'],
					'last_name'   => $meta['last_name'],
					'user_type'   => $meta['user_type'],
					'plugin_name' => $meta['plugin_name'],
					'destination' => $meta['destination'],
					'forms'       => implode( ', ', $meta['form_ids'] ),
					'error'       => $bulk_result['error'] ?? __( 'Unknown Kit error.', 'freemkit' ),
				);
				continue;
			}

			// Success: save to DB.
			$was_existing = ! is_wp_error( $this->database->get_subscriber_by_email( $email ) );

			$subscriber = new Subscriber(
				array(
					'email'            => $email,
					'first_name'       => $meta['first_name'],
					'last_name'        => $meta['last_name'],
					'marketing'        => $meta['marketing'],
					'freemius_user_id' => $meta['freemius_user_id'],
					'freemius_created' => $meta['freemius_created'],
					'is_verified'      => $meta['is_verified'],
					'email_status'     => $meta['email_status'],
					'meta'             => $meta['meta'],
				)
			);

			$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
			if ( is_wp_error( $db_result ) ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $meta['first_name'],
					'last_name'   => $meta['last_name'],
					'user_type'   => $meta['user_type'],
					'plugin_name' => $meta['plugin_name'],
					'destination' => $meta['destination'],
					'forms'       => implode( ', ', $meta['form_ids'] ),
					'error'       => $db_result->get_error_message(),
				);
				continue;
			}

			$event_type = ( 'local' === $meta['source'] ) ? 'sync.resynced' : 'sync.imported';
			$event      = new Subscriber_Event(
				array(
					'subscriber_id'    => $db_result,
					'plugin_id'        => $meta['plugin_id'],
					'plugin_slug'      => $meta['plugin_slug'],
					'event_type'       => $event_type,
					'user_type'        => $meta['user_type'],
					'form_ids'         => implode( ',', $meta['form_ids'] ),
					'tag_ids'          => implode( ',', $meta['tag_ids'] ),
					'freemius_user_id' => $meta['freemius_user_id'],
				)
			);

			$this->database->add_subscriber_event( $event );
			$action = $was_existing ? 'updated' : 'synced';

			/**
			 * Fires after a subscriber is successfully synced.
			 *
			 * @since 1.0.0
			 *
			 * @param string $email       Subscriber email.
			 * @param string $user_type   User type (free|paid|trial).
			 * @param string $plugin_id   Freemius plugin ID.
			 * @param string $source      'freemius' or 'local'.
			 * @param string $action      'synced' or 'updated'.
			 */
			do_action( 'freemkit_sync_user_complete', $email, $meta['user_type'], $meta['plugin_id'], $meta['source'], $action );

			$results[] = array(
				'action'      => $action,
				'email'       => $email,
				'first_name'  => $meta['first_name'],
				'last_name'   => $meta['last_name'],
				'user_type'   => $meta['user_type'],
				'plugin_name' => $meta['plugin_name'],
				'destination' => $meta['destination'],
				'forms'       => implode( ', ', $meta['form_ids'] ),
				'error'       => '',
			);
		}

		return $results;
	}

	/**
	 * Query local subscribers with their most-recent event metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $plugin_id  Filter by plugin ID, or empty for all.
	 * @param string[] $user_types Filter by user type array.
	 * @param int      $offset     Pagination offset.
	 * @param int      $count      Page size.
	 * @return array{ rows: object[], total: int }
	 */
	public function query_local_subscribers( string $plugin_id, array $user_types, int $offset, int $count ): array {
		global $wpdb;

		$subs_table   = $this->database->get_table_name();
		$events_table = $this->database->get_events_table_name();

		$join_values  = array();
		$where_values = array();
		$where_parts  = array( '1=1' );

		if ( ! empty( $plugin_id ) ) {
			$join_sql       = "INNER JOIN (
				SELECT subscriber_id, MAX(id) AS max_id
				FROM {$events_table}
				WHERE plugin_id = %s
				GROUP BY subscriber_id
			) latest ON latest.subscriber_id = s.id
			INNER JOIN {$events_table} le ON le.id = latest.max_id";
			$join_values[]  = $plugin_id;
			$user_type_expr = 'le.user_type';
			$extra_selects  = 'le.user_type AS user_type, le.plugin_id AS plugin_id, le.plugin_slug AS plugin_slug, le.freemius_user_id AS freemius_user_id';
		} else {
			$join_sql       = "LEFT JOIN (
				SELECT subscriber_id, MAX(id) AS max_id
				FROM {$events_table}
				GROUP BY subscriber_id
			) latest ON latest.subscriber_id = s.id
			LEFT JOIN {$events_table} le ON le.id = latest.max_id";
			$user_type_expr = "COALESCE(le.user_type, 'free')";
			$extra_selects  = "COALESCE(le.user_type, 'free') AS user_type, COALESCE(le.plugin_id, '') AS plugin_id, COALESCE(le.plugin_slug, '') AS plugin_slug, COALESCE(le.freemius_user_id, 0) AS freemius_user_id";
		}

		if ( ! empty( $user_types ) ) {
			$placeholders  = implode( ', ', array_fill( 0, count( $user_types ), '%s' ) );
			$where_parts[] = "{$user_type_expr} IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_values  = array_merge( $where_values, $user_types );
		}

		$where_clause     = implode( ' AND ', $where_parts );
		$all_count_values = array_merge( $join_values, $where_values );
		$all_row_values   = array_merge( $join_values, $where_values, array( $count, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_sql = "SELECT COUNT(*) FROM {$subs_table} s {$join_sql} WHERE {$where_clause}";
		if ( ! empty( $all_count_values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $all_count_values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		$rows_sql = "SELECT s.id, s.email, s.first_name, s.last_name, s.marketing, {$extra_selects}
		             FROM {$subs_table} s {$join_sql}
		             WHERE {$where_clause}
		             ORDER BY s.id ASC
		             LIMIT %d OFFSET %d";
		$rows_sql = $wpdb->prepare( $rows_sql, $all_row_values );
		$rows     = $wpdb->get_results( $rows_sql );
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Sanitize scalar task fields, leaving arrays untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_task Raw task data.
	 * @return array
	 */
	private function sanitize_task_scalars( array $raw_task ): array {
		$task = array();

		foreach ( $raw_task as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}

			$task[ $key ] = sanitize_text_field( (string) $value );
		}

		return $task;
	}
}
