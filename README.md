# Coinflow Payments for WooCommerce

Accept payments through [Coinflow](https://coinflow.cash) in your WooCommerce store. Customers are redirected to a secure, hosted Coinflow checkout and returned to your store after paying; orders are marked paid via Coinflow webhooks.

> This repository is a read-only mirror. Source lives in the Coinflow monorepo at `lib/wordpress/` and is published here automatically. Do not open PRs against this mirror — they will be overwritten.

## Requirements

- WordPress 6.5+
- WooCommerce (active)
- PHP 7.4+

## Setup

1. Install and activate the plugin (upload the `coinflow-payments.zip` from a [release](https://github.com/coinflow-labs-us/coinflow-wordpress/releases), or install from the WordPress admin).
2. **WooCommerce → Settings → Payments → Coinflow.**
3. Enter your **API Key** and **Webhook Validation Key**, pick **Sandbox** or **Production**.
4. Copy the **Webhook URL** shown on the settings page into your Coinflow dashboard as a webhook endpoint.
5. Enable and save.

## How it works

- **Checkout:** the gateway calls `POST /api/checkout/link` with a `standaloneLinkConfig` and redirects the customer to the returned link. No card data touches your server.
- **Order status:** Coinflow webhooks (`Settled`, `Card Payment Authorized`, `Card Payment Declined`, `Refund`, `Card Payment Chargeback Opened`) drive the WooCommerce order status. Webhooks are authenticated by signature and/or the Authorization header, and an amount guard puts mismatched orders on hold rather than completing them.

## Notes

- Coinflow checkout links are IP-locked. Behind a proxy/CDN, ensure WooCommerce detects the real client IP, or adjust it with the `coinflow_customer_ip` filter.
- Refunds are initiated from Coinflow; the plugin reflects them inbound via webhook.

## License

GPL-2.0-or-later.
