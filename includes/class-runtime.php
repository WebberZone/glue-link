<?php
/**
 * Runtime initialization for FreemKit.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit;

use WebberZone\FreemKit\Kit\Kit_API;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Runtime class.
 */
class Runtime {

	/**
	 * Whether runtime services have been initialized.
	 *
	 * @var bool
	 */
	public bool $initialized = false;

	/**
	 * Database object.
	 *
	 * @var \WebberZone\FreemKit\Database
	 */
	public Database $database;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->database = new Database();
	}

	/**
	 * Initialize runtime services.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$api = new Kit_API();
		new Webhook_Handler( $this->get_plugin_configs(), $api, $this->database );
		$this->initialized = true;
	}

	/**
	 * Initialize admin components on init.
	 *
	 * @return void
	 */
	public function init_admin(): void {
		if ( is_admin() ) {
			new Admin\Admin( $this->database );
		}
	}

	/**
	 * Build plugin configurations from settings.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function get_plugin_configs(): array {
		$settings       = get_option( Options_API::SETTINGS_OPTION, array() );
		$plugin_configs = array();

		if ( ! is_array( $settings ) || empty( $settings['plugins'] ) || ! is_array( $settings['plugins'] ) ) {
			return $plugin_configs;
		}

		foreach ( $settings['plugins'] as $plugin ) {
			if ( ! is_array( $plugin ) || empty( $plugin['id'] ) ) {
				continue;
			}

			$plugin_id                    = sanitize_text_field( (string) $plugin['id'] );
			$plugin_configs[ $plugin_id ] = array(
				'slug'             => sanitize_title( (string) ( $plugin['name'] ?? '' ) ),
				'public_key'       => (string) ( $plugin['public_key'] ?? '' ),
				'secret_key'       => (string) ( $plugin['secret_key'] ?? '' ),
				'free_form_ids'    => (string) ( $plugin['free_form_ids'] ?? '' ),
				'free_tag_ids'     => (string) ( $plugin['free_tag_ids'] ?? '' ),
				'free_event_types' => (string) ( $plugin['free_event_types'] ?? '' ),
				'paid_form_ids'    => (string) ( $plugin['paid_form_ids'] ?? '' ),
				'paid_tag_ids'     => (string) ( $plugin['paid_tag_ids'] ?? '' ),
				'paid_event_types' => (string) ( $plugin['paid_event_types'] ?? '' ),
			);
		}

		return $plugin_configs;
	}

	/**
	 * Plugin activation handler.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$database = new Database();
		$result   = $database->create_table();

		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Plugin Activation Error', 'freemkit' ),
				array(
					'back_link' => true,
				)
			);
		}

		// Trigger setup wizard on next admin page load.
		delete_option( 'freemkit_wizard_completed' );
		delete_option( 'freemkit_wizard_completed_date' );
		update_option( 'freemkit_wizard_current_step', 1 );
		update_option( 'freemkit_show_wizard', true );
		set_transient( 'freemkit_show_wizard_activation_redirect', true, HOUR_IN_SECONDS );
	}
}
