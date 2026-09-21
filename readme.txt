=== Coinflow Payments for WooCommerce ===
Contributors: coinflow
Tags: woocommerce, payments, payment gateway, checkout, coinflow
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments through Coinflow in your WooCommerce store. Customers are redirected to a secure Coinflow checkout and returned to your store after paying.

== Description ==

Coinflow Payments adds Coinflow as a payment method to WooCommerce. When a customer chooses Coinflow at checkout they are redirected to a secure, hosted Coinflow checkout page. After a successful payment they are returned to your store's order-received page, and the order is marked paid automatically via a Coinflow webhook.

Features:

* Redirect-based checkout — no card data touches your server.
* Works on both classic (shortcode) and block-based checkout.
* Sandbox and Production environments.
* Orders marked paid, failed, refunded, and flagged on chargeback via webhooks.
* HPOS (High-Performance Order Storage) compatible.

== Installation ==

1. Install and activate the plugin.
2. Go to WooCommerce > Settings > Payments > Coinflow.
3. Enter your API key and Webhook Validation Key from the Coinflow dashboard, and choose your environment.
4. Copy the Webhook URL shown on the settings screen and add it as a webhook endpoint in your Coinflow dashboard.
5. Enable the payment method and save.

== Frequently Asked Questions ==

= The checkout link fails to load for customers behind a proxy or CDN =

Coinflow checkout links are locked to the IP address that created them. If your site sits behind a proxy or CDN, make sure WooCommerce is configured to detect the customer's real IP address. Advanced users can adjust the address sent to Coinflow with the `coinflow_customer_ip` filter.

== Changelog ==

= 0.1.2 =
* Security: guest checkouts no longer reuse a Coinflow customer derived from the (unverified) billing email. Each guest order now uses a per-order customer id, so saved cards can no longer be shared between shoppers who enter the same email. Saved-card reuse now requires a logged-in WordPress account.

= 0.1.0 =
* Initial release. Redirect-based Coinflow checkout with webhook-driven order status, classic + block checkout support, and HPOS compatibility.
