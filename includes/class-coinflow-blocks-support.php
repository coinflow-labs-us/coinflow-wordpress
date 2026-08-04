<?php
/**
 * WooCommerce Blocks (Store API) integration so the gateway appears on the
 * block-based checkout, which is the WordPress default.
 *
 * @package Coinflow_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Coinflow_Blocks_Support extends AbstractPaymentMethodType
{
    /** Must equal the gateway id. */
    protected $name = 'coinflow';

    /** @var WC_Gateway_Coinflow|null */
    private $gateway;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_coinflow_settings', []);
        if (class_exists('WC_Gateway_Coinflow')) {
            $this->gateway = new WC_Gateway_Coinflow();
        }
    }

    public function is_active(): bool
    {
        return $this->gateway ? $this->gateway->is_available() : false;
    }

    public function get_payment_method_script_handles(): array
    {
        wp_register_script(
            'coinflow-blocks',
            COINFLOW_PAYMENTS_URL . 'assets/js/coinflow-blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            COINFLOW_PAYMENTS_VERSION,
            true
        );
        return ['coinflow-blocks'];
    }

    public function get_payment_method_data(): array
    {
        return [
            'title'       => $this->gateway ? $this->gateway->title : __('Credit Card', 'coinflow-payments'),
            'description' => $this->gateway ? $this->gateway->description : '',
            'icon'        => COINFLOW_PAYMENTS_URL . 'assets/images/coinflow-icon.png',
            'supports'    => $this->gateway
                ? array_values(array_filter($this->gateway->supports, [$this->gateway, 'supports']))
                : ['products'],
        ];
    }
}
