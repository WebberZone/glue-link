<?php
/**
 * Core plugin class
 *
 * @package WebberZone\Glue_Link
 */

namespace WebberZone\Glue_Link;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Core plugin class
 */
class Core {

	/**
	 * Plugin instance.
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Admin object.
	 *
	 * @since 1.0.0
	 * @var \WebberZone\Glue_Link\Admin\Admin
	 */
	public $admin;

	/**
	 * Webhook handler object.
	 *
	 * @since 1.0.0
	 * @var \WebberZone\Glue_Link\Webhook_Handler
	 */
	public $webhook_handler;

	/**
	 * Database object.
	 *
	 * @since 1.0.0
	 * @var \WebberZone\Glue_Link\Database
	 */
	public $database;

	/**
	 * Returns the instance of this class.
	 *
	 * @return object Instance of this class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor class.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Initialize database.
		$this->database = new Database();

		// Initialize admin functionality if in admin.
		if ( is_admin() ) {
			$this->admin = new Admin\Admin( $this->database );
		}

		// Initialize webhook handler.
		$settings = get_option( 'glue_link_settings', array() );

		// Build plugin configs from settings.
		$plugin_configs = array();
		if ( ! empty( $settings['plugins'] ) ) {
			foreach ( $settings['plugins'] as $plugin ) {
				if ( empty( $plugin['id'] ) ) {
					continue;
				}

				$plugin_configs[ $plugin['id'] ] = array(
					'slug'          => sanitize_title( $plugin['name'] ),
					'public_key'    => $plugin['public_key'] ?? '',
					'secret_key'    => $plugin['secret_key'] ?? '',
					'free_form_ids' => $plugin['free_form_ids'] ?? '',
					'free_tag_ids'  => $plugin['free_tag_ids'] ?? '',
					'paid_form_ids' => $plugin['paid_form_ids'] ?? '',
					'paid_tag_ids'  => $plugin['paid_tag_ids'] ?? '',
				);
			}
		}

		$api                   = new Kit_API();
		$this->webhook_handler = new Webhook_Handler( $plugin_configs, $api, $this->database );

		$this->hooks();
	}

	/**
	 * Plugin activation handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		$result = self::get_instance()->database->create_table();

		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Plugin Activation Error', 'glue-link' ),
				array(
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function hooks(): void {
	}
}
