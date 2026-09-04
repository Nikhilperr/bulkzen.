<?php
/**
 * Binance Spot API Helper
 * Handles interactions with Binance Spot API for deposits
 */
class BinanceSpot {
    private $apiKey;
    private $secretKey;
    private $baseUrl = 'https://api.binance.com';

    public function __construct($apiKey, $secretKey) {
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
    }

    /**
     * Get Deposit Address for a specific coin and network
     * Endpoint: GET /sapi/v1/capital/deposit/address
     */
    public function getDepositAddress($coin, $network = null) {
        $endpoint = '/sapi/v1/capital/deposit/address';
        $params = [
            'coin' => $coin,
            'timestamp' => round(microtime(true) * 1000),
            'recvWindow' => 60000 // 60 seconds tolerance
        ];
        
        if ($network) {
            $params['network'] = $network;
        }

        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Get Deposit History
     * Endpoint: GET /sapi/v1/capital/deposit/hisrec
     */
    public function getDepositHistory($coin, $startTime = null, $limit = 50) {
        $endpoint = '/sapi/v1/capital/deposit/hisrec';
        $params = [
            'coin' => $coin,
            'limit' => $limit,
            'timestamp' => round(microtime(true) * 1000),
            'recvWindow' => 60000
        ];

        if ($startTime) {
            $params['startTime'] = $startTime;
        }

        return $this->request('GET', $endpoint, $params);
    }

    /**
     * Make Signed API Request
     */
    private function request($method, $endpoint, $params = []) {
        // Create query string
        $queryString = http_build_query($params);
        
        // Generate signature
        $signature = hash_hmac('sha256', $queryString, $this->secretKey);
        
        // Append signature to query string
        $queryString .= '&signature=' . $signature;
        
        $url = $this->baseUrl . $endpoint . '?' . $queryString;
        
        $headers = [
            'X-MBX-APIKEY: ' . $this->apiKey
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }

        $response = curl_exec($ch);
        
        if ($response === false) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if (isset($data['code']) && isset($data['msg'])) {
            throw new Exception("Binance API Error [{$data['code']}]: {$data['msg']}");
        }

        return $data;
    }
}
