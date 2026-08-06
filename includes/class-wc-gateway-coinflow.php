<?php
/**
 * Coinflow payment gateway. Redirect flow: process_payment() creates a
 * standalone Coinflow checkout link server-side and redirects the customer to
 * it. Orders are marked paid exclusively by the webhook handler.
 *
 * @package Coinflow_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Coinflow extends WC_Payment_Gateway
{
    /** Currencies Coinflow can charge in for this gateway. */
    private const SUPPORTED_CURRENCIES = ['USD'];

    public function __construct()
    {
        $this->id                 = 'coinflow';
        $this->method_title       = __('Coinflow', 'coinflow-payments');
        $this->method_description = __(
            'Accept card payments through Coinflow.',
            'coinflow-payments'
        );
        $this->icon               = COINFLOW_PAYMENTS_URL . 'assets/images/coinflow-icon.png';
        $this->has_fields         = false;
        // Refunds are inbound-only (Coinflow-initiated) in v1 — no 'refunds' support.
        // Refunds are supported outbound: a WooCommerce refund calls Coinflow's
        // refund API. Coinflow-initiated refunds still arrive via webhook.
        $this->supports           = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled     = $this->get_option('enabled');

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled'     => [
                'title'   => __('Enable/Disable', 'coinflow-payments'),
                'type'    => 'checkbox',
                'label'   => __('Enable Coinflow Payments', 'coinflow-payments'),
                'default' => 'no',
            ],
            'title'       => [
                'title'       => __('Title', 'coinflow-payments'),
                'type'        => 'text',
                'description' => __('Payment method name shown to customers at checkout.', 'coinflow-payments'),
                'default'     => __('Credit Card', 'coinflow-payments'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'coinflow-payments'),
                'type'        => 'textarea',
                'description' => __('Payment method description shown to customers at checkout.', 'coinflow-payments'),
                'default'     => __('You will be redirected to Coinflow to complete your payment securely.', 'coinflow-payments'),
            ],
            'environment' => [
                'title'       => __('Environment', 'coinflow-payments'),
                'type'        => 'select',
                'description' => __('Use Sandbox for testing, Production for live payments.', 'coinflow-payments'),
                'default'     => 'production',
                'options'     => self::environment_options(),
                'desc_tip'    => true,
            ],
            'api_key'     => [
                'title'       => __('API Key', 'coinflow-payments'),
                'type'        => 'password',
                'description' => __('Your Coinflow API key. Found in the Coinflow merchant dashboard.', 'coinflow-payments'),
                'default'     => '',
            ],
            'webhook_key' => [
                'title'       => __('Webhook Validation Key', 'coinflow-payments'),
                'type'        => 'password',
                'description' => __('Your Coinflow webhook validation key. Used to authenticate incoming webhooks.', 'coinflow-payments'),
                'default'     => '',
            ],
            'webhook_url' => [
                'title'       => __('Webhook URL', 'coinflow-payments'),
                'type'        => 'coinflow_webhook_url',
            ],
        ];
    }

    /**
     * Custom read-only field that renders the webhook URL for the merchant to
     * paste into the Coinflow dashboard.
     */
    public function generate_coinflow_webhook_url_html($key, $data): string
    {
        $url = rest_url('coinflow/v1/webhook');
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <?php echo esc_html__('Webhook URL', 'coinflow-payments'); ?>
            </th>
            <td class="forminp">
                <input type="text" readonly="readonly" class="input-text regular-input"
                       value="<?php echo esc_attr($url); ?>"
                       onclick="this.select();" style="width:100%;max-width:480px;" />
                <p class="description">
                    <?php echo esc_html__('Add this URL as a webhook endpoint in your Coinflow dashboard.', 'coinflow-payments'); ?>
                </p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Environment choices. Production and Sandbox always show; the internal
     * Staging/Local targets only appear on dev installs (WP_DEBUG on, e.g. the
     * testbench) so real merchants never see them. Override with the
     * `coinflow_show_dev_environments` filter.
     *
     * @return array
     */
    private static function environment_options(): array
    {
        $options = [
            'production' => __('Production', 'coinflow-payments'),
            'sandbox'    => __('Sandbox', 'coinflow-payments'),
        ];

        $show_dev = apply_filters(
            'coinflow_show_dev_environments',
            defined('WP_DEBUG') && WP_DEBUG
        );
        if ($show_dev) {
            $options['staging'] = __('Staging (internal)', 'coinflow-payments');
            $options['local']   = __('Local (internal)', 'coinflow-payments');
        }

        return $options;
    }

    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }
        if ('' === trim((string) $this->get_option('api_key'))) {
            return false;
        }
        if (!in_array(get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true)) {
            return false;
        }
        return true;
    }

    /**
     * Redirect flow. Create the Coinflow link and hand the customer off to it.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_get_logger()->error(
                'Coinflow process_payment: could not load order #' . $order_id,
                ['source' => 'coinflow']
            );
            wc_add_notice(
                __('Unable to start payment. Please try again or choose another method.', 'coinflow-payments'),
                'error'
            );
            return ['result' => 'failure'];
        }

        $client = new Coinflow_API_Client(
            (string) $this->get_option('environment'),
            (string) $this->get_option('api_key')
        );

        $link = $client->get_checkout_link($order);

        if (is_wp_error($link)) {
            wc_get_logger()->error(
                'Failed to create Coinflow checkout link: ' . $link->get_error_message(),
                ['source' => 'coinflow', 'data' => $link->get_error_data()]
            );
            wc_add_notice(
                __('Unable to start payment. Please try again or choose another method.', 'coinflow-payments'),
                'error'
            );
            return ['result' => 'failure'];
        }

        // Leave the order pending — the webhook is the sole authority that marks
        // it paid. Redirect straight to Coinflow (no order-pay page, no iframe).
        $order->update_status('pending', __('Redirecting to Coinflow checkout.', 'coinflow-payments'));

        return [
            'result'   => 'success',
            'redirect' => $link,
        ];
    }

    /**
     * Refund handler for the WooCommerce "Refund" button. Calls Coinflow's
     * refund API using the payment id stored on the order at settlement, so the
     * refund executes on Coinflow and appears in the merchant dashboard.
     * Returning true lets WooCommerce record the refund and adjust order status.
     *
     * @param int        $order_id
     * @param float|null $amount  Amount to refund (store currency); null = full.
     * @param string     $reason  Free-text reason entered in WooCommerce (unused
     *                            by Coinflow, which takes an enum reason instead).
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return new WP_Error('coinflow_refund_no_order', __('Order not found.', 'coinflow-payments'));
        }

        $payment_id = (string) $order->get_meta('_coinflow_payment_id');
        if ('' === $payment_id) {
            return new WP_Error(
                'coinflow_refund_no_payment',
                __('This order has no settled Coinflow payment to refund yet.', 'coinflow-payments')
            );
        }

        // Full refund when the whole order total is being returned; otherwise
        // send the partial amount in cents.
        $amount_cents = null;
        if (null !== $amount) {
            $cents = Coinflow_Money::to_cents((float) $amount);
            if ($cents <= 0) {
                return new WP_Error('coinflow_refund_bad_amount', __('Refund amount must be greater than zero.', 'coinflow-payments'));
            }
            if ($cents < Coinflow_Money::to_cents((float) $order->get_total())) {
                $amount_cents = $cents;
            }
        }

        // Coinflow takes an enum reason (userCancellation|failedFulfillment|
        // buyerFraud|other), not WooCommerce's free text. Default to `other`;
        // sites can map their own via the filter.
        $refund_reason = (string) apply_filters('coinflow_refund_reason', 'other', $order, $reason);

        $client = new Coinflow_API_Client(
            (string) $this->get_option('environment'),
            (string) $this->get_option('api_key')
        );
        $result = $client->refund_payment($payment_id, $amount_cents, $refund_reason);

        if (is_wp_error($result)) {
            wc_get_logger()->error(
                'Coinflow refund failed: ' . $result->get_error_message(),
                ['source' => 'coinflow', 'data' => $result->get_error_data()]
            );
            return $result;
        }

        $order->add_order_note(sprintf(
            /* translators: 1: refund scope (full amount or a value), 2: reason. */
            __('Coinflow refund initiated (%1$s, reason: %2$s). It will appear in your Coinflow dashboard.', 'coinflow-payments'),
            null === $amount_cents
                ? __('full amount', 'coinflow-payments')
                : html_entity_decode(wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()]))),
            $refund_reason
        ));

        return true;
    }
}
