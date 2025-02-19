=== WebberZone Glue Link - Glue for Freemius and Kit ===
Contributors: ajay, webberzone
Tags: freemius, kit, email marketing, subscribers, newsletter
Requires at least: 5.0
Tested up to: 6.3
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Seamlessly connect your Freemius software licensing with Kit email marketing to automate customer communications and marketing workflows.

== Description ==

WebberZone's Glue Link bridges the gap between Freemius software licensing and Kit (formerly ConvertKit) email marketing platforms, enabling WordPress plugin and theme developers to automate subscription workflows and enhance customer communication.

= Key Features =

* **Real-time Webhook Integration**: Automatically sync customer data between Freemius and Kit through secure webhooks.
* **Automated List Management**: Automatically add or remove customers from Kit based on their Freemius subscription status.
* **Custom Field Mapping**: Map Freemius customer data to Kit custom fields for personalized email marketing.
* **Secure Implementation**: Built with WordPress security best practices including nonce verification and proper sanitization.
* **Developer Friendly**: Extensible through WordPress filters and actions.
* **Modern Architecture**: Built using object-oriented programming with proper namespacing for better maintainability.

= Use Cases =

* Automatically add new customers to your email list when they purchase your product.
* Segment customers based on their subscription status or product purchases.
* Create targeted email campaigns for different customer segments.
* Automate customer onboarding sequences.
* Manage customer communication throughout their lifecycle.

= Technical Features =

* Modern object-oriented architecture with proper namespacing.
* Comprehensive webhook handling for real-time updates.
* Secure API integration with both Freemius and Kit.
* Custom Settings API implementation for easy configuration.
* Extensive error handling and logging capabilities.
* Translation-ready.

== Installation ==

1. Upload the `glue-link` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to Settings → Glue Link to configure your Freemius and Kit API credentials.
4. Set up your desired automation rules and field mappings.
5. Test the integration using the built-in testing tools.

== Frequently Asked Questions ==

= What are the requirements for using this plugin? =

* A WordPress installation (latest version preferred).
* PHP 7.4 or higher.
* Active Freemius account with API access.
* Active Kit account with API access.
* Valid API credentials for both services.

= Is it secure? =

Yes! The plugin is built with security in mind:
* All API communications are encrypted
* Implements WordPress nonce verification
* Includes proper user capability checks
* Features comprehensive input sanitization
* Stores sensitive data securely

= Can I customize the integration? =

Yes! The plugin provides various WordPress filters and actions for developers to customize its behavior. Check the documentation for available hooks.

== Screenshots ==

1. Main settings interface
2. API configuration page
3. Field mapping interface
4. Webhook configuration
5. Testing and debugging tools

== Changelog ==

= 1.0.0 =
* Initial release with core integration features
* Webhook handling implementation
* Custom Settings API integration
* Kit field mapping
* Basic debugging tools

== Upgrade Notice ==

= 1.0.0 =
Initial release of Glue Link - Connect your Freemius and Kit accounts for automated marketing workflows.
