<?php
/**
 * Admin class.
 *
 * @link  https://webberzone.com
 * @since 1.0.0
 *
 * @package WebberZone\Glue_Link\Admin
 */

namespace WebberZone\Glue_Link\Admin;

use WebberZone\Glue_Link\Database;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class to handle all admin functionality.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * Settings API object.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	public $settings;

	/**
	 * Subscribers list table object.
	 *
	 * @since 1.0.0
	 * @var Subscribers_List
	 */
	public $subscribers_list;

	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var Database
	 */
	protected $database;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Database $database Database instance.
	 */
	public function __construct( Database $database ) {
		$this->database = $database;

		// Initialize settings.
		$this->settings = new Settings();

		// Initialize subscribers list.
		$this->subscribers_list = new Subscribers_List( $database );

		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// Add admin notices.
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add admin menu items.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function admin_menu(): void {
		// No menu items needed as we'll link from settings page.
	}

	/**
	 * Display admin notices.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		// Get any stored notices.
		$notices = get_transient( 'glue_link_admin_notices' );

		if ( false === $notices ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$notice_class = isset( $notice['class'] ) ? $notice['class'] : 'notice-info';
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice_class ),
				wp_kses_post( $notice['message'] )
			);
		}

		// Clear the notices.
		delete_transient( 'glue_link_admin_notices' );
	}

	/**
	 * Add an admin notice.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message      Notice message.
	 * @param string $notice_class Notice class.
	 * @return void
	 */
	public static function add_notice( $message, $notice_class = 'notice-info' ): void {
		$notices = get_transient( 'glue_link_admin_notices' );
		if ( false === $notices ) {
			$notices = array();
		}
		$notices[] = array(
			'message' => $message,
			'class'   => $notice_class,
		);

		set_transient( 'glue_link_admin_notices', $notices, HOUR_IN_SECONDS );
	}
}
