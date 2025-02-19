<?php
/**
 * Database management class.
 *
 * @package WebberZone\Glue_Link
 */

namespace WebberZone\Glue_Link;

use WebberZone\Glue_Link\Subscriber;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class to handle database operations.
 *
 * @since 1.0.0
 */
class Database {

	/**
	 * Table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $table_name;

	/**
	 * Database version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $db_version = '1.0.0';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table_name = $wpdb->prefix . 'glue_link_subscribers';
	}

	/**
	 * Create the database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|\WP_Error True if table created successfully, WP_Error on failure.
	 */
	public function create_table(): bool|\WP_Error {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(100) NOT NULL,
			first_name varchar(50) DEFAULT '',
			last_name varchar(50) DEFAULT '',
			fields longtext DEFAULT NULL,
			tags longtext DEFAULT NULL,
			forms longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			modified datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$result = dbDelta( $sql );

		if ( ! empty( $wpdb->last_error ) ) {
			return new \WP_Error(
				'database_creation_error',
				sprintf(
					/* translators: 1: Database error */
					__( 'Error creating database table: %s', 'glue-link' ),
					$wpdb->last_error
				)
			);
		}

		add_option( 'glue_link_db_version', $this->db_version );

		return true;
	}

	/**
	 * Check if database needs to be updated.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if update is required, false otherwise.
	 */
	public function needs_update(): bool {
		$current_version = get_option( 'glue_link_db_version', '0' );
		return version_compare( $current_version, $this->db_version, '<' );
	}

	/**
	 * Get table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name.
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Clear subscriber cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $identifier Subscriber ID or email.
	 */
	public function clear_subscriber_cache( $identifier ): void {
		if ( is_int( $identifier ) ) {
			wp_cache_delete( 'glue_link_subscriber_' . $identifier, 'glue_link' );
		} elseif ( is_string( $identifier ) ) {
			wp_cache_delete( 'glue_link_subscriber_email_' . md5( $identifier ), 'glue_link' );
		}
	}

	/**
	 * Get subscriber by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Subscriber ID.
	 * @return Subscriber|\WP_Error Subscriber object or WP_Error on failure.
	 */
	public function get_subscriber( int $id ): Subscriber|\WP_Error {
		global $wpdb;

		$cache_key  = 'glue_link_subscriber_' . $id;
		$subscriber = wp_cache_get( $cache_key, 'glue_link' );

		if ( false === $subscriber ) {
			$table = $this->get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$subscriber = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$id
				)
			);

			if ( null === $subscriber ) {
				return new \WP_Error(
					'subscriber_not_found',
					__( 'Subscriber not found.', 'glue-link' )
				);
			}

			wp_cache_set( $cache_key, $subscriber, 'glue_link' );
		}

		return new Subscriber( (array) $subscriber );
	}

	/**
	 * Get subscriber by email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Subscriber email.
	 * @return Subscriber|\WP_Error Subscriber object or WP_Error on failure.
	 */
	public function get_subscriber_by_email( string $email ): Subscriber|\WP_Error {
		global $wpdb;

		$cache_key  = 'glue_link_subscriber_email_' . md5( $email );
		$subscriber = wp_cache_get( $cache_key, 'glue_link' );

		if ( false === $subscriber ) {
			$table = $this->get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$subscriber = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$email
				)
			);

			if ( ! $subscriber ) {
				return new \WP_Error(
					'subscriber_not_found',
					__( 'Subscriber not found.', 'glue-link' )
				);
			}

			wp_cache_set( $cache_key, $subscriber, 'glue_link' );
		}

		return new Subscriber( (array) $subscriber );
	}

	/**
	 * Insert subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $subscriber Subscriber object.
	 * @return int|\WP_Error Subscriber ID on success, WP_Error on failure.
	 */
	public function insert_subscriber( Subscriber $subscriber ): int|\WP_Error {
		global $wpdb;

		if ( empty( $subscriber->email ) ) {
			return new \WP_Error(
				'missing_email',
				__( 'Email is required.', 'glue-link' )
			);
		}

		// Check if subscriber already exists.
		$existing = $this->get_subscriber_by_email( $subscriber->email );
		if ( ! is_wp_error( $existing ) ) {
			return new \WP_Error(
				'subscriber_exists',
				__( 'Subscriber already exists.', 'glue-link' )
			);
		}

		$table = $this->get_table_name();
		$data  = array(
			'email'      => $subscriber->email,
			'first_name' => $subscriber->first_name,
			'last_name'  => $subscriber->last_name,
			'fields'     => is_array( $subscriber->fields ) ? implode( ',', array_unique( $subscriber->fields ) ) : $subscriber->fields,
			'tags'       => is_array( $subscriber->tags ) ? implode( ',', array_unique( $subscriber->tags ) ) : $subscriber->tags,
			'forms'      => is_array( $subscriber->forms ) ? implode( ',', array_unique( $subscriber->forms ) ) : $subscriber->forms,
			'status'     => ! empty( $subscriber->status ) ? $subscriber->status : 'active',
			'created'    => ! empty( $subscriber->created ) ? $subscriber->created : current_time( 'mysql', true ),
		);

		$format = array(
			'%s', // Email address.
			'%s', // First name.
			'%s', // Last name.
			'%s', // Custom fields.
			'%s', // Tags.
			'%s', // Forms.
			'%s', // Status.
			'%s', // Created date.
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $table, $data, $format );

		if ( false === $result ) {
			return new \WP_Error(
				'db_insert_error',
				__( 'Could not insert subscriber.', 'glue-link' )
			);
		}

		$this->clear_subscriber_cache( $subscriber->email );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $subscriber Subscriber object.
	 * @return int|\WP_Error Subscriber ID on success, WP_Error on failure.
	 */
	public function update_subscriber( Subscriber $subscriber ): int|\WP_Error {
		global $wpdb;

		if ( ! $subscriber->id ) {
			return new \WP_Error(
				'missing_id',
				__( 'Subscriber ID is required.', 'glue-link' )
			);
		}

		if ( empty( $subscriber->email ) ) {
			return new \WP_Error(
				'missing_email',
				__( 'Email is required.', 'glue-link' )
			);
		}

		// Check if email is taken by another subscriber.
		$existing = $this->get_subscriber_by_email( $subscriber->email );
		if ( ! is_wp_error( $existing ) && $existing->id !== $subscriber->id ) {
			return new \WP_Error(
				'email_exists',
				__( 'Email is already taken by another subscriber.', 'glue-link' )
			);
		}

		$table = $this->get_table_name();

		// Get existing subscriber data to merge arrays.
		$existing = $this->get_subscriber( $subscriber->id );
		if ( ! is_wp_error( $existing ) ) {
			// Convert arrays to comma-separated strings, merge, and ensure no duplicates.
			$subscriber->fields = array_unique( array_merge( wp_parse_list( $existing->fields ), wp_parse_list( $subscriber->fields ) ) );
			$subscriber->tags   = array_unique( array_merge( wp_parse_list( $existing->tags ), wp_parse_list( $subscriber->tags ) ) );
			$subscriber->forms  = array_unique( array_merge( wp_parse_list( $existing->forms ), wp_parse_list( $subscriber->forms ) ) );
		}

		// Prepare data for update or insert.
		$data = array(
			'email'      => sanitize_email( $subscriber->email ), // Ensure email is properly sanitized.
			'first_name' => sanitize_text_field( $subscriber->first_name ),
			'last_name'  => sanitize_text_field( $subscriber->last_name ),
			'fields'     => is_array( $subscriber->fields ) ? implode( ',', $subscriber->fields ) : $subscriber->fields,
			'tags'       => is_array( $subscriber->tags ) ? implode( ',', $subscriber->tags ) : $subscriber->tags,
			'forms'      => is_array( $subscriber->forms ) ? implode( ',', $subscriber->forms ) : $subscriber->forms,
			'status'     => $subscriber->status,
		);

		$format = array(
			'%s', // Email address.
			'%s', // First name.
			'%s', // Last name.
			'%s', // Custom fields.
			'%s', // Tags.
			'%s', // Forms.
			'%s', // Status.
		);

		$where = array(
			'id' => $subscriber->id,
		);

		$where_format = array(
			'%d',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, $data, $where, $format, $where_format );

		if ( false === $result ) {
			return new \WP_Error(
				'db_update_error',
				__( 'Could not update subscriber.', 'glue-link' )
			);
		}

		$this->clear_subscriber_cache( $subscriber->id );
		$this->clear_subscriber_cache( $subscriber->email );

		/**
		 * Fires after a subscriber is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param int       $subscriber_id Subscriber ID.
		 * @param Subscriber $subscriber    Subscriber object.
		 */
		do_action( 'glue_link_update_subscriber', $subscriber->id, $subscriber );

		return $subscriber->id;
	}

	/**
	 * Delete subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Subscriber ID.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_subscriber( int $id ): bool|\WP_Error {
		global $wpdb;

		$subscriber = $this->get_subscriber( $id );
		if ( is_wp_error( $subscriber ) ) {
			return $subscriber;
		}

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error(
				'db_delete_error',
				__( 'Could not delete subscriber.', 'glue-link' )
			);
		}

		$this->clear_subscriber_cache( $id );
		$this->clear_subscriber_cache( $subscriber->email );

		/**
		 * Fires after a subscriber is deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param int       $id         Subscriber ID.
		 * @param Subscriber $subscriber Subscriber object.
		 */
		do_action( 'glue_link_delete_subscriber', $id, $subscriber );

		return true;
	}

	/**
	 * Get subscribers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve subscribers.
	 *
	 *     @type string       $search   Search term.
	 *     @type string|array $status   Single status or array of statuses.
	 *     @type int         $per_page  Number of subscribers per page.
	 *     @type int         $page      Page number.
	 *     @type string      $orderby   Column to order by.
	 *     @type string      $order     Order direction.
	 * }
	 * @return Subscriber[] Array of Subscriber objects.
	 */
	public function get_subscribers( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'search'   => '',
			'status'   => '',
			'per_page' => 10,
			'page'     => 1,
			'orderby'  => 'id',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause.
		$where  = array();
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]     = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$values[]    = $search_like;
			$values[]    = $search_like;
			$values[]    = $search_like;
		}

		if ( ! empty( $args['status'] ) ) {
			$statuses = wp_parse_list( $args['status'] );
			if ( ! empty( $statuses ) ) {
				$placeholders = array_fill( 0, count( $statuses ), '%s' );
				$where[]      = 'status IN (' . implode( ', ', $placeholders ) . ')';
				$values       = array_merge( $values, $statuses );
			}
		}

		// Default WHERE clause if no conditions.
		if ( empty( $where ) ) {
			$where_clause = '';
		} else {
			$where_clause = 'WHERE ' . implode( ' AND ', $where );
		}

		// Calculate offset.
		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		$table = $this->get_table_name();

		// Build query.
		$sql = "SELECT * FROM {$table} {$where_clause}";

		// Add ORDER BY clause.
		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		if ( ! empty( $orderby ) ) {
			$sql .= " ORDER BY {$orderby}";
		}

		// Add LIMIT and OFFSET.
		$sql .= ' LIMIT %d OFFSET %d';

		// Merge LIMIT and OFFSET values.
		$values = array_merge( $values, array( $args['per_page'], $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		// Convert results to Subscriber objects.
		$items = array();
		foreach ( $results as $result ) {
			$items[] = new Subscriber( (array) $result );
		}

		return $items;
	}

	/**
	 * Get subscriber counts by status.
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error Array of counts by status or WP_Error on failure.
	 */
	public function get_subscriber_counts(): array|\WP_Error {
		global $wpdb;

		$cache_key = 'glue_link_subscriber_counts';
		$counts    = wp_cache_get( $cache_key, 'glue_link' );

		if ( false === $counts ) {
			$table = $this->get_table_name();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
			$results = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status" );

			if ( null === $results ) {
				return new \WP_Error(
					'db_query_error',
					sprintf(
						/* translators: %s: Database error */
						__( 'Could not get subscriber counts: %s', 'glue-link' ),
						$wpdb->last_error
					)
				);
			}

			$counts = array();
			foreach ( $results as $row ) {
				$counts[ $row->status ] = (int) $row->count;
			}

			wp_cache_set( $cache_key, $counts, 'glue_link', HOUR_IN_SECONDS );
		}

		return $counts;
	}

	/**
	 * Delete multiple subscribers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $ids Array of subscriber IDs.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_subscribers( array $ids ): bool|\WP_Error {
		global $wpdb;

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'invalid_ids',
				__( 'No subscriber IDs provided.', 'glue-link' )
			);
		}

		// Parse and validate IDs.
		$ids = wp_parse_id_list( $ids );

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'invalid_ids',
				__( 'No valid subscriber IDs provided.', 'glue-link' )
			);
		}

		// Delete subscribers.
		$table  = $this->get_table_name();
		$ids    = implode( ',', $ids );
		$result = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $result ) {
			return new \WP_Error(
				'db_delete_error',
				sprintf(
					/* translators: %s: Database error */
					__( 'Could not delete subscribers: %s', 'glue-link' ),
					$wpdb->last_error
				)
			);
		}

		return true;
	}

	/**
	 * Get subscriber count based on filters.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional. Arguments to filter subscribers.
	 *
	 *     @type string       $search  Search term.
	 *     @type string|array $status  Single status or array of statuses.
	 * }
	 * @return int Total number of subscribers matching the criteria.
	 */
	public function get_subscriber_count( array $args = array() ): int {
		global $wpdb;

		$defaults = array(
			'search' => '',
			'status' => '',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause.
		$where  = array();
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]     = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$values[]    = $search_like;
			$values[]    = $search_like;
			$values[]    = $search_like;
		}

		if ( ! empty( $args['status'] ) ) {
			$statuses = wp_parse_list( $args['status'] );
			if ( ! empty( $statuses ) ) {
				$placeholders = array_fill( 0, count( $statuses ), '%s' );
				$where[]      = 'status IN (' . implode( ', ', $placeholders ) . ')';
				$values       = array_merge( $values, $statuses );
			}
		}

		if ( ! empty( $where ) ) {
			$where_clause = implode( ' AND ', $where );
			$where_clause = $wpdb->prepare( $where_clause, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$where_clause = '1=1';
		}

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}" );
	}
}
