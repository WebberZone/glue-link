<?php
/**
 * Sync Admin class.
 *
 * @package WebberZone\FreemKit\Admin
 */

namespace WebberZone\FreemKit\Admin;

use WebberZone\FreemKit\Database;
use WebberZone\FreemKit\Sync;
use WebberZone\FreemKit\Util\Hook_Registry;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Sync Admin class — registers the Sync admin page and handles the two-phase AJAX wizard.
 *
 * @since 1.0.0
 */
class Sync_Admin {

	/**
	 * Admin page hook suffix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $page_id = '';

	/**
	 * Sync service.
	 *
	 * @since 1.0.0
	 * @var Sync
	 */
	protected Sync $sync;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Database $database Database instance.
	 */
	public function __construct( Database $database ) {
		$this->sync = new Sync( $database );

		Hook_Registry::add_action( 'admin_menu', array( $this, 'register_sync_page' ) );
		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		Hook_Registry::add_action( 'wp_ajax_freemkit_sync_list_users', array( $this, 'ajax_list_users' ) );
		Hook_Registry::add_action( 'wp_ajax_freemkit_sync_process_batch', array( $this, 'ajax_process_batch' ) );
	}

	/**
	 * Register the Sync submenu page.
	 *
	 * @since 1.0.0
	 */
	public function register_sync_page(): void {
		$this->page_id = add_submenu_page(
			'tools.php',
			__( 'FreemKit Sync', 'freemkit' ),
			__( 'FreemKit Sync', 'freemkit' ),
			'manage_options',
			'freemkit_sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts for the sync page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_id ) {
			return;
		}

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Tom Select — registered globally by Settings_API on every admin page; enqueue here.
		wp_enqueue_style( 'wz-freemkit-tom-select' );
		wp_enqueue_script( 'wz-freemkit-tom-select' );
		wp_enqueue_script( 'wz-freemkit-tom-select-init' );

		wp_localize_script(
			'wz-freemkit-tom-select-init',
			'freemkitTomSelectSettings',
			array(
				'prefix'   => 'freemkit',
				'nonce'    => wp_create_nonce( 'freemkit_kit_search' ),
				'action'   => 'freemkit_kit_search',
				'endpoint' => '',
				'forms'    => Settings::get_localized_kit_data( 'forms' ),
				'tags'     => Settings::get_localized_kit_data( 'tags' ),
				'strings'  => array(
					/* translators: %s: search term */
					'no_results' => esc_html__( 'No results found for %s', 'freemkit' ),
				),
			)
		);

		wp_enqueue_script(
			'freemkit-sync-admin',
			plugins_url( 'includes/admin/js/sync-admin' . $min . '.js', FREEMKIT_PLUGIN_FILE ),
			array( 'jquery', 'wz-freemkit-tom-select-init' ),
			FREEMKIT_VERSION,
			true
		);

		wp_localize_script(
			'freemkit-sync-admin',
			'FreemKitSync',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'freemkit_sync' ),
				'strings'  => array(
					'fetching'       => __( 'Fetching users…', 'freemkit' ),
					'fetching_more'  => __( 'Fetching next page…', 'freemkit' ),
					'processing'     => __( 'Processing', 'freemkit' ),
					'done'           => __( 'Sync complete.', 'freemkit' ),
					'cancelled'      => __( 'Sync cancelled.', 'freemkit' ),
					'no_users'       => __( 'No users found matching the selected criteria.', 'freemkit' ),
					'fetch_error'    => __( 'Failed to fetch users. Check your settings and try again.', 'freemkit' ),
					'process_error'  => __( 'Error processing user.', 'freemkit' ),
					'request_failed' => __( 'Request failed. Check your connection and try again.', 'freemkit' ),
					'summary'        => __( 'Processed: {processed} • Synced: {synced} • Updated: {updated} • Opted-out: {opted_out} • Skipped: {skipped} • Errors: {errors}', 'freemkit' ),
				),
			)
		);
	}

	/**
	 * Render the Sync admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'freemkit' ) );
		}

		$plugin_configs = $this->get_plugin_configs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FreemKit Sync', 'freemkit' ); ?></h1>
			<p><?php esc_html_e( 'Manually sync users from Freemius or your local subscriber database. Use this to backfill historical users or re-push existing subscribers after a form/tag change.', 'freemkit' ); ?></p>

			<?php if ( empty( $plugin_configs ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: Settings page URL. */
							wp_kses_post( __( 'No plugins configured. Add at least one plugin in <a href="%s">FreemKit Settings</a> before running a sync.', 'freemkit' ) ),
							esc_url( admin_url( 'options-general.php?page=freemkit_options_page' ) )
						);
						?>
					</p>
				</div>
			<?php else : ?>

			<form id="freemkit-sync-form" method="post">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'freemkit' ); ?></th>
						<td>
							<label>
								<input type="radio" name="sync_source" value="freemius" checked />
								<?php esc_html_e( 'Freemius API (import historical users)', 'freemkit' ); ?>
							</label>
							<br />
							<label>
								<input type="radio" name="sync_source" value="local" />
								<?php esc_html_e( 'Local database (re-sync captured subscribers)', 'freemkit' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Freemius imports users who never triggered a webhook. Local re-pushes already-captured subscribers (e.g. after Kit was offline).', 'freemkit' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Destination', 'freemkit' ); ?></th>
						<td>
							<label>
								<input type="radio" name="sync_destination" value="both" />
								<?php esc_html_e( 'Local database + Kit (save locally and subscribe to email list)', 'freemkit' ); ?>
							</label>
							<br />
							<label>
								<input type="radio" name="sync_destination" value="local" checked />
								<?php esc_html_e( 'Local database only (no Kit push)', 'freemkit' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Subscribers are always saved to the local database. Choose "Local database only" to import without immediately pushing to Kit — useful for a staged migration.', 'freemkit' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="freemkit-plugin-id"><?php esc_html_e( 'Plugin', 'freemkit' ); ?></label>
						</th>
						<td>
							<select name="plugin_id" id="freemkit-plugin-id">
								<option value=""><?php esc_html_e( '— All Plugins —', 'freemkit' ); ?></option>
								<?php foreach ( $plugin_configs as $id => $config ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>">
										<?php echo esc_html( ! empty( $config['name'] ) ? $config['name'] : $id ); ?>
										(<?php echo esc_html( $id ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Leave blank to sync all configured plugins. For Freemius source, each plugin is paginated in sequence.', 'freemkit' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'User Types', 'freemkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sync_user_types[]" value="free" />
								<?php esc_html_e( 'Free', 'freemkit' ); ?>
							</label>
							&nbsp;&nbsp;
							<label>
								<input type="checkbox" name="sync_user_types[]" value="paid" checked />
								<?php esc_html_e( 'Paid', 'freemkit' ); ?>
							</label>
							&nbsp;&nbsp;
							<label>
								<input type="checkbox" name="sync_user_types[]" value="trial" />
								<?php esc_html_e( 'Trial (uses free forms/tags)', 'freemkit' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'For Freemius source, filters by current licence status. For local source, filters by recorded event type.', 'freemkit' ); ?>
							</p>
						</td>
					</tr>
					<tbody id="freemkit-kit-fields">
						<tr>
							<th scope="row">
								<label for="freemkit-override-form-ids"><?php esc_html_e( 'Override Kit Form IDs', 'freemkit' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									name="override_form_ids"
									id="freemkit-override-form-ids"
									class="ts_autocomplete"
									data-wp-prefix="freemkit"
									data-wp-action="freemkit_kit_search"
									data-wp-nonce="<?php echo esc_attr( wp_create_nonce( 'freemkit_kit_search' ) ); ?>"
									data-wp-endpoint="forms"
									value=""
								/>
								<p class="description">
									<?php esc_html_e( 'Kit forms to push all synced users to, overriding per-plugin and global settings. Leave blank to use configured settings.', 'freemkit' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="freemkit-override-tag-ids"><?php esc_html_e( 'Override Kit Tag IDs', 'freemkit' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									name="override_tag_ids"
									id="freemkit-override-tag-ids"
									class="ts_autocomplete"
									data-wp-prefix="freemkit"
									data-wp-action="freemkit_kit_search"
									data-wp-nonce="<?php echo esc_attr( wp_create_nonce( 'freemkit_kit_search' ) ); ?>"
									data-wp-endpoint="tags"
									value=""
								/>
								<p class="description">
									<?php esc_html_e( 'Kit tags to apply to all synced users, overriding per-plugin and global settings. Leave blank to use configured settings.', 'freemkit' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Start Sync', 'freemkit' ), 'primary', 'freemkit_sync_submit' ); ?>
				<button type="button" id="freemkit-sync-cancel" class="button" style="display:none; margin-left:6px;"><?php esc_html_e( 'Cancel', 'freemkit' ); ?></button>
			</form>

			<div id="freemkit-sync-progress" style="display:none; margin-top:20px;">
				<div style="background:#e0e0e0; border-radius:4px; height:22px; overflow:hidden;">
					<div id="freemkit-progress-bar-inner" style="background:#2271b1; height:100%; width:0%; transition:width 0.2s;"></div>
				</div>
				<p id="freemkit-progress-text" style="margin:6px 0 0;"></p>
			</div>

			<div id="freemkit-sync-results" style="display:none; margin-top:20px;">
				<h2><?php esc_html_e( 'Sync Results', 'freemkit' ); ?></h2>
				<p id="freemkit-sync-summary" style="display:none; font-weight:600;"></p>
				<table class="widefat striped" id="freemkit-results-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Action', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Email', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Name', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'User Type', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Plugin', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Destination', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'freemkit' ); ?></th>
						</tr>
					</thead>
					<tbody id="freemkit-results-tbody"></tbody>
				</table>
			</div>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX: return a page of users ready to process.
	 *
	 * POST params: nonce, source, plugin_id, plugin_index, user_types[], offset, count
	 *
	 * @since 1.0.0
	 */
	public function ajax_list_users(): void {
		check_ajax_referer( 'freemkit_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'freemkit' ) ) );
		}

		// A DB error echoed mid-response (e.g. by another plugin leaving $wpdb->show_errors() on) would corrupt this JSON payload.
		global $wpdb;
		$wpdb->hide_errors();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$source       = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'local';
		$plugin_id    = isset( $_POST['plugin_id'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_id'] ) ) : '';
		$plugin_index = isset( $_POST['plugin_index'] ) ? (int) $_POST['plugin_index'] : 0;
		$raw_types    = isset( $_POST['user_types'] ) && is_array( $_POST['user_types'] ) ? wp_unslash( $_POST['user_types'] ) : array();
		$offset       = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$count        = isset( $_POST['count'] ) ? min( 50, max( 1, (int) $_POST['count'] ) ) : 50;
		// phpcs:enable

		$allowed_types = array( 'free', 'paid', 'trial' );
		$user_types    = array_values( array_intersect( array_map( 'sanitize_key', (array) $raw_types ), $allowed_types ) );

		$result = $this->sync->list_users( $source, $plugin_id, $user_types, $offset, $count, $plugin_index );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: process a batch of users and return result rows.
	 *
	 * @since 1.0.0
	 */
	public function ajax_process_batch(): void {
		check_ajax_referer( 'freemkit_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'freemkit' ) ) );
		}

		// A DB error echoed mid-response (e.g. by another plugin leaving $wpdb->show_errors() on) would corrupt this JSON payload.
		global $wpdb;
		$wpdb->hide_errors();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_tasks = isset( $_POST['tasks'] ) && is_array( $_POST['tasks'] ) ? wp_unslash( $_POST['tasks'] ) : array();
		// phpcs:enable

		wp_send_json_success( array( 'results' => $this->sync->process_batch( $raw_tasks ) ) );
	}

	/**
	 * Build plugin configurations from settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_plugin_configs(): array {
		return $this->sync->get_plugin_configs();
	}
}
