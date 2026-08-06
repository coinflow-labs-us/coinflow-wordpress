<?php
/**
 * Thin wrapper around the Coinflow REST API. Only responsibility today is
 * turning a WooCommerce order into a standalone (redirectable) checkout link.
 *
 * @package Coinflow_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Coinflow_API_Client
{
    private const BASE_PRODUCTION = 'https://api.coinflow.cash';
    private const BASE_SANDBOX    = 'https://api-sandbox.coinflow.cash';

    // Internal-only environments — NOT offered in the gateway settings UI
    // (merchants only see Production/Sandbox). They resolve here so Coinflow
    // engineers can point a test store at staging or a local stack by setting
    // the `environment` option directly (e.g. via WP-CLI).
    private const BASE_STAGING    = 'https://api-staging.coinflow.cash';
    // Local dev: the WooCommerce container reaches the host-run API via
    // host.docker.internal. Overridable with the COINFLOW_LOCAL_API_URL constant.
    private const BASE_LOCAL      = 'http://host.docker.internal:5000';

    /** @var string production|sandbox|staging|local */
    private $environment;

    /** @var string */
    private $api_key;

    public function __construct(string $environment, string $api_key)
    {
        $this->environment = $environment;
        $this->api_key     = $api_key;
    }

    private function base_url(): string
    {
        switch ($this->environment) {
            case 'sandbox':
                return self::BASE_SANDBOX;
            case 'staging':
                return self::BASE_STAGING;
            case 'local':
                return defined('COINFLOW_LOCAL_API_URL') ? COINFLOW_LOCAL_API_URL : self::BASE_LOCAL;
            default:
                return self::BASE_PRODUCTION;
        }
    }

    /**
     * Create a standalone Coinflow checkout link for an order.
     *
     * @param WC_Order $order
     * @return string|WP_Error The checkout link URL, or a WP_Error on failure.
     */
    public function get_checkout_link(WC_Order $order)
    {
        if ('' === trim($this->api_key)) {
            return new WP_Error('coinflow_no_api_key', __('Coinflow API key is not configured.', 'coinflow-payments'));
        }

        $body = [
            'subtotal'            => [
                // Coinflow "subtotal" is the amount to charge. Pass the Woo order total.
                'cents'    => Coinflow_Money::to_cents($order->get_total()),
                'currency' => $order->get_currency(),
            ],
            'email'               => $order->get_billing_email(),
            'webhookInfo'         => [
                'wooOrderId' => $order->get_id(),
                'orderKey'   => $order->get_order_key(),
            ],
            'standaloneLinkConfig' => [
                'callbackUrl'            => $order->get_checkout_order_received_url(),
                // The link is IP-locked to the address that created it. The filter
                // is the escape hatch for proxy/CDN setups where the detected IP
                // differs from the customer's real address.
                'endUserDeviceIpAddress' => apply_filters(
                    'coinflow_customer_ip',
                    WC_Geolocation::get_ip_address(),
                    $order
                ),
            ],
        ];

        // Prefill the Coinflow checkout with the billing details WooCommerce
        // already collected (better UX + AVS/fraud signals). Only sent when at
        // least one field is present.
        $customer_info = self::customer_info($order);
        if (!empty($customer_info)) {
            $body['customerInfo'] = $customer_info;
        }

        $response = wp_remote_post($this->base_url() . '/api/checkout/link', [
            'timeout' => 20,
            'headers' => [
                'Content-Type'             => 'application/json',
                'Authorization'            => $this->api_key,
                'x-coinflow-auth-user-id'  => self::customer_id($order),
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'coinflow_link_http_error',
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('Coinflow returned an unexpected status (%d) while creating the checkout link.', 'coinflow-payments'),
                    $code
                ),
                ['status' => $code, 'body' => self::excerpt($raw)]
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['link']) || !is_string($decoded['link'])) {
            return new WP_Error(
                'coinflow_link_missing',
                __('Coinflow did not return a checkout link.', 'coinflow-payments'),
                ['body' => self::excerpt($raw)]
            );
        }

        return $decoded['link'];
    }

    /**
     * Refund a settled Coinflow payment. Omit $amount_cents for a full refund;
     * pass it for a partial refund (must be <= the payment total). The refund is
     * executed by Coinflow and appears in the merchant dashboard.
     *
     * @param string   $payment_id   The Coinflow payment id (data.id from the Settled webhook).
     * @param int|null $amount_cents  Partial amount in cents, or null for a full refund.
     * @param string   $reason        One of Coinflow's RefundReason values.
     * @return array|int|string|WP_Error  Refund job id on success, WP_Error on failure.
     */
    public function refund_payment(string $payment_id, ?int $amount_cents, string $reason)
    {
        if ('' === trim($this->api_key)) {
            return new WP_Error('coinflow_no_api_key', __('Coinflow API key is not configured.', 'coinflow-payments'));
        }
        if ('' === trim($payment_id)) {
            return new WP_Error('coinflow_no_payment_id', __('This order has no Coinflow payment id to refund.', 'coinflow-payments'));
        }

        $body = ['refundReason' => $reason];
        if (null !== $amount_cents) {
            $body['partialAmount'] = ['cents' => $amount_cents];
        }

        $response = wp_remote_request(
            $this->base_url() . '/api/merchant/payments/' . rawurlencode($payment_id) . '/refund',
            [
                'method'  => 'PUT',
                'timeout' => 30,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => $this->api_key,
                ],
                'body'    => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'coinflow_refund_failed',
                self::api_message($raw) ?: sprintf(
                    /* translators: %d: HTTP status code. */
                    __('Coinflow refund failed (status %d).', 'coinflow-payments'),
                    $code
                ),
                ['status' => $code, 'body' => self::excerpt($raw)]
            );
        }

        return json_decode($raw, true);
    }

    /**
     * Pull the most useful human-readable text out of a Coinflow error body.
     * Prefers `details` (specific, e.g. "Payment not found") over the generic
     * top-level `message`.
     *
     * @param string $raw
     * @return string
     */
    private static function api_message(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        foreach (['details', 'message'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                return $decoded[$key];
            }
        }
        return '';
    }

    /**
     * Stable per-customer id for x-coinflow-auth-user-id. Logged-in users get a
     * durable id; guests get a deterministic id derived from their email so
     * repeat guest purchases reuse the same Coinflow customer.
     */
    private static function customer_id(WC_Order $order): string
    {
        $user_id = $order->get_user_id();
        if ($user_id) {
            return 'wp-' . $user_id;
        }

        $email = strtolower(trim($order->get_billing_email()));
        if ('' !== $email) {
            return 'guest-' . md5($email);
        }

        return 'guest-order-' . $order->get_id();
    }

    /**
     * Map the order's billing details to Coinflow's customerInfo so the checkout
     * arrives pre-filled. Blank fields are omitted; firstName/lastName are only
     * sent together (both are @minLength 1), otherwise a combined `name` is used.
     * Overridable via the `coinflow_customer_info` filter.
     *
     * @param WC_Order $order
     * @return array
     */
    private static function customer_info(WC_Order $order): array
    {
        $info = [];

        $first = trim((string) $order->get_billing_first_name());
        $last  = trim((string) $order->get_billing_last_name());
        if ('' !== $first && '' !== $last) {
            $info['firstName'] = $first;
            $info['lastName']  = $last;
        } elseif ('' !== $first || '' !== $last) {
            $info['name'] = trim($first . ' ' . $last);
        }

        $address = trim(
            $order->get_billing_address_1()
            . ' ' . $order->get_billing_address_2()
        );

        $fields = [
            'address' => $address,
            'city'    => (string) $order->get_billing_city(),
            'state'   => (string) $order->get_billing_state(),
            'zip'     => (string) $order->get_billing_postcode(),
            'country' => (string) $order->get_billing_country(),
            'email'   => (string) $order->get_billing_email(),
            'ip'      => (string) WC_Geolocation::get_ip_address(),
        ];
        foreach ($fields as $key => $value) {
            $value = trim($value);
            if ('' !== $value) {
                $info[$key] = $value;
            }
        }

        return apply_filters('coinflow_customer_info', $info, $order);
    }

    /**
     * Short, log-safe excerpt of a response body — never surfaced to customers.
     */
    private static function excerpt(string $raw): string
    {
        return substr($raw, 0, 500);
    }
}
