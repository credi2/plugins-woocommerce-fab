<?php

declare(strict_types=1);

namespace Spinnwerk\FinanceABike;

use Exception;
use stdClass;
use WC_Order;
use WC_Order_Item;
use WC_Payment_Gateway;

if (class_exists('\Spinnwerk\FinanceABike\FinanceABike') === false && class_exists('\WC_Payment_Gateway')) {
    class FinanceABike extends WC_Payment_Gateway
    {
        public const GATEWAY_ID = 'finance-a-bike';

        public const SHORTCODE_PRODUCT_INTEGRATION = 'fab_product_integration';

        private const GATEWAY_VERSION = '0.0.8';

        private const CALLBACK_STATUS_SUCCESS = 'SUCCESS';
        private const CALLBACK_STATUS_CANCELLED = 'CANCELLED';
        private const CALLBACK_STATUS_TIMEOUT = 'TIMEOUT';

        private const DIV_ID = 'ecom-checkout';
        private const ORDER_META_USAGE = 'fab_usage';
        private const ORDER_META_URL = 'fab_register_url';

        public $countries = ['DE'];
        public $min_amount = 500;
        public $max_amount = 12000;

        private $modes;
        private $usagePrefix;

        private $apiKey = '';
        private $secretKey = '';
        private $mode = 'test';
        private $validityDays = 3;
        private $productLevel = 'no';
        private $productCheckoutLink = 'yes';
        private $statePendingPayment = 'wc-pending';
        private $statePaymentReceived = 'wc-processing';
        private $stateCancelled = 'wc-cancelled';
        private $stateTimedOut = 'wc-failed';
        private $allowInsecure = 'no';

        private static $isProductScriptAdded = false;
        private static $isPostCheckoutScriptAdded = false;

        public function __construct(bool $autoInit = true)
        {
            $this->id = self::GATEWAY_ID;
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = 'FINANCE A BIKE';
            $this->method_description = __(
                'FINANCE A BIKE enables customers to pay for goods worth up to €12,000 in installments also in the '
                . 'online shop.',
                'finance-a-bike'
            );

            $this->modes = [
                'live' => [ 'value' => 'live', 'label' => __('Live', 'finance-a-bike') ],
                'test' => [ 'value' => 'test', 'label' => __('Test', 'finance-a-bike') ],
            ];

            $this->usagePrefix = __('Order', 'finance-a-bike');

            if ($autoInit) {
                $this->init();
            }
        }

        // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
        public function init_form_fields()
        {
            $statuses = wc_get_order_statuses();

            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', 'finance-a-bike'),
                    'type' => 'checkbox',
                    'label' => __('Enable FINANCE A BIKE', 'finance-a-bike'),
                    'default' => 'no',
                ],
                'title' => [
                    'title' => __('Title', 'finance-a-bike'),
                    'type' => 'text',
                    'description' => __(
                        'This controls the title which the user sees during checkout.',
                        'finance-a-bike'
                    ),
                    'default' => __('Installment purchase', 'finance-a-bike'),
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('Description', 'finance-a-bike'),
                    'type' => 'textarea',
                    'description' => __(
                        'Payment method description that the customer will see on your checkout.',
                        'finance-a-bike'
                    ),
                    'default' => __(
                        'FINANCE A BIKE allows you to pay for your purchases in installments. You can select the '
                        . 'installment amount at the time of purchase and change it later at any time.',
                        'finance-a-bike'
                    ),
                    'desc_tip' => true,
                ],
                'secretKey' => [
                    'title' => __('Secret', 'finance-a-bike'),
                    'type' => 'text',
                    'description' => __(
                        'You can find your Secret in your FINANCE A BIKE portal under Settings -> E-COMMERCE',
                        'finance-a-bike'
                    ),
                    'default' => '',
                    'desc_tip' => true,
                ],
                'apiKey' => [
                    'title' => __('API Key', 'finance-a-bike'),
                    'type' => 'text',
                    'description' => __(
                        'You can find your API Key in your FINANCE A BIKE portal under Settings -> E-COMMERCE',
                        'finance-a-bike'
                    ),
                    'default' => '',
                    'desc_tip' => true,
                ],
                'mode' => [
                    'title' => __('Mode', 'finance-a-bike'),
                    'type' => 'select',
                    'options' => iterator_to_array(
                        new \RecursiveIteratorIterator(
                            new \RecursiveArrayIterator(
                                array_map(
                                    function ($option) {
                                        return [$option['value'] => $option['label']];
                                    },
                                    $this->modes
                                )
                            )
                        ),
                        true
                    ),
                    'description' => __(
                        'The two modes can only be used with the corresponding access data. For example, if you have '
                        . 'Live access data, you can only use Live mode. To de-/activate sandboxing in live mode, '
                        . 'please contact FINANCE A BIKE.',
                        'finance-a-bike'
                    ),
                    'default' => 'test',
                    'desc_tip' => true,
                ],
                'allowInsecure' => [
                    'title' => __('Allow FINANCE A BIKE on insecure websites?', 'finance-a-bike'),
                    'type' => 'checkbox',
                    'description' => sprintf(
                        __(
                            'FINANCE A BIKE disables itself if the website it runs on does not use SSL secured'
                            . ' connections and WooCommerce\'s setting \'%s\' is not active. Activating this setting'
                            . ' disables this behaviour.',
                            'finance-a-bike'
                        ),
                        __('Force secure checkout', 'woocommerce'),
                    ),
                    'default' => $this->allowInsecure,
                    'desc_tip' => true,
                ],
                'usagePrefix' => [
                    'title' => __('Reference', 'finance-a-bike'),
                    'type' => 'text',
                    'description' => __(
                        'Will be used for the transfer to your account. The order number will be appended '
                        . 'automatically.',
                        'finance-a-bike'
                    ),
                    'default' => __($this->usagePrefix, 'finance-a-bike'),
                    'desc_tip' => true,
                ],
                'validityDays' => [
                    'title' => __('Validity period', 'finance-a-bike'),
                    'type' => 'number',
                    'description' => __(
                        'How long (in days) users can complete the process at FINANCE A BIKE. You must hold the goods '
                        . 'during this period.',
                        'finance-a-bike'
                    ),
                    'default' => $this->validityDays,
                    'desc_tip' => true,
                ],
                'productLevel' => [
                    'title' => __('Product level placement', 'finance-a-bike'),
                    'type' => 'select',
                    'options' => [
                        'no' => __('No', 'finance-a-bike'),
                        'product-single' => __('Product page', 'finance-a-bike'),
                        'product-archive' => __('Overview page', 'finance-a-bike'),
                        'product-single-archive' => __('Product & Overview Page', 'finance-a-bike')
                    ],
                    'description' => __(
                        'Whether and where to integrate a widget with products.',
                        'finance-a-bike'
                    ),
                    'default' => $this->productLevel,
                    'desc_tip' => true,
                ],
                'productCheckoutLink' => [
                    'title' => __('Show button to checkout?', 'finance-a-bike'),
                    'type' => 'checkbox',
                    'description' => __(
                        'Defines whether to display a button in the product-level overlay that takes users directly '
                        . 'to checkout.',
                        'finance-a-bike'
                    ),
                    'default' => $this->productCheckoutLink,
                    'desc_tip' => true,
                ],
                'statePendingPayment' => [
                    'title' => __('Order state \'Order Received\'', 'finance-a-bike'),
                    'type' => 'select',
                    'options' => $statuses,
                    'description' => __(
                        'Initial state of an order placed with FINANCE A BIKE.',
                        'finance-a-bike'
                    ),
                    'default' => $this->statePendingPayment,
                    'desc_tip' => true,
                ],
                'statePaymentReceived' => [
                    'title' => __('Order state \'Payment Received\'', 'finance-a-bike'),
                    'type' => 'select',
                    'options' => $statuses,
                    'description' => __(
                        'State of an order placed with FINANCE A BIKE once the customer completed the funding process.',
                        'finance-a-bike'
                    ),
                    'default' => $this->statePaymentReceived,
                    'desc_tip' => true,
                ],
                'stateCancelled' => [
                    'title' => __('Order state \'Cancelled\'', 'finance-a-bike'),
                    'type' => 'select',
                    'options' => $statuses,
                    'description' => __(
                        'State of an order placed with FINANCE A BIKE if the customer cancels the funding process.',
                        'finance-a-bike'
                    ),
                    'default' => $this->stateCancelled,
                    'desc_tip' => true,
                ],
                'stateTimedOut' => [
                    'title' => __('Order state \'Timeout\'', 'finance-a-bike'),
                    'type' => 'select',
                    'options' => $statuses,
                    'description' => sprintf(
                        __(
                            'State of an order placed with FINANCE A BIKE if the customer does not complete the funding'
                            . ' process in time. Use setting \'%s\' to adjust this period.',
                            'finance-a-bike'
                        ),
                        __('Validity period', 'finance-a-bike'),
                    ),
                    'default' => $this->stateTimedOut,
                    'desc_tip' => true,
                ],
            ];
        }

        public function payment_fields()
        {
            parent::payment_fields();

            if (is_checkout()) {
                echo sprintf(
                    '<div id="%s" data-total="%F"></div>',
                    self::DIV_ID,
                    $this->getCartTotal()
                );
            }
        }

        // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
        public function process_payment($orderId)
        {
            $order = new WC_Order($orderId);

            $registerUrl = $this->sendBuyRequest($order);

            if (empty($registerUrl)) {
                $message = sprintf(
                    __(
                        '%s %s: An error has occurred, please contact <a href="%s">%s</a>.',
                        'finance-a-bike'
                    ),
                    $this->method_title,
                    $this->title,
                    'mailto:service@financeabike.de',
                    'service@financeabike.de'
                );

                wc_add_notice($message, 'error');

                return [
                    'result' => 'failure'
                ];
            }

            $order->update_status($this->statePendingPayment, __('Customer still needs to verify.', 'finance-a-bike'));

            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        public function checkProductIntegration(): void
        {
            if (is_admin()) {
                return;
            }

            $this->init_form_fields();
            $this->init_settings();

            $this->handleSettings();

            if ($this->enabled === 'yes' && empty($this->productLevel) === false && $this->productLevel !== 'no') {
                $this->eventuallyAddProductIntegration();
            }
        }

        public function productIntegrationShortcode($atts): string
        {
            $atts = shortcode_atts([
                'product_id' => null,
            ], $atts, self::SHORTCODE_PRODUCT_INTEGRATION);

            $this->init_form_fields();
            $this->init_settings();

            $this->handleSettings();

            if ($this->enabled === 'yes') {
                return $this->eventuallyGetProductIntegrationForShortcode($atts['product_id']);
            }

            return '';
        }

        // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
        public function is_available(): bool
        {
            return is_admin()
                || (
                    $this->enabled === 'yes'
                    && $this->isWithinMaxima()
                    && $this->isShippedToAllowedCountry()
                );
        }

        private function isWithinMaxima(float $price = null): bool
        {
            $total = $price;

            if ($total === null) {
                $wc = WC();

                if (empty($wc->cart) === false) {
                    $total = $wc->cart->total;
                }
            }

            if (
                (
                    $this->min_amount > 0
                    && $total < $this->min_amount
                )
                || (
                    $this->max_amount > 0
                    && $total > $this->max_amount
                )
            ) {
                return false;
            }

            return true;
        }

        /**
         * @return bool `true` if shipping country allowed or not known, `false` otherwise
         */
        private function isShippedToAllowedCountry(): bool
        {
            try {
                $billingCountry = WC()->customer->get_billing_country();

                if (empty($billingCountry) === false) {
                    return in_array($billingCountry, $this->countries, true);
                }

                return true;
            } catch (Exception $e) {
                return true;
            }
        }

        private function addSupportForCustomQueryVars(array $query, array $queryVars): array
        {
            if (empty($queryVars[self::ORDER_META_USAGE]) === false) {
                $query['meta_query'][] = array(
                    'key' => self::ORDER_META_USAGE,
                    'value' => esc_attr($queryVars[self::ORDER_META_USAGE]),
                );
            }

            return $query;
        }

        private function eventuallyAddCheckoutIntegration(): void
        {
            if (
                $this->enabled === 'yes'
                && is_checkout()
                && is_order_received_page() === false
                && $this->isWithinMaxima()
            ) {
                add_action('wp_footer', function () {
                    $this->addCheckoutJavaScript();
                });

                add_action('wp_enqueue_scripts', function () {
                    wp_enqueue_script(
                        'finance-a-bike-checkout',
                        plugins_url(
                            'public/js/finance-a-bike-checkout.js',
                            realpath(__DIR__ . '/../finance-a-bike.php')
                        ),
                        [],
                        self::GATEWAY_VERSION,
                        true
                    );

                    wp_localize_script(
                        'finance-a-bike-checkout',
                        'fabConfig',
                        ['id' => self::DIV_ID]
                    );
                });
            }
        }

        private function init(): void
        {
            $this->init_form_fields();
            $this->init_settings();

            $this->handleSettings();

            $this->checkSSL();
            $this->checkCurrency();

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

            if ($this->enabled !== 'yes') {
                return;
            }

            /** @see https://github.com/woocommerce/woocommerce/wiki/wc_get_orders-and-WC_Order_Query#adding-custom-parameter-support */
            add_filter('woocommerce_order_data_store_cpt_get_orders_query', function ($query, $queryVars) {
                return $this->addSupportForCustomQueryVars($query, $queryVars);
            }, 10, 2);

            if (did_action('wp')) {
                $this->eventuallyAddCheckoutIntegration();
            } else {
                add_action('wp', function () {
                    $this->eventuallyAddCheckoutIntegration();
                });
            }

            add_action('wp_enqueue_scripts', function () {
                wp_enqueue_style(
                    'finance-a-bike',
                    plugins_url('public/css/finance-a-bike.css', realpath(__DIR__ . '/../finance-a-bike.php')),
                    [],
                    self::GATEWAY_VERSION
                );
            });

            add_action('woocommerce_api_' . $this->id, function () {
                $this->processCallback();
            });

            add_action('woocommerce_before_thankyou', function (?int $orderId) {
                if ($orderId === null) {
                    return;
                }

                $order = wc_get_order($orderId);

                if (empty($order) === false) {
                    $this->addPostCheckoutJavaScript($order);
                }
            });

            add_filter('woocommerce_thankyou_order_received_text', function (string $text, ?WC_Order $order): string {
                if (self::$isPostCheckoutScriptAdded && $order && $order->has_status('pending')) {
                    return '';
                }

                return $text;
            }, 10, 2);

            // do not allow WooCommerce to cancel orders created with this payment gateway
            add_filter('woocommerce_cancel_unpaid_order', function ($cancelOrder, WC_Order $order): bool {
                if ($order->get_payment_method() === self::GATEWAY_ID) {
                    return false;
                }

                return $cancelOrder;
            }, 10, 2);

            add_filter('woocommerce_payment_complete_order_status', function ($state, $id, $order): string {
                if ($order->get_payment_method() === self::GATEWAY_ID) {
                    return $this->statePaymentReceived;
                }

                return $state;
            }, 10, 3);

            if (
                is_admin()
                && class_exists('\WCML\PaymentGateways\Hooks')
                && isset($_GET['page']) && $_GET['page'] === 'wc-settings'
                && isset($_GET['tab']) && $_GET['tab'] === 'checkout'
                && isset($_GET['section']) && $_GET['section'] === self::GATEWAY_ID
            ) {
                add_action('woocommerce_settings_checkout', function () {
                    echo '<p>'
                        . sprintf(
                            /*
                             * translators: WooCommerce Multilingual plugin adds a feature for restricting gateways per
                             * country, that can not be removed, so the admin gets informed that this setting won't
                             * allow more countries than intended
                             */
                            __(
                                '<strong>Caution:</strong> %s is only available for customers with a billing '
                                . 'address in Germany,<br>allowing more countries in the following setting added by '
                                . 'another plugin will not change this!',
                                'finance-a-bike'
                            ),
                            $this->method_title
                        )
                        . '</p>';
                }, \WCML\PaymentGateways\Hooks::PRIORITY - 1);
            }
        }

        private function registerProductStyle(): void
        {
            wp_register_style(
                'finance-a-bike-product',
                plugins_url('public/css/finance-a-bike-product.css', realpath(__DIR__ . '/../finance-a-bike.php')),
                [],
                self::GATEWAY_VERSION
            );
        }

        private function eventuallyGetProductIntegrationForShortcode($productId = null): string
        {
            $html = $this->getProductIntegrationHTML($productId);

            if (empty($html)) {
                return '';
            }

            $this->registerProductStyle();

            add_action('wp_enqueue_scripts', function () {
                wp_enqueue_style('finance-a-bike-product');
            });

            add_action('wp_footer', function () {
                $this->addProductJavaScript();
            });

            return $html;
        }

        private function eventuallyAddProductIntegration(): void
        {
            $this->registerProductStyle();

            if (
                $this->productLevel === 'product-single-archive'
                || $this->productLevel === 'product-archive'
            ) {
                add_action('woocommerce_after_shop_loop_item', function () {
                    echo $this->getProductIntegrationHTML();
                }, 6); // 5 = the ending </a>, 10 = add to cart button -> place between

                add_action('wp_footer', function () {
                    if (is_product() || is_shop()) {
                        $this->addProductJavaScript();
                    }
                });

                add_action('wp_enqueue_scripts', function () {
                    if (is_product() || is_shop()) {
                        wp_enqueue_style('finance-a-bike-product');
                    }
                });
            }

            if (
                $this->productLevel === 'product-single-archive'
                || $this->productLevel === 'product-single'
            ) {
                add_action('woocommerce_single_product_summary', function () {
                    echo $this->getProductIntegrationHTML();
                }, 11); // 10 = price, 20 = excerpt -> place between

                if ($this->productLevel === 'product-single') {
                    add_action('wp_footer', function () {
                        if (is_product()) {
                            global $product;

                            $productPrice = wc_get_price_to_display($product);

                            if ($this->isWithinMaxima($productPrice)) {
                                $this->addProductJavaScript();
                            }
                        }
                    });

                    add_action('wp_enqueue_scripts', function () {
                        if (is_product()) {
                            wp_enqueue_style('finance-a-bike-product');
                        }
                    });
                }
            }
        }

        private function getProductIntegrationHTML($productId = null): string
        {
            if ($productId !== null) {
                $product = wc_get_product($productId);
            } else {
                global $product;
            }

            if (empty($product) || is_a($product, 'WC_Product') === false) {
                return '';
            }

            $productPrice = wc_get_price_to_display($product);

            if ($this->isWithinMaxima($productPrice)) {
                return '<div class="c2-financing-label"
                    data-c2-financing-amount="' . esc_html($productPrice) . '"
                    data-fab-product-id="' . esc_html($product->get_id()) . '"
                    data-fab-product-sku="' . esc_html($product->get_sku('edit')) . '"></div>';
            }

            return '';
        }

        private function checkCurrency(): void
        {
            if ($this->enabled === 'yes') {
                if (get_woocommerce_currency() !== 'EUR') {
                    $this->enabled = 'no';

                    $this->update_option('enabled', 'no');
                    $this->update_option('show_currency_notice', true);
                } else {
                    $this->update_option('show_currency_notice', false);
                }
            }

            if ($this->get_option('show_currency_notice')) {
                add_action('admin_notices', function () {
                    echo
                        '<div class="notice notice-error" data-finance-a-bike-notice><p>'
                        . sprintf(
                            __(
                                '<strong>%s</strong> has been disabled because WooCommerce does not use Euro as '
                                . 'currency. Please set Euro as currency and re-enable the payment method again '
                                . '<a href="%s">here</a>.',
                                'finance-a-bike'
                            ),
                            $this->method_title,
                            admin_url('admin.php?page=wc-settings&tab=checkout')
                        )
                        . '</p></div>';
                });
            }
        }

        private function checkSSL(): void
        {
            if ($this->enabled === 'yes') {
                if (
                    $this->allowInsecure !== 'yes'
                    && is_ssl() === false
                    && get_option('woocommerce_force_ssl_checkout') === 'no'
                ) {
                    if ($this->isLive()) {
                        $this->enabled = 'no';

                        $this->update_option('enabled', 'no');
                        $this->update_option('show_ssl_notice', 'live');
                    } else {
                        $this->update_option('show_ssl_notice', 'test');
                    }
                } else {
                    $this->update_option('show_ssl_notice', false);
                }
            }

            if ($this->get_option('show_ssl_notice') === 'live') {
                add_action('admin_notices', function () {
                    echo
                        '<div class="notice notice-error is-dismissible" data-finance-a-bike-notice><p>'
                        . sprintf(
                            __(
                                '<strong>%s</strong> has been disabled because WooCommerce does not require an SSL '
                                . 'certificate on the payment page. Please purchase and install a valid SSL '
                                . 'certificate and then re-enable the payment method <a href="%s">here</a>.',
                                'finance-a-bike'
                            ),
                            $this->method_title,
                            admin_url('admin.php?page=wc-settings&tab=checkout')
                        )
                        . '</p></div>';
                });
            } elseif ($this->get_option('show_ssl_notice') === 'test') {
                add_action('admin_notices', function () {
                    echo
                        '<div class="notice notice-warning is-dismissible" data-finance-a-bike-notice><p>'
                        . sprintf(
                            __(
                                '<strong>%s</strong> will be disabled in %2$s mode because WooCommerce does not '
                                . 'require SSL certificate on the payment page. Please purchase and install a valid '
                                . 'SSL certificate before changing the mode to %2$s.',
                                'finance-a-bike'
                            ),
                            $this->method_title,
                            __('Live', 'finance-a-bike')
                        )
                        . '</p></div>';
                });
            }
        }

        private function processCallback(): void
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                wp_send_json_error(null, 405); // Method Not Allowed
            }

            $json = file_get_contents('php://input');

            if (empty($json)) {
                wp_send_json_error(null, 400); // Bad Request
            }

            try {
                $data = json_decode($json);
            } catch (Exception $e) {
                wp_send_json_error(null, 400); // Bad Request
            }

            if (empty($data)) {
                wp_send_json_error(null, 400); // Bad Request
            }

            if ($this->isRequestVerified($data)) {
                $order = $this->getOrderFromUsage($data->usage);

                if ($order === null) {
                    wp_send_json_error(null, 400); // Bad Request
                }

                switch ($data->status) {
                    case self::CALLBACK_STATUS_SUCCESS:
                        $order->payment_complete($data->referenceId);
                        $this->clearMetaData($order);
                        break;

                    case self::CALLBACK_STATUS_CANCELLED:
                        $order->update_status(
                            $this->stateCancelled,
                            __('Payment process was canceled.', 'finance-a-bike')
                        );
                        $this->clearMetaData($order);
                        break;

                    case self::CALLBACK_STATUS_TIMEOUT:
                        $order->update_status(
                            $this->stateTimedOut,
                            __('Order expired.', 'finance-a-bike')
                        );
                        $this->clearMetaData($order);
                        break;

                    default:
                        wp_send_json_error(null, 400); // Bad Request
                        break;
                }

                wp_send_json_success();
            } else {
                wp_send_json_error(null, 403); // Forbidden
            }
        }

        private function clearMetaData(WC_Order $order): void
        {
            $order->delete_meta_data(self::ORDER_META_URL);
            $order->delete_meta_data(self::ORDER_META_USAGE);
            $order->save_meta_data();
        }

        private function generateVerificationHash(
            string $secretKey,
            ?string $status,
            ?string $referenceId,
            ?string $usage
        ): string {
            $key = implode(';', [
                $secretKey,
                $status,
                $referenceId,
                $usage,
            ]);

            return hash('sha512', $key);
        }

        private function isRequestVerified(stdClass $request): bool
        {
            $verificationHash = $this->generateVerificationHash(
                $this->secretKey,
                $request->status,
                $request->referenceId,
                $request->usage
            );

            return $verificationHash === $request->verificationHash;
        }

        private function addCheckoutJavaScript(): void
        {
            ?>
                <script id="c2EcomCheckoutScript"
                        data-src="https://portal.financeabike.de/assets/static/checkout/c2_ecom_checkout.all.min.js"
                        data-c2-partnerApiKey="<?php echo $this->apiKey; ?>"
                        data-c2-mode="<?php echo $this->isLive()
                            ? $this->modes['live']['value']
                            : $this->modes['test']['value']; ?>"
                        data-c2-locale="<?php echo $this->getCurrentLanguage(); ?>"
                        data-c2-amount="<?php echo $this->getCartTotal(); ?>"
                        data-state="init"
                ></script>
            <?php
        }

        private function addPostCheckoutJavaScript(WC_Order $order): void
        {
            if (
                self::$isPostCheckoutScriptAdded === false
                && $order->get_payment_method() === $this->id
                && $order->has_status('pending')
            ) {
                wc_get_template('checkout/order-received.php', ['order' => $order]);

                ?>
                    <div data-finance-a-bike-post-checkout-message>
                        <ol>
                            <li>
                                <?php _e(
                                    'Click on "Continue to funding". Have your valid ID document and online banking '
                                    . 'credentials ready.',
                                    'finance-a-bike'
                                ); ?>
                            </li>
                            <li>
                                <?php _e(
                                    'Within just 5 minutes, complete your loan application, identify yourself via '
                                    . 'video call and sign your application electronically. Completely online and no '
                                    . 'paperwork required.',
                                    'finance-a-bike'
                                ); ?>
                            </li>
                            <li>
                                <?php _e(
                                    'Done! Once signed, your purchase is paid for and shipped.',
                                    'finance-a-bike'
                                ); ?>
                            </li>
                        </ol>
                    </div>
                    <script id="c2EcomPostCheckoutScript"
                            src="https://portal.financeabike.de/assets/static/checkout/c2_ecom_post_checkout.all.min.js"
                            data-c2-partnerApiKey="<?php echo $this->apiKey; ?>"
                            data-c2-mode="<?php echo $this->isLive()
                                ? $this->modes['live']['value']
                                : $this->modes['test']['value']; ?>"
                            data-c2-locale="<?php echo $this->getCurrentLanguage(); ?>"
                            data-c2-purchaseUrl="<?php echo base64_decode($order->get_meta(self::ORDER_META_URL)); ?>">
                    </script>
                <?php

                self::$isPostCheckoutScriptAdded = true;
            }
        }

        private function addProductJavaScript(): void
        {
            if (self::$isProductScriptAdded) {
                return;
            }

            ?>
                <script id="c2EcomLabelScript"
                        src="https://portal.financeabike.de/assets/static/label/c2_ecom_label.all.min.js"
                        defer async
                        data-c2-partnerApiKey="<?php echo $this->apiKey; ?>"
                        data-c2-mode="<?php echo $this->mode; ?>"
                        data-c2-locale="<?php echo $this->getCurrentLanguage(); ?>"
                        data-c2-checkoutCallback="<?php
                            echo $this->productCheckoutLink === 'yes' ? 'true' : 'false';
                        ?>"
                ></script>

                <?php if ($this->productCheckoutLink === 'yes') : ?>
                    <script>
                        if (typeof c2Checkout === 'undefined') {
                            function c2Checkout() {
                                location.href = '<?php echo wc_get_checkout_url(); ?>';
                            }
                        }
                    </script>
                <?php endif; ?>
            <?php

            wp_enqueue_script(
                'finance-a-bike-product',
                plugins_url(
                    'public/js/finance-a-bike-product.js',
                    realpath(__DIR__ . '/../finance-a-bike.php')
                ),
                [],
                self::GATEWAY_VERSION,
                true
            );

            self::$isProductScriptAdded = true;
        }

        private function getCartTotal(bool $calculate = true): float
        {
            $cart = WC()->cart;

            if (empty($cart)) {
                return 0.0;
            }

            if ($calculate) {
                $cart->calculate_totals();
            }

            return (float)$cart->get_total('edit');
        }

        private function handleSettings(): void
        {
            foreach ($this->settings as $key => $value) {
                if (property_exists(self::class, $key)) {
                    $this->$key = sanitize_text_field($value);
                }
            }
        }

        private function getUrl(string $path): string
        {
            if ($this->isLive()) {
                $url = 'https://backend.financeabike.de/rest';
            } else {
                $url = 'https://backend.test-financeabike.de';
            }

            return "$url/" . ltrim($path, '/');
        }

        private function getCurrentLanguage(): string
        {
            if (preg_match('/^de([_-][A-Z]{1,3})?$/', get_bloginfo('language'))) {
                return 'de';
            }

            return 'en';
        }

        private function createUsage(WC_Order $order): string
        {
            $prefix = preg_replace('/\s/m', '-', trim($this->usagePrefix));
            $orderId = apply_filters('fab_get_order_number', $order->get_order_number(), $order);
            $usage = "$prefix-$orderId";

            if (strlen($usage) > 255) {
                $prefix = substr($prefix, 0, 255 - strlen($usage));
                $usage = "$prefix-$orderId";
            }

            return $usage;
        }

        private function getOrderFromUsage(string $usage): ?WC_Order
        {
            $queryArgs = [
                'limit' => 1
            ];

            $queryArgs[self::ORDER_META_USAGE] = $usage;

            $orders = wc_get_orders($queryArgs);

            if (empty($orders) || count($orders) !== 1) {
                return null;
            }

            return $orders[0] ?: null;
        }

        private function sendBuyRequest(WC_Order $order): ?string
        {
            $usage = $this->createUsage($order);

            $parameters = [
                'partnerKey' => $this->secretKey,
                'amount' => $this->getCartTotal(false),
                'validityDays' => $this->validityDays ?? 3,
                'usage' => $usage,
                'email' => $order->get_billing_email('edit'),
                'basket' => array_merge(
                    array_values(
                        array_map(
                            function (WC_Order_Item $orderItem) use ($order) {
                                return [
                                    'description' => $orderItem->get_name(),
                                    'amount' => $order->get_item_total($orderItem, true),
                                    'times' => $orderItem->get_quantity(),
                                ];
                            },
                            $order->get_items()
                        )
                    ),
                    [
                        [
                            'description' => __('Shipping costs', 'finance-a-bike'),
                            'amount' => (float)WC()->cart->get_shipping_total(),
                            'times' => 1
                        ]
                    ]
                ),
                'callbackUrl' => add_query_arg(
                    [
                        'wc-api' => $this->id,
                    ],
                    get_site_url(null, '/', 'https')
                ),
                'description' => '',
                'phone' => $order->get_billing_phone('edit'),
                'given' => $order->get_billing_first_name('edit'),
                'family' => $order->get_billing_last_name('edit'),
                'birthdate' => '',
                'country' => $order->get_billing_country('edit'),
                'zip' => $order->get_billing_postcode('edit'),
                'city' => $order->get_billing_city('edit'),
                'streetAndHousenumber' => $order->get_billing_address_1('edit'),
            ];

            $url = $this->getUrl('backend/urlreferral/url');

            $response = wp_remote_post($url, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'method' => 'POST',
                'body' => json_encode($parameters),
            ]);

            if ($this->wasRequestSuccess($response)) {
                $obj = json_decode($response['body']);

                if ($obj === null || json_last_error() !== JSON_ERROR_NONE) {
                    return null;
                }

                $url = esc_url($obj->url);
                $order->add_meta_data(self::ORDER_META_URL, base64_encode($url));
                $order->add_meta_data(self::ORDER_META_USAGE, $usage);
                $order->save_meta_data();

                return $url;
            } else {
                return null;
            }
        }

        private function wasRequestSuccess($data): bool
        {
            if (is_wp_error($data)) {
                $this->logError($data);

                return false;
            }

            if (isset($data['body'])) {
                $obj = json_decode($data['body']);

                if (is_object($obj)) {
                    if (property_exists($obj, 'success') && $obj->success === true) {
                        return true;
                    }
                }
            }

            return false;
        }

        private function logError($data): void
        {
            try {
                if (is_array($data) || is_object($data)) {
                    error_log(print_r($data, true));
                } else {
                    error_log($data);
                }
            } catch (Exception $e) {
                return;
            }
        }

        private function isLive(): bool
        {
            return $this->mode === $this->modes['live']['value'];
        }
    }
}
