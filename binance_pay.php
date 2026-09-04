<?php
/**
 * Binance Pay API Helper
 */
class BinancePay {
    private $apiKey;
    private $secretKey;
    private $baseUrl = 'https://bpay.binanceapi.com';

    public function __construct($apiKey, $secretKey) {
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
    }

    /**
     * Create a new order
     */
    public function createOrder($orderId, $amount, $currency, $goodsName, $returnUrl, $cancelUrl) {
        $endpoint = '/binancepay/openapi/v2/order';
        
        $payload = [
            'env' => [
                'terminalType' => 'WEB'
            ],
            'merchantTradeNo' => $orderId,
            'orderAmount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'goods' => [
                'goodsType' => '02', // Virtual Goods
                'goodsCategory' => '6000', // Software
                'referenceGoodsId' => 'sub_monthly',
                'goodsName' => $goodsName,
                'goodsDetail' => 'Monthly subscription for BulkZen'
            ],
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl
        ];

        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * Query order status
     */
    public function queryOrder($orderId) {
        $endpoint = '/binancepay/openapi/v2/order/query';
        
        $payload = [
            'merchantTradeNo' => $orderId
        ];

        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * Make API request
     */
    private function request($method, $endpoint, $payload) {
        $timestamp = round(microtime(true) * 1000);
        $nonce = $this->generateNonce();
        $body = json_encode($payload);
        
        $signature = $this->generateSignature($timestamp, $nonce, $body);
        
        $headers = [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $this->apiKey,
            'BinancePay-Signature: ' . $signature
        ];

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        return json_decode($response, true);
    }

    private function generateNonce($length = 32) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $nonce = '';
        for ($i = 0; $i < $length; $i++) {
            $nonce .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $nonce;
    }

    private function generateSignature($timestamp, $nonce, $body) {
        $payload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        return strtoupper(hash_hmac('sha512', $payload, $this->secretKey));
    }
}
?>
