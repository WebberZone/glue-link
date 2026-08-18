<?php
/**
 * Base WP-CLI Command class for FreemKit.
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
 * Abstract base class for all FreemKit CLI commands.
 *
 * @since 1.0.0
 */
abstract class Base_Command extends \WP_CLI_Command {

	/**
	 * Validate environment before running CLI commands.
	 *
	 * @since 1.0.0
	 *
	 * @throws \Exception If environment validation fails.
	 */
	protected function validate_environment(): void {
		if ( ! class_exists( '\WebberZone\FreemKit\Main' ) ) {
			throw new \Exception( 'FreemKit plugin is not active.' );
		}

		global $wpdb;
		$result = $wpdb->query( 'SELECT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $result ) {
			throw new \Exception( 'Database connection failed.' );
		}
	}

	/**
	 * Handle fatal errors with proper logging.
	 *
	 * @since 1.0.0
	 *
	 * @param \Exception $exception Exception to handle.
	 * @return void
	 */
	protected function fatal_error( \Exception $exception ): void {
		\WP_CLI::error(
			sprintf(
				'%s in %s:%d',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			)
		);
	}

	/**
	 * Get output format from arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return string Output format.
	 */
	protected function get_format( array $assoc_args ): string {
		$format        = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$valid_formats = array( 'table', 'json', 'csv' );

		if ( ! in_array( $format, $valid_formats, true ) ) {
			\WP_CLI::warning( "Invalid format '{$format}'. Using 'table' instead." );
			return 'table';
		}

		return $format;
	}

	/**
	 * Check if verbose mode is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return bool True if verbose mode is enabled.
	 */
	protected function is_verbose( array $assoc_args ): bool {
		return isset( $assoc_args['verbose'] );
	}

	/**
	 * Check if force mode is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return bool True if force mode is enabled.
	 */
	protected function is_force( array $assoc_args ): bool {
		return isset( $assoc_args['force'] );
	}

	/**
	 * Format output based on requested format.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data   Data to output.
	 * @param string $format Output format (table, json, csv).
	 * @return void
	 */
	protected function format_output( array $data, string $format = 'table' ): void {
		switch ( $format ) {
			case 'json':
				\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
				break;
			case 'csv':
				$this->output_csv( $data );
				break;
			case 'table':
			default:
				if ( empty( $data ) ) {
					\WP_CLI::line( 'No items found.' );
					break;
				}
				\WP_CLI\Utils\format_items( 'table', $data, array_keys( $data[0] ) );
				break;
		}
	}

	/**
	 * Output data in CSV format.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Data to output.
	 * @return void
	 */
	protected function output_csv( array $data ): void {
		if ( empty( $data ) ) {
			return;
		}

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		$headers = array_keys( $data[0] );
		fputcsv( $output, $headers, ',', '"', '\\' );

		foreach ( $data as $row ) {
			fputcsv( $output, $row, ',', '"', '\\' );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Check if the given path is safe for file operations.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path File path to check.
	 * @return bool True if path is safe.
	 */
	protected function is_path_safe( string $path ): bool {
		if ( ! \WP_CLI\Utils\is_path_absolute( $path ) ) {
			return true;
		}

		$upload_dir = wp_get_upload_dir();
		$safe_paths = array(
			ABSPATH,
			get_temp_dir(),
			$upload_dir['basedir'],
		);

		$real_path = $this->resolve_path( $path, true );

		foreach ( $safe_paths as $safe_path ) {
			$safe_real = rtrim( $this->resolve_path( $safe_path, false ), DIRECTORY_SEPARATOR );

			if ( $real_path === $safe_real || 0 === strpos( $real_path, $safe_real . DIRECTORY_SEPARATOR ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a path to an absolute form for safety comparisons.
	 *
	 * Prefers realpath(), but realpath() can return false on hosts where
	 * open_basedir or a symlinked document root/home directory prevents it
	 * from fully resolving an otherwise valid path. Falling back to a purely
	 * lexical normalisation (rather than failing closed) keeps the safety
	 * check working on those hosts without needing filesystem access.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path                   Path to resolve.
	 * @param bool   $allow_dirname_fallback Whether to retry against dirname() when the path itself doesn't exist yet.
	 * @return string Best-effort absolute path.
	 */
	private function resolve_path( string $path, bool $allow_dirname_fallback ): string {
		$real_path = realpath( $path );
		if ( false !== $real_path ) {
			return $real_path;
		}

		if ( $allow_dirname_fallback ) {
			$dir_real_path = realpath( dirname( $path ) );
			if ( false !== $dir_real_path ) {
				return rtrim( $dir_real_path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . basename( $path );
			}
		}

		return $this->normalize_path_string( $path );
	}

	/**
	 * Lexically normalise a path by collapsing `.` and `..` segments.
	 *
	 * Does not touch the filesystem, so it works even where realpath() can't.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path to normalise.
	 * @return string Normalised path.
	 */
	private function normalize_path_string( string $path ): string {
		$path        = wp_normalize_path( $path );
		$is_absolute = isset( $path[0] ) && '/' === $path[0];
		$segments    = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return ( $is_absolute ? '/' : '' ) . implode( '/', $segments );
	}
}
