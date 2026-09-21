# Changelog

All notable changes to Coinflow Payments for WooCommerce are documented here.

## 0.1.2

- **Security fix.** Guest checkout uses order id as the customerId instead of using unverified email.

## 0.1.0

- Initial release.
- Redirect-based Coinflow checkout: `process_payment()` creates a standalone Coinflow checkout link and redirects the customer.
- Webhook handler is the sole authority on order status (Settled/Authorized → paid, Declined → failed, Refund → refunded, Chargeback Opened → on-hold), with signature + Authorization verification and an amount guard.
- Classic and block-based (Store API) checkout support.
- HPOS compatibility.
- Self-update via GitHub release assets (Plugin Update Checker).
