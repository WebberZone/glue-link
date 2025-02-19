<?php
/**
 * Subscriber class file
 *
 * @package WebberZone\Glue_Link
 * @since 1.0.0
 */

namespace WebberZone\Glue_Link;

/**
 * Class representing a subscriber.
 *
 * @since 1.0.0
 */
class Subscriber {

	/**
	 * Subscriber ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Subscriber email.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $email = '';

	/**
	 * Subscriber first name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $first_name = '';

	/**
	 * Subscriber last name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $last_name = '';

	/**
	 * Custom fields.
	 *
	 * @since 1.0.0
	 * @var array|string
	 */
	public $fields = array();

	/**
	 * Tags.
	 *
	 * @since 1.0.0
	 * @var array|string
	 */
	public $tags = array();

	/**
	 * Forms.
	 *
	 * @since 1.0.0
	 * @var array|string
	 */
	public $forms = array();

	/**
	 * Subscriber status.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $status = 'active';

	/**
	 * Created timestamp.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $created = '';

	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var Database
	 */
	public Database $db;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string|array|object|Subscriber $subscriber Subscriber ID, email, array or object.
	 * @param ?Database                          $db         Optional. Database instance.
	 */
	public function __construct( $subscriber = 0, ?Database $db = null ) {
		$this->db = $db ?? new Database();

		if ( ! $subscriber ) {
			return;
		}

		if ( is_numeric( $subscriber ) && $subscriber > 0 ) {
			$this->init( $subscriber );
		} elseif ( is_string( $subscriber ) ) {
			$this->init_by_email( $subscriber );
		} elseif ( $subscriber instanceof Subscriber ) {
			$this->init_by_data( $subscriber->to_array() );
		} elseif ( is_array( $subscriber ) || is_object( $subscriber ) ) {
			$this->init_by_data( (array) $subscriber );
		}
	}

	/**
	 * Initialize subscriber data by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Subscriber ID.
	 */
	private function init( int $id ): void {
		$subscriber = $this->db->get_subscriber( $id );

		if ( ! is_wp_error( $subscriber ) ) {
			$this->init_by_data( (array) $subscriber );
		}
	}

	/**
	 * Initialize subscriber data by email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Subscriber email.
	 */
	private function init_by_email( string $email ): void {
		$subscriber = $this->db->get_subscriber_by_email( $email );

		if ( ! is_wp_error( $subscriber ) ) {
			$this->init_by_data( (array) $subscriber );
		}
	}

	/**
	 * Initialize subscriber data from array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Subscriber data.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	private function init_by_data( array $data ): bool|\WP_Error {
		// Ensure email exists and is valid.
		if ( empty( $data['email'] ) || ! filter_var( $data['email'], FILTER_VALIDATE_EMAIL ) ) {
			return new \WP_Error( 'invalid_email', __( 'Invalid subscriber email.', 'glue-link' ) );
		}

		foreach ( get_object_vars( $this ) as $key => $value ) {
			if ( isset( $data[ $key ] ) ) {
				switch ( $key ) {
					case 'id':
						$this->id = (int) $data[ $key ];
						break;
					case 'email':
						$this->email = $data[ $key ];
						break;
					case 'first_name':
					case 'last_name':
					case 'status':
						$this->$key = sanitize_text_field( $data[ $key ] );
						break;
					case 'fields':
					case 'tags':
					case 'forms':
						$data_value = maybe_unserialize( $data[ $key ] );
						$this->$key = wp_parse_list( $data_value );
						break;
					case 'created':
						// Assign only if it exists, otherwise let MySQL handle it.
						if ( ! empty( $data[ $key ] ) ) {
							$this->created = $data[ $key ];
						}
						break;
					default:
						$this->$key = $data[ $key ];
						break;
				}
			}
		}

		return true;
	}

	/**
	 * Convert subscriber to JSON.
	 *
	 * @since 1.0.0
	 *
	 * @return string JSON-encoded subscriber data.
	 */
	public function to_json(): string {
		return wp_json_encode( $this->to_array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Convert subscriber to array.
	 *
	 * @since 1.0.0
	 *
	 * @return array Subscriber data.
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Get subscriber display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Display name.
	 */
	public function get_display_name(): string {
		$display_name = trim( "{$this->first_name} {$this->last_name}" );
		return '' !== $display_name ? $display_name : $this->email;
	}

	/**
	 * Save subscriber to database.
	 *
	 * @since 1.0.0
	 *
	 * @return int|\WP_Error Subscriber ID on success, WP_Error on failure.
	 */
	public function save(): int|\WP_Error {
		$result = $this->id ? $this->db->update_subscriber( $this ) : $this->db->insert_subscriber( $this );

		if ( ! is_wp_error( $result ) ) {
			$this->id = $result;
			return $this->id;
		}

		return $result;
	}

	/**
	 * Delete subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete() {
		if ( ! $this->id ) {
			return new \WP_Error( 'no_subscriber', __( 'No subscriber found to delete.', 'glue-link' ) );
		}

		return $this->db->delete_subscriber( $this->id );
	}
}
