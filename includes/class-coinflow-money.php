<?php
/**
 * Small money helpers shared across the plugin so amount conversions stay in
 * lockstep (the outgoing checkout link and the incoming webhook amount guard
 * must agree on how dollars map to cents).
 *
 * @package Coinflow_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Coinflow_Money
{
    /**
     * Convert a decimal amount (dollars) to an integer number of cents without
     * float drift.
     *
     * @param mixed $amount
     * @return int
     */
    public static function to_cents($amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
