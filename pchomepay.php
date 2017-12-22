<?php
/**
 * @copyright  Copyright © 2017 PChomePay Electronic Payment Co., Ltd.(https://www.pchomepay.com.tw)
 *
 * Plugin Name: PChomePay Gateway for WooCommerce
 * Plugin URI: https://www.pchomepay.com.tw
 * Description: 讓 WooCommerce 可以使用 PChomePay支付連 進行結帳！水啦！！
 * Version: 1.0.1
 * Author: PChomePay支付連
 * Author URI: https://www.pchomepay.com.tw
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'pchomepay_gateway_init', 0);

function pchomepay_gateway_init()
{
    // Make sure WooCommerce is setted.
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once(dirname(__FILE__) . '/includes/PChomePayClient.php');

    class WC_Gateway_PChomePay extends WC_Payment_Gateway
    {
        /** @var bool Whether or not logging is enabled */
        public static $log_enabled = false;

        /** @var WC_Logger Logger instance */
        public static $log = false;

        public function __construct()
        {
            // Validate ATM ExpireDate
            if (isset($_POST['woocommerce_pchomepay_atm_expiredate']) && (!preg_match('/^\d*$/', $_POST['woocommerce_pchomepay_atm_expiredate']) || $_POST['woocommerce_pchomepay_atm_expiredate'] < 1 || $_POST['woocommerce_pchomepay_atm_expiredate'] > 5)) {
                $_POST['woocommerce_pchomepay_atm_expiredate'] = 5;
            }

            $this->id = 'pchomepay';
            $this->icon = apply_filters('woocommerce_pchomepay_icon', plugins_url('images/pchomepay_logo.png', __FILE__));;
            $this->has_fields = false;
            $this->method_title = __('PChomePay支付連', 'woocommerce');
            $this->method_description = '透過 PChomePay支付連 付款。<br>會連結到 PChomePay支付連 付款頁面。';
            $this->supports = array('products', 'refunds');

            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->app_id = trim($this->get_option('app_id'));
            $this->secret = trim($this->get_option('secret'));
            $this->sandbox_secret = trim($this->get_option('sandbox_secret'));
            $this->atm_expiredate = $this->get_option('atm_expiredate');
            // Test Mode
            $this->test_mode = ($this->get_option('test_mode') === 'yes') ? true : false;
            $this->debug = ($this->get_option('debug') === 'yes') ? true : false;
            $this->notify_url = WC()->api_request_url(get_class($this));
            $this->payment_methods = $this->get_option('payment_methods');
            $this->card_installment = $this->get_option('card_installment');

            self::$log_enabled = $this->debug;

            if (empty($this->app_id) || empty($this->secret)) {
                $this->enabled = false;
            } else {
                $this->client = new PChomePayClient($this->app_id, $this->secret, $this->sandbox_secret, $this->test_mode, self::$log_enabled);
            }

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'receive_response'));
        }

        public function init_form_fields()
        {
            $this->form_fields = include('includes/settings.php');
        }

        public function admin_options()
        {
            ?>
            <h2><?php _e('PChomePay 收款模組', 'woocommerce'); ?></h2>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table> <?php
        }

        private function get_pchomepay_payment_data($order)
        {
            global $woocommerce;

            $order_id = date('Ymd') . $order->get_order_number();
            $pay_type = $this->payment_methods;
            $amount = ceil($order->get_total());
            $return_url = $this->get_return_url($order);
            $notify_url = $this->notify_url;
            $buyer_email = $order->get_billing_email();

            if (isset($this->atm_expiredate) && (!preg_match('/^\d*$/', $this->atm_expiredate) || $this->atm_expiredate < 1 || $this->atm_expiredate > 5)) {
                $this->atm_expiredate = 5;
            }

            $atm_info = (object)['expire_days' => (int)$this->atm_expiredate];

            $card_info = [];

            foreach ($this->card_installment as $items) {
                switch ($items) {
                    case 'CRD_3' :
                        $card_installment['installment'] = 3;
                        break;
                    case 'CRD_6' :
                        $card_installment['installment'] = 6;
                        break;
                    case 'CRD_12' :
                        $card_installment['installment'] = 12;
                        break;
                    default :
                        unset($card_installment);
                        break;
                }
                if (isset($card_installment)) {
                    $card_info[] = (object)$card_installment;
                }
            }

            $items = [];

            $order_items = $order->get_items();
            foreach ($order_items as $item) {
                $product = [];
                $order_item = new WC_Order_Item_Product($item);
                $product_id = ($order_item->get_product_id());
                $product['name'] = $order_item->get_name();
                $product['url'] = get_permalink($product_id);

                $items[] = (object)$product;
            }

            $pchomepay_args = [
                'order_id' => $order_id,
                'pay_type' => $pay_type,
                'amount' => $amount,
                'return_url' => $return_url,
                'notify_url' => $notify_url,
                'items' => $items,
                'buyer_email' => $buyer_email,
                'atm_info' => $atm_info,
            ];

            if ($card_info) $pchomepay_args['card_info'] = $card_info;

            $pchomepay_args = apply_filters('woocommerce_pchomepay_args', $pchomepay_args);

            return $pchomepay_args;
        }

        public function process_payment($order_id)
        {
            try {
                global $woocommerce;

                $order = new WC_Order($order_id);

                // 更新訂單狀態為等待中 (等待第三方支付網站返回)
                $order->update_status('pending', __('Awaiting PChomePay payment', 'woocommerce'));

                $pchomepay_args = json_encode($this->get_pchomepay_payment_data($order));

                if (!class_exists('PChomePayClient')) {
                    if (!require(dirname(__FILE__) . '/includes/PChomePayClient.php')) {
                        throw new Exception(__('PChomePayClient Class missed.', 'woocommerce'));
                    }
                }

                // 建立訂單
                $result = $this->client->postPayment($pchomepay_args);

                if (!$result) {
                    self::log("交易失敗：伺服器端未知錯誤，請聯絡 PChomePay支付連。");
                    throw new Exception("嘗試使用付款閘道 API 建立訂單時發生錯誤，請聯絡網站管理員。");
                }

                // 減少庫存
                wc_reduce_stock_levels($order_id);
                // 清空購物車
                $woocommerce->cart->empty_cart();
                add_post_meta($order_id, 'pchomepay_orderid', $result->order_id);
                // 返回感謝購物頁面跳轉
                return array(
                    'result' => 'success',
//                'redirect' => $order->get_checkout_payment_url(true)
                    'redirect' => $result->payment_url
                );

            } catch (Exception $e) {
                wc_add_notice(__($e->getMessage(), 'woocommerce'), 'error');
            }
        }

        public function receive_response()
        {
            $notify_type = $_REQUEST['notify_type'];
            $notify_message = $_REQUEST['notify_message'];

            if (!$notify_type || !$notify_message) {
                http_response_code(404);
                exit;
            }

            $order_data = json_decode(str_replace('\"', '"', $notify_message));

            $order = new WC_Order(substr($order_data->order_id, 8));

            # 紀錄訂單付款方式
            switch ($order_data->pay_type) {
                case 'ATM':
                    $pay_type_note = 'ATM 付款';
                    break;
                case 'CARD':
                    if ($order_data->payment_info->installment == 1) {
                        $pay_type_note = '信用卡 付款 (一次付清)';
                    } else {
                        $pay_type_note = '信用卡 分期付款 (' . $order_data->payment_info->installment . '期)';
                    }
                    break;
                case 'ACCT':
                    $pay_type_note = '支付連餘額 付款';
                    break;
                case 'EACH':
                    $pay_type_note = '銀行支付 付款';
                    break;
                default:
                    $pay_type_note = $order_data->pay_type . '付款';
            }

            if ($notify_type == 'order_expired') {
                $order->add_order_note($pay_type_note, true);
                if ($order_data->status_code) {
                    $order->update_status('failed');
                    $order->add_order_note(sprintf(__('訂單已失敗。<br>error code: %1$s<br>message: %2$s', 'woocommerce'), $order_data->status_code, OrderStatusCodeEnum::getErrMsg($order_data->status_code)), true);
                } else {
                    $order->update_status('failed');
                    $order->add_order_note( '訂單已失敗。', true);
                }
            } elseif ($notify_type == 'order_confirm') {
                $order->add_order_note($pay_type_note, true);
                $order->payment_complete();
            } elseif($notify_type == 'order_audit') {
                $order->add_order_note(sprintf(__('訂單交易等待中。<br>status code: %1$s<br>message: %2$s', 'woocommerce'), $order_data->status_code, OrderStatusCodeEnum::getErrMsg($order_data->status_code)), true);
            }

            echo 'success';
            exit();
        }

        private function get_pchomepay_refund_data($orderID, $amount, $refundID = null)
        {
            try {
                global $woocommerce;

                $order = $this->client->getPayment($orderID);
                $order_id = $order->order_id;

                if ($amount === $order->amount) {
                    $refund_id = 'RF' . $order_id;
                } else {
                    if ($refundID) {
                        $number = (int)substr($refundID, strpos($refundID, '-') + 1) + 1;
                        $refund_id = 'RF' . $order_id . '-' . $number;
                    } else {
                        $refund_id = 'RF' . $order_id . '-1';
                    }
                }

                $trade_amount = (int)$amount;
                $pchomepay_args = [
                    'order_id' => $order_id,
                    'refund_id' => $refund_id,
                    'trade_amount' => $trade_amount,
                ];

                $pchomepay_args = apply_filters('woocommerce_pchomepay_args', $pchomepay_args);

                return $pchomepay_args;
            } catch (Exception $e) {
                throw $e;
            }
        }

        public function process_refund($order_id, $amount = null, $reason = '')
        {

            try {
                $orderID = get_post_meta($order_id, 'pchomepay_orderid', true);
                $refundIDs = get_post_meta($order_id, 'pchomepay_refundid', true);

                if ($refundIDs) {
                    $refundID = trim(strrchr($refundIDs, ','), ', ') ? trim(strrchr($refundIDs, ','), ', ') : $refundIDs;
                } else {
                    $refundID = $refundIDs;
                }

                $wcOrder = new WC_Order(substr($order_id, 8));

                $pchomepay_args = json_encode($this->get_pchomepay_refund_data($orderID, $amount, $refundID));

                if (!class_exists('PChomePayClient')) {
                    if (!require(dirname(__FILE__) . '/includes/PChomePayClient.php')) {
                        throw new Exception(__('PChomePayClient Class missed.', 'woocommerce'));
                    }
                }

                // 退款
                $response_data = $this->client->postRefund($pchomepay_args);

                if (!$response_data) {
                    self::log("退款失敗：伺服器端未知錯誤，請聯絡 PChomePay支付連。");
                    return false;
                }

                // 更新 meta
                ($refundID) ? update_post_meta($order_id, 'pchomepay_refundid', $refundIDs . ", " . $response_data->refund_id) : add_post_meta($order_id, 'pchomepay_refundid', $response_data->refund_id);

                if (isset($response_data->redirect_url)) {
                    (get_post_meta($order_id, 'pchomepay_refund_url', true)) ? update_post_meta($order_id, 'pchomepay_refund_url', $response_data->refund_id . ' : ' . $response_data->redirect_url) : add_post_meta($order_id, 'pchomepay_refund_url', $response_data->refund_id . ' : ' . $response_data->redirect_url);
                }

                $wcOrder->add_order_note('退款編號：' . $response_data->refund_id, true);

                return true;
            } catch (Exception $e) {
                throw $e;
            }
        }

        /**
         * Logging method.
         *
         * @param string $message Log message.
         * @param string $level Optional. Default 'info'.
         *     emergency|alert|critical|error|warning|notice|info|debug
         */
        public static function log($message, $level = 'info')
        {
            if (self::$log_enabled) {
                if (empty(self::$log)) {
                    self::$log = wc_get_logger();
                }
                self::$log->log($level, $message, array('source' => 'pchomepay'));
            }
        }
    }

    function add_pchomepay_gateway_class($methods)
    {
        $methods[] = 'WC_Gateway_PChomePay';
        return $methods;
    }

    function add_pchomepay_settings_link($links)
    {
        $mylinks = array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=pchomepay') . '">' . __('設定') . '</a>',
        );
        return array_merge($links, $mylinks);
    }

    add_filter('woocommerce_payment_gateways', 'add_pchomepay_gateway_class');
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'add_pchomepay_settings_link');


}

add_action('init', 'pchomepay_plugin_updater_init');

function pchomepay_plugin_updater_init()
{

    include_once 'includes/updater.php';

    define('WP_GITHUB_FORCE_UPDATE', true);

    if (is_admin()) {

        $config = array(
            'slug' => plugin_basename(__FILE__),
            'proper_folder_name' => 'PCHomePay-for-WooCommerce-master',
            'api_url' => 'https://api.github.com/repos/JerryR7/PChomePay-for-WooCommerce',
            'raw_url' => 'https://raw.github.com/JerryR7/PChomePay-for-WooCommerce/master',
            'github_url' => 'https://github.com/JerryR7/PChomePay-for-WooCommerce',
            'zip_url' => 'https://github.com/JerryR7/PChomePay-for-WooCommerce/archive/master.zip',
            'sslverify' => true,
            'requires' => '3.0',
            'tested' => '4.8',
            'readme' => 'README.md',
            'access_token' => '',
        );

        new WP_GitHub_Updater($config);

    }

}