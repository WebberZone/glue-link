<?php
/**
 * Webhook Handler class
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit;

use WebberZone\FreemKit\Audit_Log;
use WebberZone\FreemKit\Kit\Kit_API;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook Handler class
 *
 * @since 1.0.0
 */
class Webhook_Handler {

	/**
	 * Cron hook for async webhook processing.
	 */
	private const PROCESS_HOOK = 'freemkit_process_webhook_event';

	/**
	 * Prefix for queued event transients.
	 */
	private const QUEUE_PREFIX = 'freemkit_webhook_queue_';

	/**
	 * Prefix for replay/deduplication transients.
	 */
	private const SEEN_PREFIX = 'freemkit_webhook_seen_';

	/**
	 * Plugin configurations.
	 *
	 * @var array
	 */
	public $plugin_configs;

	/**
	 * ConvertKit API instance.
	 *
	 * @var Kit_API
	 */
	public $api;

	/**
	 * Database instance.
	 *
	 * @var Database
	 */
	public $database;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $plugin_configs Plugin configurations array indexed by plugin ID.
	 * @param Kit_API  $api            ConvertKit API instance.
	 * @param Database $database       Database instance.
	 */
	public function __construct( array $plugin_configs, Kit_API $api, Database $database ) {
		$this->plugin_configs = $plugin_configs;
		$this->api            = $api;
		$this->database       = $database;

		$this->init();
	}

	/**
	 * Initialize the webhook handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init() {
		$endpoint_type = Options_API::get_option( 'webhook_endpoint_type', 'rest' );

		if ( 'rest' === $endpoint_type ) {
			add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
		} else {
			add_action( 'parse_request', array( $this, 'handle_query_var_webhook' ) );
		}

		add_action( self::PROCESS_HOOK, array( $this, 'process_queued_webhook' ), 10, 1 );
	}

	/**
	 * Register the webhook endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_webhook_endpoint() {
		register_rest_route(
			'freemkit/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'check_webhook_permissions' ),
			)
		);
	}

	/**
	 * Process webhook data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Raw webhook input data.
	 * @return array|\WP_Error Array of processed data or WP_Error on failure.
	 */
	public function process_webhook( string $input ) {
		$fs_event = json_decode( $input );
		if ( empty( $fs_event ) || empty( $fs_event->plugin_id ) ) {
			return new \WP_Error( 'invalid_request', __( 'Invalid request body or missing plugin ID', 'freemkit' ) );
		}

		$plugin_id = $fs_event->plugin_id;
		if ( ! isset( $this->plugin_configs[ $plugin_id ] ) ) {
			return new \WP_Error( 'invalid_plugin', __( 'Plugin ID not found in configuration', 'freemkit' ) );
		}

		if ( ! isset( $fs_event->objects ) || ! isset( $fs_event->objects->user ) ) {
			return new \WP_Error( 'invalid_data', __( 'Missing user data in request.', 'freemkit' ) );
		}

		$user = $fs_event->objects->user;
		if ( empty( $user->email ) || ! filter_var( $user->email, FILTER_VALIDATE_EMAIL ) ) {
			return new \WP_Error( 'invalid_email', __( 'Invalid or missing email address.', 'freemkit' ) );
		}

		$freemius_user_id = isset( $user->id ) ? (int) $user->id : 0;

		$email = sanitize_email( $user->email );

		// Strip "Admin" from first/last name to avoid creating dummy contacts.
		$first_name = isset( $user->first ) ? ( 0 === strcasecmp( $user->first, 'Admin' ) ? '' : sanitize_text_field( $user->first ) ) : '';
		$last_name  = isset( $user->last ) ? ( 0 === strcasecmp( $user->last, 'Admin' ) ? '' : sanitize_text_field( $user->last ) ) : '';

		$plugin_config = $this->plugin_configs[ $plugin_id ];
		$kit_form_id   = Options_API::get_option( 'kit_form_id' );
		$kit_tag_id    = Options_API::get_option( 'kit_tag_id' );

		$free_form_ids = $this->resolve_list_config( $plugin_config, 'free_form_ids', $kit_form_id );
		$paid_form_ids = $this->resolve_list_config( $plugin_config, 'paid_form_ids', $kit_form_id );
		$free_tag_ids  = $this->resolve_list_config( $plugin_config, 'free_tag_ids', $kit_tag_id );
		$paid_tag_ids  = $this->resolve_list_config( $plugin_config, 'paid_tag_ids', $kit_tag_id );

		$default_free_event_types = array( 'user.marketing.opted_in' );
		$default_paid_event_types = array( 'license.created' );

		$free_event_types = $this->resolve_list_config( $plugin_config, 'free_event_types', '', apply_filters( 'freemkit_default_free_event_types', $default_free_event_types, $plugin_config ) );
		$paid_event_types = $this->resolve_list_config( $plugin_config, 'paid_event_types', '', apply_filters( 'freemkit_default_paid_event_types', $default_paid_event_types, $plugin_config ) );
		$free_event_types = Freemius::normalize_event_types( $free_event_types );
		$paid_event_types = Freemius::normalize_event_types( $paid_event_types );

		if ( ! isset( $fs_event->type ) ) {
			return new \WP_Error( 'invalid_event', __( 'Missing event type in request.', 'freemkit' ) );
		}

		$event_type               = Freemius::normalize_event_type( (string) $fs_event->type );
		$respect_marketing_optout = (bool) Options_API::get_option( 'respect_marketing_optout' );

		$raw_unsubscribe_events  = Options_API::get_option( 'unsubscribe_event_types', 'user.marketing.opted_out' );
		$unsubscribe_event_list  = wp_parse_list( $raw_unsubscribe_events );
		$unsubscribe_event_types = Freemius::normalize_event_types( ! empty( $unsubscribe_event_list ) ? $unsubscribe_event_list : array( 'user.marketing.opted_out' ) );

		// Handle marketing opt-out / unsubscribe trigger events.
		if ( in_array( $event_type, $unsubscribe_event_types, true ) ) {
			return $this->process_marketing_optout( $email, $first_name, $last_name, $plugin_id, $plugin_config, $freemius_user_id, $respect_marketing_optout );
		}

		// Handle marketing opt-in / reset events.
		if ( 'user.marketing.opted_in' === $event_type ) {
			return $this->process_marketing_optin( $email, $first_name, $last_name, $plugin_id, $plugin_config, $freemius_user_id, $event_type );
		}

		// Handle name change events.
		if ( 'user.name.changed' === $event_type ) {
			return $this->process_name_change( $email, $first_name, $last_name, $plugin_id, $plugin_config, $freemius_user_id );
		}

		$user_type       = '';
		$active_form_ids = array();
		$active_tag_ids  = array();

		if ( in_array( $event_type, $free_event_types, true ) ) {
			$user_type       = 'free';
			$active_form_ids = $free_form_ids;
			$active_tag_ids  = $free_tag_ids;
		} elseif ( in_array( $event_type, $paid_event_types, true ) ) {
			$user_type       = 'paid';
			$active_form_ids = $paid_form_ids;
			$active_tag_ids  = $paid_tag_ids;
		} else {
			Audit_Log::add(
				'webhook_ignored',
				array(
					'event_type' => $event_type,
					'plugin_id'  => (string) $plugin_id,
				)
			);
			return array(
				'status'  => 'ignored',
				'message' => __( 'Event type not mapped; ignored.', 'freemkit' ),
			);
		}

		// Block subscription if the subscriber has opted out of marketing.
		if ( $respect_marketing_optout ) {
			$existing = $this->database->get_subscriber_by_email( $email );
			if ( ! is_wp_error( $existing ) && empty( $existing->marketing ) ) {
				// Safety: ensure they are unsubscribed from Kit.
				$this->api->unsubscribe_subscriber( $email );

				return array(
					'status'  => 'ignored',
					'message' => __( 'Subscriber has opted out of marketing; subscription blocked.', 'freemkit' ),
				);
			}
		}

		// Build Kit custom fields from settings mappings.
		// resolve_custom_field_key() converts legacy numeric IDs to string keys.
		$kit_fields      = array();
		$last_name_field = Options_API::get_option( 'last_name_field' );
		if ( $last_name_field && $last_name ) {
			$kit_fields[ $this->api->resolve_custom_field_key( $last_name_field ) ] = $last_name;
		}
		$custom_field_mappings = Options_API::get_option( 'custom_fields' );
		if ( is_array( $custom_field_mappings ) ) {
			foreach ( $custom_field_mappings as $mapping ) {
				// Repeater stores data under a nested 'fields' key.
				$data        = isset( $mapping['fields'] ) ? $mapping['fields'] : $mapping;
				$local_name  = $data['local_name'] ?? '';
				$remote_name = $data['remote_name'] ?? '';
				if ( $local_name && $remote_name && isset( $user->{$local_name} ) ) {
					$kit_fields[ $this->api->resolve_custom_field_key( $remote_name ) ] = sanitize_text_field( $user->{$local_name} );
				}
			}
		}

		$api_result = $this->subscribe_to_forms( $active_form_ids, $email, $first_name, $kit_fields, $active_tag_ids );

		if ( is_null( $api_result ) ) {
			Audit_Log::add(
				'webhook_no_forms',
				array(
					'event_type' => $event_type,
					'plugin_id'  => (string) $plugin_id,
					'email'      => $email,
				),
				'warning'
			);
			return new \WP_Error( 'no_forms', __( 'No Kit forms configured for this event type.', 'freemkit' ) );
		}

		if ( is_wp_error( $api_result ) ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[FreemKit] Kit API Error: %s', $api_result->get_error_message() ) );
			}
			Audit_Log::add(
				'webhook_kit_error',
				array(
					'event_type' => $event_type,
					'plugin_id'  => (string) $plugin_id,
					'email'      => $email,
					'error'      => $api_result->get_error_message(),
				),
				'error'
			);
			return new \WP_Error( 'api_error', __( 'Processed with API errors', 'freemkit' ) );
		}

		$subscriber = new Subscriber(
			array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);

		$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
		if ( is_wp_error( $db_result ) ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[FreemKit] Database Error: %s', $db_result->get_error_message() ) );
			}
			Audit_Log::add(
				'webhook_db_error',
				array(
					'event_type' => $event_type,
					'plugin_id'  => (string) $plugin_id,
					'error'      => $db_result->get_error_message(),
				),
				'error'
			);
			return new \WP_Error( 'db_error', __( 'Processed with database errors', 'freemkit' ) );
		}

		$event = new Subscriber_Event(
			array(
				'subscriber_id'    => $db_result,
				'plugin_id'        => (string) $plugin_id,
				'plugin_slug'      => $plugin_config['slug'],
				'event_type'       => $event_type,
				'user_type'        => $user_type,
				'form_ids'         => implode( ',', $active_form_ids ),
				'tag_ids'          => implode( ',', $active_tag_ids ),
				'freemius_user_id' => $freemius_user_id,
			)
		);

		$event_result = $this->database->add_subscriber_event( $event );
		if ( is_wp_error( $event_result ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[FreemKit] Event insert error: %s', $event_result->get_error_message() ) );
		}

		Audit_Log::add(
			'webhook_processed',
			array(
				'event_type' => $event_type,
				'plugin_id'  => (string) $plugin_id,
				'user_type'  => $user_type,
				'email'      => $email,
			)
		);

		return array(
			'status'  => 'success',
			'message' => __( 'Webhook processed successfully.', 'freemkit' ),
		);
	}

	/**
	 * Resolve a plugin configuration list with optional fallback and defaults.
	 *
	 * @param array              $plugin_config Plugin configuration.
	 * @param string             $key           Setting key.
	 * @param string|array|mixed $fallback      Fallback source value.
	 * @param array              $defaults      Default list when everything else is empty.
	 * @return array
	 */
	public function resolve_list_config( array $plugin_config, string $key, $fallback = '', array $defaults = array() ): array {
		$list = wp_parse_list( $plugin_config[ $key ] ?? '' );
		if ( empty( $list ) ) {
			$list = wp_parse_list( $fallback );
		}
		if ( empty( $list ) && ! empty( $defaults ) ) {
			$list = wp_parse_list( $defaults );
		}

		return $list;
	}

	/**
	 * Subscribe a user to each form in a list with optional tags.
	 *
	 * @param array  $form_ids   Form IDs.
	 * @param string $email      Subscriber email.
	 * @param string $first_name Subscriber first name.
	 * @param array  $fields     Custom fields.
	 * @param array  $tag_ids    Tag IDs.
	 * @return array|\WP_Error|null
	 */
	public function subscribe_to_forms( array $form_ids, string $email, string $first_name, array $fields, array $tag_ids ) {
		$result = null;

		foreach ( $form_ids as $form_id ) {
			if ( empty( $form_id ) ) {
				continue;
			}

			$result = $this->api->subscribe_to_form(
				(int) $form_id,
				$email,
				$first_name,
				$fields,
				$tag_ids
			);
			if ( is_wp_error( $result ) ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Process a marketing opt-out event.
	 *
	 * Records the opt-out in the database and unsubscribes from Kit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email                    Subscriber email.
	 * @param string $first_name               Subscriber first name.
	 * @param string $last_name                Subscriber last name.
	 * @param string $plugin_id                Freemius plugin ID.
	 * @param array  $plugin_config            Plugin configuration.
	 * @param int    $freemius_user_id         Freemius user ID.
	 * @param bool   $respect_marketing_optout Whether the setting is enabled.
	 * @return array|\WP_Error
	 */
	public function process_marketing_optout( string $email, string $first_name, string $last_name, string $plugin_id, array $plugin_config, int $freemius_user_id, bool $respect_marketing_optout ) {
		if ( ! $respect_marketing_optout ) {
			return array(
				'status'  => 'ignored',
				'message' => __( 'Marketing opt-out handling is disabled.', 'freemkit' ),
			);
		}

		// Unsubscribe from Kit.
		$unsubscribe_result = $this->api->unsubscribe_subscriber( $email );
		if ( is_wp_error( $unsubscribe_result ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[FreemKit] Kit unsubscribe error: %s', $unsubscribe_result->get_error_message() ) );
		}

		$subscriber = new Subscriber(
			array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'status'     => 'opted_out',
				'marketing'  => 0,
				'kit_status' => is_wp_error( $unsubscribe_result ) ? '' : 'cancelled',
			)
		);

		$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
		if ( is_wp_error( $db_result ) ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[FreemKit] Database Error during marketing opt-out: %s', $db_result->get_error_message() ) );
			}
			return new \WP_Error( 'db_error', __( 'Marketing opt-out recorded with database errors', 'freemkit' ) );
		}

		$event = new Subscriber_Event(
			array(
				'subscriber_id'    => $db_result,
				'plugin_id'        => (string) $plugin_id,
				'plugin_slug'      => $plugin_config['slug'],
				'event_type'       => 'user.marketing.opted_out',
				'user_type'        => 'opted_out',
				'form_ids'         => '',
				'tag_ids'          => '',
				'freemius_user_id' => $freemius_user_id,
			)
		);

		$event_result = $this->database->add_subscriber_event( $event );
		if ( is_wp_error( $event_result ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[FreemKit] Event insert error during marketing opt-out: %s', $event_result->get_error_message() ) );
		}

		return array(
			'status'  => 'success',
			'message' => __( 'Marketing opt-out processed; subscriber unsubscribed from Kit.', 'freemkit' ),
		);
	}

	/**
	 * Resolve the user type a subscriber was last recorded under.
	 *
	 * A marketing opt-in event carries no free/paid signal of its own, so the most
	 * recent event that did record one decides which forms and tags to restore.
	 *
	 * @since 1.0.0
	 *
	 * @param int $subscriber_id Local subscriber ID.
	 * @return string 'paid', 'free', or '' when nothing has been recorded yet.
	 */
	protected function resolve_last_user_type( int $subscriber_id ): string {
		$events = $this->database->get_subscriber_events(
			$subscriber_id,
			array(
				'per_page' => 50,
				'orderby'  => 'created',
				'order'    => 'DESC',
			)
		);

		if ( empty( $events ) ) {
			return '';
		}

		foreach ( $events as $event ) {
			$user_type = sanitize_key( $event->user_type );
			if ( 'paid' === $user_type || 'free' === $user_type ) {
				return $user_type;
			}
		}

		return '';
	}

	/**
	 * Process a marketing opt-in or reset event.
	 *
	 * Clears the opt-out flag, sets the subscriber status back to active, and restores
	 * the Kit form and tag membership that the matching opt-out removed.
	 *
	 * A subscriber who has already left Kit is not pulled back in — Kit's v4 API has no
	 * endpoint that reverses an unsubscribe, and that's treated as a decision to respect
	 * rather than an obstacle to work around. When the subscriber's Kit state is anything
	 * other than 'active', no Kit write is attempted; the opt-in is still recorded locally
	 * and logged as 'kit_reactivation_skipped' so the gap between Freemius and Kit stays
	 * visible, and re-subscribing is left to the subscriber via a Kit form.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email            Subscriber email.
	 * @param string $first_name       Subscriber first name.
	 * @param string $last_name        Subscriber last name.
	 * @param string $plugin_id        Freemius plugin ID.
	 * @param array  $plugin_config    Plugin configuration.
	 * @param int    $freemius_user_id Freemius user ID.
	 * @param string $event_type       Normalized event type.
	 * @return array|\WP_Error
	 */
	public function process_marketing_optin( string $email, string $first_name, string $last_name, string $plugin_id, array $plugin_config, int $freemius_user_id, string $event_type ) {
		// A subscriber who has left Kit stays gone — Kit offers no way to reverse an unsubscribe.
		$kit_state = $this->api->get_subscriber_state( $email );
		$has_left  = ! is_wp_error( $kit_state ) && '' !== $kit_state && 'active' !== $kit_state;

		$subscriber = new Subscriber(
			array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'status'     => $has_left ? 'kit_unsubscribed' : 'active',
				'marketing'  => 1,
				'kit_status' => ( ! is_wp_error( $kit_state ) ) ? $kit_state : '',
			)
		);

		$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
		if ( is_wp_error( $db_result ) ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[FreemKit] Database Error during marketing opt-in: %s', $db_result->get_error_message() ) );
			}
			return new \WP_Error( 'db_error', __( 'Marketing opt-in recorded with database errors', 'freemkit' ) );
		}

		// Restore the forms and tags for whichever type this subscriber was last seen as.
		$user_type = $this->resolve_last_user_type( (int) $db_result );
		$type_key  = ( 'paid' === $user_type ) ? 'paid' : 'free';

		$active_form_ids = $this->resolve_list_config( $plugin_config, $type_key . '_form_ids', Options_API::get_option( 'kit_form_id' ) );
		$active_tag_ids  = $this->resolve_list_config( $plugin_config, $type_key . '_tag_ids', Options_API::get_option( 'kit_tag_id' ) );

		$api_result = null;

		if ( $has_left ) {
			Audit_Log::add(
				'kit_reactivation_skipped',
				array(
					'event_type' => $event_type,
					'plugin_id'  => (string) $plugin_id,
					'email'      => $email,
					'kit_state'  => $kit_state,
				),
				'warning'
			);

			$active_form_ids = array();
			$active_tag_ids  = array();
		} else {
			$kit_fields      = array();
			$last_name_field = Options_API::get_option( 'last_name_field' );
			if ( $last_name_field && $last_name ) {
				$kit_fields[ $this->api->resolve_custom_field_key( $last_name_field ) ] = $last_name;
			}

			$api_result = $this->subscribe_to_forms( $active_form_ids, $email, $first_name, $kit_fields, $active_tag_ids );

			if ( is_wp_error( $api_result ) ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( '[FreemKit] Kit API Error during marketing opt-in: %s', $api_result->get_error_message() ) );
				}
				Audit_Log::add(
					'optin_kit_error',
					array(
						'event_type' => $event_type,
						'plugin_id'  => (string) $plugin_id,
						'email'      => $email,
						'error'      => $api_result->get_error_message(),
					),
					'error'
				);

				$active_form_ids = array();
				$active_tag_ids  = array();
			}
		}

		$event = new Subscriber_Event(
			array(
				'subscriber_id'    => $db_result,
				'plugin_id'        => (string) $plugin_id,
				'plugin_slug'      => $plugin_config['slug'],
				'event_type'       => $event_type,
				'user_type'        => $user_type,
				'form_ids'         => implode( ',', $active_form_ids ),
				'tag_ids'          => implode( ',', $active_tag_ids ),
				'freemius_user_id' => $freemius_user_id,
			)
		);

		$event_result = $this->database->add_subscriber_event( $event );
		if ( is_wp_error( $event_result ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[FreemKit] Event insert error during marketing opt-in: %s', $event_result->get_error_message() ) );
		}

		if ( is_wp_error( $api_result ) ) {
			return new \WP_Error( 'api_error', __( 'Marketing opt-in recorded locally, but the Kit update failed.', 'freemkit' ) );
		}

		if ( $has_left ) {
			return array(
				'status'  => 'success',
				'message' => __( 'Marketing opt-in recorded locally. The subscriber has unsubscribed in Kit and was not re-added; they can resubscribe themselves through a Kit form.', 'freemkit' ),
			);
		}

		return array(
			'status'  => 'success',
			'message' => __( 'Marketing opt-in processed; subscriber reactivated locally and Kit forms/tags restored.', 'freemkit' ),
		);
	}

	/**
	 * Process a user name change event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email            Subscriber email.
	 * @param string $first_name       Subscriber first name.
	 * @param string $last_name        Subscriber last name.
	 * @param string $plugin_id        Freemius plugin ID.
	 * @param array  $plugin_config    Plugin configuration.
	 * @param int    $freemius_user_id Freemius user ID.
	 * @return array|\WP_Error
	 */
	public function process_name_change( string $email, string $first_name, string $last_name, string $plugin_id, array $plugin_config, int $freemius_user_id ) {
		$sync_name_on_change = (bool) Options_API::get_option( 'sync_name_on_change', 1 );
		if ( ! $sync_name_on_change ) {
			return array(
				'status'  => 'ignored',
				'message' => __( 'Name change sync is disabled.', 'freemkit' ),
			);
		}

		$existing_subscriber = $this->database->get_subscriber_by_email( $email );
		if ( is_wp_error( $existing_subscriber ) ) {
			if ( 'subscriber_not_found' !== $existing_subscriber->get_error_code() ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( '[FreemKit] Database error during name sync lookup: %s', $existing_subscriber->get_error_message() ) );
				}
				return $existing_subscriber;
			}
			return array(
				'status'  => 'ignored',
				'message' => __( 'Subscriber not found in local database; skipping name sync.', 'freemkit' ),
			);
		}

		$status    = $existing_subscriber->status;
		$marketing = (int) $existing_subscriber->marketing;

		$kit_fields      = array();
		$last_name_field = Options_API::get_option( 'last_name_field' );
		if ( $last_name_field && $last_name ) {
			$kit_fields[ $this->api->resolve_custom_field_key( (string) $last_name_field ) ] = $last_name;
		}

		$api_result = $this->api->update_subscriber_name( $email, $first_name, $kit_fields );
		if ( is_wp_error( $api_result ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[FreemKit] Kit API Error during name sync: %s', $api_result->get_error_message() ) );
		}

		$subscriber = new Subscriber(
			array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'status'     => $status,
				'marketing'  => $marketing,
			)
		);

		$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
		if ( is_wp_error( $db_result ) ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[FreemKit] Database Error during name sync: %s', $db_result->get_error_message() ) );
			}
			return new \WP_Error( 'db_error', __( 'Name change processed with database errors', 'freemkit' ) );
		}

		$event = new Subscriber_Event(
			array(
				'subscriber_id'    => $db_result,
				'plugin_id'        => (string) $plugin_id,
				'plugin_slug'      => $plugin_config['slug'],
				'event_type'       => 'user.name.changed',
				'user_type'        => '',
				'form_ids'         => '',
				'tag_ids'          => '',
				'freemius_user_id' => $freemius_user_id,
			)
		);

		$event_result = $this->database->add_subscriber_event( $event );
		if ( is_wp_error( $event_result ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[FreemKit] Event insert error during name sync: %s', $event_result->get_error_message() ) );
		}

		return array(
			'status'  => 'success',
			'message' => __( 'Name change processed successfully.', 'freemkit' ),
		);
	}

	/**
	 * Extract and validate signature from various possible header formats.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request|null $request Optional request object for REST API.
	 * @return string The signature or empty string if not found.
	 */
	public function get_signature( ?\WP_REST_Request $request = null ): string {
		$signature = '';

		if ( null !== $request ) {
			$signature = $request->get_header( 'x-signature' );
		}

		if ( empty( $signature ) && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			foreach ( $headers as $key => $value ) {
				if ( strtolower( $key ) === 'x-signature' ) {
					$signature = $value;
					break;
				}
			}
		}

		if ( empty( $signature ) && isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) );
		}

		return $signature;
	}

	/**
	 * Get request header value.
	 *
	 * @param string                $header_name Header name.
	 * @param \WP_REST_Request|null $request Request instance.
	 * @return string
	 */
	public function get_request_header( string $header_name, ?\WP_REST_Request $request = null ): string {
		$value = '';

		if ( null !== $request ) {
			$value = (string) $request->get_header( $header_name );
		}

		if ( '' === $value && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			foreach ( $headers as $key => $header_value ) {
				if ( strtolower( $key ) === strtolower( $header_name ) ) {
					$value = (string) $header_value;
					break;
				}
			}
		}

		if ( '' === $value ) {
			$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header_name ) );
			if ( isset( $_SERVER[ $server_key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
			}
		}

		return trim( $value );
	}

	/**
	 * Handle webhook requests via query variable.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_query_var_webhook() {
		if ( empty( $_SERVER['QUERY_STRING'] ) ) {
			return;
		}

		$query_string = sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
		if ( false === strpos( $query_string, 'freemkit_webhook' ) ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method ) {
			status_header( 405 );
			die( 'Invalid request method' );
		}

		$input      = file_get_contents( 'php://input' );
		$signature  = $this->get_signature();
		$validation = $this->validate_webhook_signature( $input, $signature );
		if ( is_wp_error( $validation ) ) {
			status_header( 400 );
			die( esc_html( $validation->get_error_message() ) );
		}

		$freshness = $this->validate_webhook_freshness( $input );
		if ( is_wp_error( $freshness ) ) {
			status_header( 400 );
			die( esc_html( $freshness->get_error_message() ) );
		}

		$result = $this->queue_webhook_event( $input );
		if ( is_wp_error( $result ) ) {
			status_header( 500 );
			die( esc_html( $result->get_error_message() ) );
		}

		status_header( 202 );
		die( esc_html( $result['message'] ) );
	}

	/**
	 * Validate webhook signature and configuration without processing side effects.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Raw webhook input data.
	 * @param string $signature Request signature.
	 * @return true|\WP_Error True if validation passes, WP_Error otherwise.
	 */
	public function validate_webhook_signature( string $input, string $signature ) {
		$fs_event = json_decode( $input );
		if ( empty( $fs_event ) || empty( $fs_event->plugin_id ) ) {
			return new \WP_Error( 'invalid_request', __( 'Invalid request body or missing plugin ID', 'freemkit' ) );
		}

		$plugin_id = $fs_event->plugin_id;
		if ( ! isset( $this->plugin_configs[ $plugin_id ] ) ) {
			return new \WP_Error( 'invalid_plugin', __( 'Plugin ID not found in configuration', 'freemkit' ) );
		}

		$plugin_config = $this->plugin_configs[ $plugin_id ];
		$hash          = hash_hmac( 'sha256', $input, $plugin_config['secret_key'] );

		if ( ! hash_equals( $hash, $signature ) ) {
			return new \WP_Error( 'invalid_signature', __( 'Invalid signature', 'freemkit' ) );
		}

		return true;
	}

	/**
	 * Validate webhook freshness using timestamp if available.
	 *
	 * @param string                $input Raw webhook input data.
	 * @param \WP_REST_Request|null $request Request instance.
	 * @return true|\WP_Error
	 */
	public function validate_webhook_freshness( string $input, ?\WP_REST_Request $request = null ) {
		$timestamp = $this->extract_webhook_timestamp( $input, $request );
		if ( null === $timestamp ) {
			$require_timestamp = (bool) apply_filters( 'freemkit_webhook_require_timestamp', false, $request );
			if ( $require_timestamp ) {
				return new \WP_Error( 'missing_timestamp', __( 'Webhook timestamp is required but missing.', 'freemkit' ) );
			}
			return true;
		}

		$max_age = (int) apply_filters( 'freemkit_webhook_max_age', 15 * MINUTE_IN_SECONDS, $timestamp );
		if ( $max_age <= 0 ) {
			$max_age = 15 * MINUTE_IN_SECONDS;
		}

		if ( abs( time() - $timestamp ) > $max_age ) {
			return new \WP_Error( 'stale_webhook', __( 'Webhook timestamp is outside the accepted time window.', 'freemkit' ) );
		}

		return true;
	}

	/**
	 * Extract webhook timestamp from headers or payload.
	 *
	 * @param string                $input Raw payload.
	 * @param \WP_REST_Request|null $request Request instance.
	 * @return int|null
	 */
	public function extract_webhook_timestamp( string $input, ?\WP_REST_Request $request = null ): ?int {
		$header_keys = array( 'x-fs-timestamp', 'x-timestamp', 'x-webhook-timestamp' );
		foreach ( $header_keys as $header_key ) {
			$header_value = $this->get_request_header( $header_key, $request );
			if ( '' !== $header_value && is_numeric( $header_value ) ) {
				return (int) $header_value;
			}
		}

		$event = json_decode( $input, true );
		if ( ! is_array( $event ) ) {
			return null;
		}

		$candidates = array(
			$event['timestamp'] ?? null,
			$event['created'] ?? null,
			$event['created_at'] ?? null,
			$event['event_timestamp'] ?? null,
			$event['date'] ?? null,
			$event['datetime'] ?? null,
			isset( $event['objects']['event']['created'] ) ? $event['objects']['event']['created'] : null,
			isset( $event['objects']['event']['created_at'] ) ? $event['objects']['event']['created_at'] : null,
			isset( $event['objects']['event']['timestamp'] ) ? $event['objects']['event']['timestamp'] : null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_numeric( $candidate ) ) {
				return (int) $candidate;
			}

			if ( is_string( $candidate ) ) {
				$parsed = strtotime( $candidate );
				if ( false !== $parsed ) {
					return (int) $parsed;
				}
			}
		}

		return null;
	}

	/**
	 * Extract a stable webhook event identifier.
	 *
	 * @param string $input Raw payload.
	 * @return string
	 */
	public function get_event_key( string $input ): string {
		$event = json_decode( $input, true );
		if ( is_array( $event ) ) {
			$candidates = array(
				$event['id'] ?? null,
				$event['event_id'] ?? null,
				isset( $event['objects']['event']['id'] ) ? $event['objects']['event']['id'] : null,
			);

			foreach ( $candidates as $candidate ) {
				if ( is_scalar( $candidate ) && '' !== (string) $candidate ) {
					return sanitize_key( (string) $candidate );
				}
			}
		}

		return hash( 'sha256', $input );
	}

	/**
	 * Check if webhook has already been seen.
	 *
	 * @param string $event_key Event key.
	 * @return bool
	 */
	public function is_duplicate_webhook( string $event_key ): bool {
		return false !== get_transient( self::SEEN_PREFIX . $event_key );
	}

	/**
	 * Mark webhook as seen to prevent replay/duplicate processing.
	 *
	 * @param string $event_key Event key.
	 * @return void
	 */
	public function mark_webhook_seen( string $event_key ): void {
		$ttl = (int) apply_filters( 'freemkit_webhook_replay_ttl', DAY_IN_SECONDS, $event_key );
		$ttl = max( HOUR_IN_SECONDS, $ttl );
		set_transient( self::SEEN_PREFIX . $event_key, 1, $ttl );
	}

	/**
	 * Queue webhook for async processing.
	 *
	 * @param string $input Raw payload.
	 * @return array|\WP_Error
	 */
	public function queue_webhook_event( string $input ) {
		$event_key = $this->get_event_key( $input );
		if ( $this->is_duplicate_webhook( $event_key ) ) {
			return array(
				'status'  => 'ignored',
				'message' => __( 'Duplicate webhook ignored.', 'freemkit' ),
			);
		}

		$this->mark_webhook_seen( $event_key );

		$payload = array(
			'input'    => $input,
			'attempts' => 0,
		);
		set_transient( self::QUEUE_PREFIX . $event_key, $payload, DAY_IN_SECONDS );

		if ( ! wp_next_scheduled( self::PROCESS_HOOK, array( $event_key ) ) ) {
			$scheduled = wp_schedule_single_event( time() + 1, self::PROCESS_HOOK, array( $event_key ) );
			if ( false === $scheduled ) {
				// Fall back to immediate processing when scheduling is unavailable.
				$this->process_queued_webhook( $event_key );
				return array(
					'status'  => 'processed',
					'message' => __( 'Webhook processed immediately because scheduling was unavailable.', 'freemkit' ),
				);
			}
		}

		// Prompt cron spawn so queues are not delayed on low-traffic sites.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
		}

		// If internal WP-Cron is disabled, process now to avoid indefinite queue delays.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$this->process_queued_webhook( $event_key );
			return array(
				'status'  => 'processed',
				'message' => __( 'Webhook processed immediately because WP-Cron is disabled.', 'freemkit' ),
			);
		}

		return array(
			'status'  => 'queued',
			'message' => __( 'Webhook accepted for asynchronous processing.', 'freemkit' ),
		);
	}

	/**
	 * Process a queued webhook event.
	 *
	 * @param string $event_key Event key.
	 * @return void
	 */
	public function process_queued_webhook( string $event_key ): void {
		$payload = get_transient( self::QUEUE_PREFIX . $event_key );
		if ( ! is_array( $payload ) || empty( $payload['input'] ) ) {
			Audit_Log::add(
				'webhook_payload_missing',
				array( 'event_key' => $event_key ),
				'error'
			);
			return;
		}

		$result = $this->process_webhook( (string) $payload['input'] );
		if ( ! is_wp_error( $result ) ) {
			delete_transient( self::QUEUE_PREFIX . $event_key );
			return;
		}

		$attempts      = isset( $payload['attempts'] ) ? (int) $payload['attempts'] : 0;
		$max_attempts  = (int) apply_filters( 'freemkit_webhook_max_retries', 3, $event_key );
		$next_attempts = $attempts + 1;

		if ( $next_attempts > $max_attempts ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[FreemKit] Webhook dropped after retries (%s): %s', $event_key, $result->get_error_message() ) );
			}
			delete_transient( self::QUEUE_PREFIX . $event_key );
			delete_transient( self::SEEN_PREFIX . $event_key );
			return;
		}

		$payload['attempts'] = $next_attempts;
		set_transient( self::QUEUE_PREFIX . $event_key, $payload, DAY_IN_SECONDS );
		$delay = min( 5 * MINUTE_IN_SECONDS, $next_attempts * MINUTE_IN_SECONDS );
		wp_schedule_single_event( time() + $delay, self::PROCESS_HOOK, array( $event_key ) );
	}

	/**
	 * Check webhook permissions for REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error True if permissions are valid, \WP_Error otherwise.
	 */
	public function check_webhook_permissions( \WP_REST_Request $request ) {
		$signature = $this->get_signature( $request );
		return $this->validate_webhook_signature( $request->get_body(), $signature );
	}

	/**
	 * Process incoming webhook requests from Freemius via REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		$body       = $request->get_body();
		$signature  = $this->get_signature( $request );
		$validation = $this->validate_webhook_signature( $body, $signature );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$freshness = $this->validate_webhook_freshness( $body, $request );
		if ( is_wp_error( $freshness ) ) {
			return $freshness;
		}

		$result = $this->queue_webhook_event( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status_code = 'queued' === $result['status'] ? 202 : 200;

		return new \WP_REST_Response(
			array(
				'status'  => sanitize_text_field( $result['status'] ),
				'message' => esc_html( $result['message'] ),
			),
			$status_code
		);
	}
}
