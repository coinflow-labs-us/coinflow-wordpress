<?php
/**
 * Coinflow webhook handler — the sole authority on order payment status.
 *
 * Coinflow signs every webhook with a `Coinflow-Signature: t=<sec>,v1=<hmac>`
 * header where the HMAC-SHA256 preimage is `${timestamp}.${rawBody}` keyed by
 * the merchant's webhook validation key. The same key is also sent verbatim in
 * the Authorization header (unless the merchant disabled it). We authenticate
 * on a valid signature OR a matching Authorization header, and treat a
 * present-but-invalid signature as a hard failure.
 *
 * @package Coinflow_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Coinflow_Webhook_Handler
{
    private const NAMESPACE  = 'coinflow/v1';
    private const ROUTE      = '/webhook';
    private const MAX_SKEW   = 300; // seconds of allowed timestamp skew
    private const META_KEY   = '_coinflow_payment_id';
    private const LOG_SOURCE  = 'coinflow';

    // Event types (must match @coinflow/common WebhookEventType exactly).
    private const EVT_SETTLED           = 'Settled';
    private const EVT_AUTHORIZED        = 'Card Payment Authorized';
    private const EVT_DECLINED          = 'Card Payment Declined';
    private const EVT_REFUND            = 'Refund';
    private const EVT_CHARGEBACK_OPENED = 'Card Payment Chargeback Opened';

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true', // Auth is enforced inside handle().
        ]);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $key = $this->webhook_key();
        if ('' === $key) {
            return new WP_REST_Response(['error' => 'Webhook key not configured'], 503);
        }

        $raw = $request->get_body();

        if (!$this->is_authentic($request, $raw, $key)) {
            return new WP_REST_Response(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new WP_REST_Response(['error' => 'Invalid payload'], 400);
        }

        $event_type = (string) ($payload['eventType'] ?? '');
        $data       = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $info       = is_array($data['webhookInfo'] ?? null) ? $data['webhookInfo'] : [];

        $order = $this->resolve_order($info);
        if (!$order) {
            // Unknown/mismatched order — ack so Coinflow doesn't retry-storm.
            $this->log('Ignoring webhook for unresolved order. eventType=' . $event_type);
            return new WP_REST_Response(['ignored' => true], 200);
        }

        return $this->apply_event($order, $event_type, $data);
    }

    /**
     * Authenticate via a valid signature OR a matching Authorization header.
     * A present-but-invalid signature is always rejected.
     */
    private function is_authentic(WP_REST_Request $request, string $raw, string $key): bool
    {
        $signature = (string) $request->get_header('coinflow_signature');

        if ('' !== $signature) {
            return $this->verify_signature($signature, $raw, $key);
        }

        $authorization = (string) $request->get_header('authorization');
        return '' !== $authorization && hash_equals($key, $authorization);
    }

    private function verify_signature(string $header, string $raw, string $key): bool
    {
        $timestamp = null;
        $provided  = null;
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$k, $v] = $pair;
            if ('t' === $k) {
                $timestamp = (int) $v;
            } elseif ('v1' === $k) {
                $provided = $v;
            }
        }

        if (null === $timestamp || null === $provided) {
            return false;
        }
        if (abs(time() - $timestamp) > self::MAX_SKEW) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $key);
        return hash_equals($expected, $provided);
    }

    /**
     * Look up the order by wooOrderId and verify the echoed order key matches.
     */
    private function resolve_order(array $info): ?WC_Order
    {
        $order_id  = (int) ($info['wooOrderId'] ?? 0);
        $order_key = (string) ($info['orderKey'] ?? '');
        if ($order_id <= 0 || '' === $order_key) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if (!hash_equals($order->get_order_key(), $order_key)) {
            return null;
        }
        return $order;
    }

    private function apply_event(WC_Order $order, string $event_type, array $data): WP_REST_Response
    {
        switch ($event_type) {
            case self::EVT_SETTLED:
                return $this->complete_payment($order, $data);

            case self::EVT_AUTHORIZED:
                // An authorization is not a capture — only Settled marks the
                // order paid. Hold the order awaiting settlement so that a later
                // Card Payment Declined can still fail it (a paid order would
                // ignore the decline).
                if (!$order->is_paid()) {
                    $order->update_status('on-hold', __('Coinflow authorized the card payment; awaiting settlement.', 'coinflow-payments'));
                }
                return new WP_REST_Response(['ok' => true], 200);

            case self::EVT_DECLINED:
                if (!$order->is_paid()) {
                    $order->update_status('failed', __('Coinflow reported the card payment was declined.', 'coinflow-payments'));
                }
                return new WP_REST_Response(['ok' => true], 200);

            case self::EVT_REFUND:
                // Only a paid order can move to `refunded`; a stray refund for an
                // unpaid order is noted rather than faking a paid-then-refunded
                // state (mirrors the chargeback branch).
                if ($order->is_paid()) {
                    $order->update_status('refunded', __('Coinflow reported a refund.', 'coinflow-payments'));
                } else {
                    $order->add_order_note(__('Coinflow reported a refund for an order with no captured payment.', 'coinflow-payments'));
                }
                return new WP_REST_Response(['ok' => true], 200);

            case self::EVT_CHARGEBACK_OPENED:
                if ($order->is_paid()) {
                    $order->update_status('on-hold', __('Coinflow reported a chargeback was opened.', 'coinflow-payments'));
                } else {
                    $order->add_order_note(__('Coinflow reported a chargeback was opened.', 'coinflow-payments'));
                }
                return new WP_REST_Response(['ok' => true], 200);

            default:
                $this->log('Unhandled eventType=' . $event_type . ' for order #' . $order->get_id());
                return new WP_REST_Response(['ignored' => true], 200);
        }
    }

    private function complete_payment(WC_Order $order, array $data): WP_REST_Response
    {
        if ($order->is_paid()) {
            return new WP_REST_Response(['ok' => true, 'alreadyPaid' => true], 200);
        }

        // Amount guard: catches order edits between link creation and settlement.
        $guard = $this->amount_matches($order, $data);
        if (true !== $guard) {
            $order->update_status('on-hold', $guard);
            $this->log($guard . ' (order #' . $order->get_id() . ')');
            return new WP_REST_Response(['ok' => true, 'onHold' => true], 200);
        }

        $payment_id = (string) ($data['id'] ?? '');
        $order->payment_complete($payment_id);
        if ('' !== $payment_id) {
            $order->update_meta_data(self::META_KEY, $payment_id);
        }
        $order->add_order_note($this->settlement_note($data));
        $order->save();

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * @return true|string True when the amount matches, else a human-readable
     *                     mismatch note.
     */
    private function amount_matches(WC_Order $order, array $data)
    {
        $expected = Coinflow_Money::to_cents($order->get_total());
        $got      = isset($data['subtotal']['cents']) ? (int) $data['subtotal']['cents'] : null;

        if (null === $got || $got !== $expected) {
            return sprintf(
                /* translators: 1: charged cents, 2: expected cents. */
                __('Coinflow amount %1$d¢ does not match order total %2$d¢ — placed on hold for review.', 'coinflow-payments'),
                (int) $got,
                $expected
            );
        }

        // Only compare currency when the webhook actually carries it.
        if (isset($data['subtotal']['currency'])) {
            $got_currency = (string) $data['subtotal']['currency'];
            if (0 !== strcasecmp($got_currency, $order->get_currency())) {
                return sprintf(
                    /* translators: 1: charged currency, 2: order currency. */
                    __('Coinflow currency %1$s does not match order currency %2$s — placed on hold for review.', 'coinflow-payments'),
                    $got_currency,
                    $order->get_currency()
                );
            }
        }

        return true;
    }

    private function settlement_note(array $data): string
    {
        $note = __('Coinflow payment settled.', 'coinflow-payments');
        $last4 = (string) ($data['last4'] ?? '');
        if ('' !== $last4) {
            $note = sprintf(
                /* translators: %s: last four card digits. */
                __('Coinflow payment settled — card ending %s.', 'coinflow-payments'),
                $last4
            );
        }
        return $note;
    }

    private function webhook_key(): string
    {
        $settings = get_option('woocommerce_coinflow_settings', []);
        return is_array($settings) ? trim((string) ($settings['webhook_key'] ?? '')) : '';
    }

    private function log(string $message): void
    {
        wc_get_logger()->info($message, ['source' => self::LOG_SOURCE]);
    }
}
