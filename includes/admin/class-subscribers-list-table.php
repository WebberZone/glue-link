<?php
/**
 * Subscribers List Table class.
 *
 * @link  https://webberzone.com
 * @since 1.0.0
 *
 * @package WebberZone\Glue_Link\Admin
 */

namespace WebberZone\Glue_Link\Admin;

use WebberZone\Glue_Link\Database;
use WebberZone\Glue_Link\Subscriber;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class to display the subscribers list.
 *
 * @since 1.0.0
 */
class Subscribers_List_Table extends \WP_List_Table {

	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var Database
	 */
	protected $database;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Database $database Database instance.
	 */
	public function __construct( Database $database ) {
		$this->database = $database;

		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array Columns.
	 */
	public function get_columns(): array {
		$columns = array(
			'cb'         => '<input type="checkbox" />',
			'email'      => __( 'Email', 'glue-link' ),
			'first_name' => __( 'First Name', 'glue-link' ),
			'last_name'  => __( 'Last Name', 'glue-link' ),
			'status'     => __( 'Status', 'glue-link' ),
			'created'    => __( 'Created', 'glue-link' ),
			'fields'     => __( 'Fields', 'glue-link' ),
			'tags'       => __( 'Tags', 'glue-link' ),
			'forms'      => __( 'Forms', 'glue-link' ),
		);

		return $columns;
	}

	/**
	 * Get sortable columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array Sortable columns.
	 */
	public function get_sortable_columns(): array {
		return array(
			'email'      => array( 'email', true ),
			'first_name' => array( 'first_name', false ),
			'last_name'  => array( 'last_name', false ),
			'status'     => array( 'status', false ),
			'created'    => array( 'created', true ),
		);
	}

	/**
	 * Prepare items.
	 *
	 * @since 1.0.0
	 */
	public function prepare_items() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$per_page     = $this->get_items_per_page( 'subscribers_per_page', 20 );
		$current_page = $this->get_pagenum();

		$orderby = ( ! empty( $_REQUEST['orderby'] ) ) ? sanitize_key( $_REQUEST['orderby'] ) : 'id'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = ( ! empty( $_REQUEST['order'] ) ) ? sanitize_key( $_REQUEST['order'] ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = ( ! empty( $_REQUEST['s'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status  = ( ! empty( $_REQUEST['status'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$query_args = array(
			'per_page' => $per_page,
			'page'     => $current_page,
			'orderby'  => $orderby,
			'order'    => $order,
			'search'   => $search,
			'status'   => $status,
		);

		$this->items = $this->database->get_subscribers( $query_args );

		// Get total count for pagination.
		$total_items = $this->database->get_subscriber_count(
			array(
				'search' => $search,
				'status' => $status,
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Get default column value.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $item        Subscriber object.
	 * @param string     $column_name Column name.
	 *
	 * @return string Column value.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'email':
				return esc_html( $item->email );

			case 'first_name':
				return esc_html( $item->first_name );

			case 'last_name':
				return esc_html( $item->last_name );

			case 'tags':
				return esc_html( implode( ', ', $item->tags ) );

			case 'forms':
				return esc_html( implode( ', ', $item->forms ) );

			case 'status':
				return esc_html( $item->status );

			case 'created':
				return esc_html( $item->created );

			default:
				return '';
		}
	}

	/**
	 * Get checkbox column.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $item Subscriber object.
	 *
	 * @return string Column content.
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="subscriber[]" value="%s" />',
			esc_attr( (string) $item->id )
		);
	}

	/**
	 * Get email column.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $item Subscriber object.
	 *
	 * @return string Column content.
	 */
	public function column_email( $item ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return esc_html( $item->email );
		}

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'   => 'subscribers',
								'action' => 'edit',
								'id'     => $item->id,
							),
							admin_url( 'admin.php' )
						),
						'edit_subscriber_' . $item->id
					)
				),
				esc_html__( 'Edit', 'glue-link' )
			),
			'delete' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'   => 'subscribers',
								'action' => 'delete',
								'id'     => $item->id,
							),
							admin_url( 'admin.php' )
						),
						'delete_subscriber_' . $item->id
					)
				),
				esc_html__( 'Delete', 'glue-link' )
			),
		);

		return sprintf(
			'%1$s %2$s',
			esc_html( $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Get views for filtering subscribers by status.
	 *
	 * @since 1.0.0
	 *
	 * @return array Views.
	 */
	public function get_views(): array {
		$views   = array();
		$current = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$statuses = array(
			'all'      => __( 'All', 'glue-link' ),
			'active'   => __( 'Active', 'glue-link' ),
			'inactive' => __( 'Inactive', 'glue-link' ),
		);

		$counts = $this->database->get_subscriber_counts();
		if ( is_wp_error( $counts ) ) {
			return $views;
		}

		foreach ( $statuses as $status => $label ) {
			$count            = 'all' === $status ? array_sum( $counts ) : ( isset( $counts[ $status ] ) ? $counts[ $status ] : 0 );
			$views[ $status ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'status', $status ) ),
				( $current === $status ? 'current' : '' ),
				esc_html( $label ),
				number_format_i18n( $count )
			);
		}

		return $views;
	}

	/**
	 * Get bulk actions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Bulk actions.
	 */
	public function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'glue-link' ),
		);
	}

	/**
	 * Process bulk actions.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_Error|bool Response.
	 */
	public function process_bulk_action(): \WP_Error|bool {
		$action = $this->current_action();

		if ( empty( $action ) ) {
			return true;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'insufficient_permissions', __( 'You do not have permission to perform this action.', 'glue-link' ) );
		}

		// Security check.
		if ( ! isset( $_REQUEST['_wpnonce'] ) ) {
			return new \WP_Error( 'missing_nonce', __( 'Security check failed.', 'glue-link' ) );
		}

		if ( 'delete' === $action ) {
			$nonce = sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'bulk-subscribers' ) ) {
				return new \WP_Error( 'invalid_nonce', __( 'Security check failed.', 'glue-link' ) );
			}

			if ( empty( $_REQUEST['subscriber'] ) || ! is_array( $_REQUEST['subscriber'] ) ) {
				return new \WP_Error( 'no_items_selected', __( 'No subscribers selected.', 'glue-link' ) );
			}

			$subscriber_ids = array_map( 'absint', $_REQUEST['subscriber'] );
			$result         = $this->database->delete_subscribers( $subscriber_ids );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Extra tablenav to display export button.
	 *
	 * @since 1.0.0
	 *
	 * @param string $which Which tablenav: top or bottom.
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'subscribers',
					'action' => 'export',
				),
				admin_url( 'admin.php' )
			),
			'export_subscribers'
		);

		printf(
			'<div class="alignleft actions"><a href="%s" class="button">%s</a></div>',
			esc_url( $export_url ),
			esc_html__( 'Export CSV', 'glue-link' )
		);
	}

	/**
	 * Display the list table page.
	 *
	 * @since 1.0.0
	 */
	public function display_page() {
		$this->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'glue-link' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=glue_link_options_page' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Settings', 'glue-link' ); ?></a>

			<form method="post">
				<?php
				$this->search_box( esc_html__( 'Search Subscribers', 'glue-link' ), 'subscriber-search' );
				wp_nonce_field( 'glue_link_subscribers', 'glue_link_subscribers_nonce' );
				$this->display();
				?>
			</form>
		</div>
		<?php
	}
}
