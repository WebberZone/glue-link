<?php
/**
 * Settings WP-CLI Command class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

use WebberZone\FreemKit\Options_API;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Settings management commands for FreemKit.
 *
 * @since 1.0.0
 */
class Settings_Command extends Base_Command {

	/**
	 * List all plugin settings.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit settings list
	 *     wp freemkit settings list --format=json
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$format   = $this->get_format( $assoc_args );
		$settings = Options_API::get_settings();

		$data = array();
		foreach ( $settings as $key => $value ) {
			$data[] = array(
				'key'   => $key,
				'value' => is_array( $value ) ? wp_json_encode( $value ) : (string) $value,
			);
		}

		$this->format_output( $data, $format );
	}

	/**
	 * Get a specific setting value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key to retrieve.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv, value. Default: value.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit settings get kit_default_form_id
	 *     wp freemkit settings get plugins --format=json
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please specify a setting key.' );
		}

		$key    = $args[0];
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'value';
		$value  = Options_API::get_option( $key );

		if ( 'value' === $format ) {
			if ( is_bool( $value ) ) {
				\WP_CLI::line( $value ? 'true' : 'false' );
			} elseif ( is_array( $value ) ) {
				\WP_CLI::line( wp_json_encode( $value ) );
			} else {
				\WP_CLI::line( (string) $value );
			}
			return;
		}

		$data = array(
			array(
				'key'   => $key,
				'value' => is_array( $value ) ? wp_json_encode( $value ) : (string) $value,
				'type'  => gettype( $value ),
			),
		);
		$this->format_output( $data, $this->get_format( array( 'format' => $format ) ) );
	}

	/**
	 * Set a specific setting value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key to set.
	 *
	 * <value>
	 * : Setting value to set.
	 *
	 * [--type=<type>]
	 * : Value type. Options: string, int, float, bool, array. Default: auto-detect.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit settings set enable_audit_log true --type=bool
	 *     wp freemkit settings set kit_default_form_id 12345 --type=int
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function set( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			\WP_CLI::error( 'Please specify both key and value.' );
		}

		$key   = $args[0];
		$value = $args[1];
		$type  = $assoc_args['type'] ?? null;

		$defaults = Options_API::get_settings_defaults();
		if ( ! array_key_exists( $key, $defaults ) ) {
			\WP_CLI::error( sprintf( 'Invalid option key "%s".', $key ) );
		}

		$converted_value = $this->convert_value( $value, $type );
		$old_value       = Options_API::get_option( $key );

		$result = Options_API::update_option( $key, $converted_value );

		// update_option() returns false when the new value matches the stored one,
		// which is still a success from the caller's perspective.
		if ( $result || $converted_value === $old_value ) {
			\WP_CLI::success(
				sprintf(
					'Updated setting "%s" from "%s" to "%s"',
					$key,
					$this->format_value( $old_value ),
					$this->format_value( $converted_value )
				)
			);
		} else {
			\WP_CLI::error( 'Failed to update setting.' );
		}
	}

	/**
	 * Export plugin settings to a file.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Path to export file. Default: freemkit-settings-{timestamp}.json
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit settings export
	 *     wp freemkit settings export --file=my-settings.json
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function export( array $args, array $assoc_args ): void {
		$file = isset( $assoc_args['file'] ) ? $assoc_args['file'] : sprintf( 'freemkit-settings-%s.json', gmdate( 'Y-m-d-H-i-s' ) );

		if ( ! $this->is_path_safe( $file ) ) {
			\WP_CLI::error( 'Invalid export path. Files can only be exported to the WordPress root, uploads, or temp directory.' );
		}

		$settings = Options_API::get_settings();
		$content  = wp_json_encode( $settings, JSON_PRETTY_PRINT );

		if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			\WP_CLI::error( 'Failed to write settings file.' );
		}

		\WP_CLI::success( sprintf( 'Settings exported to %s (%d settings)', $file, count( $settings ) ) );
	}

	/**
	 * Import plugin settings from a file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to import file.
	 *
	 * [--merge]
	 * : Merge with existing settings instead of replacing.
	 *
	 * [--dry-run]
	 * : Show what would be imported without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit settings import settings.json
	 *     wp freemkit settings import settings.json --merge
	 *     wp freemkit settings import settings.json --dry-run
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function import( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please specify a file to import.' );
		}

		$file    = $args[0];
		$merge   = isset( $assoc_args['merge'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( ! $this->is_path_safe( $file ) ) {
			\WP_CLI::error( 'Invalid import path. Files can only be imported from the WordPress root, uploads, or temp directory.' );
		}

		if ( ! file_exists( $file ) ) {
			\WP_CLI::error( sprintf( 'File not found: %s', $file ) );
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded = false !== $content ? json_decode( $content, true ) : null;

		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( 'No valid settings found in file.' );
		}

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( 'Dry run: Would import %d settings.', count( $decoded ) ) );
			return;
		}

		if ( ! $merge ) {
			update_option( Options_API::SETTINGS_OPTION, $decoded );
			Options_API::flush_cache();
			\WP_CLI::success( sprintf( 'Imported %d settings successfully.', count( $decoded ) ) );
			return;
		}

		$imported = 0;
		foreach ( $decoded as $key => $value ) {
			if ( Options_API::update_option( $key, $value ) ) {
				++$imported;
			}
		}

		\WP_CLI::success( sprintf( 'Imported %d settings successfully.', $imported ) );
	}

	/**
	 * Convert value to specified type.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $value Value to convert.
	 * @param string $type  Target type.
	 * @return mixed Converted value.
	 */
	private function convert_value( $value, ?string $type ) {
		if ( null === $type ) {
			if ( 'true' === $value || 'false' === $value ) {
				return 'true' === $value;
			}
			if ( is_numeric( $value ) ) {
				return strpos( $value, '.' ) !== false ? (float) $value : (int) $value;
			}
			if ( is_string( $value ) && isset( $value[0] ) && ( '[' === $value[0] || '{' === $value[0] ) ) {
				$decoded = json_decode( $value, true );
				return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
			}
			return $value;
		}

		switch ( $type ) {
			case 'bool':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			case 'int':
				return (int) $value;
			case 'float':
				return (float) $value;
			case 'array':
				return is_string( $value ) ? json_decode( $value, true ) : (array) $value;
			case 'string':
			default:
				return (string) $value;
		}
	}

	/**
	 * Format value for display.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value to format.
	 * @return string Formatted value.
	 */
	private function format_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}
}
