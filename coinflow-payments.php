<?php
/**
 * Plugin Name:       Coinflow Payments for WooCommerce
 * Plugin URI:        https://github.com/coinflow-labs-us/coinflow-wordpress
 * Description:       Accept payments through Coinflow. Customers are redirected to a secure Coinflow checkout and returned to your store; orders are marked paid via Coinflow webhooks.
 * Version:           0.1.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Coinflow
 * Author URI:        https://coinflow.cash
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coinflow-payments
 * Domain Path:       /languages
 *
 * @package Coinflow_Payments
 */

if (!defined('ABSPATH')) {
    exit; // No direct access.
}

// Version is kept in lockstep with the VERSION file and readme.txt Stable tag.
define('COINFLOW_PAYMENTS_VERSION', '0.1.2');
define('COINFLOW_PAYMENTS_FILE', __FILE__);
define('COINFLOW_PAYMENTS_PATH', plugin_dir_path(__FILE__));
define('COINFLOW_PAYMENTS_URL', plugin_dir_url(__FILE__));

/**
 * Show an admin notice and bail if WooCommerce is not active.
 */
function coinflow_payments_missing_wc_notice(): void
{
    echo '<div class="error"><p>';
    echo esc_html__(
        'Coinflow Payments for WooCommerce requires WooCommerce to be installed and active.',
        'coinflow-payments'
    );
    echo '</p></div>';
}

/**
 * Register the gateway class with WooCommerce.
 *
 * @param array $gateways Registered gateways.
 * @return array
 */
function coinflow_payments_add_gateway(array $gateways): array
{
    $gateways[] = 'WC_Gateway_Coinflow';
    return $gateways;
}

/**
 * Bootstrap the plugin once all others are loaded.
 */
function coinflow_payments_init(): void
{
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'coinflow_payments_missing_wc_notice');
        return;
    }

    require_once COINFLOW_PAYMENTS_PATH . 'includes/class-coinflow-money.php';
    require_once COINFLOW_PAYMENTS_PATH . 'includes/class-coinflow-api-client.php';
    require_once COINFLOW_PAYMENTS_PATH . 'includes/class-wc-gateway-coinflow.php';
    require_once COINFLOW_PAYMENTS_PATH . 'includes/class-coinflow-webhook-handler.php';

    add_filter('woocommerce_payment_gateways', 'coinflow_payments_add_gateway');
}
add_action('plugins_loaded', 'coinflow_payments_init');

/**
 * Declare compatibility with HPOS (custom order tables) and cart/checkout blocks.
 */
add_action('before_woocommerce_init', function (): void {
    if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
        'custom_order_tables',
        COINFLOW_PAYMENTS_FILE,
        true
    );
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
        'cart_checkout_blocks',
        COINFLOW_PAYMENTS_FILE,
        true
    );
});

/**
 * Register the WooCommerce Blocks (Store API) integration for the block checkout.
 */
add_action('woocommerce_blocks_loaded', function (): void {
    if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        return;
    }
    require_once COINFLOW_PAYMENTS_PATH . 'includes/class-coinflow-blocks-support.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry): void {
            $registry->register(new WC_Coinflow_Blocks_Support());
        }
    );
});

/**
 * Register the REST webhook route. Coinflow is the sole authority on order
 * payment status, so this route lives independently of the gateway UI.
 */
add_action('rest_api_init', function (): void {
    require_once COINFLOW_PAYMENTS_PATH . 'includes/class-coinflow-money.php';
    require_once COINFLOW_PAYMENTS_PATH . 'includes/class-coinflow-webhook-handler.php';
    (new Coinflow_Webhook_Handler())->register_routes();
});

/**
 * Wire up the vendored Plugin Update Checker so installs self-update from the
 * public GitHub mirror's release assets (the CI-built coinflow-payments.zip).
 */
add_action('plugins_loaded', function (): void {
    $puc = COINFLOW_PAYMENTS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if (!file_exists($puc)) {
        return; // Not present in local dev checkouts; only vendored for release.
    }
    require_once $puc;

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/coinflow-labs-us/coinflow-wordpress/',
        COINFLOW_PAYMENTS_FILE,
        'coinflow-payments'
    );
    // Install the CI-built release asset (top-level dir coinflow-payments/),
    // not a tarball of the repo root.
    $update_checker->getVcsApi()->enableReleaseAssets('/\.zip($|[?&#])/i');
}, 20);
