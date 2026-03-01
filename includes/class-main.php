<?php
/**
 * Main plugin bootstrap class.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit;

use WebberZone\FreemKit\Util\Hook_Registry;
use WebberZone\FreemKit\Kit\Kit_Credential_Hooks;
use WebberZone\FreemKit\Kit\Kit_Settings;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main class.
 */
class Main {

	/**
	 * Plugin instance.
	 *
	 * @var Main|null
	 */
	public static ?Main $instance = null;

	/**
	 * Runtime manager.
	 *
	 * @var Runtime
	 */
	public Runtime $runtime;

	/**
	 * OAuth credential hooks.
	 *
	 * @var Kit_Credential_Hooks
	 */
	public Kit_Credential_Hooks $credential_hooks;

	/**
	 * Language handler.
	 *
	 * @var Language_Handler
	 */
	public Language_Handler $language;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Main
	 */
	public static function get_instance(): Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->runtime          = new Runtime();
		$this->credential_hooks = new Kit_Credential_Hooks();
		$this->language         = new Language_Handler();
		$this->hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		Hook_Registry::add_action( 'init', array( $this->runtime, 'init' ), 1 );
		Hook_Registry::add_action( 'init', array( $this->runtime, 'init_admin' ) );
		Hook_Registry::add_action( 'freemkit_api_get_access_token', array( $this->credential_hooks, 'maybe_update_credentials' ), 10, 2 );
		Hook_Registry::add_action( 'freemkit_api_refresh_token', array( $this->credential_hooks, 'maybe_update_credentials' ), 10, 2 );
		Hook_Registry::add_action( 'convertkit_api_access_token_invalid', array( $this->credential_hooks, 'maybe_delete_credentials' ), 10, 2 );
		Hook_Registry::add_action( Kit_Settings::CRON_REFRESH_HOOK, array( $this->credential_hooks, 'refresh_kit_access_token' ) );
	}

	/**
	 * Plugin activation handler.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Runtime::activate();
	}
}
