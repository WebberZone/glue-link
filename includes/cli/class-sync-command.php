<?php
/**
 * Sync WP-CLI Command class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

use WebberZone\FreemKit\Sync;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Sync subscribers from Freemius or the local database.
 *
 * @since 1.0.0
 */
class Sync_Command extends Base_Command {

	/**
	 * Sync subscribers from Freemius or the local database.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Sync source. One of: freemius, local. Default: freemius.
	 *
	 * [--destination=<destination>]
	 * : Sync destination. One of: local, both. Default: local.
	 *
	 * [--plugin=<id>]
	 * : Freemius plugin ID. Defaults to all configured plugins.
	 *
	 * [--user-types=<types>]
	 * : Comma-separated user types (free, paid, trial). Default: paid.
	 *
	 * [--form-ids=<ids>]
	 * : Override Kit form IDs. Comma-separated.
	 *
	 * [--tag-ids=<ids>]
	 * : Override Kit tag IDs. Comma-separated.
	 *
	 * [--batch=<number>]
	 * : Users to process per page. Default: 50. Max: 50.
	 *
	 * [--dry-run]
	 * : List matching users without writing to the database or Kit.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit sync --destination=local
	 *     wp freemkit sync --source=freemius --destination=both --user-types=paid --yes
	 *     wp freemkit sync --source=local --plugin=123 --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		try {
			$this->validate_environment();

			$source      = isset( $assoc_args['source'] ) ? sanitize_key( $assoc_args['source'] ) : 'freemius';
			$destination = isset( $assoc_args['destination'] ) ? sanitize_key( $assoc_args['destination'] ) : 'local';
			$plugin_id   = isset( $assoc_args['plugin'] ) ? sanitize_text_field( $assoc_args['plugin'] ) : '';
			$batch       = isset( $assoc_args['batch'] ) ? min( 50, max( 1, absint( $assoc_args['batch'] ) ) ) : 50;
			$dry_run     = isset( $assoc_args['dry-run'] );

			if ( ! in_array( $source, array( 'freemius', 'local' ), true ) ) {
				\WP_CLI::error( '--source must be either freemius or local.' );
			}

			if ( ! in_array( $destination, array( 'local', 'both' ), true ) ) {
				\WP_CLI::error( '--destination must be either local or both.' );
			}

			$allowed_types = array( 'free', 'paid', 'trial' );
			$raw_types     = isset( $assoc_args['user-types'] ) ? $assoc_args['user-types'] : 'paid';
			$user_types    = array_values( array_intersect( array_map( 'sanitize_key', wp_parse_list( $raw_types ) ), $allowed_types ) );

			if ( empty( $user_types ) ) {
				\WP_CLI::error( '--user-types must include at least one of: free, paid, trial.' );
			}

			$sync           = new Sync();
			$plugin_configs = $sync->get_plugin_configs();
			$override_forms = isset( $assoc_args['form-ids'] ) ? (string) $assoc_args['form-ids'] : '';
			$override_tags  = isset( $assoc_args['tag-ids'] ) ? (string) $assoc_args['tag-ids'] : '';

			if ( empty( $plugin_configs ) ) {
				\WP_CLI::error( 'No plugins configured. Add at least one plugin in FreemKit Settings.' );
			}

			if ( '' !== $plugin_id && ! isset( $plugin_configs[ $plugin_id ] ) ) {
				\WP_CLI::error( sprintf( 'Plugin ID %s not found in settings.', $plugin_id ) );
			}

			$scope = '' === $plugin_id ? 'all plugins' : sprintf( 'plugin %s', $plugin_id );
			$label = sprintf(
				'%s → %s (%s, types: %s)',
				$source,
				$dry_run ? 'dry-run' : $destination,
				$scope,
				implode( ', ', $user_types )
			);

			if ( ! $dry_run ) {
				\WP_CLI::confirm( sprintf( 'Start sync: %s?', $label ), $assoc_args );
			}

			\WP_CLI::log( sprintf( 'Starting sync: %s', $label ) );

			$counts = array(
				'processed' => 0,
				'synced'    => 0,
				'updated'   => 0,
				'opted_out' => 0,
				'skipped'   => 0,
				'errors'    => 0,
			);

			$offset       = 0;
			$plugin_index = 0;

			do {
				$page = $sync->list_users( $source, $plugin_id, $user_types, $offset, $batch, $plugin_index );
				if ( is_wp_error( $page ) ) {
					\WP_CLI::error( $page->get_error_message() );
				}

				$tasks        = $page['tasks'] ?? array();
				$has_more     = ! empty( $page['has_more'] );
				$offset       = (int) ( $page['offset'] ?? ( $offset + count( $tasks ) ) );
				$plugin_index = (int) ( $page['next_plugin_index'] ?? $plugin_index );

				if ( empty( $tasks ) ) {
					if ( ! $has_more && 0 === $counts['processed'] ) {
						\WP_CLI::warning( 'No users found matching the selected criteria.' );
						return;
					}
					continue;
				}

				foreach ( $tasks as &$task ) {
					$task['destination']       = $destination;
					$task['override_form_ids'] = $override_forms;
					$task['override_tag_ids']  = $override_tags;
					$task['user_types']        = $user_types;
					$task['plugin_name']       = $task['plugin_name'] ?? $task['plugin_id'] ?? '';
				}
				unset( $task );

				if ( $dry_run ) {
					$results = array();
					foreach ( $tasks as $task ) {
						$results[] = array(
							'action'      => 'skipped',
							'email'       => $task['email'] ?? '',
							'first_name'  => $task['first_name'] ?? '',
							'last_name'   => $task['last_name'] ?? '',
							'user_type'   => $task['user_type'] ?? '',
							'plugin_name' => $task['plugin_name'] ?? '',
							'destination' => $destination,
							'error'       => 'dry-run',
						);
					}
				} else {
					$results = $sync->process_batch( $tasks );
				}

				foreach ( $results as $result ) {
					++$counts['processed'];
					$action = $result['action'] ?? 'error';
					if ( isset( $counts[ $action ] ) ) {
						++$counts[ $action ];
					} elseif ( 'error' === $action ) {
						++$counts['errors'];
					}

					if ( 'error' === $action ) {
						\WP_CLI::warning(
							sprintf(
								'%s: %s',
								$result['email'] ?? '(no email)',
								$result['error'] ?? 'Unknown error.'
							)
						);
					}
				}

				\WP_CLI::log( sprintf( 'Processed %d user(s)…', $counts['processed'] ) );
			} while ( $has_more );

			\WP_CLI::success(
				sprintf(
					'Processed: %d • Synced: %d • Updated: %d • Opted-out: %d • Skipped: %d • Errors: %d',
					$counts['processed'],
					$counts['synced'],
					$counts['updated'],
					$counts['opted_out'],
					$counts['skipped'],
					$counts['errors']
				)
			);
		} catch ( \Exception $e ) {
			$this->fatal_error( $e );
		}
	}
}
