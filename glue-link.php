<?php
/**
 * Plugin integration between Freemius and Kit
 *
 * @package   WebberZone\Glue_Link
 * @author    WebberZone
 * @license   GPL-2.0+
 * @link      https://webberzone.com
 *
 * @wordpress-plugin
 * Plugin Name: WebberZone Glue Link - Glue for Freemius and Kit
 * Plugin URI:  https://webberzone.com/plugins/glue-link/
 * Description: Easily subscribe Freemius customers to Kit email lists.
 * Version:     1.0.0-beta1
 * Author:      WebberZone
 * Author URI:  https://webberzone.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: glue-link
 * Domain Path: /languages
 */

namespace WebberZone\Glue_Link;

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants.
if ( ! defined( 'GLUE_LINK_VERSION' ) ) {
	define( 'GLUE_LINK_VERSION', '1.0.0' );
}
if ( ! defined( 'GLUE_LINK_PLUGIN_FILE' ) ) {
	define( 'GLUE_LINK_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'GLUE_LINK_PLUGIN_DIR' ) ) {
	define( 'GLUE_LINK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GLUE_LINK_PLUGIN_URL' ) ) {
	define( 'GLUE_LINK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Autoloader.
require_once GLUE_LINK_PLUGIN_DIR . 'includes/autoloader.php';

// Register activation hook.
register_activation_hook( __FILE__, array( 'WebberZone\Glue_Link\Core', 'activate' ) );

/**
 * Global variable holding the current instance of Glue Link
 *
 * @since 1.0.0
 *
 * @var \WebberZone\Glue_Link\Core
 */
global $glue_link;

if ( ! function_exists( __NAMESPACE__ . '\\load' ) ) {
	/**
	 * The main function responsible for returning the one true instance of the plugin to functions everywhere.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function load() {
		global $glue_link;
		$glue_link = Core::get_instance();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\load' );

/**
 * Global variable holding the current settings for Glue Link
 *
 * @since 1.0.0
 *
 * @var array<string, mixed>
 */
global $glue_link_settings;
$glue_link_settings = Options_API::get_settings();
