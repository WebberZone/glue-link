<?php
/**
 * Settings Wizard for FreemKit.
 *
 * @package WebberZone\FreemKit\Admin
 */

namespace WebberZone\FreemKit\Admin;

use WebberZone\FreemKit\Admin\Settings\Settings_Wizard_API;
use WebberZone\FreemKit\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Settings Wizard class.
 */
class Settings_Wizard extends Settings_Wizard_API {

	/**
	 * Wizard page slug.
	 */
	private const PAGE_SLUG = 'freemkit_setup_wizard';

	/**
	 * Settings page URL.
	 *
	 * @var string
	 */
	protected string $settings_page_url;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings_key = 'freemkit_settings';
		$prefix       = 'freemkit';

		$this->settings_page_url = admin_url( 'options-general.php?page=freemkit_options_page' );

		$args = array(
			'steps'               => $this->get_wizard_steps(),
			'translation_strings' => $this->get_translation_strings(),
			'page_slug'           => self::PAGE_SLUG,
			'menu_args'           => array(
				'parent'     => 'options-general.php',
				'capability' => 'manage_options',
			),
			'hide_when_completed' => true,
			'show_in_menu'        => false,
		);

		parent::__construct( $settings_key, $prefix, $args );

		// Handle OAuth callbacks when originating from wizard screens.
		new Kit_OAuth( $this->page_slug );

		$this->additional_hooks();
	}

	/**
	 * Register plugin-specific hooks.
	 *
	 * @return void
	 */
	protected function additional_hooks(): void {
		Hook_Registry::add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		Hook_Registry::add_action( 'admin_init', array( $this, 'maybe_restart_wizard' ) );
		Hook_Registry::add_action( 'admin_init', array( $this, 'register_wizard_notice' ) );
		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_wizard_support_scripts' ) );
		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_wizard_tom_select_data' ) );
	}

	/**
	 * Return wizard steps.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_wizard_steps(): array {
		$all_settings_grouped = Settings::get_registered_settings();
		$all_settings         = array();

		foreach ( $all_settings_grouped as $section_settings ) {
			$all_settings = array_merge( $all_settings, $section_settings );
		}

		if ( isset( $all_settings['kit_oauth_status'] ) ) {
			$all_settings['kit_oauth_status']['desc'] = Kit_OAuth::get_status_html(
				self::PAGE_SLUG,
				array(
					'step' => 2,
				)
			);
		}

		$steps = array(
			'welcome'        => array(
				'title'       => __( 'Welcome', 'freemkit' ),
				'description' => __( 'This wizard helps you complete the essential setup for FreemKit.', 'freemkit' ),
				'settings'    => array(),
			),
			'kit_connection' => array(
				'title'       => __( 'Connect Kit', 'freemkit' ),
				'description' => __( 'Connect your Kit account via OAuth. After authorization, you return directly to the mapping step.', 'freemkit' ),
				'settings'    => $this->build_step_settings(
					array(
						'kit_oauth_status',
					),
					$all_settings
				),
			),
			'kit_mapping'    => array(
				'title'       => __( 'Kit Mapping', 'freemkit' ),
				'description' => __( 'Select default forms/tags and field mappings used for subscriber sync.', 'freemkit' ),
				'settings'    => $this->build_step_settings(
					array(
						'kit_form_id',
						'kit_tag_id',
						'last_name_field',
						'custom_fields',
					),
					$all_settings
				),
			),
			'freemius'       => array(
				'title'       => __( 'Freemius Webhook', 'freemkit' ),
				'description' => __( 'Configure webhook handling and add one or more Freemius plugin mappings.', 'freemkit' ),
				'settings'    => $this->build_step_settings(
					array(
						'webhook_endpoint_type',
						'webhook_url',
						'plugins',
					),
					$all_settings
				),
			),
		);

		/**
		 * Filters wizard steps.
		 *
		 * @param array $steps Wizard steps.
		 */
		return apply_filters( 'freemkit_wizard_steps', $steps );
	}

	/**
	 * Process wizard step submission with support for blocking validation errors.
	 *
	 * @return void
	 */
	public function process_step() {
		if ( empty( $_POST['wizard_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$nonce_value = isset( $_POST[ $this->prefix . '_wizard_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $this->prefix . '_wizard_nonce' ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $nonce_value ) || ! wp_verify_nonce( $nonce_value, $this->prefix . '_wizard_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->current_step = $this->get_current_step();
		$action             = isset( $_POST['wizard_action'] ) ? sanitize_text_field( wp_unslash( $_POST['wizard_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'next_step':
				$this->process_current_step();
				if ( $this->is_freemius_step() && $this->has_blocking_validation_error() ) {
					$this->redirect_to_step( $this->current_step );
				} else {
					$this->next_step();
					$this->redirect_to_step( $this->current_step );
				}
				break;

			case 'previous_step':
				$this->previous_step();
				$this->redirect_to_step( $this->current_step );
				break;

			case 'finish_setup':
				$this->process_current_step();
				if ( $this->is_freemius_step() && $this->has_blocking_validation_error() ) {
					$this->redirect_to_step( $this->current_step );
				} else {
					$this->mark_wizard_completed();
					$this->redirect_to_step( $this->total_steps + 1 );
				}
				break;

			case 'skip_wizard':
				$this->mark_wizard_completed();
				$this->redirect_to_admin();
				break;
			default:
				break;
		}
	}

	/**
	 * Check if a blocking Freemius validation error is present.
	 *
	 * @return bool
	 */
	private function has_blocking_validation_error(): bool {
		$errors = get_settings_errors();

		foreach ( $errors as $error ) {
			$setting = (string) $error['setting'];
			$code    = (string) $error['code'];
			$type    = (string) $error['type'];
			if (
				$this->prefix . '_freemius_validation_partial' === $code
				&& 'error' === $type
				&& ( '' === $setting || $this->prefix . '-notices' === $setting )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the current wizard step is the Freemius step.
	 *
	 * @return bool
	 */
	private function is_freemius_step(): bool {
		$keys  = array_keys( $this->steps );
		$index = $this->get_current_step() - 1;

		return isset( $keys[ $index ] ) && 'freemius' === $keys[ $index ];
	}

	/**
	 * Build settings array for a wizard step from setting keys.
	 *
	 * @param array<string>              $keys         Setting keys.
	 * @param array<string,array<mixed>> $all_settings Full settings list.
	 * @return array<string,array<mixed>>
	 */
	protected function build_step_settings( array $keys, array $all_settings ): array {
		$step_settings = array();

		foreach ( $keys as $key ) {
			if ( isset( $all_settings[ $key ] ) ) {
				$step_settings[ $key ] = $all_settings[ $key ];
			}
		}

		return $step_settings;
	}

	/**
	 * Translation strings.
	 *
	 * @return array<string,string>
	 */
	public function get_translation_strings(): array {
		return array(
			'page_title'            => __( 'FreemKit Setup Wizard', 'freemkit' ),
			'menu_title'            => __( 'Setup Wizard', 'freemkit' ),
			'wizard_title'          => __( 'FreemKit Setup Wizard', 'freemkit' ),
			'next_step'             => __( 'Next Step', 'freemkit' ),
			'previous_step'         => __( 'Previous Step', 'freemkit' ),
			'finish_setup'          => __( 'Finish Setup', 'freemkit' ),
			'skip_wizard'           => __( 'Skip Wizard', 'freemkit' ),
			/* translators: %s: Search query. */
			'tom_select_no_results' => __( 'No results found for "%s"', 'freemkit' ),
			'repeater_new_item'     => __( 'New Item', 'freemkit' ),
			'required_label'        => __( 'Required', 'freemkit' ),
			'steps_nav_aria_label'  => __( 'Setup Wizard Steps', 'freemkit' ),
			/* translators: %1$d: Current step number, %2$d: Total number of steps. */
			'step_of'               => __( 'Step %1$d of %2$d', 'freemkit' ),
			'wizard_complete'       => __( 'Setup Complete!', 'freemkit' ),
			'setup_complete'        => __( 'FreemKit is ready. You can continue in the full settings screen at any time.', 'freemkit' ),
			'go_to_settings'        => __( 'Go to Settings', 'freemkit' ),
		);
	}

	/**
	 * Register wizard notice through the notices API.
	 *
	 * @return void
	 */
	public function register_wizard_notice(): void {
		if ( ! Admin::$notices_api instanceof Admin_Notices_API ) {
			return;
		}

		Admin::$notices_api->register_notice(
			array(
				'id'          => 'freemkit_wizard_notice',
				'message'     => sprintf(
					'<p>%s</p><p><a href="%s" class="button button-primary">%s</a></p>',
					esc_html__( 'Welcome to FreemKit. Run the setup wizard to complete the initial configuration.', 'freemkit' ),
					esc_url( $this->get_wizard_url() ),
					esc_html__( 'Run Setup Wizard', 'freemkit' )
				),
				'type'        => 'info',
				'dismissible' => true,
				'capability'  => 'manage_options',
				'conditions'  => array(
					function (): bool {
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check for conditional notice display.
						$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';

						return ! $this->is_wizard_completed()
							&& ( get_transient( $this->prefix . '_show_wizard_activation_redirect' ) || $this->should_show_wizard() )
							&& $this->page_slug !== $page;
					},
				),
			)
		);
	}

	/**
	 * Redirect to wizard once after activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( is_network_admin() || ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check for conditional redirect.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( $this->page_slug === $page ) {
			return;
		}

		if ( $this->is_wizard_completed() ) {
			return;
		}

		if ( ! get_transient( $this->prefix . '_show_wizard_activation_redirect' ) ) {
			return;
		}

		delete_transient( $this->prefix . '_show_wizard_activation_redirect' );

		wp_safe_redirect( $this->get_wizard_url() );
		exit;
	}

	/**
	 * Restart wizard when requested by user.
	 *
	 * @return void
	 */
	public function maybe_restart_wizard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( $this->page_slug !== $page ) {
			return;
		}

		$action = isset( $_GET['wizard_action'] ) ? sanitize_key( (string) wp_unslash( $_GET['wizard_action'] ) ) : '';
		if ( 'restart' !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'freemkit_restart_wizard' ) ) {
			return;
		}

		// Do not reset completion flags on restart entry.
		// This prevents re-showing setup nags if user exits the rerun midway.
		update_option( "{$this->prefix}_wizard_current_step", 1 );
		delete_transient( $this->prefix . '_show_wizard_activation_redirect' );

		wp_safe_redirect( $this->get_wizard_url( array( 'step' => 1 ) ) );
		exit;
	}

	/**
	 * Localize FreemKit Tom Select data on wizard pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_wizard_tom_select_data( string $hook ): void {
		if ( false === strpos( $hook, $this->page_slug ) ) {
			return;
		}

		wp_localize_script(
			'wz-' . $this->prefix . '-tom-select-init',
			"{$this->prefix}TomSelectSettings",
			array(
				'prefix'          => $this->prefix,
				'nonce'           => wp_create_nonce( $this->prefix . '_kit_search' ),
				'action'          => $this->prefix . '_kit_search',
				'endpoint'        => '',
				'forms'           => Settings::get_localized_kit_data( 'forms' ),
				'tags'            => Settings::get_localized_kit_data( 'tags' ),
				'custom_fields'   => Settings::get_localized_kit_data( 'custom_fields' ),
				'freemius_events' => Settings::get_localized_kit_data( 'freemius_events' ),
				'strings'         => array(
					/* translators: %s: search term */
					'no_results' => esc_html__( 'No results found for %s', 'freemkit' ),
				),
			)
		);
	}

	/**
	 * Enqueue wizard support scripts (e.g. Kit connection test handlers).
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_wizard_support_scripts( string $hook ): void {
		if ( false === strpos( $hook, $this->page_slug ) ) {
			return;
		}

		$suffix     = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$admin_path = "/js/admin{$suffix}.js";
		$kit_path   = "/js/connection-validate{$suffix}.js";

		$admin_file    = __DIR__ . $admin_path;
		$kit_file      = __DIR__ . $kit_path;
		$admin_version = file_exists( $admin_file ) ? (string) filemtime( $admin_file ) : FREEMKIT_VERSION;
		$kit_version   = file_exists( $kit_file ) ? (string) filemtime( $kit_file ) : FREEMKIT_VERSION;

		wp_enqueue_script(
			'freemkit-admin',
			plugins_url( $admin_path, __FILE__ ),
			array( 'jquery' ),
			$admin_version,
			true
		);

		wp_enqueue_script(
			'freemkit-connection-validate',
			plugins_url( $kit_path, __FILE__ ),
			array( 'jquery' ),
			$kit_version,
			true
		);

		wp_localize_script(
			'freemkit-admin',
			'FreemKitAdmin',
			array(
				'prefix'        => $this->prefix,
				'thumb_default' => plugins_url( 'images/default.png', __FILE__ ),
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( $this->prefix . '_admin_nonce' ),
				'webhook_urls'  => Settings::get_webhook_urls(),
				'strings'       => array(
					'cache_cleared'               => esc_html__( 'Cache cleared successfully!', 'freemkit' ),
					'cache_error'                 => esc_html__( 'Error clearing cache: ', 'freemkit' ),
					'api_validation_error'        => esc_html__( 'Error validating API credentials.', 'freemkit' ),
					'validate_freemius_keys'      => esc_html__( 'Validate Keys', 'freemkit' ),
					'freemius_missing_fields'     => esc_html__( 'Product ID, public key, and secret key are required.', 'freemkit' ),
					'freemius_validation_success' => esc_html__( 'Freemius credentials are valid.', 'freemkit' ),
					'freemius_validation_error'   => esc_html__( 'Unable to validate Freemius credentials.', 'freemkit' ),
					'copy_success'                => esc_html__( 'Webhook URL copied.', 'freemkit' ),
					'copy_failed'                 => esc_html__( 'Copy failed. Select and copy manually.', 'freemkit' ),
				),
			)
		);
	}

	/**
	 * Completion redirect URL.
	 *
	 * @return string
	 */
	protected function get_completion_redirect_url() {
		return $this->settings_page_url;
	}

	/**
	 * Completion buttons.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_completion_buttons() {
		$buttons   = parent::get_completion_buttons();
		$buttons[] = array(
			'url'     => wp_nonce_url(
				$this->get_wizard_url(
					array(
						'wizard_action' => 'restart',
					)
				),
				'freemkit_restart_wizard'
			),
			'text'    => __( 'Run Wizard Again', 'freemkit' ),
			'primary' => false,
		);

		return $buttons;
	}

	/**
	 * Build wizard URL.
	 *
	 * @param array<string,mixed> $args Optional query args.
	 * @return string
	 */
	public function get_wizard_url( array $args = array() ): string {
		$default = array(
			'page' => $this->page_slug,
		);

		return add_query_arg( array_merge( $default, $args ), admin_url( 'options-general.php' ) );
	}
}
