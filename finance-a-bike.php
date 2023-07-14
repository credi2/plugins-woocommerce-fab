<?php

declare(strict_types=1);

defined('ABSPATH') or die;

/**
 * FINANCE A BIKE - WooCommerce Payment Gateway
 *
 * @package FINANCE A BIKE
 * @copyright 2022 SPiNNWERK GmbH
 *
 * @wordpress-plugin
 * Plugin Name: FINANCE A BIKE – die schnelle und einfache Online-Fahrradfinanzierung
 * Plugin URI: https://www.financeabike.de/haendler/
 * Description: Der digitale Kredit zur Fahrradfinanzierung von Volkswagen Financial Services
 * Version: 0.0.8
 * Author: SPiNNWERK GmbH
 * Author URI: https://www.spinnwerk.at/
 * Text Domain: finance-a-bike
 * Domain Path: /languages
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use Spinnwerk\FinanceABike\FinanceABike;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

add_action('plugins_loaded', function () {
    PucFactory::buildUpdateChecker(
        'https://plugins.spinnwerk.at/finance-a-bike/details.json',
        __FILE__,
        'finance-a-bike'
    );

    if (class_exists('WooCommerce') === false) {
        return;
    }

    add_action('init', function () {
        load_plugin_textdomain(
            'finance-a-bike',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        (new FinanceABike(false))->checkProductIntegration();

        add_shortcode(FinanceABike::SHORTCODE_PRODUCT_INTEGRATION, function ($atts) {
            return (new FinanceABike(false))->productIntegrationShortcode($atts);
        });
    });
});

add_filter('woocommerce_payment_gateways', function (array $methods): array {
    $methods[] = FinanceABike::class;

    return $methods;
});

register_uninstall_hook(__FILE__, [FinanceABike::class, 'uninstall']);
