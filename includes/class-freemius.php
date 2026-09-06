<?php
/**
 * Freemius integration utilities.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit;

defined( 'ABSPATH' ) || exit;

/**
 * Freemius helper class.
 */
class Freemius {

	/**
	 * Normalize a Freemius event type.
	 *
	 * @param string $event_type Event type.
	 * @return string
	 */
	public static function normalize_event_type( string $event_type ): string {
		return strtolower( trim( $event_type, " \t\n\r\0\x0B" ) );
	}

	/**
	 * Normalize event list values.
	 *
	 * @param array $event_types Raw event types.
	 * @return array
	 */
	public static function normalize_event_types( array $event_types ): array {
		$normalized = array_map( array( __CLASS__, 'normalize_event_type' ), $event_types );
		return array_values( array_unique( array_filter( $normalized ) ) );
	}

	/**
	 * Validate a Freemius API Bearer token against the product endpoint.
	 *
	 * @param string $plugin_id  Product ID.
	 * @param string $public_key Public key from the Freemius dashboard.
	 * @param string $secret_key Secret key from the Freemius dashboard.
	 * @return array<string,string>|\WP_Error
	 */
	public static function validate_credentials( string $plugin_id, string $public_key, string $secret_key ) {
		$resource_path = '/v1/products/' . rawurlencode( $plugin_id ) . '.json';
		$url           = 'https://api.freemius.com' . $resource_path;
		$date          = gmdate( 'r' );

		$string_to_sign = implode(
			"\n",
			array(
				'GET',
				'',
				'',
				$date,
				$resource_path,
			)
		);

		$auth_type = ( $secret_key !== $public_key ) ? 'FS' : 'FSP';
		$signature = rtrim(
			strtr(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				base64_encode( hash_hmac( 'sha256', $string_to_sign, $secret_key ) ),
				'+/',
				'-_'
			),
			'='
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Date'          => $date,
					'Authorization' => $auth_type . ' ' . $plugin_id . ':' . $public_key . ':' . $signature,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'freemius_request_error',
				sprintf(
					/* translators: %s: Error details. */
					esc_html__( 'Could not reach Freemius to validate credentials: %s', 'freemkit' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );
		$product     = ( is_array( $data ) && isset( $data['product'] ) && is_array( $data['product'] ) ) ? $data['product'] : ( is_array( $data ) ? $data : array() );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$api_message = '';
			if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$api_message = sanitize_text_field( $data['error']['message'] );
			}

			$message = self::map_validation_error_message( $status_code, $api_message, $plugin_id );

			return new \WP_Error(
				'freemius_invalid_credentials',
				$message,
				array(
					'status_code' => $status_code,
					'api_message' => $api_message,
				)
			);
		}

		$returned_id = isset( $product['id'] ) ? (string) $product['id'] : '';
		$name        = isset( $product['title'] ) ? (string) $product['title'] : ( isset( $product['name'] ) ? (string) $product['name'] : __( 'this product', 'freemkit' ) );

		if ( '' !== $returned_id && $returned_id !== $plugin_id ) {
			return new \WP_Error( 'freemius_product_mismatch', esc_html__( 'Keys are valid, but they do not match the entered Product ID.', 'freemkit' ) );
		}

		return array(
			'id'   => '' !== $returned_id ? $returned_id : $plugin_id,
			'name' => sanitize_text_field( $name ),
		);
	}

	/**
	 * Return Freemius event choices for selectors.
	 *
	 * @param string $search Optional search text.
	 * @return array<int,array<string,string>>
	 */
	public static function get_events( string $search = '' ): array {
		$events = array(
			// Subscribe events: user becomes a free user.
			'install.installed',
			'install.activated',
			'install.connected',
			// Subscribe events: trial.
			'install.trial.started',
			'user.trial.started',
			// Subscribe events: purchase / payment.
			'cart.completed',
			'payment.created',
			'plan.lifetime.purchase',
			'subscription.created',
			// Subscribe events: paid entitlement.
			'install.premium.activated',
			'license.created',
			'license.activated',
			// Subscribe events: plan change.
			'install.plan.changed',
			'install.plan.downgraded',
			// Subscribe events: explicit marketing consent.
			'user.marketing.opted_in',
			// Unsubscribe events: uninstall / removal.
			'install.deactivated',
			'install.deleted',
			'install.disconnected',
			'install.uninstalled',
			// Unsubscribe events: subscription / license ended.
			'install.premium.deactivated',
			'license.cancelled',
			'license.expired',
			'subscription.cancelled',
			'subscription.renewal.failed.last',
			// Unsubscribe events: trial ended without converting.
			'install.trial.cancelled',
			'install.trial.expired',
			// Unsubscribe events: refund / payment reversal.
			'payment.refund',
			// Unsubscribe events: explicit opt-out.
			'user.marketing.opted_out',
			// Profile update events.
			'user.name.changed',
		);

		/**
		 * Filter Freemius events available to selectors.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int,string> $events Freemius event IDs.
		 */
		$events = apply_filters( 'freemkit_freemius_events', $events );

		// Re-normalize filtered values to strings, drop empties, and de-duplicate.
		$events = array_values( array_unique( array_filter( array_map( 'strval', (array) $events ) ) ) );

		$items = array_map(
			static function ( string $event ): array {
				return array(
					'id'   => $event,
					'name' => $event,
				);
			},
			$events
		);

		if ( '' !== $search ) {
			$query = strtolower( trim( $search, " \t\n\r\0\x0B" ) );
			$items = array_values(
				array_filter(
					$items,
					static function ( array $item ) use ( $query ): bool {
						$needle = strtolower( $item['id'] . ' ' . $item['name'] );
						return false !== strpos( $needle, $query );
					}
				)
			);
		}

		return $items;
	}

	/**
	 * Map Freemius API errors to clearer admin messages.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $api_message Raw Freemius error message.
	 * @param string $plugin_id   Submitted product ID.
	 * @return string
	 */
	public static function map_validation_error_message( int $status_code, string $api_message, string $plugin_id ): string {
		if ( 401 === $status_code || 403 === $status_code ) {
			return sprintf(
				/* translators: %s: Product ID. */
				esc_html__( 'Access denied for Product ID %s. Check that the Public/Secret Keys belong to this product.', 'freemkit' ),
				$plugin_id
			);
		}

		if ( '' !== $api_message ) {
			return $api_message;
		}

		return esc_html__( 'Freemius rejected the keys. Please verify the Product ID and Public/Secret Keys.', 'freemkit' );
	}
}
