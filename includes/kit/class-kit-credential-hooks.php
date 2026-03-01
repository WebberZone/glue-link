<?php
/**
 * Kit OAuth credential hook handlers.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit\Kit;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Kit_Credential_Hooks
 */
class Kit_Credential_Hooks {

	/**
	 * Lock key used to avoid concurrent token refreshes.
	 */
	public const REFRESH_LOCK_KEY = 'freemkit_refresh_lock';

	/**
	 * Invalid-token failure tracker transient key.
	 */
	public const INVALID_TOKEN_STATE_KEY = 'freemkit_invalid_token_state';

	/**
	 * Window (seconds) for counting invalid-token failures.
	 */
	public const INVALID_TOKEN_WINDOW = 600;

	/**
	 * Number of invalid-token failures required before deleting local creds.
	 */
	public const INVALID_TOKEN_THRESHOLD = 3;

	/**
	 * Persist refreshed OAuth credentials if they belong to this plugin client.
	 *
	 * @param array  $result    OAuth result.
	 * @param string $client_id OAuth client ID.
	 * @return void
	 */
	public function maybe_update_credentials( $result, $client_id ): void {
		if ( ! defined( 'FREEMKIT_KIT_OAUTH_CLIENT_ID' ) || FREEMKIT_KIT_OAUTH_CLIENT_ID !== $client_id ) {
			return;
		}

		$settings = new Kit_Settings();
		$settings->update_credentials( $result );
		Kit_Audit_Log::add( 'credentials_updated_from_api_event', array( 'source' => 'oauth_or_refresh' ) );
	}

	/**
	 * Legacy handler for invalid access tokens.
	 *
	 * Auto-disconnect is intentionally disabled. Credentials remain stored
	 * until an administrator explicitly disconnects from Kit.
	 *
	 * @param \WP_Error $error     API error.
	 * @param string    $client_id OAuth client ID.
	 * @return void
	 */
	public function maybe_delete_credentials( $error, $client_id ): void {
		if ( ! defined( 'FREEMKIT_KIT_OAUTH_CLIENT_ID' ) || FREEMKIT_KIT_OAUTH_CLIENT_ID !== $client_id ) {
			return;
		}

		$settings = new Kit_Settings();

		// Never alter credentials owned by the official ConvertKit plugin.
		if ( $settings->using_convertkit_credentials() ) {
			Kit_Audit_Log::add( 'invalid_token_ignored_convertkit_owned' );
			return;
		}

		$code = 0;
		if ( $error instanceof \WP_Error ) {
			$code = (int) $error->get_error_data( 'convertkit_api_error' );
		}

		// Only count confirmed invalid token responses.
		if ( 401 !== $code ) {
			Kit_Audit_Log::add( 'invalid_token_ignored_non_401', array( 'code' => (string) $code ) );
			return;
		}

		$state = get_transient( self::INVALID_TOKEN_STATE_KEY );
		if ( ! is_array( $state ) ) {
			$state = array(
				'count' => 0,
				'first' => time(),
			);
		}

		$now = time();
		if ( (int) $state['first'] < ( $now - self::INVALID_TOKEN_WINDOW ) ) {
			$state['count'] = 0;
			$state['first'] = $now;
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( self::INVALID_TOKEN_STATE_KEY, $state, self::INVALID_TOKEN_WINDOW );
		Kit_Audit_Log::add( 'invalid_token_detected', array( 'count' => (string) $state['count'] ), 'warning' );

		if ( (int) $state['count'] < self::INVALID_TOKEN_THRESHOLD ) {
			return;
		}

		$settings->delete_credentials();
		delete_transient( self::INVALID_TOKEN_STATE_KEY );
		Kit_Audit_Log::add( 'invalid_token_threshold_reached_credentials_deleted', array(), 'error' );
	}

	/**
	 * Refresh OAuth access token via WP-Cron.
	 *
	 * @return void
	 */
	public function refresh_kit_access_token(): void {
		$settings = new Kit_Settings();
		if ( ! $settings->has_access_and_refresh_token() ) {
			Kit_Audit_Log::add( 'refresh_skipped_no_tokens' );
			return;
		}

		if ( $settings->using_convertkit_credentials() ) {
			wp_clear_scheduled_hook( Kit_Settings::CRON_REFRESH_HOOK );
			Kit_Audit_Log::add( 'refresh_skipped_convertkit_owned' );
			return;
		}

		if ( get_transient( self::REFRESH_LOCK_KEY ) ) {
			Kit_Audit_Log::add( 'refresh_skipped_lock_present' );
			return;
		}
		set_transient( self::REFRESH_LOCK_KEY, '1', 5 * MINUTE_IN_SECONDS );

		try {
			$api    = new Kit_API( $settings->get_access_token(), $settings->get_refresh_token() );
			$result = $api->refresh_token();
			if ( is_wp_error( $result ) ) {
				Kit_Audit_Log::add( 'refresh_failed', array( 'error' => $result->get_error_message() ), 'warning' );
				return;
			}

			$settings->update_credentials( $result );
			Kit_Audit_Log::add( 'refresh_succeeded' );
		} finally {
			delete_transient( self::REFRESH_LOCK_KEY );
		}
	}
}
