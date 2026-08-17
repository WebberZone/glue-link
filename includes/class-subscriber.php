<?php
/**
 * Subscriber class file
 *
 * @package WebberZone\FreemKit
 * @since 1.0.0
 */

namespace WebberZone\FreemKit;

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
	 * Subscriber status.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $status = 'active';

	/**
	 * Marketing consent flag (1 = opted in, 0 = opted out).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $marketing = 1;

	/**
	 * Freemius user ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $freemius_user_id = 0;

	/**
	 * Freemius account creation date.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $freemius_created = '';

	/**
	 * Email verified flag from Freemius.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $is_verified = 0;

	/**
	 * Email status from Freemius (delivered, bounced, etc.).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $email_status = '';

	/**
	 * Subscriber state as last reported by Kit (e.g. active, cancelled, bounced).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $kit_status = '';

	/**
	 * Freeform admin notes about this subscriber.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $notes = '';

	/**
	 * JSON meta / catch-all field.
	 *
	 * @since 1.0.0
	 * @var string|array
	 */
	public $meta = '';

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
	public function init( int $id ): void {
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
	public function init_by_email( string $email ): void {
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
	public function init_by_data( array $data ) {
		// Ensure email exists and is valid.
		if ( empty( $data['email'] ) || ! filter_var( $data['email'], FILTER_VALIDATE_EMAIL ) ) {
			return new \WP_Error( 'invalid_email', __( 'Invalid subscriber email.', 'freemkit' ) );
		}

		foreach ( get_object_vars( $this ) as $key => $value ) {
			if ( isset( $data[ $key ] ) ) {
				switch ( $key ) {
					case 'id':
					case 'marketing':
					case 'freemius_user_id':
					case 'is_verified':
						$this->$key = (int) $data[ $key ];
						break;
					case 'email':
						$this->email = $data[ $key ];
						break;
					case 'first_name':
					case 'last_name':
					case 'status':
					case 'freemius_created':
					case 'email_status':
					case 'kit_status':
						$this->$key = sanitize_text_field( $data[ $key ] );
						break;
					case 'notes':
						$this->notes = sanitize_textarea_field( $data[ $key ] );
						break;
					case 'meta':
						$this->meta = $data[ $key ];
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
	public function save() {
		$result = $this->id ? $this->db->update_subscriber( $this ) : $this->db->add_subscriber( $this );

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
			return new \WP_Error( 'no_subscriber', __( 'No subscriber found to delete.', 'freemkit' ) );
		}

		return $this->db->delete_subscriber( $this->id );
	}
}
