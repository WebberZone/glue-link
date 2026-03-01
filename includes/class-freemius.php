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
		return strtolower( trim( $event_type ) );
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
	 * Validate Freemius credentials against the product endpoint.
	 *
	 * @param string $plugin_id  Product ID.
	 * @param string $public_key Public key.
	 * @param string $secret_key Secret key.
	 * @return array<string,string>|\WP_Error
	 */
	public static function validate_credentials( string $plugin_id, string $public_key, string $secret_key ) {
		$resource_path = sprintf( '/v1/products/%s.json', rawurlencode( $plugin_id ) );
		$url           = 'https://api.freemius.com' . $resource_path;
		$response      = null;

		foreach ( array( 'binary', 'hex' ) as $signature_mode ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => self::build_fs_headers( 'GET', $resource_path, '', $plugin_id, $public_key, $secret_key, $signature_mode ),
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
			if ( $status_code >= 200 && $status_code < 300 ) {
				break;
			}
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
			return new \WP_Error( 'freemius_product_mismatch', esc_html__( 'Credentials are valid, but they do not match the entered Product ID.', 'freemkit' ) );
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
			'affiliate.approved',
			'affiliate.blocked',
			'affiliate.created',
			'affiliate.deleted',
			'affiliate.payout.pending',
			'affiliate.paypal.updated',
			'affiliate.rejected',
			'affiliate.suspended',
			'affiliate.unapproved',
			'affiliate.updated',
			'card.created',
			'card.updated',
			'cart.abandoned',
			'install.installed',
			'cart.completed',
			'cart.created',
			'cart.recovered',
			'cart.recovery.deactivated',
			'cart.recovery.email_1_sent',
			'cart.recovery.email_2_sent',
			'cart.recovery.email_3_sent',
			'cart.recovery.reactivated',
			'cart.recovery.subscribed',
			'cart.recovery.unsubscribed',
			'cart.updated',
			'coupon.created',
			'coupon.deleted',
			'coupon.updated',
			'email.clicked',
			'email.opened',
			'install.activated',
			'install.deactivated',
			'install.deleted',
			'install.premium.activated',
			'install.connected',
			'install.disconnected',
			'install.language.updated',
			'install.plan.changed',
			'install.plan.downgraded',
			'install.platform.version.updated',
			'install.premium.deactivated',
			'install.programming_language.version.updated',
			'install.sdk.version.updated',
			'install.title.updated',
			'install.trial.started',
			'install.trial.extended',
			'install.trial.cancelled',
			'install.trial.expired',
			'install.trial_expiring_notice.sent',
			'install.trial.plan.updated',
			'install.uninstalled',
			'install.updated',
			'install.url.updated',
			'install.version.downgrade',
			'install.version.upgraded',
			'license.created',
			'license.activated',
			'license.updated',
			'license.extended',
			'license.shortened',
			'license.expired',
			'license.expired_notice.sent',
			'license.cancelled',
			'license.deactivated',
			'license.deleted',
			'license.ownership.changed',
			'license.quota.changed',
			'license.renewal_reminder.sent',
			'license.trial_expiring_notice.sent',
			'license.site.blacklisted',
			'license.blacklisted_site.deleted',
			'license.site.whitelisted',
			'license.whitelisted_site.deleted',
			'member.created',
			'member.deleted',
			'member.updated',
			'plan.created',
			'plan.deleted',
			'subscription.created',
			'subscription.cancelled',
			'subscription.renewal_reminder.sent',
			'subscription.renewal_reminder.opened',
			'subscription.renewal.retry',
			'subscription.renewal.failed',
			'subscription.renewal.failed.last',
			'subscription.renewal.failed_email.sent',
			'subscription.renewals.discounted',
			'payment.created',
			'payment.refund',
			'payment.dispute.created',
			'payment.dispute.closed',
			'payment.dispute.lost',
			'payment.dispute.won',
			'plan.lifetime.purchase',
			'plan.updated',
			'pricing.created',
			'pricing.deleted',
			'pricing.updated',
			'review.created',
			'review.deleted',
			'review.requested',
			'review.updated',
			'store.created',
			'store.dashboard_url.updated',
			'store.plugin.added',
			'store.plugin.removed',
			'store.url.updated',
			'user.beta_program.opted_in',
			'user.beta_program.opted_out',
			'user.billing.updated',
			'user.billing.tax_id.updated',
			'user.card.created',
			'user.created',
			'user.email.changed',
			'user.email.verified',
			'user.email_status.bounced',
			'user.email_status.delivered',
			'user.email_status.dropped',
			'user.marketing.opted_in',
			'user.marketing.opted_out',
			'user.marketing.reset',
			'user.name.changed',
			'user.support.contacted',
			'user.trial.started',
			'webhook.created',
			'webhook.deleted',
			'webhook.updated',
			'addon.free.downloaded',
			'addon.premium.downloaded',
			'install.extensions.opt_in',
			'install.extensions.opt_out',
			'install.ownership.candidate.confirmed',
			'install.ownership.completed',
			'install.ownership.initiated',
			'install.ownership.owner.confirmed',
			'install.site.opt_in',
			'install.site.opt_out',
			'install.user.opt_in',
			'install.user.opt_out',
			'plugin.free.downloaded',
			'plugin.premium.downloaded',
			'plugin.version.deleted',
			'plugin.version.deployed',
			'plugin.version.released',
			'plugin.version.beta.released',
			'plugin.version.release.suspended',
			'plugin.version.updated',
			'pricing.visit',
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
			$query = strtolower( trim( $search ) );
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
	 * Build Freemius FS authorization headers.
	 *
	 * @param string $method         HTTP method.
	 * @param string $resource_path  API resource path.
	 * @param string $body           Request body.
	 * @param string $scope_entity_id Freemius scope entity ID (product ID).
	 * @param string $public_key     Public key.
	 * @param string $secret_key     Secret key.
	 * @param string $signature_mode Signature mode ('binary' or 'hex').
	 * @return array<string,string>
	 */
	public static function build_fs_headers( string $method, string $resource_path, string $body, string $scope_entity_id, string $public_key, string $secret_key, string $signature_mode = 'binary' ): array {
		$date         = gmdate( 'D, d M Y H:i:s O' );
		$content_type = 'application/json';
		$md5          = '' === $body ? '' : md5( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5
		$string       = strtoupper( $method ) . "\n" . $md5 . "\n" . $content_type . "\n" . $date . "\n" . $resource_path;

		if ( 'hex' === $signature_mode ) {
			$hmac = hash_hmac( 'sha256', $string, $secret_key );
		} else {
			$hmac = hash_hmac( 'sha256', $string, $secret_key, true );
		}

		$signature = rtrim( strtr( base64_encode( $hmac ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return array(
			'Authorization' => sprintf( 'FS %s:%s:%s', $scope_entity_id, $public_key, $signature ),
			'Content-Type'  => $content_type,
			'Accept'        => 'application/json',
			'Date'          => $date,
		);
	}

	/**
	 * Map Freemius API auth errors to clearer admin messages.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $api_message Raw Freemius error message.
	 * @param string $plugin_id   Submitted product ID.
	 * @return string
	 */
	public static function map_validation_error_message( int $status_code, string $api_message, string $plugin_id ): string {
		$message_lc = strtolower( $api_message );

		if ( false !== strpos( $message_lc, 'invalid authorization header' ) ) {
			return sprintf(
				/* translators: %s: Product ID. */
				esc_html__( 'Could not validate Product ID %s. Re-check Product ID, Public Key, and Secret Key for this product.', 'freemkit' ),
				$plugin_id
			);
		}

		if ( false !== strpos( $message_lc, 'must use fs authorization' ) ) {
			return esc_html__( 'Freemius rejected the authorization method for this request. Please verify your product credentials and try again.', 'freemkit' );
		}

		if ( 401 === $status_code || 403 === $status_code ) {
			return sprintf(
				/* translators: %s: Product ID. */
				esc_html__( 'Access denied for Product ID %s. Confirm the keys belong to this exact product.', 'freemkit' ),
				$plugin_id
			);
		}

		if ( '' !== $api_message ) {
			return $api_message;
		}

		return esc_html__( 'Freemius rejected the credentials. Please verify Product ID, public key, and secret key.', 'freemkit' );
	}
}
