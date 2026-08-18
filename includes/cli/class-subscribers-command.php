<?php
/**
 * Subscribers WP-CLI Command class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

use WebberZone\FreemKit\Database;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Manage local FreemKit subscribers.
 *
 * @since 1.0.0
 */
class Subscribers_Command extends Base_Command {

	/**
	 * List subscribers.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status. Comma-separated for multiple.
	 *
	 * [--search=<search>]
	 * : Search by email, first or last name.
	 *
	 * [--marketing=<marketing>]
	 * : Filter by marketing consent (1 for opted in, 0 for opted out).
	 *
	 * [--all]
	 * : Return all matching subscribers. Ignores --page.
	 *
	 * [--number=<number>]
	 * : Number of subscribers to return. Default: 20.
	 *
	 * [--per-page=<number>]
	 * : Alias for --number.
	 *
	 * [--page=<number>]
	 * : Page number. Default: 1.
	 *
	 * [--orderby=<column>]
	 * : Column to order by. Default: id.
	 *
	 * [--order=<order>]
	 * : Order direction (ASC or DESC). Default: DESC.
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit subscribers list
	 *     wp freemkit subscribers list --status=active --marketing=1 --number=50
	 *     wp freemkit subscribers list --all --format=csv
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		try {
			$this->validate_environment();

			$database    = new Database();
			$format      = $this->get_format( $assoc_args );
			$subscribers = $database->get_subscribers( $this->get_query_args( $assoc_args ) );

			$data = array();
			foreach ( $subscribers as $subscriber ) {
				$data[] = $this->to_row( $subscriber );
			}

			$this->format_output( $data, $format );
		} catch ( \Exception $e ) {
			$this->fatal_error( $e );
		}
	}

	/**
	 * Export subscribers in CSV format.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status. Comma-separated for multiple.
	 *
	 * [--search=<search>]
	 * : Search by email, first or last name.
	 *
	 * [--marketing=<marketing>]
	 * : Filter by marketing consent (1 for opted in, 0 for opted out).
	 *
	 * [--number=<number>]
	 * : Number of subscribers to export. Defaults to all matching subscribers.
	 *
	 * [--orderby=<column>]
	 * : Column to order by. Default: id.
	 *
	 * [--order=<order>]
	 * : Order direction (ASC or DESC). Default: DESC.
	 *
	 * [--file=<path>]
	 * : Path to write the CSV file. Default: freemkit-subscribers-{timestamp}.csv
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit subscribers export
	 *     wp freemkit subscribers export --file=subscribers.csv --marketing=1 --number=100
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function export( array $args, array $assoc_args ): void {
		try {
			$this->validate_environment();

			if ( ! isset( $assoc_args['number'] ) && ! isset( $assoc_args['per-page'] ) ) {
				$assoc_args['all'] = true;
			}

			$file = isset( $assoc_args['file'] ) ? $assoc_args['file'] : sprintf( 'freemkit-subscribers-%s.csv', gmdate( 'Y-m-d-H-i-s' ) );

			if ( ! $this->is_path_safe( $file ) ) {
				\WP_CLI::error( 'Invalid export path. Files can only be exported to the WordPress root, uploads, or temp directory.' );
			}

			$database    = new Database();
			$subscribers = $database->get_subscribers( $this->get_query_args( $assoc_args ) );
			$data        = array();
			foreach ( $subscribers as $subscriber ) {
				$data[] = $this->to_row( $subscriber );
			}

			$this->write_csv_file( $file, $data );

			\WP_CLI::success( sprintf( 'Exported %d subscriber(s) to %s', count( $data ), $file ) );
		} catch ( \Exception $e ) {
			$this->fatal_error( $e );
		}
	}

	/**
	 * Get a single subscriber by ID or email.
	 *
	 * ## OPTIONS
	 *
	 * <id-or-email>
	 * : Subscriber ID or email address.
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit subscribers get 42
	 *     wp freemkit subscribers get user@example.com --format=json
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please specify a subscriber ID or email.' );
		}

		$database   = new Database();
		$identifier = $args[0];
		$subscriber = is_numeric( $identifier )
			? $database->get_subscriber( (int) $identifier )
			: $database->get_subscriber_by_email( (string) $identifier );

		if ( is_wp_error( $subscriber ) ) {
			\WP_CLI::error( $subscriber->get_error_message() );
		}

		$this->format_output( array( $this->to_row( $subscriber ) ), $this->get_format( $assoc_args ) );
	}

	/**
	 * Delete one or more subscribers.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more subscriber IDs to delete.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit subscribers delete 42
	 *     wp freemkit subscribers delete 42 43 44 --yes
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			\WP_CLI::error( 'Please specify at least one subscriber ID.' );
		}

		\WP_CLI::confirm( sprintf( 'Delete %d subscriber(s)?', count( $args ) ), $assoc_args );

		$database = new Database();
		$deleted  = 0;

		foreach ( $args as $id ) {
			$result = $database->delete_subscriber( absint( $id ) );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( 'Subscriber %s: %s', $id, $result->get_error_message() ) );
				continue;
			}
			++$deleted;
		}

		\WP_CLI::success( sprintf( 'Deleted %d subscriber(s).', $deleted ) );
	}

	/**
	 * List a subscriber's per-plugin webhook events.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Subscriber ID.
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit subscribers events 42
	 *     wp freemkit subscribers events 42 --format=json
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function events( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please specify a subscriber ID.' );
		}

		$database = new Database();
		$events   = $database->get_subscriber_events( absint( $args[0] ) );

		$data = array();
		foreach ( $events as $event ) {
			$data[] = $event->to_array();
		}

		$this->format_output( $data, $this->get_format( $assoc_args ) );
	}

	/**
	 * Build Database::get_subscribers() args from CLI flags.
	 *
	 * @since 1.0.0
	 *
	 * @param array $assoc_args Command associative arguments.
	 * @return array<string,mixed>
	 */
	private function get_query_args( array $assoc_args ): array {
		if ( isset( $assoc_args['marketing'] ) && ! in_array( (string) $assoc_args['marketing'], array( '0', '1' ), true ) ) {
			\WP_CLI::error( '--marketing must be either 0 or 1.' );
		}

		if ( isset( $assoc_args['all'] ) && ( isset( $assoc_args['number'] ) || isset( $assoc_args['per-page'] ) ) ) {
			\WP_CLI::error( '--all cannot be combined with --number or --per-page.' );
		}

		if ( isset( $assoc_args['number'] ) && isset( $assoc_args['per-page'] ) ) {
			\WP_CLI::error( '--number and --per-page cannot be used together.' );
		}

		$number = isset( $assoc_args['number'] ) ? absint( $assoc_args['number'] ) : 20;
		$number = isset( $assoc_args['per-page'] ) ? absint( $assoc_args['per-page'] ) : $number;

		if ( isset( $assoc_args['all'] ) ) {
			$number = 0;
		} elseif ( 0 === $number ) {
			\WP_CLI::error( '--number must be greater than 0.' );
		}

		return array(
			'search'    => $assoc_args['search'] ?? '',
			'status'    => $assoc_args['status'] ?? '',
			'marketing' => isset( $assoc_args['marketing'] ) ? (int) $assoc_args['marketing'] : null,
			'per_page'  => $number,
			'page'      => isset( $assoc_args['page'] ) ? max( 1, absint( $assoc_args['page'] ) ) : 1,
			'orderby'   => $assoc_args['orderby'] ?? 'id',
			'order'     => $assoc_args['order'] ?? 'DESC',
		);
	}

	/**
	 * Write rows to a CSV file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Destination path.
	 * @param array  $data Rows to write.
	 */
	private function write_csv_file( string $file, array $data ): void {
		$output = fopen( $file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $output ) {
			\WP_CLI::error( 'Failed to write subscribers file.' );
		}

		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$headers = ! empty( $data ) ? array_keys( $data[0] ) : array( 'id', 'email', 'first_name', 'last_name', 'status', 'marketing', 'kit_status', 'created' );
		fputcsv( $output, $headers, ',', '"', '\\' );

		foreach ( $data as $row ) {
			fputcsv( $output, $row, ',', '"', '\\' );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Convert a Subscriber object into a flat display row.
	 *
	 * @since 1.0.0
	 *
	 * @param \WebberZone\FreemKit\Subscriber $subscriber Subscriber object.
	 * @return array<string,mixed>
	 */
	private function to_row( \WebberZone\FreemKit\Subscriber $subscriber ): array {
		return array(
			'id'         => $subscriber->id,
			'email'      => $subscriber->email,
			'first_name' => $subscriber->first_name,
			'last_name'  => $subscriber->last_name,
			'status'     => $subscriber->status,
			'marketing'  => $subscriber->marketing,
			'kit_status' => $subscriber->kit_status,
			'created'    => $subscriber->created,
		);
	}
}
