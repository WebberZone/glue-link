<?php
/**
 * Subscriber Form class.
 *
 * @link  https://webberzone.com
 * @since 1.0.0
 *
 * @package WebberZone\FreemKit\Admin
 */

namespace WebberZone\FreemKit\Admin;

use WebberZone\FreemKit\Admin\Settings\Settings_API;
use WebberZone\FreemKit\Admin\Settings\Settings_Form;
use WebberZone\FreemKit\Admin\Settings\Settings_Sanitize;
use WebberZone\FreemKit\Database;
use WebberZone\FreemKit\Kit\Kit_API;
use WebberZone\FreemKit\Options_API;
use WebberZone\FreemKit\Runtime;
use WebberZone\FreemKit\Subscriber;
use WebberZone\FreemKit\Subscriber_Event;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class to handle the add/edit subscriber form.
 *
 * @since 1.0.0
 */
class Subscriber_Form {

	/**
	 * Settings key for the form fields.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SETTINGS_KEY = 'freemkit_subscriber';

	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var Database
	 */
	protected Database $database;

	/**
	 * Subscriber being edited, or null for add mode.
	 *
	 * @since 1.0.0
	 * @var ?Subscriber
	 */
	protected ?Subscriber $subscriber = null;

	/**
	 * Whether we are in edit mode.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	protected bool $is_edit = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Database $database      Database instance.
	 * @param int      $subscriber_id Optional subscriber ID for edit mode.
	 */
	public function __construct( Database $database, int $subscriber_id = 0 ) {
		$this->database = $database;

		if ( $subscriber_id > 0 ) {
			$sub = $this->database->get_subscriber( $subscriber_id );
			if ( ! is_wp_error( $sub ) ) {
				$this->subscriber = $sub;
				$this->is_edit    = true;
			}
		}
	}

	/**
	 * Get the subscriber detail field definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of field definition arrays.
	 */
	protected function get_subscriber_fields(): array {
		return array(
			array(
				'id'       => 'email',
				'name'     => __( 'Email', 'freemkit' ),
				'type'     => 'text',
				'required' => true,
				'default'  => '',
				'size'     => 'large',
			),
			array(
				'id'      => 'first_name',
				'name'    => __( 'First Name', 'freemkit' ),
				'type'    => 'text',
				'default' => '',
				'size'    => 'large',
			),
			array(
				'id'      => 'last_name',
				'name'    => __( 'Last Name', 'freemkit' ),
				'type'    => 'text',
				'default' => '',
				'size'    => 'large',
			),
			array(
				'id'      => 'status',
				'name'    => __( 'Status', 'freemkit' ),
				'desc'    => __( 'Subscriber status used for filtering in the subscribers list.', 'freemkit' ),
				'type'    => 'select',
				'default' => 'active',
				'options' => array(
					'active'   => __( 'Active', 'freemkit' ),
					'inactive' => __( 'Inactive', 'freemkit' ),
				),
			),
			array(
				'id'      => 'marketing',
				'name'    => __( 'Marketing', 'freemkit' ),
				'desc'    => __( 'Subscriber has opted in to marketing emails.', 'freemkit' ),
				'type'    => 'checkbox',
				'default' => 1,
			),
			array(
				'id'               => 'freemius_user_id',
				'name'             => __( 'Freemius User ID', 'freemkit' ),
				'type'             => 'text',
				'default'          => '',
				'size'             => 'large',
				'field_attributes' => array( 'readonly' => 'readonly' ),
			),
			array(
				'id'               => 'freemius_created',
				'name'             => __( 'Freemius Created', 'freemkit' ),
				'type'             => 'text',
				'default'          => '',
				'size'             => 'large',
				'field_attributes' => array( 'readonly' => 'readonly' ),
			),
			array(
				'id'               => 'is_verified',
				'name'             => __( 'Email Verified', 'freemkit' ),
				'type'             => 'text',
				'default'          => '',
				'size'             => 'large',
				'field_attributes' => array( 'readonly' => 'readonly' ),
			),
			array(
				'id'               => 'email_status',
				'name'             => __( 'Email Status', 'freemkit' ),
				'type'             => 'text',
				'default'          => '',
				'size'             => 'large',
				'field_attributes' => array( 'readonly' => 'readonly' ),
			),
			array(
				'id'      => 'notes',
				'name'    => __( 'Notes', 'freemkit' ),
				'desc'    => __( 'Freeform notes about this subscriber. Not sent to Kit.', 'freemkit' ),
				'type'    => 'textarea',
				'default' => '',
			),
		);
	}

	/**
	 * Get the plugins repeater field definition.
	 *
	 * @since 1.0.0
	 *
	 * @return array Repeater field definition.
	 */
	protected function get_plugins_field(): array {
		$plugin_options = array( '' => __( '&mdash; None &mdash;', 'freemkit' ) );
		foreach ( $this->get_configured_plugins() as $pid => $pname ) {
			$plugin_options[ $pid ] = $pname;
		}

		return array(
			'id'                        => 'plugins',
			'name'                      => __( 'Plugins', 'freemkit' ),
			'desc'                      => __( 'Each row creates a Kit subscription event for the selected plugin. Form and tag fields are optional — when empty, they are resolved from the plugin config based on user type, then from global defaults.', 'freemkit' ),
			'type'                      => 'repeater',
			'add_button_text'           => __( 'Add Plugin', 'freemkit' ),
			'new_item_text'             => __( 'New Plugin', 'freemkit' ),
			'live_update_field'         => 'plugin_id',
			'live_update_field_options' => $plugin_options,
			'default'                   => array(),
			'fields'                    => array(
				array(
					'id'      => 'plugin_id',
					'name'    => __( 'Plugin', 'freemkit' ),
					'desc'    => __( 'Associate with a Freemius plugin. Form/tag defaults are resolved from the plugin config.', 'freemkit' ),
					'type'    => 'select',
					'default' => '',
					'options' => $plugin_options,
				),
				array(
					'id'      => 'user_type',
					'name'    => __( 'User Type', 'freemkit' ),
					'desc'    => __( 'Determines which form/tag set to use from the plugin configuration.', 'freemkit' ),
					'type'    => 'select',
					'default' => 'free',
					'options' => array(
						'free' => __( 'Free', 'freemkit' ),
						'paid' => __( 'Paid', 'freemkit' ),
					),
				),
				array(
					'id'               => 'form_ids',
					'name'             => __( 'Kit Forms', 'freemkit' ),
					'desc'             => __( 'Override Kit form(s). Leave empty to use plugin config or global default.', 'freemkit' ),
					'type'             => 'text',
					'default'          => '',
					'size'             => 'large',
					'field_class'      => 'ts_autocomplete',
					'field_attributes' => Settings::get_kit_search_field_attributes( 'forms' ),
				),
				array(
					'id'               => 'tag_ids',
					'name'             => __( 'Kit Tags', 'freemkit' ),
					'desc'             => __( 'Override Kit tag(s). Leave empty to use plugin config or global default.', 'freemkit' ),
					'type'             => 'text',
					'default'          => '',
					'size'             => 'large',
					'field_class'      => 'ts_autocomplete',
					'field_attributes' => Settings::get_kit_search_field_attributes( 'tags' ),
				),
			),
		);
	}

	/**
	 * Get the subscriber field value for rendering.
	 *
	 * @since 1.0.0
	 *
	 * @param string $field_id      Field ID.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	protected function get_subscriber_value( string $field_id, $default_value = '' ) {
		if ( ! $this->subscriber ) {
			return $default_value;
		}

		switch ( $field_id ) {
			case 'email':
				return $this->subscriber->email;
			case 'first_name':
				return $this->subscriber->first_name;
			case 'last_name':
				return $this->subscriber->last_name;
			case 'status':
				return $this->subscriber->status;
			case 'marketing':
				return $this->subscriber->marketing;
			case 'freemius_user_id':
				return $this->subscriber->freemius_user_id ? (string) $this->subscriber->freemius_user_id : '';
			case 'freemius_created':
				return $this->subscriber->freemius_created;
			case 'is_verified':
				return $this->subscriber->is_verified ? __( 'Yes', 'freemkit' ) : __( 'No', 'freemkit' );
			case 'email_status':
				return $this->subscriber->email_status;
			case 'notes':
				return $this->subscriber->notes;
			default:
				return $default_value;
		}
	}

	/**
	 * Process the form submission.
	 *
	 * Must be called before any output.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function process_form() {
		if ( ! isset( $_POST['freemkit_subscriber_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['freemkit_subscriber_nonce'] ) ), 'freemkit_save_subscriber' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'freemkit' ) );
		}

		if ( empty( $_POST[ self::SETTINGS_KEY ] ) || ! is_array( $_POST[ self::SETTINGS_KEY ] ) ) {
			$this->redirect_with_message( 'error', __( 'No form data received.', 'freemkit' ) );
			return;
		}

		$posted = $_POST[ self::SETTINGS_KEY ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		// Handle delete action.
		if ( isset( $_POST['freemkit_delete_subscriber'] ) ) {
			$subscriber_id = isset( $posted['subscriber_id'] ) ? absint( $posted['subscriber_id'] ) : 0;
			if ( $subscriber_id > 0 ) {
				$subscriber = $this->database->get_subscriber( $subscriber_id );
				$result     = $this->database->delete_subscriber( $subscriber_id );

				if ( is_wp_error( $result ) ) {
					$this->redirect_with_message( 'error', $result->get_error_message() );
					return;
				}

				$message = __( 'Subscriber deleted.', 'freemkit' );

				if ( ! is_wp_error( $subscriber ) && Options_API::get_option( 'kit_unsubscribe_on_delete', 0 ) ) {
					$kit_api = new Kit_API();
					if ( $kit_api->has_access_and_refresh_token() ) {
						$kit_result = $kit_api->unsubscribe_subscriber( $subscriber->email );
						if ( is_wp_error( $kit_result ) ) {
							/* translators: %s: Error message from Kit API */
							$message .= ' ' . sprintf( __( 'Kit unsubscribe failed: %s', 'freemkit' ), $kit_result->get_error_message() );
						} else {
							$message .= ' ' . __( 'Unsubscribed from Kit.', 'freemkit' );
						}
					}
				}

				$this->redirect_with_message( 'success', $message );
				return;
			}
		}

		$settings_sanitize = new Settings_Sanitize(
			array(
				'settings_key' => self::SETTINGS_KEY,
				'prefix'       => 'freemkit',
			)
		);

		// Sanitize subscriber fields.
		$subscriber_id = isset( $posted['subscriber_id'] ) ? absint( $posted['subscriber_id'] ) : 0;
		$email         = isset( $posted['email'] ) ? sanitize_email( $posted['email'] ) : '';
		$first_name    = $settings_sanitize->sanitize_text_field( $posted['first_name'] ?? '' );
		$last_name     = $settings_sanitize->sanitize_text_field( $posted['last_name'] ?? '' );
		$status        = $settings_sanitize->sanitize_text_field( $posted['status'] ?? 'active' );
		$marketing     = $settings_sanitize->sanitize_checkbox_field( $posted['marketing'] ?? -1 );
		$notes         = $settings_sanitize->sanitize_textarea_field( $posted['notes'] ?? '' );

		if ( empty( $email ) ) {
			$this->redirect_with_message( 'error', __( 'Email address is required.', 'freemkit' ) );
			return;
		}

		$subscriber_data = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'status'     => $status,
			'marketing'  => $marketing,
			'notes'      => $notes,
		);

		if ( $subscriber_id > 0 ) {
			$existing = $this->database->get_subscriber( $subscriber_id );
			if ( ! is_wp_error( $existing ) ) {
				$subscriber_data['freemius_user_id'] = (int) $existing->freemius_user_id;
				$subscriber_data['freemius_created'] = $existing->freemius_created;
				$subscriber_data['is_verified']      = (int) $existing->is_verified;
				$subscriber_data['email_status']     = $existing->email_status;
				$subscriber_data['meta']             = $existing->meta;
			}
			$subscriber_data['id'] = $subscriber_id;
		}

		$subscriber = new Subscriber( $subscriber_data, $this->database );
		$result     = $subscriber->save();

		if ( is_wp_error( $result ) && 'subscriber_exists' === $result->get_error_code() && $subscriber_id <= 0 ) {
			$existing = $this->database->get_subscriber_by_email( $email );
			if ( ! is_wp_error( $existing ) ) {
				$subscriber_data['id']               = (int) $existing->id;
				$subscriber_data['freemius_user_id'] = (int) $existing->freemius_user_id;
				$subscriber_data['freemius_created'] = $existing->freemius_created;
				$subscriber_data['is_verified']      = (int) $existing->is_verified;
				$subscriber_data['email_status']     = $existing->email_status;
				$subscriber_data['meta']             = $existing->meta;
				$subscriber                          = new Subscriber( $subscriber_data, $this->database );
				$result                              = $subscriber->save();
				$this->is_edit                       = true;
			}
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'error', $result->get_error_message() );
			return;
		}

		// Delete existing events before creating new ones (edit mode or subscriber_exists recovery).
		$this->database->delete_subscriber_events( $result );

		$kit_api      = new Kit_API();
		$events       = isset( $posted['plugins'] ) && is_array( $posted['plugins'] ) ? $posted['plugins'] : array();
		$kit_messages = array();
		$sync_to_kit  = isset( $_POST['freemkit_save_sync'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$kit_synced   = false;

		// If the subscriber has opted out of marketing, unsubscribe from Kit.
		if ( ! $marketing ) {
			if ( $kit_api->has_access_and_refresh_token() ) {
				$kit_api->unsubscribe_subscriber( $email );
			}
			$kit_messages[] = __( 'Subscriber unsubscribed from Kit due to marketing opt-out.', 'freemkit' );
		}

		// Always save plugin events to DB. Sync to Kit only when explicitly requested.
		foreach ( $events as $event_row ) {
			if ( ! is_array( $event_row ) ) {
				continue;
			}

			$event_fields = isset( $event_row['fields'] ) && is_array( $event_row['fields'] ) ? $event_row['fields'] : $event_row;
			$plugin_id    = $settings_sanitize->sanitize_text_field( $event_fields['plugin_id'] ?? '' );
			$user_type    = $settings_sanitize->sanitize_text_field( $event_fields['user_type'] ?? 'free' );
			$form_ids     = $settings_sanitize->sanitize_text_field( $event_fields['form_ids'] ?? '' );
			$tag_ids      = $settings_sanitize->sanitize_text_field( $event_fields['tag_ids'] ?? '' );

			if ( '' === $plugin_id && '' === $form_ids && '' === $tag_ids ) {
				continue;
			}

			$resolved      = $this->resolve_form_tag_ids( $plugin_id, $user_type, $form_ids, $tag_ids );
			$plugin_config = $this->get_plugin_config( $plugin_id );

			$event = new Subscriber_Event(
				array(
					'subscriber_id' => $result,
					'plugin_id'     => $plugin_id,
					'plugin_slug'   => $plugin_config ? $plugin_config['slug'] : '',
					'event_type'    => 'manual',
					'user_type'     => $user_type,
					'form_ids'      => implode( ',', $resolved['form_ids'] ),
					'tag_ids'       => implode( ',', $resolved['tag_ids'] ),
				)
			);
			$this->database->add_subscriber_event( $event );

			if ( $sync_to_kit && $marketing ) {
				$kit_msg = $this->sync_to_kit( $subscriber, $resolved['form_ids'], $resolved['tag_ids'], $kit_api );
				if ( ! empty( $kit_msg ) ) {
					$kit_messages[] = $kit_msg;
				}
				$kit_synced = true;
			}
		}

		if ( $subscriber_id > 0 ) {
			$message = ( $sync_to_kit && $kit_synced )
				? __( 'Subscriber updated and synced to Kit.', 'freemkit' )
				: __( 'Subscriber updated successfully.', 'freemkit' );
		} else {
			$message = ( $sync_to_kit && $kit_synced )
				? __( 'Subscriber added and synced to Kit.', 'freemkit' )
				: __( 'Subscriber added successfully.', 'freemkit' );
		}

		if ( ! empty( $kit_messages ) ) {
			$message .= ' ' . implode( ' ', array_unique( $kit_messages ) );
		}

		$this->redirect_with_message( 'success', $message );
	}

	/**
	 * Get a single plugin configuration by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id Freemius plugin ID.
	 * @return array|null Plugin config array or null.
	 */
	protected function get_plugin_config( string $plugin_id ): ?array {
		if ( empty( $plugin_id ) ) {
			return null;
		}

		$runtime = new Runtime();
		$configs = $runtime->get_plugin_configs();

		return $configs[ $plugin_id ] ?? null;
	}

	/**
	 * Resolve form and tag IDs mirroring webhook handler logic.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id Plugin ID.
	 * @param string $user_type 'free' or 'paid'.
	 * @param string $form_ids  Comma-separated form IDs from form input.
	 * @param string $tag_ids   Comma-separated tag IDs from form input.
	 * @return array{form_ids: array, tag_ids: array}
	 */
	protected function resolve_form_tag_ids( string $plugin_id, string $user_type, string $form_ids, string $tag_ids ): array {
		$form_id_list = ! empty( $form_ids ) ? wp_parse_id_list( $form_ids ) : array();
		$tag_id_list  = ! empty( $tag_ids ) ? wp_parse_id_list( $tag_ids ) : array();

		// Try plugin config based on user_type.
		$plugin_config = $this->get_plugin_config( $plugin_id );
		if ( $plugin_config ) {
			$form_key = 'free' === $user_type ? 'free_form_ids' : 'paid_form_ids';
			$tag_key  = 'free' === $user_type ? 'free_tag_ids' : 'paid_tag_ids';

			if ( empty( $form_id_list ) ) {
				$form_id_list = wp_parse_id_list( $plugin_config[ $form_key ] ?? '' );
			}
			if ( empty( $tag_id_list ) ) {
				$tag_id_list = wp_parse_id_list( $plugin_config[ $tag_key ] ?? '' );
			}
		}

		// Fall back to global defaults.
		if ( empty( $form_id_list ) ) {
			$global_form = Options_API::get_option( 'kit_form_id', '' );
			if ( ! empty( $global_form ) ) {
				$form_id_list = wp_parse_id_list( $global_form );
			}
		}

		if ( empty( $tag_id_list ) ) {
			$global_tag = Options_API::get_option( 'kit_tag_id', '' );
			if ( ! empty( $global_tag ) ) {
				$tag_id_list = wp_parse_id_list( $global_tag );
			}
		}

		return array(
			'form_ids' => $form_id_list,
			'tag_ids'  => $tag_id_list,
		);
	}

	/**
	 * Sync subscriber to Kit.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber   $subscriber Subscriber object.
	 * @param array        $form_ids   Resolved form IDs.
	 * @param array        $tag_ids    Resolved tag IDs.
	 * @param Kit_API|null $kit_api   Optional Kit_API instance; one is created if null.
	 * @return string Message about Kit sync result.
	 */
	protected function sync_to_kit( Subscriber $subscriber, array $form_ids = array(), array $tag_ids = array(), ?Kit_API $kit_api = null ): string {
		if ( null === $kit_api ) {
			$kit_api = new Kit_API();
		}

		if ( ! $kit_api->has_access_and_refresh_token() ) {
			return __( 'Kit sync skipped: not connected to Kit.', 'freemkit' );
		}

		if ( empty( $form_ids ) ) {
			return __( 'Kit sync skipped: no form specified or configured.', 'freemkit' );
		}

		// Build Kit custom fields from settings mappings.
		// resolve_custom_field_key() converts legacy numeric IDs to string keys.
		$kit_fields      = array();
		$last_name_field = Options_API::get_option( 'last_name_field', '' );
		if ( ! empty( $last_name_field ) && ! empty( $subscriber->last_name ) ) {
			$kit_fields[ $kit_api->resolve_custom_field_key( $last_name_field ) ] = $subscriber->last_name;
		}

		$errors = array();
		foreach ( $form_ids as $form_id ) {
			$result = $kit_api->subscribe_to_form( (int) $form_id, $subscriber->email, $subscriber->first_name, $kit_fields, $tag_ids );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			}
		}

		if ( ! empty( $errors ) ) {
			/* translators: %s: Error message(s) from Kit API */
			return sprintf( __( 'Kit sync failed: %s', 'freemkit' ), implode( '; ', $errors ) );
		}

		return __( 'Subscriber synced to Kit.', 'freemkit' );
	}

	/**
	 * Redirect with a message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    Message type ('success' or 'error').
	 * @param string $message Message text.
	 */
	protected function redirect_with_message( string $type, string $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'freemkit_subscribers',
					'freemkit_msg' => rawurlencode( $message ),
					'msg_type'     => $type,
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * Get the configured plugins for the plugin dropdown.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of plugin_id => plugin_name pairs.
	 */
	protected function get_configured_plugins(): array {
		$plugins = Options_API::get_option( 'plugins', array() );
		$options = array();

		if ( ! is_array( $plugins ) ) {
			return $options;
		}

		foreach ( $plugins as $plugin ) {
			// Repeater stores data under a nested 'fields' key.
			$data = isset( $plugin['fields'] ) ? $plugin['fields'] : $plugin;

			if ( ! empty( $data['id'] ) && ! empty( $data['name'] ) ) {
				$options[ $data['id'] ] = $data['name'];
			}
		}

		return $options;
	}

	/**
	 * Get subscriber events as repeater rows for edit screen.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_subscriber_event_rows(): array {
		if ( ! $this->subscriber || empty( $this->subscriber->id ) ) {
			return array();
		}

		$events = $this->database->get_subscriber_events(
			(int) $this->subscriber->id,
			array(
				'per_page' => 100,
				'page'     => 1,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		if ( empty( $events ) ) {
			return array();
		}

		$rows = array();
		foreach ( $events as $event ) {
			if ( empty( $event->plugin_id ) && empty( $event->form_ids ) && empty( $event->tag_ids ) ) {
				continue;
			}

			$rows[] = array(
				'fields' => array(
					'plugin_id' => (string) $event->plugin_id,
					'user_type' => (string) $event->user_type,
					'form_ids'  => (string) $event->form_ids,
					'tag_ids'   => (string) $event->tag_ids,
				),
				'row_id' => 'row_event_' . (int) $event->id,
			);
		}

		return $rows;
	}

	/**
	 * Enqueue scripts and styles for the subscriber form.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		$prefix = Settings::$prefix;

		// Enqueue settings scripts (includes repeater JS and Tom Select).
		Settings_API::enqueue_scripts_styles(
			$prefix,
			array(
				'strings' => array(
					/* translators: %s: search term */
					'no_results' => esc_html__( 'No results found for "%s"', 'freemkit' ),
				),
			)
		);

		// Tom Select data for Kit resource search.
		wp_localize_script(
			"wz-{$prefix}-tom-select-init",
			"{$prefix}TomSelectSettings",
			array(
				'prefix'          => $prefix,
				'nonce'           => wp_create_nonce( "{$prefix}_kit_search" ),
				'action'          => "{$prefix}_kit_search",
				'endpoint'        => '',
				'forms'           => Settings::get_localized_kit_data( 'forms' ),
				'tags'            => Settings::get_localized_kit_data( 'tags' ),
				'freemius_events' => Settings::get_localized_kit_data( 'freemius_events' ),
				'strings'         => array(
					/* translators: %s: search term */
					'no_results' => esc_html__( 'No results found for %s', 'freemkit' ),
				),
			)
		);
	}

	/**
	 * Render a set of fields in a form-table using Settings_Form callbacks.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings_Form $form   Settings_Form instance.
	 * @param array         $fields Array of field definitions.
	 * @param callable      $value_callback Callback to get the value for a field ID.
	 */
	protected function render_fields( Settings_Form $form, array $fields, callable $value_callback ) {
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $setting ) {
			$args          = Settings_API::parse_field_args( $setting );
			$args['value'] = $value_callback( $args['id'], $args['default'] ?? '' );
			$type          = $args['type'] ?? 'text';
			$callback      = method_exists( $form, "callback_{$type}" ) ? array( $form, "callback_{$type}" ) : array( $form, 'callback_missing' );

			echo '<tr>';
			echo '<th scope="row">' . $args['name'] . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>';
			call_user_func( $callback, $args );
			echo '</td>';
			echo '</tr>';
		}
		echo '</table>';
	}

	/**
	 * Render the add/edit subscriber form.
	 *
	 * @since 1.0.0
	 */
	public function render_form() {
		$this->enqueue_scripts();

		$list_url = admin_url( 'users.php?page=freemkit_subscribers' );

		$title = $this->is_edit
			? __( 'Edit Subscriber', 'freemkit' )
			: __( 'Add Subscriber', 'freemkit' );

		$submit_text = $this->is_edit
			? __( 'Update Subscriber', 'freemkit' )
			: __( 'Add Subscriber', 'freemkit' );

		$sync_text = $this->is_edit
			? __( 'Update & Sync to Kit', 'freemkit' )
			: __( 'Add & Sync to Kit', 'freemkit' );

		$settings_form = new Settings_Form(
			array(
				'settings_key' => self::SETTINGS_KEY,
				'prefix'       => 'freemkit',
			)
		);

		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Subscribers', 'freemkit' ); ?></a>

			<form method="post" action="">
				<?php wp_nonce_field( 'freemkit_save_subscriber', 'freemkit_subscriber_nonce' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::SETTINGS_KEY ); ?>[subscriber_id]" value="<?php echo esc_attr( (string) ( $this->subscriber ? $this->subscriber->id : 0 ) ); ?>" />

				<h2><?php esc_html_e( 'Subscriber Details', 'freemkit' ); ?></h2>
				<?php
				$this->render_fields(
					$settings_form,
					$this->get_subscriber_fields(),
					array( $this, 'get_subscriber_value' )
				);
				?>

				<h2><?php esc_html_e( 'Plugins', 'freemkit' ); ?></h2>
				<?php
				$events_field = Settings_API::parse_field_args( $this->get_plugins_field() );

				$events_field['value'] = $this->get_subscriber_event_rows();
				if ( empty( $events_field['value'] ) ) {
					$events_field['value'] = array(
						array(
							'fields' => array(
								'plugin_id' => '',
								'user_type' => 'free',
								'form_ids'  => '',
								'tag_ids'   => '',
							),
							'row_id' => 'row_default_0',
						),
					);
				}

				echo '<table class="form-table" role="presentation">';
				echo '<tr>';
				echo '<th scope="row">' . $events_field['name'] . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>';
				$settings_form->callback_repeater( $events_field );
				echo '</td>';
				echo '</tr>';
				echo '</table>';
				?>

				<p class="submit">
					<input type="submit" name="submit" class="button button-primary" value="<?php echo esc_attr( $submit_text ); ?>" />
					<input type="submit" name="freemkit_save_sync" class="button button-secondary" value="<?php echo esc_attr( $sync_text ); ?>" />
					<?php if ( $this->is_edit && $this->subscriber && $this->subscriber->id ) { ?>
					&nbsp;&nbsp;&nbsp;&nbsp;<button type="submit" name="freemkit_delete_subscriber" class="button" style="background-color: #dc3545; border-color: #dc3545; color: white;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this subscriber?', 'freemkit' ) ); ?>');"><?php esc_html_e( 'Delete Subscriber', 'freemkit' ); ?></button>
					<?php } ?>
				</p>
			</form>
		</div>
		<?php
	}
}
