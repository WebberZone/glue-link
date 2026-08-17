=== FreemKit - Glue for Freemius and Kit ===
Contributors: ajay, webberzone
Tags: freemius, kit, email marketing, subscribers, newsletter
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Seamlessly connect your Freemius with Kit email marketing to automate customer communications and marketing workflows.

== Description ==

WebberZone's FreemKit bridges the gap between Freemius and Kit (formerly ConvertKit) email marketing platforms, enabling WordPress plugin and theme developers to automate subscription workflows and enhance customer communication.

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

1. Upload the `freemkit` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to Settings → FreemKit to configure your Freemius and Kit API credentials.
4. Copy the **Webhook URL** displayed on the settings page.
5. In your [Freemius Developer Dashboard](https://dashboard.freemius.com), open your product, go to **Integrations → Webhooks → Listeners**, click **Add Webhook**, paste the URL, choose the events to subscribe to, and save.
6. Enter your product's **Secret Key** (found in Freemius under Settings → General) into the FreemKit settings so signatures can be verified.
7. Set up your desired automation rules and field mappings.
8. Test the integration using the built-in testing tools.

== Frequently Asked Questions ==

= What are the requirements for using this plugin? =

* A WordPress installation (latest version preferred).
* PHP 7.4 or higher.
* Active Freemius account with API access.
* Active Kit account with API access.
* Valid API credentials for both services.

= How do I add the webhook in the Freemius Developer Dashboard? =

1. Log in to your [Freemius Developer Dashboard](https://dashboard.freemius.com) and open the product you want to connect.
2. Navigate to **Integrations → Webhooks → Listeners** and click **Add Webhook**.
3. Paste the **Webhook URL** shown on the FreemKit settings page. By default this is `https://yourdomain.com/wp-json/freemkit/v1/webhook` (REST API endpoint). If you use the query-variable endpoint it will be `https://yourdomain.com/?freemkit_webhook=1`.
4. Choose which events to send — at minimum select `install.installed` (for free users) and `license.created` (for paid users), or select **All Events** if you want full coverage.
5. Save the webhook. Freemius will sign every request with your product's **Secret Key** using HMAC-SHA256.
6. Copy the **Secret Key** from your Freemius product settings (Settings → General) and enter it in the FreemKit settings page so the plugin can verify incoming requests.

= Is it secure? =

Yes! The plugin is built with security in mind:
* All API communications are encrypted
* Validates every incoming webhook request using HMAC-SHA256 signatures against your Freemius product secret key
* Implements WordPress nonce verification
* Includes proper user capability checks
* Features comprehensive input sanitization
* Stores sensitive data securely

= A customer opted back in to marketing. Why are they still unsubscribed in Kit? =

Kit provides no way to reverse an unsubscribe — there is no API endpoint that moves a subscriber from `cancelled` back to `active`. Once someone has left your list, only they can rejoin it, by submitting one of your Kit forms.

When FreemKit receives a `user.marketing.opted_in` event for someone in that position, it records the opt-in locally and logs it, but makes no attempt to add them back to Kit. Look for `kit_reactivation_skipped` entries in the audit log to see when this has happened.

Customers who are still active in Kit, or who were never added to it, are handled normally — their forms and tags are applied as usual.

= Can I customize the integration? =

Yes! The plugin provides various WordPress filters and actions for developers to customize its behavior. Check the documentation for available hooks.

== Screenshots ==

1. Main settings interface
2. API configuration page
3. Field mapping interface
4. Webhook configuration
5. Testing and debugging tools

== Plugins by WebberZone ==

* [Contextual Related Posts](https://wordpress.org/plugins/contextual-related-posts/) - Display related posts on your WordPress blog and feed
* [Top 10](https://wordpress.org/plugins/top-10/) - Track daily and total visits to your blog posts and display the popular and trending posts
* [Better Search](https://wordpress.org/plugins/better-search/) - Enhance the default WordPress search with contextual results sorted by relevance
* [Knowledge Base](https://wordpress.org/plugins/knowledgebase/) - Create a knowledge base or FAQ section on your WordPress site
* [WebberZone Snippetz](https://wordpress.org/plugins/add-to-all/) - Manage custom HTML, CSS, and JavaScript snippets
* [Auto-Close](https://wordpress.org/plugins/autoclose/) - Automatically close comments, pingbacks and trackbacks and manage revisions on your WordPress site
* [Popular Authors](https://wordpress.org/plugins/popular-authors/) - Display popular authors in your WordPress widget
* [Followed Posts](https://wordpress.org/plugins/where-did-they-go-from-here/) - Show a list of related posts based on what your users have already read
* [WebberZone Link Warnings](https://wordpress.org/plugins/webberzone-link-warnings/) - Add accessible warnings for external links and target="_blank" links

== Changelog ==

= 1.0.0 =
* Initial release
* Fixed every toggle setting (Respect Marketing Opt-out, Unsubscribe from Kit on Delete, Sync Name on Change, Enable Audit Log) resolving to an empty value instead of its declared default whenever the setting had not yet been saved, which left opt-out protection, name syncing and audit logging effectively disabled by default.
* Fixed settings on a multisite network reading another site's values in the same request after a `switch_to_blog()` call.

== Upgrade Notice ==

= 1.0.0 =
Initial release of FreemKit - Connect your Freemius and Kit accounts for automated marketing workflows.
