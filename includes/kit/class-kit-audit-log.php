<?php
/**
 * Lightweight audit log for Kit credential events.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit\Kit;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Kit_Audit_Log.
 */
class Kit_Audit_Log {

	/**
	 * Option name storing log entries.
	 */
	public const OPTION = 'freemkit_kit_audit_log';

	/**
	 * Default maximum number of entries to keep.
	 */
	public const DEFAULT_MAX_ENTRIES = 200;

	/**
	 * Add an audit log entry.
	 *
	 * @param string $event   Event key.
	 * @param array  $context Optional event context.
	 * @param string $level   Log level.
	 * @return void
	 */
	public static function add( string $event, array $context = array(), string $level = 'info' ): void {
		$entries   = self::read();
		$entries[] = array(
			'time'    => time(),
			'event'   => sanitize_key( $event ),
			'level'   => sanitize_key( $level ),
			'context' => self::sanitize_context( $context ),
		);

		$max = (int) apply_filters( 'freemkit_kit_audit_log_max_entries', self::DEFAULT_MAX_ENTRIES );
		if ( $max < 1 ) {
			$max = self::DEFAULT_MAX_ENTRIES;
		}
		if ( count( $entries ) > $max ) {
			$entries = array_slice( $entries, -1 * $max );
		}

		self::write( $entries );
	}

	/**
	 * Return log entries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return self::read();
	}

	/**
	 * Clear all entries.
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::write( array() );
	}

	/**
	 * Read current entries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function read(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Persist entries to a non-autoloaded option.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries.
	 * @return void
	 */
	private static function write( array $entries ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $entries, '', false );
			return;
		}

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Sanitize log context values for safe storage.
	 *
	 * @param array $context Context values.
	 * @return array
	 */
	private static function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$sanitized_key = sanitize_key( (string) $key );
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $sanitized_key ] = sanitize_text_field( (string) $value );
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $sanitized_key ] = wp_json_encode( $value );
			}
		}

		return $clean;
	}
}
