<?php
/**
 * Webhook queue WP-CLI Command class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

use WebberZone\FreemKit\Kit\Kit_API;
use WebberZone\FreemKit\Runtime;
use WebberZone\FreemKit\Webhook_Handler;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Inspect and manage the asynchronous webhook processing queue.
 *
 * Queue and dedup state are stored as WP transients named
 * `freemkit_webhook_queue_{event_key}` and `freemkit_webhook_seen_{event_key}`
 * (see Webhook_Handler). These prefixes are read directly here rather than
 * exposed as public constants on Webhook_Handler, to keep this an
 * additive-only change.
 *
 * @since 1.0.0
 */
class Webhook_Command extends Base_Command {

	/**
	 * Queue transient prefix (without the `_transient_` wrapper).
	 */
	private const QUEUE_PREFIX = 'freemkit_webhook_queue_';

	/**
	 * Seen/dedup transient prefix (without the `_transient_` wrapper).
	 */
	private const SEEN_PREFIX = 'freemkit_webhook_seen_';

	/**
	 * List queued (pending) webhook events.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit webhook queue-list
	 *     wp freemkit webhook queue-list --format=json
	 *
	 * @subcommand queue-list
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function queue_list( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( $this->get_queue_entries() as $event_key => $payload ) {
			$rows[] = array(
				'event_key' => $event_key,
				'attempts'  => isset( $payload['attempts'] ) ? (int) $payload['attempts'] : 0,
				'next_run'  => $this->get_next_run( $event_key ),
			);
		}

		$this->format_output( $rows, $this->get_format( $assoc_args ) );
	}

	/**
	 * Force-process a queued webhook event immediately.
	 *
	 * ## OPTIONS
	 *
	 * <event-key>
	 * : The queued event key, as shown by `wp freemkit webhook queue-list`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit webhook process abc123
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function process( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please specify an event key.' );
		}

		$event_key = sanitize_key( $args[0] );

		if ( false === get_transient( self::QUEUE_PREFIX . $event_key ) ) {
			\WP_CLI::error( sprintf( 'No queued event found for key "%s".', $event_key ) );
		}

		$runtime = new Runtime();
		$handler = new Webhook_Handler( $runtime->get_plugin_configs(), new Kit_API(), $runtime->database );
		$handler->process_queued_webhook( $event_key );

		\WP_CLI::success( sprintf( 'Processed webhook event "%s".', $event_key ) );
	}

	/**
	 * Clear queued and/or deduplication transients.
	 *
	 * ## OPTIONS
	 *
	 * [<event-key>]
	 * : Clear a single event key instead of everything.
	 *
	 * [--all]
	 * : Clear every queue and dedup transient.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt when clearing everything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit webhook clear abc123
	 *     wp freemkit webhook clear --all
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function clear( array $args, array $assoc_args ): void {
		if ( ! empty( $args[0] ) ) {
			$event_key = sanitize_key( $args[0] );
			delete_transient( self::QUEUE_PREFIX . $event_key );
			delete_transient( self::SEEN_PREFIX . $event_key );
			\WP_CLI::success( sprintf( 'Cleared event "%s".', $event_key ) );
			return;
		}

		if ( ! isset( $assoc_args['all'] ) ) {
			\WP_CLI::error( 'Specify an event key or pass --all to clear the entire queue.' );
		}

		\WP_CLI::confirm( 'Clear the entire webhook queue and dedup cache?', $assoc_args );

		$queue_count = $this->delete_transients_by_prefix( self::QUEUE_PREFIX );
		$seen_count  = $this->delete_transients_by_prefix( self::SEEN_PREFIX );

		\WP_CLI::success( sprintf( 'Cleared %d queued and %d deduplication entries.', $queue_count, $seen_count ) );
	}

	/**
	 * Fetch all currently queued events keyed by event key.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_queue_entries(): array {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::QUEUE_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$entries = array();
		foreach ( (array) $rows as $row ) {
			$event_key             = substr( $row['option_name'], strlen( '_transient_' . self::QUEUE_PREFIX ) );
			$payload               = maybe_unserialize( $row['option_value'] );
			$entries[ $event_key ] = is_array( $payload ) ? $payload : array();
		}

		return $entries;
	}

	/**
	 * Get the next scheduled run time for a queued event, if any.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_key Event key.
	 * @return string
	 */
	private function get_next_run( string $event_key ): string {
		$timestamp = wp_next_scheduled( 'freemkit_process_webhook_event', array( $event_key ) );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC' : 'not scheduled';
	}

	/**
	 * Delete every transient matching a given prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Transient key prefix (without the `_transient_` wrapper).
	 * @return int Number of entries deleted.
	 */
	private function delete_transients_by_prefix( string $prefix ): int {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$keys = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$count = 0;
		foreach ( (array) $keys as $option_name ) {
			$event_key = substr( $option_name, strlen( '_transient_' . $prefix ) );
			if ( delete_transient( $prefix . $event_key ) ) {
				++$count;
			}
		}

		return $count;
	}
}
