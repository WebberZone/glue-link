<?php
/**
 * Audit log WP-CLI Command class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

use WebberZone\FreemKit\Audit_Log;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Inspect and manage the plugin-wide audit log.
 *
 * @since 1.0.0
 */
class Audit_Log_Command extends Base_Command {

	/**
	 * List audit log entries, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of entries to show. Default: 50.
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit audit-log list
	 *     wp freemkit audit-log list --limit=10 --format=json
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$limit   = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
		$entries = array_slice( Audit_Log::all(), 0, $limit > 0 ? $limit : 50 );

		$data = array();
		foreach ( $entries as $entry ) {
			$data[] = array(
				'time'    => isset( $entry['time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) . ' UTC' : '',
				'level'   => $entry['level'] ?? '',
				'event'   => $entry['event'] ?? '',
				'context' => isset( $entry['context'] ) ? wp_json_encode( $entry['context'] ) : '',
			);
		}

		$this->format_output( $data, $this->get_format( $assoc_args ) );
	}

	/**
	 * Clear all audit log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit audit-log clear --yes
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function clear( array $args, array $assoc_args ): void {
		\WP_CLI::confirm( 'Clear the entire audit log?', $assoc_args );

		Audit_Log::clear();

		\WP_CLI::success( 'Audit log cleared.' );
	}
}
