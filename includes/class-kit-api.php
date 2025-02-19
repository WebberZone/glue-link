<?php
/**
 * Kit API wrapper class
 *
 * @package WebberZone\Glue_Link
 * @since 1.0.0
 */

namespace WebberZone\Glue_Link;

/**
 * Class Kit_API
 *
 * A wrapper class for interacting with the Kit API.
 *
 * @since 1.0.0
 */
class Kit_API {

	/**
	 * Error codes.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const ERROR_NO_API_KEY    = 'invalid_api_key';
	private const ERROR_NO_API_SECRET = 'invalid_api_secret';
	private const ERROR_NO_EMAIL      = 'invalid_email';
	private const ERROR_API_ERROR     = 'api_error';

	/**
	 * The Kit API URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $api_url = 'https://api.convertkit.com/v3/';

	/**
	 * Kit API Key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $api_key;

	/**
	 * Kit API Secret.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $api_secret;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $api_key    The Kit API key.
	 * @param string $api_secret The Kit API secret.
	 */
	public function __construct( string $api_key = '', string $api_secret = '' ) {
		$this->api_key    = $api_key ? $api_key : Options_API::get_option( 'kit_api_key' );
		$this->api_secret = $api_secret ? $api_secret : Options_API::decrypt_api_key( Options_API::get_option( 'kit_api_secret' ) );
	}

	/**
	 * Validates the Kit API credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return true|\WP_Error True if API credentials are valid, WP_Error on failure.
	 */
	public function validate_api_credentials(): bool|\WP_Error {
		if ( empty( $this->api_key ) ) {
			return new \WP_Error(
				self::ERROR_NO_API_KEY,
				esc_html__( 'Please provide a Kit API key.', 'glue-link' )
			);
		}

		$url = add_query_arg(
			array(
				'api_key' => $this->api_key,
			),
			$this->api_url . 'forms'
		);

		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code || isset( $body['error'] ) ) {
			$error = isset( $body['error'] ) ? $body['error'] : wp_remote_retrieve_response_message( $response );
			return new \WP_Error(
				self::ERROR_API_ERROR,
				sprintf(
					/* translators: %s: Error message from the API */
					esc_html__( 'Kit API Error: %s', 'glue-link' ),
					$error
				)
			);
		}

		return true;
	}

	/**
	 * Validates that the API secret is set.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True if API secret is set, WP_Error otherwise.
	 */
	private function validate_api_secret(): bool|\WP_Error {
		if ( empty( $this->api_secret ) ) {
			return new \WP_Error(
				self::ERROR_NO_API_SECRET,
				esc_html__( 'Please provide a Kit API secret.', 'glue-link' )
			);
		}

		return true;
	}

	/**
	 * Validates an email address.
	 *
	 * @since 1.0.0
	 * @param string $email Email address to validate.
	 * @return true|\WP_Error True if email is valid, WP_Error otherwise.
	 */
	private function validate_email( string $email ): bool|\WP_Error {
		if ( empty( $email ) ) {
			return new \WP_Error(
				self::ERROR_NO_EMAIL,
				esc_html__( 'Please provide an email address.', 'glue-link' )
			);
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error(
				self::ERROR_NO_EMAIL,
				sprintf(
					/* translators: %s: Email address */
					esc_html__( 'Invalid email address format: %s', 'glue-link' ),
					$email
				)
			);
		}

		return true;
	}

	/**
	 * Validates both email and API secret.
	 *
	 * @since 1.0.0
	 * @param string $email Email address to validate.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_subscriber_request( string $email ): bool|\WP_Error {
		$validate = $this->validate_api_secret();
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		return $this->validate_email( $email );
	}

	/**
	 * Gets the Kit account information.
	 *
	 * @since 1.0.0
	 * @return array|\WP_Error Response from the API or WP_Error on failure.
	 */
	public function get_account(): array|\WP_Error {
		$validate = $this->validate_api_secret();
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		return $this->get( 'account', array( 'api_secret' => $this->api_secret ) );
	}

	/**
	 * Make a GET request to the Kit API.
	 *
	 * @since 1.0.0
	 * @param string $endpoint The API endpoint.
	 * @param array  $params   Optional parameters for the request.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function get( string $endpoint, array $params = array() ): array|\WP_Error {
		// Only validate API key if api_secret is not present in params.
		if ( ! isset( $params['api_secret'] ) ) {
			if ( empty( $this->api_key ) ) {
				return new \WP_Error(
					self::ERROR_NO_API_KEY,
					esc_html__( 'Please provide a Kit API key.', 'glue-link' )
				);
			}
			$params['api_key'] = $this->api_key;
		}

		return $this->request( $endpoint, 'GET', $params );
	}

	/**
	 * Make a POST request to the Kit API.
	 *
	 * @since 1.0.0
	 * @param string $endpoint The API endpoint.
	 * @param array  $data     The data to send in the request body.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function post( string $endpoint, array $data = array() ): array|\WP_Error {
		// Pre-check API credentials.
		$validation = $this->validate_api_credentials();
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		return $this->request( $endpoint, 'POST', $data );
	}

	/**
	 * Make a request to the Kit API.
	 *
	 * @since 1.0.0
	 * @param string $endpoint The API endpoint.
	 * @param string $method  The HTTP method for the request.
	 * @param array  $params  Optional parameters for the request.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function request( string $endpoint, string $method = 'GET', array $params = array() ): array|\WP_Error {
		$url = $this->api_url . $endpoint;

		$args = array(
			'method'  => $method,
			'timeout' => 45,
		);

		if ( 'GET' === $method ) {
			$url = add_query_arg( $params, $url );
		} else {
			$args['headers'] = array(
				'Content-Type' => 'application/json',
			);
			$args['body']    = wp_json_encode( $params );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( ! preg_match( '/^[2-3][0-9]{2}/', (string) $response_code ) ) {
			return new \WP_Error( 'api_error', 'API request failed', array( 'status' => $response_code ) );
		}

		return json_decode( $response_body, true );
	}

	/**
	 * Make a PUT request to the Kit API.
	 *
	 * @since 1.0.0
	 * @param string $endpoint The API endpoint.
	 * @param array  $params   Optional parameters for the request.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function put( string $endpoint, array $params = array() ): array|\WP_Error {
		return $this->request( $endpoint, 'PUT', $params );
	}

	/**
	 * Make a DELETE request to the Kit API.
	 *
	 * @since 1.0.0
	 * @param string $endpoint The API endpoint.
	 * @param array  $params   Optional parameters for the request.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function delete( string $endpoint, array $params = array() ): array|\WP_Error {
		return $this->request( $endpoint, 'DELETE', $params );
	}

	/**
	 * Get all forms from Kit.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#list-forms
	 *
	 * @return array|\WP_Error Array of forms or \WP_Error on failure.
	 */
	public function get_forms(): array|\WP_Error {
		return $this->get( 'forms' );
	}

	/**
	 * Get all sequences (courses) from Kit.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#list-sequences
	 *
	 * @return array|\WP_Error Array of sequences or \WP_Error on failure.
	 */
	public function get_sequences(): array|\WP_Error {
		return $this->get( 'sequences' );
	}

	/**
	 * Get all tags from Kit.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#list-tags
	 *
	 * @return array|\WP_Error Array of tags or \WP_Error on failure.
	 */
	public function get_tags(): array|\WP_Error {
		return $this->get( 'tags' );
	}

	/**
	 * Get all custom fields from Kit.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#list-custom-fields
	 *
	 * @return array|\WP_Error Array of custom fields or \WP_Error on failure.
	 */
	public function get_custom_fields(): array|\WP_Error {
		return $this->get( 'custom_fields' );
	}

	/**
	 * Get all subscribers.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#list-subscribers
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve subscribers.
	 *
	 *     @type string $search       Search term.
	 *     @type string $page         Page number.
	 *     @type string $from         Start date.
	 *     @type string $to           End date.
	 *     @type string $updated_from Start date for updated subscribers.
	 *     @type string $updated_to   End date for updated subscribers.
	 *     @type string $sort_order   Sort order.
	 *     @type string $sort_field   Sort field.
	 * }
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function get_subscribers( array $args = array() ): array|\WP_Error {
		$validate = $this->validate_api_secret();
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$args['api_secret'] = $this->api_secret;

		$valid_params = array(
			'page'          => 1,
			'from'          => '',
			'to'            => '',
			'updated_from'  => '',
			'updated_to'    => '',
			'sort_order'    => 'desc',
			'sort_field'    => '',
			'email_address' => '',
		);

		foreach ( $valid_params as $param => $default ) {
			if ( ! isset( $args[ $param ] ) || '' === $args[ $param ] ) {
				unset( $args[ $param ] );
			}
		}

		return $this->get( 'subscribers', $args );
	}

	/**
	 * Get subscriber by email address.
	 *
	 * @since 1.0.0
	 * @param string $email Email address to look up.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function get_subscriber( string $email ): array|\WP_Error {
		$validate = $this->validate_subscriber_request( $email );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		return $this->get_subscribers( array( 'email_address' => $email ) );
	}

	/**
	 * Update subscriber information by ID.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#update-subscriber
	 *
	 * @param int    $subscriber_id The subscriber ID.
	 * @param string $first_name    First name of the subscriber.
	 * @param string $email_address New email address if updating.
	 * @param array  $fields        Custom fields as key/value pairs.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function update_subscriber(
		int $subscriber_id,
		string $first_name = '',
		string $email_address = '',
		array $fields = array()
	): array|\WP_Error {
		$data = array(
			'api_secret' => $this->api_secret,
		);

		if ( ! empty( $first_name ) ) {
			$data['first_name'] = $first_name;
		}

		if ( ! empty( $email_address ) ) {
			$data['email_address'] = $email_address;
		}

		if ( ! empty( $fields ) ) {
			if ( count( $fields ) > 140 ) {
				return new \WP_Error(
					'too_many_fields',
					esc_html__( 'Maximum of 140 custom fields allowed.', 'glue-link' )
				);
			}
			$data['fields'] = $fields;
		}

		return $this->put( sprintf( 'subscribers/%d', $subscriber_id ), $data );
	}

	/**
	 * Update subscriber information by email.
	 *
	 * @since 1.0.0
	 * @param string $email Email address of the subscriber.
	 * @param array  $args {
	 *     Optional. Array of subscriber arguments.
	 *
	 *     @type string $first_name    First name of the subscriber.
	 *     @type string $email_address New email address if updating.
	 *     @type array  $fields        Custom fields as key/value pairs.
	 * }
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function update_subscriber_by_email( string $email, array $args = array() ): array|\WP_Error {
		$validate = $this->validate_subscriber_request( $email );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		// Get the subscriber details first.
		$subscriber = $this->get_subscriber( $email );
		if ( is_wp_error( $subscriber ) ) {
			return $subscriber;
		}

		if ( empty( $subscriber['subscriber'] ) || empty( $subscriber['subscriber']['id'] ) ) {
			return new \WP_Error(
				'subscriber_not_found',
				sprintf(
					/* translators: %s: Email address */
					esc_html__( 'Subscriber with email %s not found.', 'glue-link' ),
					$email
				)
			);
		}

		$subscriber_id = $subscriber['subscriber']['id'];

		return $this->update_subscriber(
			$subscriber_id,
			$args['first_name'] ?? '',
			$args['email_address'] ?? '',
			$args['fields'] ?? array()
		);
	}

	/**
	 * Unsubscribe a subscriber by email address.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#unsubscribe-subscriber
	 *
	 * @param string $email Email address of the subscriber to unsubscribe.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function unsubscribe( string $email ): array|\WP_Error {
		$validate = $this->validate_subscriber_request( $email );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$data = array(
			'api_secret' => $this->api_secret,
			'email'      => $email,
		);

		return $this->put( 'unsubscribe', $data );
	}

	/**
	 * Subscribe to a form.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#subscribe-to-form
	 *
	 * @param int    $form_id Form ID.
	 * @param string $email   Email address.
	 * @param string $first_name First name of the subscriber.
	 * @param array  $fields   Custom fields as key/value pairs. Fields must exist in Kit.
	 * @param array  $tags     Array of tag IDs to subscribe to.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function subscribe_to_form(
		int $form_id,
		string $email,
		string $first_name,
		array $fields = array(),
		array $tags = array()
	): array|\WP_Error {
		$validate = $this->validate_email( $email );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$data = array(
			'api_key' => $this->api_key,
			'email'   => $email,
		);

		// Add optional parameters if they exist and are not empty.
		if ( ! empty( $first_name ) ) {
			$data['first_name'] = $first_name;
		}

		if ( ! empty( $fields ) ) {
			$data['fields'] = $fields;
		}

		if ( ! empty( $tags ) ) {
			$data['tags'] = wp_parse_id_list( $tags );
		}

		return $this->post( "forms/{$form_id}/subscribe", $data );
	}

	/**
	 * List subscriptions to a form
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#list-subscriptions-to-a-form
	 *
	 * @param integer $form_id          Form ID.
	 * @param string  $sort_order       Sort Order (asc|desc).
	 * @param string  $subscriber_state Subscriber State (active,cancelled).
	 * @param integer $page             Page.
	 *
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function get_form_subscriptions(
		int $form_id,
		string $sort_order = 'asc',
		string $subscriber_state = 'active',
		int $page = 1
	): array|\WP_Error {
		return $this->get(
			sprintf( 'forms/%s/subscriptions', $form_id ),
			array(
				'api_secret'       => $this->api_secret,
				'sort_order'       => $sort_order,
				'subscriber_state' => $subscriber_state,
				'page'             => $page,
			)
		);
	}

	/**
	 * Subscribe to a tag.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#subscribe-to-tag
	 *
	 * @param int    $tag_id Tag ID.
	 * @param string $email  Email address.
	 * @param string $first_name First name of the subscriber.
	 * @param array  $fields Custom fields as key/value pairs. Fields must exist in Kit.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function tag_subscriber(
		int $tag_id,
		string $email,
		string $first_name = '',
		array $fields = array()
	): array|\WP_Error {
		$validate = $this->validate_subscriber_request( $email );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$data = array(
			'api_secret' => $this->api_secret,
			'email'      => $email,
		);

		if ( ! empty( $first_name ) ) {
			$data['first_name'] = $first_name;
		}

		if ( ! empty( $fields ) ) {
			$data['fields'] = $fields;
		}

		return $this->post( "tags/{$tag_id}/subscribe", $data );
	}

	/**
	 * Remove a tag from a subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/v3#remove-tag-from-subscriber
	 *
	 * @param int $tag_id Tag ID.
	 * @param int $subscriber_id Subscriber ID.
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function remove_tag_from_subscriber( int $tag_id, int $subscriber_id ): array|\WP_Error {
		return $this->delete(
			sprintf( 'subscribers/%s/tags/%s', $subscriber_id, $tag_id ),
			array(
				'api_secret' => $this->api_secret,
			)
		);
	}

	/**
	 * Removes a tag from a subscriber by email address.
	 *
	 * @param integer $tag_id Tag ID.
	 * @param string  $email  Subscriber email address.
	 *
	 * @since 1.0.0
	 *
	 * @see https://developers.convertkit.com/#remove-tag-from-a-subscriber-by-email
	 *
	 * @return array|\WP_Error The response or \WP_Error on failure.
	 */
	public function remove_tag_from_subscriber_by_email( int $tag_id, string $email ): array|\WP_Error {
		return $this->post(
			sprintf( 'tags/%s/unsubscribe', $tag_id ),
			array(
				'api_secret' => $this->api_secret,
				'email'      => $email,
			)
		);
	}
}
