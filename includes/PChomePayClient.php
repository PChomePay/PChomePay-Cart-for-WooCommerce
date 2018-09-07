<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 17/10/18
 * Time: 上午10:36
 */

if (!defined('ABSPATH')) exit;

include_once('ApiException.php');
include_once('OrderStatusCodeEnum.php');
include_once('FileTokenStorage.php');

class PChomePayClient
{
    const BASE_URL = "https://api.pchomepay.com.tw/v1";
    const SB_BASE_URL = "https://sandbox-api.pchomepay.com.tw/v1";
    const TOKEN_EXPIRE_SEC = 1800;

    public function __construct($appID, $secret, $sandboxSecret, $sandBox = false, $debug = false)
    {
        $baseURL = $sandBox ? PChomePayClient::SB_BASE_URL : PChomePayClient::BASE_URL;

        $this->debug = $debug;
        $this->appID = $appID;
        $this->secret = $sandBox ? $sandboxSecret : $secret;

        $this->tokenURL = $baseURL . "/token";
        $this->postPaymentURL = $baseURL . "/payment";
        $this->getPaymentURL = $baseURL . "/payment/{order_id}";
        $this->getRefundURL = $baseURL . "/refund/{refund_id}";
        $this->postRefundURL = $baseURL . "/refund";
        $this->postPaymentAuditURL = $baseURL . "/payment/audit";

        $this->userAuth = "{$this->appID}:{$this->secret}";
        $this->tokenStorage = new FileTokenStorage(null, $sandBox);
    }

    // 紀錄log
    private function log($message)
    {
        if ($this->debug) WC_Gateway_PCHomePay::log($message);
    }

    // 建立訂單
    public function postPayment($data)
    {
        return $this->post_request($this->postPaymentURL, $data);
    }

    // 建立退款
    public function postRefund($data)
    {
        return $this->post_request($this->postRefundURL, $data);
    }

    // 查詢訂單
    public function getPayment($orderID)
    {
        if (!is_string($orderID) || stristr($orderID, "/")) {
            throw new Exception('Order does not exist!', 20002);
        }

        return $this->get_request(str_replace("{order_id}", $orderID, $this->getPaymentURL));
    }

    // 訂單審單
    public function postPaymentAudit($data)
    {
        return $this->post_request($this->postPaymentAuditURL, $data);
    }

    // 取Token
    protected function getToken()
    {
        $userAuth = "{$this->appID}:{$this->secret}";

        $r = wp_remote_post($this->tokenURL, array(
            'headers' => array(
                'Content-type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($userAuth),
            ),
        ));

        $body = wp_remote_retrieve_body($r);

        return $this->handleResult($body);
    }

    protected function validateTokenExpiredIn()
    {
        $tokenFail = false;

        if (!empty($this->tokenStorage->getTokenStr())) {
            try {
                $tokenObj = json_decode($this->tokenStorage->getTokenStr());
                if ($this->willExpiredIn($tokenObj)) {
                    $tokenFail = true;
                }
            } catch (Exception $ex) {
                $tokenFail = true;
            }
        } else {
            $tokenFail = true;
        }

        //如果沒有資料 或 token 快過期時 , 取得新的 token
        if ($tokenFail) {
            $tokenObj = $this->getToken();
            $this->tokenStorage->saveTokenStr(json_encode($tokenObj));
        } else {
            $tokenObj = json_decode($this->tokenStorage->getTokenStr());
        }

        return $tokenObj;

    }

    private function willExpiredIn($tokenObj)
    {
        return (time() + PChomePayClient::TOKEN_EXPIRE_SEC) > $tokenObj->expired_timestamp;
    }

    protected function post_request($method, $postdata)
    {
        $token = $this->validateTokenExpiredIn();

        $r = wp_remote_post($method, array(
            'headers' => array(
                'Content-type' => 'application/json',
                'pcpay-token' => $token->token,
            ),
            'body' => $postdata,
        ));

        $body = wp_remote_retrieve_body($r);

        return $this->handleResult($body);
    }

    protected function get_request($method)
    {
        $token = $this->validateTokenExpiredIn();

        $r = wp_remote_get($method, array(
            'headers' => array(
                'Content-type' => 'application/json',
                'pcpay-token' => $token->token,
            )
        ));

        $body = wp_remote_retrieve_body($r);

        return $this->handleResult($body);
    }

    private function handleResult($result)
    {
        $jsonErrMap = [
            JSON_ERROR_NONE => 'No error has occurred',
            JSON_ERROR_DEPTH => 'The maximum stack depth has been exceeded',
            JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
            JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded	PHP 5.3.3',
            JSON_ERROR_RECURSION => 'One or more recursive references in the value to be encoded	PHP 5.5.0',
            JSON_ERROR_INF_OR_NAN => 'One or more NAN or INF values in the value to be encoded	PHP 5.5.0',
            JSON_ERROR_UNSUPPORTED_TYPE => 'A value of a type that cannot be encoded was given	PHP 5.5.0'
        ];

        $obj = json_decode($result);

        $err = json_last_error();

        if ($err) {
            $errStr = "($err)" . $jsonErrMap[$err];
            if (empty($errStr)) {
                $errStr = " - unknow error, error code ({$err})";
            }
            $this->log("server result error($err) {$errStr}:$result");
            throw new Exception("server result error($err) {$errStr}:$result");
        }

        if (isset($obj->error_type)) {
            $this->log("\n錯誤類型：" . $obj->error_type . "\n錯誤代碼：" . $obj->code . "\n錯誤訊息：" . ApiException::getErrMsg($obj->code));
            throw new Exception("交易失敗，請聯絡網站管理員。錯誤代碼：" . $obj->code, $obj->code);
        }

        if (empty($obj->token) && empty($obj->order_id)) {

            return false;
        }

        if (isset($obj->status_code)) {
            $this->log("訂單編號：" . $obj->order_id . " 已失敗。\n原因：" . OrderStatusCodeEnum::getErrMsg($obj->status_code));
        }

        return $obj;
    }
}