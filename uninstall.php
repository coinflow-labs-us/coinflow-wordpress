<?php
/**
 * Uninstall cleanup. Removes the gateway settings option.
 *
 * @package Coinflow_Payments
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('woocommerce_coinflow_settings');
