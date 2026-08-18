<?php
/**
 * CLI Manager class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * CLI Manager class for registering WP-CLI commands.
 *
 * @since 1.0.0
 */
class CLI_Manager {

	/**
	 * Command instances.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private array $commands = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$this->init_commands();
		$this->register_commands();
	}

	/**
	 * Initialize command instances.
	 *
	 * @since 1.0.0
	 */
	private function init_commands(): void {
		$this->commands = array(
			'status'      => new Status_Command(),
			'settings'    => new Settings_Command(),
			'subscribers' => new Subscribers_Command(),
			'sync'        => new Sync_Command(),
			'webhook'     => new Webhook_Command(),
			'kit'         => new Kit_Command(),
			'audit-log'   => new Audit_Log_Command(),
			'db'          => new DB_Command(),
		);
	}

	/**
	 * Register all WP-CLI commands.
	 *
	 * @since 1.0.0
	 */
	public function register_commands(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		foreach ( $this->commands as $command_name => $command_instance ) {
			\WP_CLI::add_command( "freemkit {$command_name}", $command_instance );
		}
	}

	/**
	 * Get command instance by name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $command_name Command name.
	 * @return Base_Command|null Command instance or null if not found.
	 */
	public function get_command( string $command_name ): ?Base_Command {
		return $this->commands[ $command_name ] ?? null;
	}

	/**
	 * Get all registered commands.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of command instances.
	 */
	public function get_commands(): array {
		return $this->commands;
	}
}
