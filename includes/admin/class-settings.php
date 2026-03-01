<?php
/**
 * Register Settings.
 *
 * @since 1.0.0
 *
 * @package WebberZone\FreemKit\Admin
 */

namespace WebberZone\FreemKit\Admin;

use WebberZone\FreemKit\Admin\Settings\Settings_API;
use WebberZone\FreemKit\Freemius;
use WebberZone\FreemKit\Options_API;
use WebberZone\FreemKit\Kit\Kit_API;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class to register the settings.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Settings API.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings_API Settings API.
	 */
	public Settings_API $settings_api;

	/**
	 * Prefix which is used for creating the unique filters and actions.
	 *
	 * @since 1.0.0
	 *
	 * @var string Prefix.
	 */
	public static $prefix;

	/**
	 * Settings Key.
	 *
	 * @since 1.0.0
	 *
	 * @var string Settings Key.
	 */
	public $settings_key;

	/**
	 * The slug name to refer to this menu by (should be unique for this menu).
	 *
	 * @since 1.0.0
	 *
	 * @var string Menu slug.
	 */
	public $menu_slug;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->settings_key = 'freemkit_settings';
		self::$prefix       = 'freemkit';
		$this->menu_slug    = 'freemkit_options_page';
		new Kit_OAuth( $this->menu_slug );

		$this->register_hooks();
	}

	/**
	 * Register the hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'initialise_settings' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 11, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( FREEMKIT_PLUGIN_FILE ), array( $this, 'plugin_actions_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 99 );

		// Add filters for settings page customization.
		add_filter( self::$prefix . '_after_setting_output', array( $this, 'add_connection_test_button' ), 10, 2 );
		add_filter( self::$prefix . '_settings_form_buttons', array( $this, 'add_cache_clear_button' ), 10 );
		add_action( self::$prefix . '_settings_form_buttons', array( $this, 'render_wizard_button' ), 20 );
		add_filter( self::$prefix . '_settings_sanitize', array( $this, 'change_settings_on_save' ), 99, 2 );

		// Add AJAX handlers for Kit resources and connection testing.
		add_action( 'wp_ajax_' . self::$prefix . '_test_kit_connection', array( $this, 'ajax_test_kit_connection' ) );
		add_action( 'wp_ajax_' . self::$prefix . '_validate_freemius_keys', array( $this, 'ajax_validate_freemius_keys' ) );
		add_action( 'wp_ajax_' . self::$prefix . '_refresh_lists', array( $this, 'ajax_refresh_lists' ) );
		add_action( 'wp_ajax_' . self::$prefix . '_kit_search', array( $this, 'handle_kit_search' ) );
	}

	/**
	 * Initialise the settings API.
	 *
	 * @since 1.0.0
	 */
	public function initialise_settings() {
		$props = array(
			'default_tab'       => 'kit',
			'help_sidebar'      => $this->get_help_sidebar(),
			'help_tabs'         => $this->get_help_tabs(),
			'admin_footer_text' => $this->get_admin_footer_text(),
			'menus'             => $this->get_menus(),
		);

		$args = array(
			'props'               => $props,
			'translation_strings' => $this->get_translation_strings(),
			'settings_sections'   => $this->get_settings_sections(),
			'registered_settings' => $this->get_registered_settings(),
		);

		$this->settings_api = new Settings_API( $this->settings_key, self::$prefix, $args );
	}

	/**
	 * Array containing the settings' sections.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings array
	 */
	public static function get_settings_sections(): array {
		$settings_sections = array(
			'kit'         => __( 'Kit', 'freemkit' ),
			'freemius'    => __( 'Freemius', 'freemkit' ),
			'subscribers' => __( 'Subscribers', 'freemkit' ),
		);

		/**
		 * Filter the array containing the settings' sections.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings_sections Settings array
		 */
		$settings_sections = apply_filters( 'freemkit_settings_sections', $settings_sections );

		return $settings_sections;
	}

	/**
	 * Array containing the settings' translation strings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings array
	 */
	public function get_translation_strings(): array {
		$strings = array(
			'page_header'          => esc_html__( 'FreemKit Settings', 'freemkit' ),
			'reset_message'        => esc_html__( 'Settings have been reset to their default values. Reload this page to view the updated settings.', 'freemkit' ),
			'success_message'      => esc_html__( 'Settings updated.', 'freemkit' ),
			'save_changes'         => esc_html__( 'Save Changes', 'freemkit' ),
			'reset_settings'       => esc_html__( 'Reset all settings', 'freemkit' ),
			'reset_button_confirm' => esc_html__( 'Do you really want to reset all these settings to their default values?', 'freemkit' ),
			'checkbox_modified'    => esc_html__( 'Modified from default setting', 'freemkit' ),
		);

		/**
		 * Filter the array containing the settings' sections.
		 *
		 * @since 1.0.0
		 *
		 * @param array $strings Translation strings.
		 */
		return apply_filters( self::$prefix . '_translation_strings', $strings );
	}

	/**
	 * Get the admin menus.
	 *
	 * @return array Admin menus.
	 */
	public function get_menus(): array {
		$menus = array();

		// Settings menu.
		$menus[] = array(
			'settings_page' => true,
			'type'          => 'options',
			'page_title'    => esc_html__( 'FreemKit Settings', 'freemkit' ),
			'menu_title'    => esc_html__( 'FreemKit', 'freemkit' ),
			'menu_slug'     => $this->menu_slug,
		);

		return $menus;
	}

	/**
	 * Retrieve the array of plugin settings
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings array
	 */
	public static function get_registered_settings(): array {
		static $running = false;
		if ( $running ) {
			return array();
		}
		$running = true;

		$settings = array();
		$sections = self::get_settings_sections();

		foreach ( $sections as $section => $value ) {
			$method_name = 'settings_' . $section;
			if ( method_exists( __CLASS__, $method_name ) ) {
				$settings[ $section ] = self::$method_name();
			}
		}

		/**
		 * Filters the settings array
		 *
		 * @since 1.0.0
		 *
		 * @param array $freemkit_setings Settings array
		 */
		$settings = apply_filters( self::$prefix . '_registered_settings', $settings );
		$running  = false;

		return $settings;
	}

	/**
	 * Retrieve the array of Freemius settings
	 *
	 * @since 1.0.0
	 *
	 * @return array Freemius settings array
	 */
	public static function settings_freemius(): array {
		$settings = array(
			'freemius'              => array(
				'id'   => 'freemius',
				'name' => __( 'Freemius', 'freemkit' ),
				'desc' => __( 'Configure your Freemius plugins in this tab by entering required identifiers and keys. Plugin name, ID, public and secret keys are mandatory. Form and tags are optional and default to settings in the Kit tab if left blank.', 'freemkit' ),
				'type' => 'header',
			),
			'webhook_endpoint_type' => array(
				'id'      => 'webhook_endpoint_type',
				'name'    => __( 'Webhook Endpoint Type', 'freemkit' ),
				'desc'    => __( 'Select the method for registering the webhook endpoint. REST API is recommended for better security and standardization. For Query Variable, use: yourdomain.com/?freemkit_webhook', 'freemkit' ),
				'type'    => 'select',
				'options' => array(
					'rest'  => __( 'REST API', 'freemkit' ),
					'query' => __( 'Query Variable', 'freemkit' ),
				),
				'default' => 'rest',
			),
			'webhook_url'           => array(
				'id'   => 'webhook_url',
				'name' => __( 'Webhook URL', 'freemkit' ),
				'desc' => self::get_webhook_url(),
				'type' => 'header',
			),
			'plugins'               => array(
				'id'                => 'plugins',
				'name'              => __( 'Freemius Plugins', 'freemkit' ),
				'desc'              => __( 'Use "Validate Keys" on each plugin row to verify the Product ID, public key, and secret key against Freemius before saving.', 'freemkit' ),
				'type'              => 'repeater',
				'add_button_text'   => __( 'Add Plugin', 'freemkit' ),
				'new_item_text'     => __( 'New Plugin', 'freemkit' ),
				'live_update_field' => 'name',
				'default'           => array(),
				'section'           => 'freemius',
				'fields'            => array(
					array(
						'id'       => 'name',
						'name'     => __( 'Plugin Name', 'freemkit' ),
						'desc'     => __( 'Enter the name of your plugin', 'freemkit' ),
						'type'     => 'text',
						'required' => true,
						'default'  => '',
						'size'     => 'large',
					),
					array(
						'id'       => 'id',
						'name'     => __( 'Product ID', 'freemkit' ),
						'desc'     => __( 'Enter your Freemius Product ID', 'freemkit' ),
						'type'     => 'text',
						'required' => true,
						'default'  => '',
						'size'     => 'large',
					),
					array(
						'id'       => 'public_key',
						'name'     => __( 'Public Key', 'freemkit' ),
						'desc'     => __( 'Enter your Product Public Key', 'freemkit' ),
						'type'     => 'text',
						'required' => true,
						'default'  => '',
						'size'     => 'large',
					),
					array(
						'id'       => 'secret_key',
						'name'     => __( 'Secret Key', 'freemkit' ),
						'desc'     => __( 'Enter your Product Secret Key. Once saved, this will be securely stored and masked.', 'freemkit' ),
						'type'     => 'sensitive',
						'required' => true,
						'default'  => '',
						'size'     => 'large',
					),
					array(
						'id'               => 'free_form_ids',
						'name'             => __( 'Free Form', 'freemkit' ),
						'desc'             => __( 'Choose the form(s) for free subscribers. Begin typing to search.', 'freemkit' ),
						'type'             => 'text',
						'default'          => '',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'forms' ),
					),
					array(
						'id'               => 'free_event_types',
						'name'             => __( 'Free Trigger Events', 'freemkit' ),
						'desc'             => __( 'Choose Freemius webhook event(s) that should add users to the Free form/tag mapping.', 'freemkit' ),
						'type'             => 'text',
						'default'          => 'install.installed',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'freemius_events', array( 'create' => true ) ),
					),
					array(
						'id'               => 'free_tag_ids',
						'name'             => __( 'Free Tag', 'freemkit' ),
						'desc'             => __( 'Optionally, choose the tag(s) for free subscribers. Begin typing to search.', 'freemkit' ),
						'type'             => 'text',
						'default'          => '',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'tags' ),
					),
					array(
						'id'               => 'paid_form_ids',
						'name'             => __( 'Paid Form', 'freemkit' ),
						'desc'             => __( 'Choose the form(s) for paid subscribers. Begin typing to search.', 'freemkit' ),
						'type'             => 'text',
						'default'          => '',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'forms' ),
					),
					array(
						'id'               => 'paid_event_types',
						'name'             => __( 'Paid Trigger Events', 'freemkit' ),
						'desc'             => __( 'Choose Freemius webhook event(s) that should add users to the Paid form/tag mapping.', 'freemkit' ),
						'type'             => 'text',
						'default'          => 'license.created',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'freemius_events', array( 'create' => true ) ),
					),
					array(
						'id'               => 'paid_tag_ids',
						'name'             => __( 'Paid Tag', 'freemkit' ),
						'desc'             => __( 'Choose the tag(s) for paid subscribers. Begin typing to search.', 'freemkit' ),
						'type'             => 'text',
						'default'          => '',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'tags' ),
					),
				),
			),
		);

		/**
		 * Filters the General settings array.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings General settings array.
		 */
		return apply_filters( self::$prefix . '_settings_general', $settings );
	}

	/**
	 * Retrieve the array of Kit settings
	 *
	 * @since 1.0.0
	 *
	 * @return array Kit settings array
	 */
	public static function settings_kit(): array {
		$settings = array(
			'kit'              => array(
				'id'   => 'kit',
				'name' => __( 'Kit', 'freemkit' ),
				'desc' => __( 'Connect to Kit using OAuth (API v4)', 'freemkit' ),
				'type' => 'header',
			),
			'kit_oauth_status' => array(
				'id'   => 'kit_oauth_status',
				'name' => __( 'Connection', 'freemkit' ),
				'desc' => Kit_OAuth::get_status_html( 'freemkit_options_page' ),
				'type' => 'header',
			),
			'kit_form_id'      => array(
				'id'               => 'kit_form_id',
				'name'             => __( 'Global Form ID', 'freemkit' ),
				'desc'             => __( 'Select the Kit form to add subscribers to. Start typing to search. This is used if the form ID is not set for a specific plugin.', 'freemkit' ),
				'type'             => 'text',
				'default'          => '',
				'size'             => 'large',
				'field_class'      => 'ts_autocomplete',
				'field_attributes' => self::get_kit_search_field_attributes( 'forms' ),
			),
			'kit_tag_id'       => array(
				'id'               => 'kit_tag_id',
				'name'             => __( 'Tag ID', 'freemkit' ),
				'desc'             => __( 'Select the Kit tag to apply (optional). Start typing to search. This is used if the tag ID is not set for a specific plugin.', 'freemkit' ),
				'type'             => 'text',
				'default'          => '',
				'size'             => 'large',
				'field_class'      => 'ts_autocomplete',
				'field_attributes' => self::get_kit_search_field_attributes( 'tags' ),
			),
		);

		/**
		 * Filters the Kit settings array
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings Kit settings array
		 */
		return apply_filters( 'freemkit_settings_kit', $settings );
	}

	/**
	 * Retrieve the array of Subscribers settings
	 *
	 * @since 1.0.0
	 *
	 * @return array Subscribers settings array
	 */
	public static function settings_subscribers(): array {
		$settings = array(
			'subscribers'     => array(
				'id'   => 'subscribers',
				'name' => __( 'Subscribers', 'freemkit' ),
				'desc' => __( 'Configure your subscribers settings in this tab.', 'freemkit' ),
				'type' => 'header',
			),
			'last_name_field' => array(
				'id'               => 'last_name_field',
				'name'             => __( 'Last Name field', 'freemkit' ),
				'desc'             => __( 'Select the field name for mapping the last name in Kit. Note: Kit lacks a default last name field; a custom field must be created in your account first.', 'freemkit' ),
				'type'             => 'text',
				'default'          => '',
				'field_class'      => 'ts_autocomplete',
				'field_attributes' => self::get_kit_search_field_attributes( 'custom_fields', array( 'maxItems' => 1 ) ),
			),
			'custom_fields'   => array(
				'id'                => 'custom_fields',
				'name'              => __( 'Custom Fields', 'freemkit' ),
				'desc'              => '',
				'type'              => 'repeater',
				'live_update_field' => 'local_name',
				'default'           => array(),
				'fields'            => array(
					array(
						'id'      => 'local_name',
						'name'    => __( 'Field Local Name', 'freemkit' ),
						'desc'    => __( 'Enter the name of your field that will be used locally in the database on this site.', 'freemkit' ),
						'type'    => 'text',
						'default' => '',
					),
					array(
						'id'               => 'remote_name',
						'name'             => __( 'Field name on Kit', 'freemkit' ),
						'desc'             => __( 'Enter the name of your custom field that is used on the Kit.', 'freemkit' ),
						'type'             => 'text',
						'default'          => '',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'custom_fields', array( 'maxItems' => 1 ) ),
					),
				),
			),
		);

		/**
		 * Filters the Subscribers settings array
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings Subscribers settings array
		 */
		return apply_filters( 'freemkit_settings_subscribers', $settings );
	}

	/**
	 * Get common field attributes for Kit search fields
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint   The endpoint to search ('forms', 'tags', 'custom_fields', 'freemius_events').
	 * @param array  $ts_config  Optional TypeScript configuration.
	 * @return array Field attributes array
	 */
	public static function get_kit_search_field_attributes( string $endpoint, array $ts_config = array() ): array {
		$attributes = array(
			'data-wp-prefix'   => 'FreemKit',
			'data-wp-action'   => self::$prefix . '_kit_search',
			'data-wp-nonce'    => wp_create_nonce( self::$prefix . '_kit_search' ),
			'data-wp-endpoint' => $endpoint,
		);

		if ( ! empty( $ts_config ) ) {
			$attributes['data-ts-config'] = wp_json_encode( $ts_config );
		}

		return $attributes;
	}

	/**
	 * Adding WordPress plugin action links.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Array of links.
	 * @return array Updated array of links.
	 */
	public function plugin_actions_links( array $links ): array {

		return array_merge(
			array(
				'settings' => '<a href="' . admin_url( 'admin.php?page=' . $this->menu_slug ) . '">' . esc_html__( 'Settings', 'freemkit' ) . '</a>',
				'wizard'   => '<a href="' . admin_url( 'options-general.php?page=freemkit_setup_wizard' ) . '">' . esc_html__( 'Setup Wizard', 'freemkit' ) . '</a>',
			),
			$links
		);
	}

	/**
	 * Add meta links on Plugins page.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $links Array of Links.
	 * @param string $file Current file.
	 * @return array Updated array of links.
	 */
	public function plugin_row_meta( array $links, string $file ): array {

		if ( false !== strpos( $file, 'freemkit.php' ) ) {
			$new_links = array(
				'support' => '<a href = "https://webberzone.com/support/">' . esc_html__( 'Support', 'freemkit' ) . '</a>',
			);

			$links = array_merge( $links, $new_links );
		}
		return $links;
	}

	/**
	 * Get the help sidebar content to display on the plugin settings page.
	 *
	 * @since 1.0.0
	 */
	public function get_help_sidebar() {
		$help_sidebar =
			/* translators: 1: Plugin support site link. */
			'<p>' . sprintf( __( 'For more information or how to get support visit the <a href="%s">support site</a>.', 'freemkit' ), esc_url( 'https://webberzone.com/support/' ) ) . '</p>';

		/**
		 * Filter to modify the help sidebar content.
		 *
		 * @since 1.0.0
		 *
		 * @param string $help_sidebar Help sidebar content.
		 */
		return apply_filters( self::$prefix . '_settings_help', $help_sidebar );
	}

	/**
	 * Get the help tabs to display on the plugin settings page.
	 *
	 * @since 1.0.0
	 */
	public function get_help_tabs() {
		$help_tabs = array(
			array(
				'id'      => 'freemkit-settings-general-help',
				'title'   => esc_html__( 'Freemius Plugins', 'freemkit' ),
				'content' =>
				'<p><strong>' . esc_html__( 'This tab allows you to add or remove plugins that you have added on Freemius', 'freemkit' ) . '</strong></p>' .
					'<p>' . esc_html__( 'You must click the Save Changes button at the bottom of the screen for new settings to take effect.', 'freemkit' ) . '</p>',
			),
			array(
				'id'      => 'freemkit-settings-kit-help',
				'title'   => esc_html__( 'Kit', 'freemkit' ),
				'content' =>
				'<p><strong>' . esc_html__( 'This tab provides the settings for configuring the integration with Kit. OAuth (API v4) is recommended; API key/secret can be used as fallback.', 'freemkit' ) . '</strong></p>' .
					'<p>' . esc_html__( 'You must click the Save Changes button at the bottom of the screen for new settings to take effect.', 'freemkit' ) . '</p>',
			),
		);

		/**
		 * Filter to add more help tabs.
		 *
		 * @since 1.0.0
		 *
		 * @param array $help_tabs Associative array of help tabs.
		 */
		return apply_filters( self::$prefix . '_settings_help', $help_tabs );
	}

	/**
	 * Add footer text on the plugin page.
	 *
	 * @since 1.0.0
	 */
	public static function get_admin_footer_text() {
		return sprintf(
			/* translators: 1: Opening achor tag with Plugin page link, 2: Closing anchor tag. */
			__( 'Thank you for using %1$sFreemKit%2$s!', 'freemkit' ),
			'<a href="https://webberzone.com/plugins/freemkit/" target="_blank">',
			'</a>'
		);
	}

	/**
	 * Enqueue scripts and styles for the admin settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function admin_enqueue_scripts( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for current admin page.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';

		if ( false === strpos( $hook, $this->menu_slug ) && $page !== $this->menu_slug ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		// Kit-specific scripts.
		$this->enqueue_admin_script(
			'connection-validate',
			"/js/connection-validate{$suffix}.js",
			array( 'jquery' )
		);

		// Settings scripts.
		$this->enqueue_admin_script(
			'admin',
			"/js/admin{$suffix}.js",
			array( 'jquery' )
		);

			wp_localize_script(
				'freemkit-admin',
				'FreemKitAdmin',
				array(
					'prefix'        => self::$prefix,
					'thumb_default' => plugins_url( 'images/default.png', __FILE__ ),
					'ajax_url'      => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( self::$prefix . '_admin_nonce' ),
					'webhook_urls'  => self::get_webhook_urls(),
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

		// Tom Select variables.
			wp_localize_script(
				'wz-' . self::$prefix . '-tom-select-init',
				'FreemKitTomSelectSettings',
				array(
					'prefix'          => 'FreemKit',
					'nonce'           => wp_create_nonce( self::$prefix . '_kit_search' ),
					'action'          => self::$prefix . '_kit_search',
					'endpoint'        => '',
					'forms'           => self::get_localized_kit_data( 'forms' ),
					'tags'            => self::get_localized_kit_data( 'tags' ),
					'custom_fields'   => self::get_localized_kit_data( 'custom_fields' ),
					'freemius_events' => self::get_localized_kit_data( 'freemius_events' ),
					'strings'         => array(
						/* translators: %s: search term */
						'no_results' => esc_html__( 'No results found for %s', 'freemkit' ),
					),
				)
			);
	}

	/**
	 * Helper function to enqueue admin scripts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $handle Script handle without the 'freemkit-' prefix.
	 * @param string $path   Path to the script relative to the admin directory.
	 * @param array  $deps   Array of script dependencies.
	 */
	public function enqueue_admin_script( string $handle, string $path, array $deps = array() ) {
		$script_file = __DIR__ . $path;
		$version     = file_exists( $script_file ) ? (string) filemtime( $script_file ) : FREEMKIT_VERSION;

		wp_enqueue_script(
			'freemkit-' . $handle,
			plugins_url( $path, __FILE__ ),
			$deps,
			$version,
			true
		);
	}

	/**
	 * Modify settings when they are being saved.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $settings Settings array.
	 * @param  array $input    Submitted input payload for current save operation.
	 * @return array Sanitized settings array.
	 */
	public function change_settings_on_save( array $settings, array $input = array() ): array {
		// Enforce Freemius credential validation on save when plugin rows are being submitted.
		if ( isset( $input['plugins'] ) ) {
			$validation = $this->validate_freemius_plugins_for_save( $settings );
			$errors     = $validation['errors'];

			// Persist validated rows and preserve existing saved rows for invalid entries.
			$settings['plugins'] = $validation['plugins'];

			if ( ! empty( $errors ) ) {
				add_settings_error(
					self::$prefix . '-notices',
					self::$prefix . '_freemius_validation_partial',
					esc_html__( 'Some Freemius plugin rows failed validation. Existing saved values were kept for those rows.', 'freemkit' ),
					'error'
				);

				foreach ( $errors as $error_message ) {
					add_settings_error(
						self::$prefix . '-notices',
						self::$prefix . '_freemius_validation_detail',
						$error_message,
						'error'
					);
				}
			}
		}

		return $settings;
	}

	/**
	 * Validate Freemius plugin credentials before persisting settings.
	 *
	 * @param array $settings Full settings payload prepared for save.
	 * @return array{plugins: array<int,mixed>, errors: array<int,string>} Filtered rows and validation errors.
	 */
	private function validate_freemius_plugins_for_save( array $settings ): array {
		$errors            = array();
		$persisted_plugins = array();
		$plugins           = isset( $settings['plugins'] ) && is_array( $settings['plugins'] ) ? $settings['plugins'] : array();
		$existing_settings = get_option( Options_API::SETTINGS_OPTION, array() );
		$existing_plugins  = ( is_array( $existing_settings ) && isset( $existing_settings['plugins'] ) && is_array( $existing_settings['plugins'] ) )
			? $existing_settings['plugins']
			: array();

		$existing_by_row_id = array();
		foreach ( $existing_plugins as $existing_plugin ) {
			if ( ! is_array( $existing_plugin ) ) {
				continue;
			}
			$row_id = isset( $existing_plugin['row_id'] ) ? (string) $existing_plugin['row_id'] : '';
			if ( '' !== $row_id ) {
				$existing_by_row_id[ $row_id ] = $existing_plugin;
			}
		}

		foreach ( $plugins as $index => $plugin ) {
			if ( ! is_array( $plugin ) || ! isset( $plugin['fields'] ) || ! is_array( $plugin['fields'] ) ) {
				continue;
			}

			$fields     = $plugin['fields'];
			$label      = isset( $fields['name'] ) && '' !== trim( (string) $fields['name'] ) ? trim( (string) $fields['name'] ) : sprintf( 'Row %d', (int) $index + 1 );
			$plugin_id  = trim( (string) ( $fields['id'] ?? '' ) );
			$public_key = trim( (string) ( $fields['public_key'] ?? '' ) );
			$secret_raw = (string) ( $fields['secret_key'] ?? '' );

			$secret_key = Settings_API::decrypt_api_key( $secret_raw );
			if ( '' === $secret_key ) {
				$secret_key = trim( $secret_raw );
			}

			if ( '' === $plugin_id || '' === $public_key || '' === $secret_key ) {
				/* translators: %s: plugin row label. */
				$errors[]            = sprintf( esc_html__( '%s: Product ID, Public Key, and Secret Key are required.', 'freemkit' ), esc_html( $label ) );
				$persisted_plugins[] = $this->resolve_saved_or_submitted_plugin_row( $plugin, $existing_by_row_id );
				continue;
			}

			$result = $this->validate_freemius_credentials( $plugin_id, $public_key, $secret_key );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: plugin row label, 2: validation error message. */
				$errors[]            = sprintf( esc_html__( '%1$s: %2$s', 'freemkit' ), esc_html( $label ), esc_html( $result->get_error_message() ) );
				$persisted_plugins[] = $this->resolve_saved_or_submitted_plugin_row( $plugin, $existing_by_row_id );
				continue;
			}

			$persisted_plugins[] = $plugin;
		}

		return array(
			'plugins' => array_values( $persisted_plugins ),
			'errors'  => $errors,
		);
	}

	/**
	 * Return existing saved plugin row when possible, otherwise submitted row.
	 *
	 * @param array $submitted_plugin Submitted plugin row.
	 * @param array $existing_by_row_id Existing rows indexed by row_id.
	 * @return array
	 */
	private function resolve_saved_or_submitted_plugin_row( array $submitted_plugin, array $existing_by_row_id ): array {
		$row_id = isset( $submitted_plugin['row_id'] ) ? (string) $submitted_plugin['row_id'] : '';

		if ( '' !== $row_id && isset( $existing_by_row_id[ $row_id ] ) && is_array( $existing_by_row_id[ $row_id ] ) ) {
			return $existing_by_row_id[ $row_id ];
		}

		return $submitted_plugin;
	}

	/**
	 * Handle AJAX search for ConvertKit resources
	 *
	 * @since 1.0.0
	 */
	public function handle_kit_search() {
		if ( ! isset( $_REQUEST['endpoint'] ) || ! isset( $_REQUEST['nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_send_json_error(
				(object) array(
					'message' => __( 'Invalid request parameters', 'freemkit' ),
					'items'   => array(),
				)
			);
		}

		// Tom Select endpoint.
		$endpoint = sanitize_text_field( wp_unslash( $_REQUEST['endpoint'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce    = self::$prefix . '_kit_search';
		$query    = isset( $_REQUEST['q'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: ( isset( $_REQUEST['query'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '' );

		check_ajax_referer( $nonce, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions', 'freemkit' ),
					'items'   => array(),
				)
			);
		}

		try {
			$items = array();

			switch ( $endpoint ) {
				case 'forms':
					$data = $this->get_kit_forms( $query );
					break;
				case 'tags':
					$data = $this->get_kit_tags( $query );
					break;
				case 'custom_fields':
					$data = $this->get_kit_custom_fields( $query );
					break;
				case 'freemius_events':
					$data = Freemius::get_events( $query );
					break;
				default:
					$data = array();
					break;
			}

			if ( is_wp_error( $data ) ) {
				wp_send_json_error(
					array(
						'message' => $data->get_error_message(),
						'items'   => array(),
					)
				);
			}

			foreach ( $data as $entry ) {
				$items[] = array(
					'id'   => $entry['id'],
					'name' => $entry['name'],
				);
			}

			wp_send_json_success(
				array(
					'message' => '',
					'items'   => $items,
				)
			);

		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'items'   => array(),
				)
			);
		}
	}

	/**
	 * AJAX endpoint to refresh ConvertKit lists
	 *
	 * @since 1.0.0
	 */
	public function ajax_refresh_lists() {
		check_ajax_referer( self::$prefix . '_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( (object) array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'freemkit' ) ) );
		}

		foreach ( array( 'forms', 'tags', 'sequences', 'custom_fields' ) as $transient ) {
			delete_transient( 'freemkit_kit_' . $transient );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler to test Kit connection.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_test_kit_connection() {
		check_ajax_referer( self::$prefix . '_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( (object) array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'freemkit' ) ) );
		}

		$api    = new Kit_API();
		$result = $api->get_account();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( (object) array( 'message' => $result->get_error_message() ) );
		}

		$account_name = isset( $result['account']['name'] ) ? sanitize_text_field( (string) $result['account']['name'] ) : '';
		$message      = $account_name
			? sprintf(
				/* translators: %s: Kit account name. */
				esc_html__( 'Connection successful. Account: %s', 'freemkit' ),
				$account_name
			)
			: esc_html__( 'Connection successful.', 'freemkit' );

		wp_send_json_success( (object) array( 'message' => $message ) );
	}

	/**
	 * AJAX handler to validate Freemius product credentials.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_validate_freemius_keys() {
		check_ajax_referer( self::$prefix . '_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( (object) array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'freemkit' ) ) );
		}

		$plugin_id  = isset( $_POST['plugin_id'] ) ? trim( (string) wp_unslash( $_POST['plugin_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$public_key = isset( $_POST['public_key'] ) ? trim( (string) wp_unslash( $_POST['public_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$secret_key = isset( $_POST['secret_key'] ) ? trim( (string) wp_unslash( $_POST['secret_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$row_id     = isset( $_POST['row_id'] ) ? sanitize_text_field( wp_unslash( $_POST['row_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Preserve key material exactly; only reject control characters.
		if ( preg_match( '/[\x00-\x1F\x7F]/', $public_key . $secret_key ) ) {
			wp_send_json_error(
				(object) array(
					'message' => esc_html__( 'The provided key contains invalid control characters.', 'freemkit' ),
				)
			);
		}

		$stored_row       = array();
		$secret_is_masked = false !== strpos( $secret_key, '**' );

		if ( '' !== $row_id || '' !== $plugin_id ) {
			$stored_row = $this->get_saved_freemius_plugin_row( $row_id, $plugin_id );
		}

		// If secret is masked and non-secret values changed, require re-entry of the secret.
		if ( $secret_is_masked && ! empty( $stored_row ) ) {
			$stored_id         = isset( $stored_row['id'] ) ? (string) $stored_row['id'] : '';
			$stored_public_key = isset( $stored_row['public_key'] ) ? (string) $stored_row['public_key'] : '';
			$id_changed        = '' !== $plugin_id && '' !== $stored_id && $plugin_id !== $stored_id;
			$public_changed    = '' !== $public_key && '' !== $stored_public_key && $public_key !== $stored_public_key;

			if ( $id_changed || $public_changed ) {
				wp_send_json_error(
					(object) array(
						'message' => esc_html__( 'You changed Product ID or Public Key. Please re-enter the Secret Key before validating.', 'freemkit' ),
					)
				);
			}
		}

		if ( '' === $plugin_id || '' === $public_key || '' === $secret_key || $secret_is_masked ) {
			if ( empty( $stored_row ) ) {
				$stored_row = $this->get_saved_freemius_plugin_row( $row_id, $plugin_id );
			}

			if ( ! empty( $stored_row ) ) {
				$plugin_id  = '' !== $plugin_id ? $plugin_id : (string) ( $stored_row['id'] ?? '' );
				$public_key = '' !== $public_key ? $public_key : (string) ( $stored_row['public_key'] ?? '' );
				$secret_key = $this->resolve_secret_key_for_validation( $secret_key, $stored_row );
			}
		}

		if ( '' === $plugin_id || '' === $public_key || '' === $secret_key ) {
			wp_send_json_error(
				(object) array(
					'message' => esc_html__( 'Product ID, public key, and secret key are required to validate credentials.', 'freemkit' ),
				)
			);
		}

		$result = $this->validate_freemius_credentials( $plugin_id, $public_key, $secret_key );
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			$details = $result->get_error_data();

			if ( is_array( $details ) ) {
				$status_code = isset( $details['status_code'] ) ? absint( $details['status_code'] ) : 0;
				$api_message = isset( $details['api_message'] ) ? sanitize_text_field( (string) $details['api_message'] ) : '';

				if ( $status_code > 0 ) {
					$message .= sprintf( ' (HTTP %d)', $status_code );
				}
			}

			wp_send_json_error( (object) array( 'message' => $message ) );
		}

		/* translators: 1: Freemius product name, 2: Product ID. */
		$message = sprintf( esc_html__( 'Credentials are valid for %1$s (ID: %2$s).', 'freemkit' ), $result['name'], $result['id'] );
		wp_send_json_success( (object) array( 'message' => $message ) );
	}

	/**
	 * Get a saved Freemius repeater row by row ID and/or plugin ID.
	 *
	 * @param string $row_id    Repeater row ID.
	 * @param string $plugin_id Freemius plugin ID.
	 * @return array<string,string>
	 */
	private function get_saved_freemius_plugin_row( string $row_id, string $plugin_id ): array {
		$settings = get_option( Options_API::SETTINGS_OPTION, array() );
		$plugins  = ( is_array( $settings ) && ! empty( $settings['plugins'] ) && is_array( $settings['plugins'] ) ) ? $settings['plugins'] : array();

		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}

			$fields = isset( $plugin['fields'] ) && is_array( $plugin['fields'] ) ? $plugin['fields'] : $plugin;
			$id     = isset( $fields['id'] ) ? sanitize_text_field( (string) $fields['id'] ) : '';
			$rid    = isset( $plugin['row_id'] ) ? sanitize_text_field( (string) $plugin['row_id'] ) : '';

			if ( '' !== $row_id && '' !== $rid && $row_id === $rid ) {
				return array(
					'id'         => $id,
					'public_key' => isset( $fields['public_key'] ) ? (string) $fields['public_key'] : '',
					'secret_key' => isset( $fields['secret_key'] ) ? (string) $fields['secret_key'] : '',
				);
			}

			if ( '' !== $plugin_id && '' !== $id && $plugin_id === $id ) {
				return array(
					'id'         => $id,
					'public_key' => isset( $fields['public_key'] ) ? (string) $fields['public_key'] : '',
					'secret_key' => isset( $fields['secret_key'] ) ? (string) $fields['secret_key'] : '',
				);
			}
		}

		return array();
	}

	/**
	 * Resolve plaintext secret key for validation request.
	 *
	 * @param string               $secret_key Submitted secret key.
	 * @param array<string,string> $stored_row Stored row values.
	 * @return string
	 */
	private function resolve_secret_key_for_validation( string $secret_key, array $stored_row ): string {
		if ( '' !== $secret_key && false === strpos( $secret_key, '**' ) ) {
			return $secret_key;
		}

		$stored_secret = isset( $stored_row['secret_key'] ) ? (string) $stored_row['secret_key'] : '';
		if ( '' === $stored_secret ) {
			return '';
		}

		return Settings_API::decrypt_api_key( $stored_secret );
	}

	/**
	 * Validate Freemius credentials against the product endpoint.
	 *
	 * @param string $plugin_id  Product ID.
	 * @param string $public_key Public key.
	 * @param string $secret_key Secret key.
	 * @return array<string,string>|\WP_Error
	 */
	private function validate_freemius_credentials( string $plugin_id, string $public_key, string $secret_key ) {
		return Freemius::validate_credentials( $plugin_id, $public_key, $secret_key );
	}

	/**
	 * Get Kit data, optionally filtered by search term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type   Type of data to get ('forms' or 'tags').
	 * @param string $search Optional search term.
	 * @return array|\WP_Error Array of items or WP_Error on failure.
	 */
	public static function get_kit_data( string $type, string $search = '' ) {
		$transient_key = self::$prefix . "_kit_{$type}";
		$items         = get_transient( $transient_key );

		if ( false === $items ) {
			$api = new Kit_API();
			$has = $api->validate_api_credentials();

			if ( is_wp_error( $has ) ) {
				return $has;
			}

			switch ( $type ) {
				case 'forms':
					$response = $api->get_forms();
					break;
				case 'tags':
					$response = $api->get_tags();
					break;
				case 'custom_fields':
					$response = $api->get_custom_fields();
					break;
				default:
					$response = new \WP_Error( 'invalid_type', __( 'Invalid type specified.', 'freemkit' ) );
					break;
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract items from the appropriate key in response.
			if ( isset( $response[ $type ] ) && is_array( $response[ $type ] ) ) {
				$items = $response[ $type ];
			} elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
				$items = $response['data'];
			} else {
				$items = array();
			}

			$items = self::normalize_kit_items( $items, $type );

			if ( ! empty( $items ) ) {
				set_transient( $transient_key, $items, DAY_IN_SECONDS );
			}
		}

		if ( ! empty( $search ) ) {
			$search = trim( strtolower( $search ) );
			$items  = array_filter(
				$items,
				function ( $item ) use ( $search ) {
					$name = trim( strtolower( (string) $item['name'] ) );
					$id   = trim( strtolower( (string) $item['id'] ) );
					return false !== strpos( $name, $search ) || false !== strpos( $id, $search );
				}
			);
		}

		return array_values( $items );
	}

	/**
	 * Return Kit data for script localization.
	 *
	 * @param string $type Resource type.
	 * @return array
	 */
	public static function get_localized_kit_data( string $type ): array {
		if ( 'freemius_events' === $type ) {
			return Freemius::get_events();
		}

		$data = self::get_kit_data( $type );
		return is_wp_error( $data ) ? array() : $data;
	}

	/**
	 * Return Freemius event choices for selectors.
	 *
	 * @param string $search Optional search text.
	 * @return array<int,array<string,string>>
	 */
	public static function get_freemius_events( string $search = '' ): array {
		return Freemius::get_events( $search );
	}

	/**
	 * Normalize Kit API resource items into id/name pairs.
	 *
	 * @param array  $items Raw items.
	 * @param string $type  Resource type.
	 * @return array
	 */
	public static function normalize_kit_items( array $items, string $type ): array {
		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id = isset( $item['id'] ) ? (string) $item['id'] : ( isset( $item['key'] ) ? (string) $item['key'] : '' );
			if ( '' === $id ) {
				continue;
			}

			$name = '';
			if ( isset( $item['name'] ) ) {
				$name = (string) $item['name'];
			} elseif ( isset( $item['label'] ) ) {
				$name = (string) $item['label'];
			} elseif ( 'custom_fields' === $type && isset( $item['key'] ) ) {
				$name = (string) $item['key'];
			}

			if ( '' === $name ) {
				$name = $id;
			}

			$normalized[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}

		return $normalized;
	}

	/**
	 * Get Kit forms, optionally filtered by search term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Optional search term.
	 * @return array|\WP_Error Array of forms.
	 */
	public function get_kit_forms( $search = '' ) {
		return self::get_kit_data( 'forms', $search );
	}

	/**
	 * Get Kit tags, optionally filtered by search term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Optional search term.
	 * @return array|\WP_Error Array of tags.
	 */
	public function get_kit_tags( $search = '' ) {
		return self::get_kit_data( 'tags', $search );
	}

	/**
	 * Get Kit custom fields, optionally filtered by search term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Optional search term.
	 * @return array|\WP_Error Array of custom fields.
	 */
	public function get_kit_custom_fields( $search = '' ) {
		return self::get_kit_data( 'custom_fields', $search );
	}

	/**
	 * Add a "Test Connection" button after the OAuth connection output.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Field output HTML.
	 * @param array  $args Field arguments.
	 * @return string
	 */
	public function add_connection_test_button( string $html, array $args ): string {
		if ( ! isset( $args['id'] ) || 'kit_oauth_status' !== $args['id'] ) {
			return $html;
		}

		$html .= '<p><button type="button" class="button button-secondary test-kit-connection">' . esc_html__( 'Test Connection', 'freemkit' ) . '</button>';
		$html .= '<span class="kit-connection-status" style="margin-left: 10px;"></span></p>';

		return $html;
	}

	/**
	 * Get settings defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default settings.
	 */
	public static function settings_defaults() {
		static $running = false;
		if ( $running ) {
			return array();
		}
		$running = true;

		$defaults = array();

		// Get all registered settings.
		$settings = self::get_registered_settings();

		// Loop through each section.
		foreach ( $settings as $section => $section_settings ) {
			// Loop through each setting in the section.
			foreach ( $section_settings as $setting ) {
				if ( isset( $setting['id'] ) ) {
					// When checkbox is set to true, set this to 1.
					if ( 'checkbox' === $setting['type'] && ! empty( $setting['options'] ) ) {
						$defaults[ $setting['id'] ] = 1;
					} elseif ( in_array( $setting['type'], array( 'textarea', 'css', 'html', 'text', 'url', 'csv', 'color', 'numbercsv', 'postids', 'posttypes', 'number', 'wysiwyg', 'file', 'password' ), true ) && isset( $setting['default'] ) ) {
						$defaults[ $setting['id'] ] = $setting['default'];
					} elseif ( in_array( $setting['type'], array( 'multicheck', 'radio', 'select', 'radiodesc', 'thumbsizes', 'repeater' ), true ) && isset( $setting['default'] ) ) {
						$defaults[ $setting['id'] ] = $setting['default'];
					} else {
						$defaults[ $setting['id'] ] = '';
					}
				}
			}
		}

		/**
		 * Filter the default settings array.
		 *
		 * @since 1.0.0
		 *
		 * @param array $defaults Default settings.
		 */
		$defaults = apply_filters( self::$prefix . '_settings_defaults', $defaults );
		$running  = false;

		return $defaults;
	}

	/**
	 * Add subscribers link to settings page header.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_subscribers_link() {
		$url = add_query_arg(
			array(
				'page' => 'freemkit_subscribers',
			),
			admin_url( 'users.php' )
		);
		?>

		<a href="<?php echo esc_url( $url ); ?>" class="page-title-action"><?php esc_html_e( 'View Subscribers', 'freemkit' ); ?></a>
		<?php
	}

	/**
	 * Add clear cache button to the settings page.
	 *
	 * @since 1.0.0
	 */
	public static function add_cache_clear_button() {
		printf(
			'<button type="button" name="wp_ajax_freemkit_refresh_cache" id="wp_ajax_freemkit_refresh_cache" class="button button-secondary freemkit_cache_clear" aria-label="%1$s">%1$s</button>',
			esc_html__( 'Clear cache', 'freemkit' )
		);
	}

	/**
	 * Add Setup Wizard button on the settings page.
	 *
	 * @return void
	 */
	public function render_wizard_button(): void {
		printf(
			'<br /><a aria-label="%1$s" class="button button-secondary" href="%2$s" title="%1$s" style="margin-top: 10px;">%3$s</a>',
			esc_attr__( 'Start Setup Wizard', 'freemkit' ),
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'page'          => 'freemkit_setup_wizard',
							'wizard_action' => 'restart',
						),
						admin_url( 'options-general.php' )
					),
					'freemkit_restart_wizard'
				)
			),
			esc_html__( 'Start Setup Wizard', 'freemkit' )
		);
	}

	/**
	 * Return webhook URLs for both endpoint types.
	 *
	 * @return array<string,string>
	 */
	public static function get_webhook_urls(): array {
		return array(
			'rest'  => home_url( '/wp-json/freemkit/v1/webhook' ),
			'query' => add_query_arg( 'freemkit_webhook', '1', home_url() ),
		);
	}

	/**
	 * Get the webhook URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string The webhook URL.
	 */
	public static function get_webhook_url(): string {
		$urls      = self::get_webhook_urls();
		$rest_url  = $urls['rest'];
		$query_url = $urls['query'];

		// Avoid recursive defaults resolution while settings are being registered.
		$settings      = get_option( Options_API::SETTINGS_OPTION, array() );
		$endpoint_type = isset( $settings['webhook_endpoint_type'] ) ? (string) $settings['webhook_endpoint_type'] : 'rest';
		$webhook_url   = 'query' === $endpoint_type ? $query_url : $rest_url;

		$string  = '<div class="webhook-url-container" data-rest-url="' . esc_attr( $rest_url ) . '" data-query-url="' . esc_attr( $query_url ) . '">';
		$string .= '<p>' . esc_html__( 'Copy the following URL to your Freemius dashboard:', 'freemkit' ) . '</p>';
		$string .= '<p><input type="text" class="regular-text freemkit-webhook-url-input" readonly value="' . esc_attr( $webhook_url ) . '" /></p>';
		$string .= '<p><button type="button" class="button button-secondary freemkit-webhook-copy">' . esc_html__( 'Copy URL', 'freemkit' ) . '</button></p>';
		$string .= '<p class="description freemkit-webhook-copy-status" aria-live="polite"></p>';
		$string .= '<p><code class="freemkit-webhook-url-code" title="' . esc_attr__( 'Click to copy URL', 'freemkit' ) . '" style="cursor:pointer;">' . esc_html( $webhook_url ) . '</code></p>';
		$string .= '<p class="description">' . esc_html__( 'This URL updates automatically based on your selected endpoint type.', 'freemkit' ) . '</p>';
		$string .= '</div>';

		return $string;
	}
}
