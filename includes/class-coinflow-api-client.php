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
     * Short, log-safe excerpt of a response body — never surfaced to customers.
     */
    private static function excerpt(string $raw): string
    {
        return substr($raw, 0, 500);
    }
}
