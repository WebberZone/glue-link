<?php
/**
 * Webhook Handler class
 *
 * @package WebberZone\Glue_Link
 */

namespace WebberZone\Glue_Link;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook Handler class
 *
 * @since 1.0.0
 */
class Webhook_Handler {

	/**
	 * Plugin configurations.
	 *
	 * @var array{
	 *     secret_key: string,
	 *     free_form_id: int,
	 *     premium_form_id: int
	 * }[]
	 */
	private $plugin_configs;

	/**
	 * ConvertKit API instance.
	 *
	 * @var Kit_API
	 */
	private $api;

	/**
	 * Database instance.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $plugin_configs Plugin configurations array indexed by plugin ID.
	 * @param Kit_API              $api           ConvertKit API instance.
	 * @param Database             $database      Database instance.
	 */
	public function __construct( array $plugin_configs, Kit_API $api, Database $database ) {
		$this->plugin_configs = $plugin_configs;
		$this->api            = $api;
		$this->database       = $database;
	}

	/**
	 * Initialize the webhook handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
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
			'glue-link/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'check_webhook_permissions' ),
			)
		);
	}

	/**
	 * Check webhook permissions.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error True if permissions are valid, \WP_Error otherwise.
	 */
	public function check_webhook_permissions( \WP_REST_Request $request ): bool|\WP_Error {
		// Retrieve the request's body.
		$input = $request->get_body();

		// Get plugin ID from the request.
		$fs_event = json_decode( $input );
		if ( empty( $fs_event->plugin_id ) ) {
			return new \WP_Error( 'invalid_plugin_id', 'Invalid plugin ID' );
		}

		$plugin_id = $fs_event->plugin_id;

		// Check if plugin ID exists in config.
		if ( ! isset( $this->plugin_configs[ $plugin_id ] ) ) {
			return new \WP_Error( 'invalid_plugin_id', 'Plugin ID not found in configuration' );
		}

		// Verify the signature.
		$signature = $request->get_header( 'x-signature' );
		$hash      = hash_hmac( 'sha256', $input, $this->plugin_configs[ $plugin_id ]['secret_key'] );

		if ( ! hash_equals( $hash, $signature ) ) {
			return new \WP_Error( 'invalid_signature', 'Invalid signature' );
		}

		return true;
	}

	/**
	 * Processes incoming webhook requests from ConvertKit.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Get the request body.
		$input = $request->get_body();

		// Decode the request.
		$fs_event = json_decode( $input );
		if ( empty( $fs_event ) ) {
			return new \WP_Error( 'invalid_request', 'Invalid request body' );
		}

		$plugin_id = $fs_event->plugin_id;
		$user      = $fs_event->objects->user;

		// Prepare user data for ConvertKit.
		$email      = $user->email;
		$first_name = ( 0 === strcasecmp( $user->first, 'Admin' ) ) ? '' : $user->first;
		$last_name  = ( 0 === strcasecmp( $user->last, 'Admin' ) ) ? '' : $user->last;
		$fields     = array(
			'last_name' => $last_name, // TODO: Map the correct lastname field.
		);
		$tags       = array(); // TODO: Get tags.
		$forms      = array(); // TODO: Get forms.

		// Handle different event types.
		switch ( $fs_event->type ) {
			case 'install.installed':
				// Subscribe to free form/sequence.
				$result = $this->api->subscribe_to_form(
					(int) $this->plugin_configs[ $plugin_id ]['free_form_id'],
					$email,
					$first_name,
					$fields,
					$tags
				);
				break;

			case 'license.created':
				// Subscribe to premium form/sequence.
				$result = $this->api->subscribe_to_form(
					(int) $this->plugin_configs[ $plugin_id ]['premium_form_id'],
					$email,
					$first_name,
					$fields,
					$tags
				);
				break;

			default:
				return new \WP_REST_Response( array( 'message' => 'Event type not handled' ), 200 );
		}

		if ( is_wp_error( $result ) ) {
			// Log the error using WordPress debug log if enabled.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[Glue Link] ConvertKit API Error: %s', $result->get_error_message() ) );
			}
			return new \WP_REST_Response( array( 'message' => 'Processed with errors' ), 200 );
		}

		// Check if subscriber already exists.
		$database     = $this->database;
		$existing_sub = $database->get_subscriber_by_email( $email );

		// Create a new subscriber object with the data.
		$subscriber = new Subscriber(
			array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'fields'     => $fields,
				'tags'       => $tags,
				'forms'      => $forms,
			)
		);

		// Update existing subscriber or insert new one.
		if ( ! is_wp_error( $existing_sub ) ) {
			$subscriber->id = $existing_sub->id;
			$result         = $database->update_subscriber( $subscriber );
		} else {
			$result = $database->insert_subscriber( $subscriber );
		}

		if ( is_wp_error( $result ) ) {
			// Log the error using WordPress debug log if enabled.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[Glue Link] Database Error: %s', $result->get_error_message() ) );
			}
			return new \WP_REST_Response( array( 'message' => 'Processed with errors' ), 200 );
		}

		return new \WP_REST_Response( array( 'message' => 'Success' ), 200 );
	}
}
