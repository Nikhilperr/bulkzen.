<?php
/**
 * API endpoint for Facebook Messenger Bulk Sender
 * Handles AJAX requests for listing conversations and sending broadcasts
 */

// Turn off error display (but keep logging) to prevent HTML in JSON responses
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set execution time to 2 hours (7200 seconds) for large broadcasts
set_time_limit(7200);
ini_set('max_execution_time', 7200);
ini_set('memory_limit', '512M'); // Increase memory for large batches

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function debugLog($message) {
    file_put_contents(__DIR__ . '/debug_log.txt', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

// Helper functions for page management (copied from fb-callback.php to avoid execution issues)
if (!function_exists('getUserPagesWithLogging')) {
    function getUserPagesWithLogging($user_access_token) {
        $version = FB_GRAPH_API_VERSION;
        $url = "https://graph.facebook.com/{$version}/me/accounts?" . http_build_query([
            'access_token' => $user_access_token,
            'fields' => 'id,name,access_token',
            'limit' => 100
        ]);
        
        try {
            $response = fbApiGet($url);
            logApiResponse($response, 'pages_response');
            return $response;
        } catch (Exception $e) {
            logApiResponse(['error' => $e->getMessage(), 'url' => $url], 'pages_response_error');
            throw $e;
        }
    }
    
    function verifyPageAdminPermissions($page_id, $page_access_token) {
        try {
            $version = FB_GRAPH_API_VERSION;
            $url = "https://graph.facebook.com/{$version}/{$page_id}?" . http_build_query([
                'access_token' => $page_access_token,
                'fields' => 'id,name'
            ]);
            
            $response = fbApiGet($url);
            return isset($response['id']) && $response['id'] == $page_id;
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            if (strpos($error_msg, 'permission') !== false || 
                strpos($error_msg, 'access') !== false ||
                strpos($error_msg, '200') !== false) {
                return false;
            }
            logApiResponse(['error' => $e->getMessage(), 'page_id' => $page_id], 'page_permission_check_error');
            return false;
        }
    }
    
    function getPageMetadata($page_id, $page_access_token) {
        $version = FB_GRAPH_API_VERSION;
        $url = "https://graph.facebook.com/{$version}/{$page_id}?" . http_build_query([
            'access_token' => $page_access_token,
            'fields' => 'name,picture{url}'
        ]);
        
        try {
            $response = fbApiGet($url);
            return $response;
        } catch (Exception $e) {
            logApiResponse(['error' => $e->getMessage(), 'page_id' => $page_id], 'page_metadata_error');
            return null;
        }
    }
    
    function fbApiGet(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception("cURL error: {$curl_error}");
        }
        
        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_msg = $error_data['error']['message'] ?? "HTTP {$http_code}";
            $error_code = $error_data['error']['code'] ?? $http_code;
            throw new Exception("Facebook API error [{$error_code}]: {$error_msg}");
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }
        
        return $data;
    }
    
    function logApiResponse($response, $prefix = 'api_response') {
        if (!is_dir(LOGS_DIR)) {
            mkdir(LOGS_DIR, 0755, true);
        }
        
        $filename = LOGS_DIR . $prefix . '_' . date('Y-m-d_His') . '.json';
        file_put_contents($filename, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

// Start output buffering to catch any accidental output
ob_start();

// Set content type to JSON
header('Content-Type: application/json');

// Get action parameter first to check if it's a page-related action that doesn't require page selection
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Check authentication - user must be logged in with Facebook
// Some actions (get_pages, switch_page) don't require a page to be selected yet
$requiresPage = !in_array($action, ['get_pages', 'switch_page', 'generate_ai_message']);

if (!isset($_SESSION['user_access_token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in with Facebook.']);
    exit;
}

if ($requiresPage && (!isset($_SESSION['page_id']) || !isset($_SESSION['page_access_token']))) {
    http_response_code(401);
    echo json_encode(['error' => 'No Page selected. Please select a page from the dropdown.']);
    exit;
}

// Get page credentials from session (if available)
$pageId = $_SESSION['page_id'] ?? null;
$accessToken = $_SESSION['page_access_token'] ?? null;

// Route to appropriate handler with error catching
try {
    switch ($action) {
        case 'list_conversations':
            handleListConversations();
            break;
        
        case 'send_broadcast':
            handleSendBroadcast();
            break;
        
        case 'stop_broadcast':
            handleStopBroadcast();
            break;
        
        case 'get_pages':
            handleGetPages();
            break;
        
        case 'switch_page':
            handleSwitchPage();
            break;

        case 'create_payment':
            handleCreatePayment();
            break;

        case 'check_payment':
            handleCheckPayment();
            break;

        case 'get_subscription_status':
            handleGetSubscriptionStatus();
            break;

        case 'redeem_promo_code':
            handleRedeemPromoCode();
            break;

        case 'cancel_order':
            handleCancelOrder();
            break;

        case 'start_new_payment':
            handleStartNewPayment();
            break;
            
        case 'get_deposit_address':
            handleGetDepositAddress();
            break;

        case 'check_deposit_status':
            handleCheckDepositStatus();
            break;

        case 'get_pending_payment':
            handleGetPendingPayment();
            break;

        case 'generate_ai_message':
            handleGenerateAiMessage();
            break;
        
        default:
            ob_end_clean();
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
            exit;
    }
} catch (Throwable $e) {
    // Catch any unhandled errors (including PHP warnings/notices) and return JSON
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get subscription status for current page
 */
function handleGetSubscriptionStatus() {
    global $pageId;
    
    if (!$pageId) {
        echo json_encode(['success' => false, 'error' => 'No page selected']);
        exit;
    }

    try {
        $conn = getDbConnection();
    } catch (Throwable $e) {
        echo json_encode([
            'success' => true,
            'is_premium' => true,
            'plan_type' => 'unlimited',
            'user_credits' => 0,
            'wallet_balance' => 0,
            'total_sent' => 0,
            'free_limit' => defined('FREE_TIER_LIMIT') ? FREE_TIER_LIMIT : 500,
            'remaining_free' => defined('FREE_TIER_LIMIT') ? FREE_TIER_LIMIT : 500,
            'expires_at' => null,
            'just_upgraded' => false,
            'pending_order' => null
        ]);
        exit;
    }

    $hasFreeCol = bulkzen_has_column($conn, 'pages', 'free_credits_remaining');
    $pageSql = $hasFreeCol
        ? "SELECT total_messages_sent, subscription_expires_at, free_credits_remaining FROM pages WHERE id = ?"
        : "SELECT total_messages_sent, subscription_expires_at FROM pages WHERE id = ?";
    $stmt = $conn->prepare($pageSql);
    if (!$stmt) {
        echo json_encode([
            'success' => true,
            'is_premium' => true,
            'plan_type' => 'unlimited',
            'user_credits' => 0,
            'wallet_balance' => 0,
            'total_sent' => 0,
            'free_limit' => defined('FREE_TIER_LIMIT') ? FREE_TIER_LIMIT : 500,
            'remaining_free' => defined('FREE_TIER_LIMIT') ? FREE_TIER_LIMIT : 500,
            'expires_at' => null,
            'just_upgraded' => false,
            'pending_order' => null
        ]);
        exit;
    }
    $stmt->bind_param("s", $pageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $page = $result->fetch_assoc();
    
    $isPremium = false;
    
    // Check Page Subscription (Legacy)
    if ($page && $page['subscription_expires_at']) {
        $expiresAt = strtotime($page['subscription_expires_at']);
        if ($expiresAt > time()) {
            $isPremium = true;
        }
    }
    
    // Check User Global Subscription (New)
    $userId = $_SESSION['fb_user_id'] ?? null;
    
    // Fallback: If session user_id is missing, try multiple methods to find user
    if (!$userId && $pageId) {
         // Method 1: Try user_pages table
         $uStmt = $conn->prepare("SELECT user_id FROM user_pages WHERE page_id = ? LIMIT 1");
         if ($uStmt) {
         $uStmt->bind_param("s", $pageId);
         $uStmt->execute();
         $uRes = $uStmt->get_result();
         if ($uRow = $uRes->fetch_assoc()) {
             $userId = $uRow['user_id'];
         }
         }
         
         // Method 2: If still no user_id, try to get from most recent PAID order for this page
         if (!$userId) {
             $oStmt = $conn->prepare("SELECT user_id FROM orders WHERE page_id = ? AND user_id IS NOT NULL AND status = 'PAID' ORDER BY created_at DESC LIMIT 1");
             $oStmt->bind_param("s", $pageId);
             $oStmt->execute();
             $oRes = $oStmt->get_result();
             if ($oRow = $oRes->fetch_assoc()) {
                 $userId = $oRow['user_id'];
             }
         }
    }

    $userPlan = 'free';
    $userCredits = 0;
    $walletBalance = 0.00;
    $creditsExpiresAt = null;
    
    if ($userId && bulkzen_has_column($conn, 'credits', 'plan_type')) {
        $creditCols = 'monthly_credits';
        if (bulkzen_has_column($conn, 'credits', 'plan_type')) {
            $creditCols .= ', plan_type';
        }
        if (bulkzen_has_column($conn, 'credits', 'expires_at')) {
            $creditCols .= ', expires_at';
        }
        if (bulkzen_has_column($conn, 'credits', 'wallet_balance')) {
            $creditCols .= ', wallet_balance';
        }
        $stmt = $conn->prepare("SELECT {$creditCols} FROM credits WHERE user_id = ?");
        if ($stmt) {
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $cRes = $stmt->get_result();
        if ($cRow = $cRes->fetch_assoc()) {
            $userPlan = $cRow['plan_type'] ?? 'free';
            $userCredits = $cRow['monthly_credits'] ?? 0;
            $walletBalance = floatval($cRow['wallet_balance'] ?? 0);
            $creditsExpiresAt = $cRow['expires_at'] ?? null;
            
            // If Unlimited Plan AND not expired
            if ($cRow['plan_type'] === 'unlimited' && $cRow['expires_at']) {
                if (strtotime($cRow['expires_at']) > time()) {
                    $isPremium = true;
                }
            }
            // If Standard Plan (Credit Based) - check if not expired
            elseif ($cRow['plan_type'] === 'standard') {
                // Standard plan expires after 30 days - check expiry
                if ($cRow['expires_at'] && strtotime($cRow['expires_at']) > time()) {
                    $isPremium = true;
                } elseif (!$cRow['expires_at']) {
                    // If no expiry set, treat as active (backward compatibility)
                    $isPremium = true;
                }
            }
        }
        }
    }
    
    // DEBUG LOGGING
    $logFile = __DIR__ . '/logs/subscription_debug.log';
    $logEntry = date('Y-m-d H:i:s') . " Page: $pageId | User: " . ($userId ?? 'NULL') . " | Plan: $userPlan | Credits: $userCredits | Premium: " . ($isPremium ? 'YES' : 'NO') . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    // BACKGROUND CHECK: If not premium, check for missed payments on pending orders
    if (!$isPremium) {
        try {
            // Get pending orders from last 24 hours
            $stmt = $conn->prepare("SELECT * FROM orders WHERE page_id = ? AND status = 'PENDING' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
            $stmt->bind_param("s", $pageId);
            $stmt->execute();
            $pendingOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if (!empty($pendingOrders)) {
                require_once __DIR__ . '/binance_spot.php';
                $binance = new BinanceSpot(BINANCE_API_KEY, BINANCE_SECRET_KEY);
                
                // Group orders by coin to minimize API calls
                $ordersByCoin = [];
                foreach ($pendingOrders as $order) {
                    $parts = explode('|', $order['binance_prepay_id']);
                    $coin = $parts[2] ?? 'USDT';
                    $ordersByCoin[$coin][] = $order;
                }

                foreach ($ordersByCoin as $coin => $orders) {
                    // Fetch history for this coin (look back 24h)
                    $startTime = (time() - 86400) * 1000;
                    $history = $binance->getDepositHistory($coin, $startTime);

                    if (is_array($history)) {
                        foreach ($orders as $order) {
                            $parts = explode('|', $order['binance_prepay_id']);
                            $targetAddress = trim($parts[0] ?? '');
                            $orderAmount = floatval($order['amount']);
                            $orderTime = strtotime($order['created_at']) * 1000;

                            foreach ($history as $deposit) {
                                // Check match: Address + Time + Amount + Status
                                if ($deposit['address'] === $targetAddress && 
                                    $deposit['insertTime'] >= $orderTime &&
                                    ($deposit['status'] == 1 || $deposit['status'] == 6)) {
                                    
                                    $depositAmount = floatval($deposit['amount']);
                                    
                                    // Check if fully paid
                                    if ($depositAmount >= ($orderAmount * 0.98)) {
                                        // PAYMENT FOUND! Process Upgrade
                                        
                                        // 1. Mark Order PAID
                                        $upd = $conn->prepare("UPDATE orders SET status = 'PAID' WHERE id = ?");
                                        $upd->bind_param("s", $order['id']);
                                        $upd->execute();

                                        // 2. Update Page Subscription
                                        $newExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                                        $upd = $conn->prepare("UPDATE pages SET subscription_expires_at = ? WHERE id = ?");
                                        $upd->bind_param("ss", $newExpiry, $pageId);
                                        $upd->execute();

                                        // 3. Update User Credits
                                        $planType = $parts[3] ?? 'standard';
                                        $stmt = $conn->prepare("SELECT user_id FROM user_pages WHERE page_id = ? LIMIT 1");
                                        $stmt->bind_param("s", $pageId);
                                        $stmt->execute();
                                        $res = $stmt->get_result();
                                        if ($row = $res->fetch_assoc()) {
                                            $userId = $row['user_id'];
                                            $monthlyCredits = ($planType === 'standard') ? STANDARD_CREDIT_LIMIT : 0;
                                            $ins = $conn->prepare("INSERT INTO credits (id, user_id, monthly_credits, plan_type) VALUES (UUID(), ?, ?, ?) ON DUPLICATE KEY UPDATE monthly_credits = ?, plan_type = ?");
                                            $ins->bind_param("sisss", $userId, $monthlyCredits, $planType, $monthlyCredits, $planType);
                                            $ins->execute();
                                        }

                                        $isPremium = true;
                                        $page['subscription_expires_at'] = $newExpiry; // Update local var for response
                                        break 2; // Break out of history and orders loop for this coin
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Silent fail for background check - don't block UI
            // error_log("Background payment check failed: " . $e->getMessage());
        }
    }
    
    $totalSent = $page['total_messages_sent'] ?? 0;
    // Check for active pending order for UI Resume
    $pendingOrderData = null;
    // Remove !$isPremium check so even Premium users can resume an upgrade/purchase
    if ($userId) {
        // Look for PENDING or CANCELLED_UI order created in last 20 minutes (based on expires_at if available, or created_at fallback)
        // We prioritize orders where resume_allowed = 1 AND (expires_at > NOW() OR (expires_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 20 MINUTE)))
        
        $sql = "SELECT id, amount, currency, binance_prepay_id, created_at, expires_at 
                FROM orders 
                WHERE user_id = ? 
                AND (status = 'PENDING' OR status = 'CANCELLED_UI') 
                AND resume_allowed = 1 
                AND (expires_at > NOW() OR (expires_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 20 MINUTE)))
                ORDER BY created_at DESC LIMIT 1";
                
        $stmt = $conn->prepare($sql);
        if ($stmt) {
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($order = $res->fetch_assoc()) {
            // Parse metadata
            $parts = explode('|', $order['binance_prepay_id']);
            
            // Calculate remaining seconds
            $expiryTime = $order['expires_at'] ? strtotime($order['expires_at']) : (strtotime($order['created_at']) + 1200);
            $remaining = max(0, $expiryTime - time());
            
            if ($remaining > 0) {
                $pendingOrderData = [
                    'order_id' => $order['id'],
                    'amount' => $order['amount'],
                    'coin' => $parts[2] ?? 'USDT',
                    'network' => $parts[1] ?? null,
                    'address' => $parts[0] ?? null,
                    'plan' => $parts[3] ?? 'standard',
                    'created_at' => $order['created_at'],
                    'remaining_seconds' => $remaining
                ];
            }
        }
        }
    }
    
    $totalSent = $page['total_messages_sent'] ?? 0;
    // Use the explicit counter from DB
    $remaining = $hasFreeCol
        ? ($page['free_credits_remaining'] ?? FREE_TIER_LIMIT)
        : FREE_TIER_LIMIT;
    
    // For Standard plan, use credits expires_at; for Unlimited, use page subscription_expires_at
    $expiresAt = ($userPlan === 'standard' && $creditsExpiresAt) ? $creditsExpiresAt : ($page['subscription_expires_at'] ?? null);
    
    echo json_encode([
        'success' => true,
        'is_premium' => $isPremium,
        'plan_type' => $userPlan,
        'user_credits' => $userCredits,
        'wallet_balance' => $walletBalance,
        'total_sent' => $totalSent,
        'free_limit' => FREE_TIER_LIMIT,
        'remaining_free' => $remaining,
        'expires_at' => $expiresAt,
        'just_upgraded' => $isPremium, // Flag to potentially show UI notification
        'pending_order' => $pendingOrderData
    ]);
    exit;
}

/**
 * Create Binance Pay Order
 */
function handleCreatePayment() {
    global $pageId, $accessToken;
    require_once __DIR__ . '/binance_pay.php';
    
    if (!$pageId) {
        echo json_encode(['success' => false, 'error' => 'No page selected']);
        exit;
    }
    
    $orderId = uniqid('ORD_');
    $amount = PREMIUM_PRICE;
    $currency = 'USD';
    
    // Save order to DB
    $conn = getDbConnection();
    $userId = $_SESSION['fb_user_id'] ?? null;
    $stmt = $conn->prepare("INSERT INTO orders (id, page_id, user_id, amount, currency, status) VALUES (?, ?, ?, ?, ?, 'PENDING')");
    $stmt->bind_param("sssds", $orderId, $pageId, $userId, $amount, $currency);
    
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    // Call Binance API
    $binance = new BinancePay(BINANCE_API_KEY, BINANCE_SECRET_KEY);
    
    // Get base URL for return/cancel
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $baseUrl = $protocol . '://' . $host . $scriptPath;
    
    $returnUrl = $baseUrl . '/index.php?payment_success=1&order_id=' . $orderId;
    $cancelUrl = $baseUrl . '/index.php?payment_cancel=1';
    
    try {
        $response = $binance->createOrder($orderId, $amount, $currency, 'BulkZen Premium', $returnUrl, $cancelUrl);
        
        if ($response['status'] === 'SUCCESS') {
            // Update order with prepay ID
            $prepayId = $response['data']['prepayId'];
            $stmt = $conn->prepare("UPDATE orders SET binance_prepay_id = ? WHERE id = ?");
            $stmt->bind_param("ss", $prepayId, $orderId);
            $stmt->execute();
            
            echo json_encode([
                'success' => true,
                'checkoutUrl' => $response['data']['checkoutUrl'],
                'orderId' => $orderId
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Binance Error: ' . ($response['errorMessage'] ?? 'Unknown error')
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Check Payment Status (can be called by frontend polling or return URL)
 */
function handleCheckPayment() {
    require_once __DIR__ . '/binance_pay.php';
    
    $orderId = $_GET['order_id'] ?? null;
    if (!$orderId) {
        echo json_encode(['success' => false, 'error' => 'Order ID required']);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Get order
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->bind_param("s", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }
    
    if ($order['status'] === 'PAID') {
        echo json_encode(['success' => true, 'status' => 'PAID']);
        exit;
    }
    
    // Query Binance
    $binance = new BinancePay(BINANCE_API_KEY, BINANCE_SECRET_KEY);
    try {
        $response = $binance->queryOrder($orderId);
        
        if ($response['status'] === 'SUCCESS' && $response['data']['status'] === 'PAID') {
            // Update order status
            $stmt = $conn->prepare("UPDATE orders SET status = 'PAID' WHERE id = ?");
            $stmt->bind_param("s", $orderId);
            $stmt->execute();
            
            // Update page subscription (30 days from now)
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            $stmt = $conn->prepare("UPDATE pages SET subscription_expires_at = ? WHERE id = ?");
            $stmt->bind_param("ss", $expiresAt, $order['page_id']);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'status' => 'PAID']);
        } else {
            echo json_encode(['success' => true, 'status' => $response['data']['status'] ?? 'PENDING']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Get Deposit Address for Crypto Payment
 */
function handleGetDepositAddress() {
    global $pageId;
    require_once __DIR__ . '/binance_spot.php';

    if (!$pageId) {
        echo json_encode(['success' => false, 'error' => 'No page selected']);
        exit;
    }

    $network = $_GET['network'] ?? null;
    $coin = $_GET['coin'] ?? 'USDT'; // Default to USDT
    $plan = $_GET['plan'] ?? 'standard'; // standard ($10) or unlimited ($49.99)
    
    // Determine Amount based on Plan
    if ($plan === 'unlimited') {
        $amount = UNLIMITED_PRICE;
    } else {
        $amount = STANDARD_PRICE;
        $plan = 'standard'; // Enforce valid plan name
    }

    // Adjust amount for non-stablecoins (approximate conversion for testing)
    // In a real app, you'd fetch live rates. For now, we'll keep it simple or use 0.1 USD equivalent
    // Since this is for testing, we will just request the USD amount value in the crypto
    // WARNING: For BTC/ETH 0.1 is huge. We need to handle conversion or just stick to USDT for exact value
    // User asked for 0.1$ subscription. 
    // If coin is NOT USDT, we need to convert 0.1 USD to that coin.
    // for volatile coins to make testing easier.
    $displayAmount = $amount;
    if ($coin !== 'USDT') {
        // For Demo/Testing: Just ask for a small amount of crypto
        // In Production: Call Binance Ticker API to get rate: BTCUSDT, etc.
        $displayAmount = 0.001; // Example fixed amount for demo
    }

    // Create Order in DB
    $orderId = uniqid('ORD_');
    $conn = getDbConnection();
    
    // Store Plan Type in the order metadata (using binance_prepay_id column for now or add new column)
    // Format: ADDRESS|NETWORK|COIN|PLAN
    // We'll handle the address generation first
    
    try {
        $binance = new BinanceSpot(BINANCE_API_KEY, BINANCE_SECRET_KEY);
        $addressData = $binance->getDepositAddress($coin, $network);
        
        if (!isset($addressData['address'])) {
             throw new Exception('Failed to generate address: ' . ($addressData['msg'] ?? 'Unknown error'));
        }

        // Store metadata in binance_prepay_id for reference
        $orderRef = $addressData['address'] . '|' . ($network ?? $coin) . '|' . $coin . '|' . $plan;
        
        $userId = $_SESSION['fb_user_id'] ?? null;
        
        // Fallback: If session user_id is missing, try to find owner of the page
        if (!$userId && $pageId) {
             $uStmt = $conn->prepare("SELECT user_id FROM user_pages WHERE page_id = ? LIMIT 1");
             $uStmt->bind_param("s", $pageId);
             $uStmt->execute();
             $uRes = $uStmt->get_result();
             if ($uRow = $uRes->fetch_assoc()) {
                 $userId = $uRow['user_id'];
             }
        }

        $expiresAt = date('Y-m-d H:i:s', time() + 1200); // 20 minutes from now
        
        // Ensure user_pages mapping exists (for future lookups)
        if ($userId && $pageId) {
            $uStmt = $conn->prepare("INSERT IGNORE INTO user_pages (user_id, page_id) VALUES (?, ?)");
            $uStmt->bind_param("ss", $userId, $pageId);
            $uStmt->execute();
        }
        
        $stmt = $conn->prepare("INSERT INTO orders (id, page_id, user_id, amount, currency, status, binance_prepay_id, expires_at, resume_allowed) VALUES (?, ?, ?, ?, 'USD', 'PENDING', ?, ?, 1)");
        $stmt->bind_param("sssdss", $orderId, $pageId, $userId, $displayAmount, $orderRef, $expiresAt);
        
        if (!$stmt->execute()) {
            throw new Exception('Database error: ' . $conn->error);
        }

        echo json_encode([
            'success' => true,
            'order_id' => $orderId,
            'address' => $addressData['address'],
            'coin' => $coin,
            'network' => $network ?? $coin,
            'amount' => $displayAmount,
            'tag' => $addressData['tag'] ?? '',
            'url' => $addressData['url'] ?? ''
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Cancel Pending Order (UI Cancel)
 * Keeps session alive but marks as CANCELLED_UI
 */
function handleCancelOrder() {
    $orderId = $_GET['order_id'] ?? null;
    if (!$orderId) {
        echo json_encode(['success' => false, 'error' => 'Order ID required']);
        exit;
    }

    $conn = getDbConnection();
    $userId = $_SESSION['fb_user_id'] ?? null;
    
    if ($userId) {
        // Update to CANCELLED_UI and ensure resume_allowed is 1
        // IMPORTANT: Do NOT expire the session here. Keep expires_at as is.
        $stmt = $conn->prepare("UPDATE orders SET status = 'CANCELLED_UI', resume_allowed = 1 WHERE id = ? AND user_id = ? AND (status = 'PENDING' OR status = 'CANCELLED_UI')");
        $stmt->bind_param("ss", $orderId, $userId);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0 || $conn->info) { // info check for no-change updates
            echo json_encode(['success' => true, 'message' => 'Order marked as cancelled in UI']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Order not found or cannot be cancelled']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    }
    exit;
}

/**
 * Get Pending Payment
 * Returns the latest active pending payment for the user
 */
function handleGetPendingPayment() {
    $conn = getDbConnection();
    $userId = $_SESSION['fb_user_id'] ?? null;
    
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    // Look for PENDING or CANCELLED_UI order created in last 20 minutes (based on expires_at if available)
    // We prioritize orders where resume_allowed = 1 AND (expires_at > NOW())
    
    $sql = "SELECT id, amount, currency, binance_prepay_id, created_at, expires_at 
            FROM orders 
            WHERE user_id = ? 
            AND (status = 'PENDING' OR status = 'CANCELLED_UI') 
            AND resume_allowed = 1 
            AND (expires_at > NOW() OR (expires_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 20 MINUTE)))
            ORDER BY created_at DESC LIMIT 1";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($order = $res->fetch_assoc()) {
        // Parse metadata
        $parts = explode('|', $order['binance_prepay_id']);
        
        // Calculate remaining seconds
        $expiryTime = $order['expires_at'] ? strtotime($order['expires_at']) : (strtotime($order['created_at']) + 1200);
        $remaining = max(0, $expiryTime - time());
        
        if ($remaining > 0) {
            echo json_encode([
                'success' => true,
                'pending_order' => [
                    'order_id' => $order['id'],
                    'amount' => $order['amount'],
                    'coin' => $parts[2] ?? 'USDT',
                    'network' => $parts[1] ?? null,
                    'address' => $parts[0] ?? null,
                    'plan' => $parts[3] ?? 'standard',
                    'created_at' => $order['created_at'],
                    'remaining_seconds' => $remaining
                ]
            ]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'No pending payment found']);
    exit;
}

/**
 * Start New Payment
 * Expires old sessions and allows creating a new one
 */
function handleStartNewPayment() {
    $conn = getDbConnection();
    $userId = $_SESSION['fb_user_id'] ?? null;
    
    if ($userId) {
        // Expire all active pending orders for this user
        $stmt = $conn->prepare("UPDATE orders SET status = 'EXPIRED_BY_NEW', resume_allowed = 0 WHERE user_id = ? AND (status = 'PENDING' OR status = 'CANCELLED_UI')");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Old sessions expired']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    }
    exit;
}

/**
 * Check Crypto Deposit Status
 */
function handleCheckDepositStatus() {
    global $pageId;
    require_once __DIR__ . '/binance_spot.php';

    $orderId = $_GET['order_id'] ?? null;
    if (!$orderId) {
        echo json_encode(['success' => false, 'error' => 'Order ID required']);
        exit;
    }

    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->bind_param("s", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    if ($order['status'] === 'PAID') {
        echo json_encode(['success' => true, 'status' => 'PAID']);
        exit;
    }

    // Extract address from stored reference
    // Format: ADDRESS|NETWORK|COIN
    $parts = explode('|', $order['binance_prepay_id']);
    $targetAddress = $parts[0] ?? '';
    $coin = $parts[2] ?? 'USDT'; // Default to USDT if not found
    
    // Check Binance History
    try {
        $binance = new BinanceSpot(BINANCE_API_KEY, BINANCE_SECRET_KEY);
        // Look back 24 hours (extended window for safety)
        $startTime = (time() - 86400) * 1000; 
        $history = $binance->getDepositHistory($coin, $startTime);

        $totalReceived = 0.0;
        $totalPending = 0.0; // Track pending (unconfirmed) deposits
        $orderAmount = floatval($order['amount']);
        $targetAddress = trim($targetAddress);
        
        // Get Order Creation Time (timestamp in ms)
        // created_at is in 'Y-m-d H:i:s' format in DB (Server Time: Asia/Kolkata)
        // Binance returns timestamps in UTC ms.
        // We must convert created_at to UTC timestamp for correct comparison.
        
        $dt = new DateTime($order['created_at'], new DateTimeZone('Asia/Kolkata'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        $orderTime = $dt->getTimestamp() * 1000;
        
        // DEBUG LOGGING
        $logFile = __DIR__ . '/logs/payment_debug.log';
        $logEntry = date('Y-m-d H:i:s') . " Checking Order: $orderId\n";
        $logEntry .= "Target Address: $targetAddress\n";
        $logEntry .= "Order Time (DB): " . $order['created_at'] . "\n";
        $logEntry .= "Order Time (UTC ms): $orderTime\n";
        $logEntry .= "Order Amount: $orderAmount\n";

        if (is_array($history)) {
            $logEntry .= "Found " . count($history) . " deposits in history.\n";
            foreach ($history as $deposit) {
                // Check if address matches AND deposit is AFTER order creation
                // Binance status: 0 = Pending (Email Sent), 1 = Pending (Confirming), 6 = Credited (Success)
                // insertTime is in ms
                
                $isMatch = ($deposit['address'] === $targetAddress && 
                            $deposit['insertTime'] > ($orderTime + 5000));
                
                $logEntry .= "Deposit: " . $deposit['amount'] . " " . $coin . " at " . $deposit['insertTime'] . " (Address Match: " . ($deposit['address'] === $targetAddress ? 'YES' : 'NO') . ") -> Match: " . ($isMatch ? 'YES' : 'NO') . " Status: " . $deposit['status'] . "\n";
                
                if ($isMatch) {
                     if ($deposit['status'] == 6) {
                         $totalReceived += floatval($deposit['amount']);
                     } elseif ($deposit['status'] == 0 || $deposit['status'] == 1) {
                         $totalPending += floatval($deposit['amount']);
                     }
                }
            }
        } else {
            $logEntry .= "No history returned or error.\n";
        }
        $logEntry .= "Total Received (Confirmed): $totalReceived\n";
        $logEntry .= "Total Pending: $totalPending\n";
        $logEntry .= "--------------------------------\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);

        // Check if fully paid (allowing small epsilon for float comparison)
        // CRITICAL: Ensure orderAmount is > 0 to prevent false positives from DB rounding errors
        if ($orderAmount > 0 && $totalReceived >= ($orderAmount * 0.98)) {
            // Check if order was already processed (to prevent duplicate credit grants)
            // Use atomic update to prevent race conditions
            $stmt = $conn->prepare("UPDATE orders SET status = 'PAID', updated_at = NOW() WHERE id = ? AND status != 'PAID'");
            $stmt->bind_param("s", $orderId);
            $stmt->execute();
            $rowsAffected = $stmt->affected_rows;
            
            // If no rows were affected, order was already PAID (prevent duplicate grants)
            if ($rowsAffected === 0) {
                echo json_encode(['success' => true, 'status' => 'PAID', 'received' => $totalReceived, 'message' => 'Order already processed']);
                exit;
            }

            // Parse Plan from Order Ref
            $parts = explode('|', $order['binance_prepay_id']);
            $planType = $parts[3] ?? 'standard'; // Default to standard if missing

            // Grant Premium / Update Credits
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            
            // Update Page Subscription Expiry (Legacy/Visual)
            // ONLY for UNLIMITED plan
            if ($planType === 'unlimited') {
                $stmt = $conn->prepare("UPDATE pages SET subscription_expires_at = ? WHERE id = ?");
                $stmt->bind_param("ss", $expiresAt, $order['page_id']);
                $stmt->execute();
            } else {
                // For Standard plan, ensure we DON'T set them as premium (expires_at = NULL)
                // This ensures isPremium check fails, but they have credits
                // Optional: We could explicitly set it to NULL if it was previously set, 
                // but usually we just leave it alone or let it expire.
                // If they are downgrading from Unlimited to Standard, we might want to let the Unlimited expire?
                // For now, let's just NOT update it.
            }

            // Update User Credits & Plan
            // Use the User ID from the Order (the purchaser)
            $userId = $order['user_id'];
            
            // Fallback 1: Get User ID from Page ID (via user_pages)
            if (!$userId) {
                $stmt = $conn->prepare("SELECT user_id FROM user_pages WHERE page_id = ? LIMIT 1");
                $stmt->bind_param("s", $order['page_id']);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $userId = $row['user_id'];
                }
            }
            
            // Fallback 2: Get User ID from Session (current logged-in user)
            if (!$userId && isset($_SESSION['fb_user_id'])) {
                $userId = $_SESSION['fb_user_id'];
            }
            
            // Fallback 3: Get User ID from most recent order for this page
            if (!$userId) {
                $stmt = $conn->prepare("SELECT user_id FROM orders WHERE page_id = ? AND user_id IS NOT NULL ORDER BY created_at DESC LIMIT 1");
                $stmt->bind_param("s", $order['page_id']);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $userId = $row['user_id'];
                }
            }
            
            // CRITICAL: If still no userId, log error and try to create user_pages mapping from session
            if (!$userId) {
                $logFile = __DIR__ . '/logs/payment_credits_error.log';
                $errorMsg = date('Y-m-d H:i:s') . " - CRITICAL: Cannot find userId for Order: $orderId, Page: " . $order['page_id'] . "\n";
                $errorMsg .= "Order user_id: " . ($order['user_id'] ?? 'NULL') . "\n";
                $errorMsg .= "Session user_id: " . ($_SESSION['fb_user_id'] ?? 'NULL') . "\n";
                file_put_contents($logFile, $errorMsg, FILE_APPEND);
                error_log("CRITICAL: Cannot grant credits - userId is NULL for order $orderId");
                
                // Last resort: Use session user_id if available (even if not in order)
                if (isset($_SESSION['fb_user_id'])) {
                    $userId = $_SESSION['fb_user_id'];
                    // Update the order with the userId for future reference
                    $updStmt = $conn->prepare("UPDATE orders SET user_id = ? WHERE id = ?");
                    $updStmt->bind_param("ss", $userId, $orderId);
                    $updStmt->execute();
                }
            }
            
            if ($userId) {
                $monthlyCredits = ($planType === 'standard') ? STANDARD_CREDIT_LIMIT : 0;
                
                // Calculate Overpayment
                $overpayment = max(0, $totalReceived - $orderAmount);
                
                // Update or Insert Credits Record
                // If Unlimited: Set expires_at. If Standard: Set expires_at = NULL (or keep existing?)
                // Actually, Standard credits don't expire in this logic, or we can set 30 days.
                // Let's set 30 days for both for simplicity, but 'standard' plan_type won't trigger isPremium global check
                
                $creditExpiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                
                $stmt = $conn->prepare("INSERT INTO credits (id, user_id, monthly_credits, plan_type, expires_at, wallet_balance) VALUES (UUID(), ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE monthly_credits = monthly_credits + ?, plan_type = ?, expires_at = ?, wallet_balance = wallet_balance + ?");
                // Note: For Standard, we ADD credits (monthly_credits + ?). For Unlimited, we usually just set status.
                // But the query above was `monthly_credits = ?` (replace).
                // Let's change to ADD for Standard.
                
                if ($planType === 'standard') {
                     // For Standard plan: Add credits (don't replace existing, but ensure minimum)
                     // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing records
                     $stmt = $conn->prepare("INSERT INTO credits (id, user_id, monthly_credits, plan_type, expires_at, wallet_balance) VALUES (UUID(), ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE monthly_credits = GREATEST(monthly_credits, ?), plan_type = ?, expires_at = GREATEST(COALESCE(expires_at, '1970-01-01'), ?), wallet_balance = wallet_balance + ?");
                     $stmt->bind_param("sisssdssssd", $userId, $monthlyCredits, $planType, $creditExpiresAt, $overpayment, $monthlyCredits, $planType, $creditExpiresAt, $overpayment);
                     
                     if (!$stmt->execute()) {
                         // Log error but don't fail the payment - credits can be fixed manually
                         $errorMsg = "Failed to update credits for standard plan user $userId: " . $conn->error;
                         error_log($errorMsg);
                         $logFile = __DIR__ . '/logs/payment_credits_error.log';
                         file_put_contents($logFile, date('Y-m-d H:i:s') . " - Order: $orderId, User: $userId, Plan: standard, Error: " . $conn->error . "\n", FILE_APPEND);
                     } else {
                         // Success - log for debugging
                         $logFile = __DIR__ . '/logs/payment_credits_success.log';
                         file_put_contents($logFile, date('Y-m-d H:i:s') . " - Order: $orderId, User: $userId, Plan: standard, Credits: $monthlyCredits, Expires: $creditExpiresAt\n", FILE_APPEND);
                     }
                } else {
                     // Unlimited: Set plan to unlimited with expiry
                     // Always update to ensure unlimited plan is active (even if extending existing)
                     // Use GREATEST to extend expiry if user already has unlimited plan
                     $stmt = $conn->prepare("INSERT INTO credits (id, user_id, monthly_credits, plan_type, expires_at, wallet_balance) VALUES (UUID(), ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE plan_type = ?, expires_at = GREATEST(COALESCE(expires_at, '1970-01-01'), ?), wallet_balance = wallet_balance + ?");
                     $stmt->bind_param("sissssdssd", $userId, $monthlyCredits, $planType, $creditExpiresAt, $overpayment, $planType, $creditExpiresAt, $overpayment);
                     
                     if (!$stmt->execute()) {
                         // Log error but don't fail the payment - credits can be fixed manually
                         $errorMsg = "Failed to update credits for unlimited plan user $userId: " . $conn->error;
                         error_log($errorMsg);
                         $logFile = __DIR__ . '/logs/payment_credits_error.log';
                         file_put_contents($logFile, date('Y-m-d H:i:s') . " - Order: $orderId, User: $userId, Plan: unlimited, Error: " . $conn->error . "\n", FILE_APPEND);
                     } else {
                         // Success - log for debugging
                         $logFile = __DIR__ . '/logs/payment_credits_success.log';
                         file_put_contents($logFile, date('Y-m-d H:i:s') . " - Order: $orderId, User: $userId, Plan: unlimited, Expires: $creditExpiresAt\n", FILE_APPEND);
                     }
                }
                
                // Ensure user_pages mapping exists (for future lookups)
                if ($userId && $order['page_id']) {
                    $stmt = $conn->prepare("INSERT IGNORE INTO user_pages (user_id, page_id) VALUES (?, ?)");
                    $stmt->bind_param("ss", $userId, $order['page_id']);
                    $stmt->execute();
                }
            } else {
                // Log warning if userId was missing but payment was processed
                $logFile = __DIR__ . '/logs/payment_credits_error.log';
                file_put_contents($logFile, date('Y-m-d H:i:s') . " - WARNING: Payment processed but credits NOT granted - Order: $orderId, Page: " . $order['page_id'] . " (userId was NULL)\n", FILE_APPEND);
            }

            echo json_encode(['success' => true, 'status' => 'PAID', 'received' => $totalReceived]);
        } elseif (($totalReceived + $totalPending) >= ($orderAmount * 0.98)) {
            // Payment Detected (Processing)
            echo json_encode(['success' => true, 'status' => 'PROCESSING']);
        } elseif ($totalReceived > 0) {
            // Partial Payment
            echo json_encode([
                'success' => true, 
                'status' => 'PARTIAL', 
                'received' => $totalReceived,
                'remaining' => $orderAmount - $totalReceived,
                'currency' => $coin
            ]);
        } else {
            echo json_encode(['success' => true, 'status' => 'PENDING']);
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Redeem Promo Code
 */
function handleRedeemPromoCode() {
    global $pageId;
    
    if (!$pageId) {
        echo json_encode(['success' => false, 'error' => 'No page selected']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $promoCode = $input['promo_code'] ?? '';
    
    if ($promoCode === '@029096Pp') {
        $conn = getDbConnection();
        
        // Get User ID from Page ID (with multiple fallbacks)
        $userId = $_SESSION['fb_user_id'] ?? null;
        
        // Fallback 1: Get User ID from user_pages table
        if (!$userId) {
            $stmt = $conn->prepare("SELECT user_id FROM user_pages WHERE page_id = ? LIMIT 1");
            $stmt->bind_param("s", $pageId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $userId = $row['user_id'];
            }
        }
        
        // Fallback 2: Get User ID from most recent PAID order
        if (!$userId) {
            $stmt = $conn->prepare("SELECT user_id FROM orders WHERE page_id = ? AND user_id IS NOT NULL AND status = 'PAID' ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param("s", $pageId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $userId = $row['user_id'];
            }
        }
        
        if (!$userId) {
            echo json_encode(['success' => false, 'error' => 'Unable to identify user. Please ensure you are logged in.']);
            exit;
        }
        
        // Grant LIFETIME unlimited plan (set expiry to year 2099 - effectively lifetime)
        $expiresAt = '2099-12-31 23:59:59'; // Lifetime expiry date
        
        // Update pages table (legacy/visual)
        $stmt = $conn->prepare("UPDATE pages SET subscription_expires_at = ? WHERE id = ?");
        $stmt->bind_param("ss", $expiresAt, $pageId);
        $stmt->execute();
        
        // Update credits table to set plan_type to 'unlimited' with lifetime expiry
        $planType = 'unlimited';
        $monthlyCredits = 0; // Unlimited doesn't need credits
        $walletBalance = 0.00; // Set wallet balance to 0 for promo code
        
        // SQL: VALUES has 5 placeholders (user_id, monthly_credits, plan_type, expires_at, wallet_balance)
        //      UPDATE has 2 placeholders (plan_type, expires_at)
        //      Total: 7 placeholders
        $stmt = $conn->prepare("INSERT INTO credits (id, user_id, monthly_credits, plan_type, expires_at, wallet_balance) VALUES (UUID(), ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE plan_type = ?, expires_at = ?");
        // bind_param: 7 parameters total
        // VALUES: s=user_id, i=monthly_credits, s=plan_type, s=expires_at, d=wallet_balance (5 params)
        // UPDATE: s=plan_type, s=expires_at (2 params)
        // Type string: "sisssdss" = 8 chars, but we only have 7 params, so it should be "sisssds" = 7 chars
        // Wait, let me count: user_id(s), monthly_credits(i), plan_type(s), expires_at(s), wallet_balance(d), plan_type(s), expires_at(s) = 7 params
        // Type string should be: "sisssdss" but that's 8 chars. Let me check: s-i-s-s-s-d-s-s = 8
        // Actually: s(1) + i(2) + s(3) + s(4) + s(5) + d(6) + s(7) + s(8) = 8 chars for 7 params? No!
        // Correct: s(1) + i(2) + s(3) + s(4) + s(5) + d(6) + s(7) = 7 chars for 7 params
        // SQL has 7 placeholders: 5 in VALUES + 2 in UPDATE
        // Parameters in order: $userId(s), $monthlyCredits(i), $planType(s), $expiresAt(s), $walletBalance(d), $planType(s), $expiresAt(s)
        // Type string must be exactly 7 characters for 7 parameters
        // "sisssdss" = 8 chars (WRONG - has extra 's')
        // Correct: "sisssds" = 7 chars, but wallet_balance needs to be string 's' not double 'd'
        $walletBalanceStr = (string)$walletBalance;
        $stmt->bind_param("sisssss", $userId, $monthlyCredits, $planType, $expiresAt, $walletBalanceStr, $planType, $expiresAt);
        
        if ($stmt->execute()) {
            // Ensure user_pages mapping exists
            $stmt = $conn->prepare("INSERT IGNORE INTO user_pages (user_id, page_id) VALUES (?, ?)");
            $stmt->bind_param("ss", $userId, $pageId);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'Promo code redeemed! You are now on the Unlimited Plan for LIFETIME.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid promo code']);
    }
    exit;
}

/**
 * Helper to get DB connection - MOVED TO db.php
 */
// function getDbConnection() { ... }

/**
 * Get all pages the user manages
 */
function handleGetPages() {
    ob_start();
    
    try {
        // Double-check session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_access_token'])) {
            ob_end_clean();
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Not logged in with Facebook. Please login again.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $user_access_token = $_SESSION['user_access_token'];
        $pages_response = getUserPagesWithLogging($user_access_token);
        
        if (!isset($pages_response['data']) || !is_array($pages_response['data'])) {
            throw new Exception('Invalid response format from Facebook API');
        }
        
        $pages = $pages_response['data'];
        $currentPageId = $_SESSION['page_id'] ?? null;
        
        $formattedPages = [];
        foreach ($pages as $page) {
            $formattedPages[] = [
                'id' => $page['id'],
                'name' => $page['name'] ?? 'Unknown Page',
                'access_token' => $page['access_token'] ?? null,
                'is_current' => ($page['id'] === $currentPageId)
            ];
        }
        
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'pages' => $formattedPages
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Switch to a different page
 */
function handleSwitchPage() {
    ob_start();
    
    try {
        if (!isset($_SESSION['user_access_token'])) {
            throw new Exception('User access token not found');
        }
        
        $pageId = $_POST['page_id'] ?? $_GET['page_id'] ?? null;
        if (!$pageId) {
            throw new Exception('Page ID is required');
        }
        
        $user_access_token = $_SESSION['user_access_token'];
        $pages_response = getUserPagesWithLogging($user_access_token);
        
        if (!isset($pages_response['data']) || !is_array($pages_response['data'])) {
            throw new Exception('Invalid response format from Facebook API');
        }
        
        $pages = $pages_response['data'];
        $selectedPage = null;
        foreach ($pages as $page) {
            if ($page['id'] === $pageId) {
                $selectedPage = $page;
                break;
            }
        }
        
        if (!$selectedPage) {
            throw new Exception('Page not found or you do not have access to it');
        }
        
        $page_access_token = $selectedPage['access_token'] ?? null;
        if (!$page_access_token) {
            throw new Exception('Page access token not available');
        }
        
        if (!verifyPageAdminPermissions($pageId, $page_access_token)) {
            throw new Exception('You do not have admin permissions for this page');
        }
        
        $page_metadata = getPageMetadata($pageId, $page_access_token);
        
        $_SESSION['page_id'] = $pageId;
        $_SESSION['page_name'] = $page_metadata['name'] ?? $selectedPage['name'] ?? 'Unknown Page';
        $_SESSION['page_access_token'] = $page_access_token;
        $_SESSION['page_picture_url'] = $page_metadata['picture']['data']['url'] ?? null;
        
        // Ensure page exists in DB with free credits initialized
        // This ensures "Each Page ID receives free credits only once"
        $conn = getDbConnection();
        $stmt = $conn->prepare("INSERT INTO pages (id, name, free_credits_remaining) VALUES (?, ?, 500) ON DUPLICATE KEY UPDATE name = VALUES(name)");
        $stmt->bind_param("ss", $pageId, $_SESSION['page_name']);
        $stmt->execute();
        
        // Ensure user_pages mapping exists (for fallback user_id lookup)
        $userId = $_SESSION['fb_user_id'] ?? null;
        if ($userId) {
            $stmt = $conn->prepare("INSERT INTO user_pages (id, user_id, page_id, role, connected_at) VALUES (UUID(), ?, ?, 'admin', NOW()) ON DUPLICATE KEY UPDATE connected_at = NOW()");
            $stmt->bind_param("ss", $userId, $pageId);
            $stmt->execute();
        }
        
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Page switched successfully',
            'page' => [
                'id' => $pageId,
                'name' => $_SESSION['page_name'],
                'picture_url' => $_SESSION['page_picture_url']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Fetch conversations from Facebook Graph API
 */
function handleListConversations() {
    global $action;
    
    // Ensure we output JSON only (prevent any HTML/errors from being output)
    ob_start(); // Start output buffering to catch any accidental output
    
    try {
        debugLog('handleListConversations: Starting');
        $conversations = fetchConversations();
        debugLog('handleListConversations: Fetched ' . count($conversations) . ' conversations');
        
        ob_end_clean(); // Clear any accidental output
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'conversations' => $conversations
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        ob_end_clean(); // Clear any accidental output
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Stop broadcast
 */
function handleStopBroadcast() {
    ob_end_clean();
    header('Content-Type: application/json');
    
    // Ensure logs directory exists
    if (!is_dir(LOGS_DIR)) {
        @mkdir(LOGS_DIR, 0755, true);
    }
    
    $stopFlagFile = BROADCAST_STOP_FLAG;
    
    // Create stop flag file with current timestamp (LOCK_EX ensures atomic write)
    $result = @file_put_contents($stopFlagFile, time(), LOCK_EX);
    
    if ($result === false) {
        // Try to get more details about the error
        $error = error_get_last();
        $error_msg = 'Could not create stop flag file';
        if ($error) {
            $error_msg .= ': ' . $error['message'];
        }
        $error_msg .= ' (Path: ' . $stopFlagFile . ')';
        
        echo json_encode([
            'success' => false,
            'error' => $error_msg
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Verify file was created
    if (!file_exists($stopFlagFile)) {
        echo json_encode([
            'success' => false,
            'error' => 'Stop flag file was not created (Path: ' . $stopFlagFile . ')'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Stop signal sent',
        'file_path' => $stopFlagFile,
        'file_exists' => file_exists($stopFlagFile)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Check if a stop signal has been requested.
 */
function isStopRequested() {
    clearstatcache(false, BROADCAST_STOP_FLAG);
    return file_exists(BROADCAST_STOP_FLAG);
}

/**
 * Send broadcast messages to selected users
 */
function handleSendBroadcast() {
    global $action, $pageId, $accessToken;
    
    // Copy session values to local variables and release session lock
    $pageId = $_SESSION['page_id'];
    $pageAccessToken = $_SESSION['page_access_token'];
    session_write_close();
    
    // Update global variables so other functions can use them
    $accessToken = $pageAccessToken;
    
    // Set execution time to 2 hours for this specific request
    set_time_limit(7200);
    ini_set('max_execution_time', 7200);
    ini_set('memory_limit', '512M');
    
    // Clear any accidental output
    ob_clean();
    
    // Validate inputs
    $message_text = trim($_POST['message_text'] ?? '');
    $selected_psids = $_POST['selected_psids'] ?? [];
    
    if (empty($message_text)) {
        ob_end_clean();
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Message text is required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (empty($selected_psids) || !is_array($selected_psids)) {
        ob_end_clean();
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'At least one user must be selected'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Handle image upload if present
    $image_path = null;
    
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        $upload_result = handleImageUpload($_FILES['image_file']);
        if ($upload_result['success']) {
            $image_path = $upload_result['path'];
        } else {
            http_response_code(400);
            echo json_encode(['error' => $upload_result['error']]);
            return;
        }
    }
    
    // Get conversation data to check 24h status for each PSID
    // This allows us to use MESSAGE_TAG for users outside 24h window
    $conversations_data = [];
    try {
        $conversations = fetchConversations();
        foreach ($conversations as $conv) {
            $conversations_data[$conv['psid']] = $conv;
        }
    } catch (Exception $e) {
        // If we can't fetch conversations, assume all are within 24h (safer)
    }
    
    // Initialize stop flag file path
    $stopFlagFile = BROADCAST_STOP_FLAG;
    
    // Clear any existing stop flag
    if (file_exists($stopFlagFile)) {
        @unlink($stopFlagFile);
    }
    
    // Send messages to each selected PSID
    $selected_psids = array_unique($selected_psids);
    $results = [];
    $total = count($selected_psids);
    $sent = 0;
    $failed = 0;
    
    // Ensure page exists in DB and handle auto-upgrade for specific user
    $conn = getDbConnection();
    $pageName = $_SESSION['page_name'] ?? 'Unknown Page';
    
    // Check if page exists
    debugLog("Checking if page exists: $pageId");
    $checkStmt = $conn->prepare("SELECT id, subscription_expires_at FROM pages WHERE id = ?");
    $checkStmt->bind_param("s", $pageId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        debugLog("Page not found, inserting: $pageId ($pageName)");
        // Insert new page
        $insertStmt = $conn->prepare("INSERT INTO pages (id, name, free_credits_remaining) VALUES (?, ?, 500)");
        $insertStmt->bind_param("ss", $pageId, $pageName);
        if ($insertStmt->execute()) {
            debugLog("Page inserted successfully");
        } else {
            debugLog("Page insertion failed: " . $conn->error);
        }
    } else {
        debugLog("Page exists");
    }
    
    // Get User ID and Credits for Plan Enforcement
    // We use the logged-in Admin's ID (from session) to enforce User-Based Plans
    $userId = $_SESSION['fb_user_id'] ?? null;
    $userCredits = 0;
    $userPlan = 'free';

    if ($userId && bulkzen_has_column($conn, 'credits', 'plan_type')) {
        $stmt = $conn->prepare("SELECT monthly_credits, plan_type FROM credits WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $cRes = $stmt->get_result();
            if ($cRow = $cRes->fetch_assoc()) {
                $userCredits = $cRow['monthly_credits'];
                $userPlan = $cRow['plan_type'];
            }
        }
    }

    $hasFreeCol = bulkzen_has_column($conn, 'pages', 'free_credits_remaining');
    $pageSql = $hasFreeCol
        ? "SELECT total_messages_sent, subscription_expires_at, free_credits_remaining FROM pages WHERE id = ?"
        : "SELECT total_messages_sent, subscription_expires_at FROM pages WHERE id = ?";
    $stmt = $conn->prepare($pageSql);
    $pageData = null;
    if ($stmt) {
        $stmt->bind_param("s", $pageId);
        $stmt->execute();
        $pageData = $stmt->get_result()->fetch_assoc();
    }
    
    if (!$pageData) {
        $pageData = [
            'total_messages_sent' => 0,
            'subscription_expires_at' => null,
            'free_credits_remaining' => FREE_TIER_LIMIT
        ];
    }
    
    $isPremium = false;
    if ($pageData && $pageData['subscription_expires_at']) {
        if (strtotime($pageData['subscription_expires_at']) > time()) {
            $isPremium = true;
        }
    }
    
    // Check User Global Subscription (Unlimited Plan or Standard)
    if ($userId && bulkzen_has_column($conn, 'credits', 'plan_type')) {
        $stmt = $conn->prepare("SELECT plan_type, expires_at FROM credits WHERE user_id = ?");
        if (!$stmt) {
            $stmt = null;
        } else {
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $cRes = $stmt->get_result();
        if ($cRow = $cRes->fetch_assoc()) {
            if ($cRow['plan_type'] === 'unlimited' && $cRow['expires_at']) {
                if (strtotime($cRow['expires_at']) > time()) {
                    $isPremium = true;
                    $userPlan = 'unlimited'; // Enforce unlimited status
                }
            }             elseif ($cRow['plan_type'] === 'standard') {
                // Check if Standard plan is not expired
                if ($cRow['expires_at'] && strtotime($cRow['expires_at']) > time()) {
                    $isPremium = true;
                    $userPlan = 'standard';
                } elseif (!$cRow['expires_at']) {
                    // If no expiry set, treat as active (backward compatibility)
                    $isPremium = true;
                    $userPlan = 'standard';
                }
            }
        }
        }
    }
    
    $totalSent = $pageData['total_messages_sent'] ?? 0;

    foreach ($selected_psids as $psid) {
        // Check for stop flag BEFORE processing each message
        if (isStopRequested()) {
            // Stop flag detected - break the loop immediately
            break;
        }
        
        // Free Tier Check (using page-based credit counter)
        $freeCredits = $hasFreeCol ? ($pageData['free_credits_remaining'] ?? FREE_TIER_LIMIT) : FREE_TIER_LIMIT;
        if ($hasFreeCol && !$isPremium && $freeCredits <= 0) {
            // Limit reached
            $results[] = [
                'psid' => $psid,
                'status' => 'error',
                'error_message' => 'Free limit reached (500 messages). Please upgrade to Premium.'
            ];
            $failed++;
            continue; // Skip this user
        }

        // Standard Plan Credit Check (using local counter)
        if ($isPremium && $userPlan === 'standard') {
            if ($userCredits <= 0) {
                $results[] = [
                    'psid' => $psid,
                    'status' => 'error',
                    'error_message' => 'Monthly credit limit reached (5000). Please upgrade to Unlimited.'
                ];
                $failed++;
                continue;
            }
        }
 
        $psid = trim($psid);
        if (empty($psid)) {
            continue;
        }
        
        // Check if user is within 24h window
        $is_within_24h = isset($conversations_data[$psid]) ? $conversations_data[$psid]['is_within_24h'] : true;
        
        try {
            $result = sendMessageToUser($psid, $message_text, $image_path, $is_within_24h);
            
            // Check for stop flag immediately after sending
            if (isStopRequested()) {
                // Stop flag detected - break the loop immediately
                break;
            }
            
            // Ensure result has required fields
            if (!isset($result['status'])) {
                $result = [
                    'psid' => $psid,
                    'status' => 'error',
                    'error_message' => 'Invalid response from sendMessageToUser'
                ];
            }
            
            $results[] = $result;
            
            if ($result['status'] === 'success') {
                $sent++;
                
                // Update Local Counters
                $totalSent++;
                
                // Update DB (still needed for persistence)
                $conn->query("UPDATE pages SET total_messages_sent = total_messages_sent + 1 WHERE id = '$pageId'");
                
                // Deduct Free Credits if not premium
                if (!$isPremium && $hasFreeCol) {
                    $conn->query("UPDATE pages SET free_credits_remaining = GREATEST(0, free_credits_remaining - 1) WHERE id = '$pageId'");
                    $pageData['free_credits_remaining']--;
                }

                // Deduct Credit for Standard Plan
                if ($isPremium && $userPlan === 'standard' && $userId) {
                    $conn->query("UPDATE credits SET monthly_credits = monthly_credits - 1 WHERE user_id = '$userId'");
                    $userCredits--; // Update local counter
                }
            } else {
                $failed++;
            }
        } catch (Exception $e) {
            // Check if this is a stop exception - if so, break the loop
            if (strpos($e->getMessage(), 'stopped by user') !== false || 
                strpos($e->getMessage(), 'Broadcast stopped') !== false) {
                // Stop flag detected - break the loop immediately
                break;
            }
            
            // Skip failed messages and continue - don't stop the process
            $results[] = [
                'psid' => $psid,
                'status' => 'error',
                'error_message' => 'Exception: ' . $e->getMessage()
            ];
            $failed++;
            // Continue to next message - don't break
        } catch (Throwable $e) {
            // Check if this is a stop exception - if so, break the loop
            if (strpos($e->getMessage(), 'stopped by user') !== false || 
                strpos($e->getMessage(), 'Broadcast stopped') !== false) {
                // Stop flag detected - break the loop immediately
                break;
            }
            
            // Skip failed messages and continue - don't stop the process
            $results[] = [
                'psid' => $psid,
                'status' => 'error',
                'error_message' => 'Error: ' . $e->getMessage()
            ];
            $failed++;
            // Continue to next message - don't break
        }
        
        // Check for stop flag AFTER sending each message
        if (isStopRequested()) {
            // Stop flag detected - break the loop immediately
            break;
        }
        
        // Delay between messages (except for the last one)
        // Break delay into smaller chunks to check stop flag more frequently
        if ($psid !== end($selected_psids)) {
            $delay_microseconds = MESSAGE_DELAY_SECONDS * 1000000;
            $chunk_size = 100000; // 0.1 seconds per chunk
            $chunks = ceil($delay_microseconds / $chunk_size);
            
            for ($i = 0; $i < $chunks; $i++) {
                // Check stop flag during delay
                if (isStopRequested()) {
                    break 2; // Break out of both delay loop and main loop
                }
                usleep(min($chunk_size, $delay_microseconds - ($i * $chunk_size)));
            }
        }
    }
    
    // Clean up stop flag if it exists
    if (isStopRequested()) {
        @unlink($stopFlagFile);
    }
    
    // Log the broadcast (suppress any errors from logging)
    try {
        logBroadcast([
            'timestamp' => date('Y-m-d H:i:s'),
            'total_recipients' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'message_text' => substr($message_text, 0, 200),
            'image_path' => $image_path,
            'results' => $results
        ]);
    } catch (Exception $e) {
        // Ignore logging errors - don't break the response
    }
    
    // Clear any accidental output and send JSON response
    // For large batches, return summary only to avoid JSON truncation
    ob_end_clean();
    // Clean up uploaded images and old logs after broadcast completes
    cleanupUploadedImages();
    cleanupOldLogs();
    
    header('Content-Type: application/json');
    
    // If results array is too large, return summary only
    if (count($results) > 100) {
        // Return summary and first/last 50 results to avoid JSON size issues
        $first_results = array_slice($results, 0, 50);
        $last_results = array_slice($results, -50);
        
        echo json_encode([
            'success' => true,
            'summary' => [
                'total' => $total,
                'sent' => $sent,
                'failed' => $failed
            ],
            'results' => array_merge($first_results, [['psid' => '...', 'status' => 'info', 'error_message' => 'Results truncated. Full details in log file.']], $last_results),
            'message' => 'Large batch completed. Full results saved to log file.'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'summary' => [
                'total' => $total,
                'sent' => $sent,
                'failed' => $failed
            ],
            'results' => $results
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // Flush output to ensure it's sent
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
    exit;
}

/**
 * Fetch conversations from Facebook Graph API
 */
function fetchConversations() {
    global $pageId, $accessToken;
    
    $page_id = $pageId;
    $access_token = $accessToken;
    $version = FB_GRAPH_API_VERSION;
    
    // Optimized: Get conversations with minimal fields and no per-conversation API calls
    // This makes it MUCH faster for large numbers of conversations
    $conversations_url = "https://graph.facebook.com/{$version}/{$page_id}/conversations";
    $conversations_params = [
        'access_token' => $access_token,
        'fields' => 'participants{id,name,picture{url}},updated_time,snippet', // Added snippet to avoid extra API calls
        'limit' => 100 // Fetch 100 per page for pagination
    ];
    
    $result = [];
    $page_count = 0;
    $next_url = null;
    
    // Fetch ALL conversations using pagination (including old ones from 2022, 2023, etc.)
    do {
        $page_count++;
        try {
            if ($next_url) {
                // Fetch next page using the full URL from paging.next (Facebook provides complete URL)
                debugLog("fetchConversations: Fetching page $page_count (pagination)");
                // Use curl directly for pagination URLs since they're complete URLs
                $ch = curl_init($next_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                if ($error) {
                    throw new Exception('cURL error: ' . $error);
                }
                
                $conversations_response = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Failed to decode JSON: ' . json_last_error_msg());
                }
                
                if ($http_code >= 400) {
                    throw new Exception('HTTP error: ' . $http_code . ' - ' . ($conversations_response['error']['message'] ?? 'Unknown error'));
                }
            } else {
                // First page - use initial URL
                debugLog("fetchConversations: Fetching page $page_count (initial)");
                debugLog('fetchConversations: Calling Facebook API: ' . $conversations_url);
                $conversations_response = callFacebookAPI($conversations_url, $conversations_params);
            }
            debugLog("fetchConversations: Page $page_count returned");
        } catch (Exception $e) {
            debugLog('fetchConversations: API Error on page ' . $page_count . ': ' . $e->getMessage());
            // If we have some results, return them instead of failing completely
            if (!empty($result)) {
                debugLog('fetchConversations: Returning ' . count($result) . ' conversations fetched before error');
                break;
            }
            throw new Exception('Facebook API error: ' . $e->getMessage());
        }
        
        if (!isset($conversations_response['data'])) {
            // If we have some results, return them
            if (!empty($result)) {
                debugLog('fetchConversations: No more data, returning ' . count($result) . ' conversations');
                break;
            }
            throw new Exception('Failed to fetch conversations: ' . ($conversations_response['error']['message'] ?? 'Unknown error'));
        }
        
        $conversations_data = $conversations_response['data'];
        $current_batch_count = count($conversations_data);
        debugLog("fetchConversations: Page $page_count returned $current_batch_count conversations");
        
        // Process each conversation - NO API calls per conversation (much faster!)
        foreach ($conversations_data as $conv) {
        // Get the user PSID (participant that is not the page)
        $psid = null;
        if (isset($conv['participants']['data'])) {
            foreach ($conv['participants']['data'] as $participant) {
                if (isset($participant['id']) && $participant['id'] != $page_id) {
                    $psid = $participant['id'];
                    break;
                }
            }
        }
        
        if (!$psid) {
            continue; // Skip if we can't find a valid PSID
        }
        
        // Try to get user name and picture from conversation participants
        $user_name = null;
        $user_picture = null;
        
        if (isset($conv['participants']['data'])) {
            foreach ($conv['participants']['data'] as $participant) {
                if (isset($participant['id']) && $participant['id'] == $psid) {
                    if (isset($participant['name'])) {
                        $user_name = $participant['name'];
                    }
                    // Get picture URL - handle different response formats
                    if (isset($participant['picture'])) {
                        if (isset($participant['picture']['data']['url'])) {
                            $user_picture = $participant['picture']['data']['url'];
                        } elseif (isset($participant['picture']['url'])) {
                            $user_picture = $participant['picture']['url'];
                        } elseif (is_string($participant['picture'])) {
                            $user_picture = $participant['picture'];
                        }
                    }
                    break;
                }
            }
        }
        
        // Use updated_time and snippet from conversation (no extra API call needed!)
        $last_message_time = $conv['updated_time'] ?? null;
        $last_message_snippet = $conv['snippet'] ?? '';
        
        // Calculate if within 24 hours
        $is_within_24h = false;
        if ($last_message_time) {
            try {
                $last_timestamp = strtotime($last_message_time);
                $current_timestamp = time();
                $diff_seconds = $current_timestamp - $last_timestamp;
                $diff_hours = $diff_seconds / 3600;
                $is_within_24h = $diff_hours <= 23.5;
            } catch (Exception $e) {
                $is_within_24h = false;
            }
        }
        
            $result[] = [
                'psid' => $psid,
                'name' => $user_name ?: 'Unknown',
                'picture_url' => $user_picture ?? null,
                'last_message_snippet' => $last_message_snippet,
                'last_message_time' => $last_message_time ? date('Y-m-d H:i:s', strtotime($last_message_time)) : '',
                'is_within_24h' => $is_within_24h
            ];
        }
        
        // Check for next page
        $next_url = null;
        if (isset($conversations_response['paging']['next'])) {
            $next_url = $conversations_response['paging']['next'];
            debugLog("fetchConversations: Found next page, total conversations so far: " . count($result) . " (Page $page_count)");
        } else {
            debugLog("fetchConversations: No more pages, total conversations: " . count($result) . " (Completed $page_count pages)");
        }
        
        // Safety limit: Stop after 100 pages (10,000 conversations) to prevent infinite loops
        if ($page_count >= 100) {
            debugLog("fetchConversations: Reached safety limit of 100 pages, stopping pagination");
            break;
        }
        
    } while ($next_url);
    
    debugLog("fetchConversations: Completed fetching all conversations. Total: " . count($result) . " conversations across $page_count pages");
    return $result;
}

/**
 * Get the last message from a conversation
 */
function getLastMessage($conversation_id, $access_token, $version) {
    $messages_url = "https://graph.facebook.com/{$version}/{$conversation_id}/messages";
    $params = [
        'access_token' => $access_token,
        'fields' => 'message,created_time',
        'limit' => 1
    ];
    
    $response = callFacebookAPI($messages_url, $params);
    
    if (isset($response['data']) && !empty($response['data'])) {
        $message = $response['data'][0];
        return [
            'snippet' => substr($message['message'] ?? '', 0, 100),
            'time' => $message['created_time'] ?? null
        ];
    }
    
    return ['snippet' => '', 'time' => null];
}

/**
 * Get user name and profile picture from PSID (optional, may not always work)
 */
function getUserInfo($psid, $access_token, $version) {
    try {
        $user_url = "https://graph.facebook.com/{$version}/{$psid}";
        $params = [
            'access_token' => $access_token,
            'fields' => 'first_name,last_name,picture{url}'
        ];
        
        $response = callFacebookAPI($user_url, $params);
        
        $result = [
            'name' => null,
            'picture_url' => null
        ];
        
        if (isset($response['first_name'])) {
            $name = $response['first_name'];
            if (isset($response['last_name'])) {
                $name .= ' ' . $response['last_name'];
            }
            $result['name'] = $name;
        }
        
        if (isset($response['picture']['data']['url'])) {
            $result['picture_url'] = $response['picture']['data']['url'];
        }
        
        return $result;
    } catch (Exception $e) {
        // User info fetching is optional, so we don't throw
        return ['name' => null, 'picture_url' => null];
    }
}

/**
 * Send a message to a specific user via Messenger API
 * Note: Messenger API doesn't support text + attachment in one call
 * So if both text and image are provided, we send them as separate messages
 */
function sendMessageToUser($psid, $message_text, $image_path = null, $is_within_24h = true) {
    global $accessToken;
    
    // Check for stop flag before processing
    if (isStopRequested()) {
        throw new Exception('Broadcast stopped by user');
    }
    
    $access_token = $accessToken;
    $version = FB_GRAPH_API_VERSION;
    
    // Validate access token exists
    if (empty($access_token)) {
        return [
            'psid' => $psid,
            'status' => 'error',
            'error_message' => 'Access token is missing. Please log in again.'
        ];
    }

    // Credit check is handled in handleSendBroadcast
    // No need to check again here
    // ==========================================
    
    $errors = [];
    
    // Send text message first if provided
    if (!empty($message_text)) {
        // Check stop flag before API call
        if (isStopRequested()) {
            throw new Exception('Broadcast stopped by user');
        }
        
        try {
            sendTextMessage($psid, $message_text, $access_token, $version, $is_within_24h);
            
            // Check stop flag immediately after text message is sent
            if (isStopRequested()) {
                throw new Exception('Broadcast stopped by user');
            }
        } catch (Exception $e) {
            // If it's a stop exception, re-throw it
            if (strpos($e->getMessage(), 'stopped by user') !== false) {
                throw $e;
            }
            $errors[] = 'Text message failed: ' . $e->getMessage();
        }
    }
    
    // Send image if provided
    if ($image_path && file_exists($image_path)) {
        // Check stop flag before image upload
        if (isStopRequested()) {
            throw new Exception('Broadcast stopped by user');
        }
        
        try {
            // Upload image to Facebook first to get an attachment_id
            $attachment_id = uploadImageToFacebook($image_path, $access_token, $version);
            
            // Check stop flag after image upload
            if (isStopRequested()) {
                throw new Exception('Broadcast stopped by user');
            }
            
            if (!$attachment_id) {
                $errors[] = 'Image message failed: Could not upload image to Facebook';
            } else {
                // Include access token in URL for POST requests
                $url = "https://graph.facebook.com/{$version}/me/messages?access_token=" . urlencode($access_token);
                $payload = [
                    'recipient' => ['id' => $psid],
                    'message' => [
                        'attachment' => [
                            'type' => 'image',
                            'payload' => [
                                'attachment_id' => $attachment_id
                            ]
                        ]
                    ]
                ];
                
                // Try RESPONSE first, then retry with tags if needed
                if ($is_within_24h) {
                    $payload['messaging_type'] = 'RESPONSE';
                    
                    try {
                        $response = callFacebookAPI($url, $payload, 'POST');
                        if (isset($response['error'])) {
                            throw new Exception($response['error']['message'] ?? 'Unknown error');
                        }
                    } catch (Exception $e) {
                        // If RESPONSE fails with "outside window" error, retry with tags
                        $error_msg = $e->getMessage();
                        if (strpos($error_msg, 'outside the allowed window') !== false || 
                            strpos($error_msg, 'outside the allowed') !== false ||
                            strpos($error_msg, '#10') !== false) {
                            // Try multiple tags as fallback
                            try {
                                sendMessageWithTagFallback($url, $payload, $access_token, $version);
                            } catch (Exception $tag_error) {
                                $errors[] = 'Image message failed: ' . $tag_error->getMessage();
                            }
                        } else {
                            $errors[] = 'Image message failed: ' . $error_msg;
                        }
                    }
                } else {
                    // For users outside 24h, try multiple tags until one works
                    try {
                        sendMessageWithTagFallback($url, $payload, $access_token, $version);
                    } catch (Exception $tag_error) {
                        $errors[] = 'Image message failed: ' . $tag_error->getMessage();
                    }
                }
                
                // Check stop flag after image message is sent
                if (isStopRequested()) {
                    throw new Exception('Broadcast stopped by user');
                }
            }
        } catch (Exception $e) {
            // If it's a stop exception, re-throw it
            if (strpos($e->getMessage(), 'stopped by user') !== false) {
                throw $e;
            }
            $errors[] = 'Image message failed: ' . $e->getMessage();
        }
    }
    
    // Return success if no errors, or error if any failed
    if (empty($errors)) {
        return [
            'psid' => $psid,
            'status' => 'success',
            'error_message' => null
        ];
    } else {
        return [
            'psid' => $psid,
            'status' => 'error',
            'error_message' => implode('; ', $errors)
        ];
    }
}

/**
 * Try sending message with multiple tags as fallback
 * Tries tags that might work without App Review approval
 */
function sendMessageWithTagFallback($url, $payload, $access_token, $version) {
    // List of tags to try in order (most likely to work first)
    // These tags are for customer service/account updates and might work without approval
    $tags_to_try = [
        'ISSUE_RESOLUTION',        // Customer service issues - most likely to work
        'ACCOUNT_UPDATE',           // Account-related updates
        'PAYMENT_UPDATE',           // Payment confirmations
        'SHIPPING_UPDATE',          // Shipping notifications
        'RESERVATION_UPDATE',       // Reservation confirmations
        'NON_PROMOTIONAL_SUBSCRIPTION' // Last resort (requires approval)
    ];
    
    $last_error = null;
    
    foreach ($tags_to_try as $tag) {
        if (isStopRequested()) {
            throw new Exception('Broadcast stopped by user');
        }
        
        try {
            $payload['messaging_type'] = 'MESSAGE_TAG';
            $payload['tag'] = $tag;
            callFacebookAPI($url, $payload, 'POST');
            return; // Success!
        } catch (Exception $e) {
            $error_msg = strtolower($e->getMessage());
            $last_error = $e;
            
            // Check if error is about tag/permission issues - try next tag
            $is_tag_error = (
                strpos($error_msg, 'tag') !== false || 
                strpos($error_msg, 'permission') !== false ||
                strpos($error_msg, 'approval') !== false ||
                strpos($error_msg, 'not allowed') !== false ||
                strpos($error_msg, 'invalid tag') !== false ||
                strpos($error_msg, 'unauthorized') !== false ||
                strpos($error_msg, 'forbidden') !== false ||
                strpos($error_msg, '#200') !== false ||  // Invalid tag error code
                strpos($error_msg, '#201') !== false     // Tag not approved error code
            );
            
            if ($is_tag_error) {
                // Try next tag
                continue;
            }
            
            // If it's a different error (like invalid PSID, rate limit, etc.), don't retry with other tags
            throw $e;
        }
    }
    
    // If all tags failed, throw the last error
    if ($last_error) {
        throw new Exception('All message tags failed. Last error: ' . $last_error->getMessage() . 
            '. Note: This app is available for general use. Any Facebook Page admin can connect and send messages to their page users.');
    }
}

/**
 * Send a text-only message with automatic retry using message tags if needed
 * @param string $psid - Recipient PSID
 * @param string $text - Message text
 * @param string $access_token - Page access token
 * @param string $version - API version
 * @param bool $is_within_24h - Whether user is within 24h messaging window
 */
function sendTextMessage($psid, $text, $access_token, $version, $is_within_24h = true) {
    // Check for stop flag before making API call
    if (isStopRequested()) {
        throw new Exception('Broadcast stopped by user');
    }
    
    // Include access token in URL for POST requests
    $url = "https://graph.facebook.com/{$version}/me/messages?access_token=" . urlencode($access_token);
    $payload = [
        'recipient' => ['id' => $psid],
        'message' => ['text' => $text]
    ];
    
    // Try RESPONSE first for users we think are within 24h
    if ($is_within_24h) {
        $payload['messaging_type'] = 'RESPONSE';
        
        try {
            callFacebookAPI($url, $payload, 'POST');
            return; // Success, exit
        } catch (Exception $e) {
            // If RESPONSE fails with "outside window" error, retry with tags
            $error_msg = $e->getMessage();
            if (strpos($error_msg, 'outside the allowed window') !== false || 
                strpos($error_msg, 'outside the allowed') !== false ||
                strpos($error_msg, '#10') !== false) {
                // Try multiple tags as fallback
                sendMessageWithTagFallback($url, $payload, $access_token, $version);
                return; // Success with tag
            }
            // If it's a different error, re-throw it
            throw $e;
        }
    } else {
        // For users outside 24h, try multiple tags until one works
        sendMessageWithTagFallback($url, $payload, $access_token, $version);
    }
}

/**
 * Upload image to Facebook and get attachment_id
 * This is required because localhost URLs are not accessible to Facebook
 */
/**
 * Upload image to Facebook and get attachment_id
 * This is required because localhost URLs are not accessible to Facebook
 */
function uploadImageToFacebook($image_path, $access_token, $version) {
    // Check for stop flag before making API call
    if (isStopRequested()) {
        throw new Exception('Broadcast stopped by user');
    }
    
    if (!file_exists($image_path)) {
        throw new Exception('Image file not found: ' . $image_path);
    }
    
    // Upload to Facebook using message_attachments endpoint
    $upload_url = "https://graph.facebook.com/{$version}/me/message_attachments?access_token=" . urlencode($access_token);
    
    // Detect MIME type
    $mime_type = mime_content_type($image_path);
    if (!$mime_type) {
        // Fallback MIME type detection
        $ext = strtolower(pathinfo($image_path, PATHINFO_EXTENSION));
        $mime_types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png'
        ];
        $mime_type = $mime_types[$ext] ?? 'image/jpeg';
    }
    
    // Use CURLFile for file upload
    $cfile = new CURLFile($image_path, $mime_type, basename($image_path));
    
    $ch = curl_init($upload_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'message' => json_encode([
            'attachment' => [
                'type' => 'image',
                'payload' => []
            ]
        ]),
        'filedata' => $cfile
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception('cURL error during upload: ' . $curl_error);
    }
    
    if (!$response) {
        throw new Exception('Empty response from Facebook upload API');
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Failed to decode JSON response: ' . json_last_error_msg());
    }
    
    if (isset($data['attachment_id'])) {
        return $data['attachment_id'];
    } elseif (isset($data['error'])) {
        $error_msg = $data['error']['message'] ?? 'Unknown error';
        $error_code = $data['error']['code'] ?? 'Unknown';
        throw new Exception("Facebook upload error [{$error_code}]: {$error_msg}");
    } else {
        throw new Exception('Unexpected response from Facebook: ' . substr($response, 0, 200));
    }
}

/**
 * Handle image file upload
 */
function handleImageUpload($file) {
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
    $file_type = $file['type'];
    
    if (!in_array($file_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type. Only JPG, JPEG, and PNG are allowed.'];
    }
    
    // Validate file size
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'error' => 'File size exceeds maximum allowed size (5MB).'];
    }
    
    // Ensure uploads directory exists
    if (!is_dir(UPLOADS_DIR)) {
        if (!mkdir(UPLOADS_DIR, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create uploads directory.'];
        }
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'msg_' . time() . '_' . uniqid() . '.' . $extension;
    $file_path = UPLOADS_DIR . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file.'];
    }
    
    // Generate public URL
    $base_url = BASE_URL;
    if (empty($base_url)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $script_path = dirname($_SERVER['SCRIPT_NAME']);
        $base_url = $protocol . '://' . $host . $script_path;
    }
    
    $image_url = rtrim($base_url, '/') . '/uploads/' . $filename;
    
    return [
        'success' => true,
        'path' => $file_path,
        'url' => $image_url
    ];
}

/**
 * Make a call to Facebook Graph API
 */
function callFacebookAPI($url, $params, $method = 'GET') {
    $ch = curl_init();
    
    // Removed isStopRequested check here to allow other API calls (like fetching conversations) 
    // to proceed even if a broadcast was stopped.
    // The broadcast loop handles its own stop checks.
    
    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_URL, $url);
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        throw new Exception('cURL error: ' . $error);
    }
    
    $data = json_decode($response, true);
    
    // Check for JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Failed to decode JSON response: ' . json_last_error_msg() . ' (Response: ' . substr($response, 0, 200) . ')');
    }
    
    // Check for HTTP errors
    if ($http_code >= 400) {
        $error_code = isset($data['error']['code']) ? $data['error']['code'] : 'Unknown';
        $error_type = isset($data['error']['type']) ? $data['error']['type'] : 'Unknown';
        $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $http_code;
        throw new Exception("Facebook API error [{$error_code}] ({$error_type}): {$error_msg}");
    }
    
    // Check for error in response body (even if HTTP code is 200)
    if (isset($data['error'])) {
        $error_code = $data['error']['code'] ?? 'Unknown';
        $error_type = $data['error']['type'] ?? 'Unknown';
        $error_msg = $data['error']['message'] ?? 'Unknown error';
        throw new Exception("Facebook API error [{$error_code}] ({$error_type}): {$error_msg}");
    }
    
    return $data;
}

/**
 * Log broadcast to JSON file
 */
function logBroadcast($data) {
    // Ensure logs directory exists
    if (!is_dir(LOGS_DIR)) {
        mkdir(LOGS_DIR, 0755, true);
    }
    
    $log_file = LOGS_DIR . 'broadcast-log.json';
    
    // Read existing logs
    $logs = [];
    if (file_exists($log_file)) {
        $existing = file_get_contents($log_file);
        $logs = json_decode($existing, true) ?: [];
    }
    
    // Add new log entry
    $logs[] = $data;
    
    // Keep only last 100 entries to prevent file from growing too large
    if (count($logs) > 100) {
        $logs = array_slice($logs, -100);
    }
    
    // Write back to file
    file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT));
}

/**
 * Clean up uploaded images to save space
 */
function cleanupUploadedImages() {
    $uploadsDir = __DIR__ . '/uploads';
    
    if (!is_dir($uploadsDir)) {
        return;
    }
    
    try {
        // Get all image files in uploads directory (excluding index.php)
        $files = glob($uploadsDir . '/msg_*.{jpg,jpeg,png,gif}', GLOB_BRACE);
        
        if ($files && is_array($files)) {
            $deletedCount = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    // Delete broadcast images
                    if (@unlink($file)) {
                        $deletedCount++;
                    }
                }
            }
            
            // Log cleanup
            logApiResponse('Image cleanup completed', [
                'files_deleted' => $deletedCount,
                'directory' => $uploadsDir
            ]);
        }
    } catch (Exception $e) {
        // Silent fail - don't break broadcast if cleanup fails
        logApiResponse('Image cleanup failed', ['error' => $e->getMessage()]);
    }
}

/**
 * Clean up old log files to save space (keep only last 50 entries per log)
 */
function cleanupOldLogs() {
    $logsDir = LOGS_DIR;
    
    if (!is_dir($logsDir)) {
        return;
    }
    
    try {
        // Get all JSON log files
        $logFiles = glob($logsDir . '/*.json');
        
        if ($logFiles && is_array($logFiles)) {
            foreach ($logFiles as $logFile) {
                // Skip progress file (it's actively used)
                if (strpos($logFile, 'broadcast_progress.json') !== false) {
                    continue;
                }
                
                if (is_file($logFile) && filesize($logFile) > 0) {
                    $logs = json_decode(file_get_contents($logFile), true);
                    
                    if (is_array($logs) && count($logs) > 50) {
                        // Keep only last 50 entries
                        $logs = array_slice($logs, -50);
                        file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Silent fail
        logApiResponse('Log cleanup failed', ['error' => $e->getMessage()]);
    }
}

/**
 * Generate a short, engaging Messenger message with OpenAI or Gemini.
 * API key comes from the request or from config.php. The key is never logged.
 */
function handleGenerateAiMessage() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $provider = strtolower(trim($input['provider'] ?? (defined('AI_API_PROVIDER') ? AI_API_PROVIDER : 'kira')));
    $apiKey = trim($input['api_key'] ?? '');
    if ($apiKey === '' && defined('AI_API_KEY')) {
        $apiKey = AI_API_KEY;
    }

    $topic = trim($input['topic'] ?? '');
    $tone = trim($input['tone'] ?? 'friendly');
    $count = (int) ($input['count'] ?? 12);
    if ($count < 1) {
        $count = 1;
    }
    if ($count > 16) {
        $count = 16;
    }

    $avoid = $input['avoid'] ?? [];
    if (!is_array($avoid)) {
        $avoid = [];
    }
    $avoidLines = [];
    foreach ($avoid as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $avoidLines[] = function_exists('mb_substr') ? mb_substr($line, 0, 120) : substr($line, 0, 120);
        if (count($avoidLines) >= 40) {
            break;
        }
    }

    if ($provider !== 'kira' && $apiKey === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Add an AI API key in the dashboard (or config.php) first.']);
        return;
    }

    $allowedTones = ['friendly', 'curious', 'warm', 'promo'];
    if (!in_array($tone, $allowedTones, true)) {
        $tone = 'friendly';
    }

    $toneGuide = [
        'friendly' => 'Warm and human, like a real host checking in.',
        'curious' => 'Ask one light question so they want to tap reply.',
        'warm' => 'Welcoming and personal, never pushy.',
        'promo' => 'Bonus or offer first, still sounds like a person not an ad.',
    ];

    $brief = $topic !== '' ? $topic : 'welcome bonus or come-back offer if they have not played';

    $prompt = "Write {$count} different Facebook Messenger texts for a gaming page.\n";
    $prompt .= "Each text goes to a different person. They must not look like a copy-paste blast.\n";
    $prompt .= "Campaign brief from the host (follow this offer, do not invent a different bonus): {$brief}\n";
    $prompt .= "Tone: {$tone}. {$toneGuide[$tone]}\n";
    $prompt .= "Rules:\n";
    $prompt .= "- Gender-neutral. Never use dude, bro, man, girl, ladies, guys, he, or she.\n";
    $prompt .= "- Speak to one person as you. No names.\n";
    $prompt .= "- First few words must hook them so they tap the chat.\n";
    $prompt .= "- One short sentence is best. Two only if needed. Max 110 characters.\n";
    $prompt .= "- Stay on the brief. Change wording, rhythm, and angle — not the offer itself.\n";
    $prompt .= "- 0 or 1 emoji. Never more than one. Not every line needs an emoji.\n";
    $prompt .= "- No hashtags, no links, no ALL CAPS, no quotation marks around the whole line.\n";
    $prompt .= "- Do not mention AI, bots, or that this is a campaign.\n";
    if ($avoidLines) {
        $prompt .= "Do not repeat or closely rewrite any of these already-used lines:\n";
        foreach ($avoidLines as $line) {
            $prompt .= '- ' . $line . "\n";
        }
    }
    $prompt .= "Return ONLY a JSON array of {$count} unique strings. No extra text.";

    try {
        if ($provider === 'gemini') {
            $raw = generateMessageWithGemini($apiKey, $prompt, 1400);
        } elseif ($provider === 'openai') {
            $raw = generateMessageWithOpenAI($apiKey, $prompt, 1400);
        } else {
            $raw = generateMessageWithKira($prompt, max(500, $count * 80), $apiKey);
        }

        $messages = parseAiMessageList($raw, $count);
        if (count($messages) === 0) {
            throw new Exception('The AI did not return usable messages. Try again.');
        }

        echo json_encode([
            'success' => true,
            'messages' => $messages,
            'message' => $messages[0]
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function parseAiMessageList($raw, $wanted) {
    $text = trim((string) $raw);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $decoded = json_decode($text, true);
    $items = [];

    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            if (is_string($row)) {
                $items[] = $row;
            } elseif (is_array($row) && isset($row['message'])) {
                $items[] = (string) $row['message'];
            }
        }
    } else {
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            $line = preg_replace('/^\d+[\).\-\:]\s*/', '', $line);
            $line = trim($line, " \t\"'`");
            if ($line !== '') {
                $items[] = $line;
            }
        }
    }

    $clean = [];
    $seen = [];
    foreach ($items as $item) {
        $item = trim((string) $item);
        $item = trim($item, "\"'`");
        $item = preg_replace('/\s+/', ' ', $item);
        if ($item === '') {
            continue;
        }
        if (function_exists('mb_strlen') && mb_strlen($item) > 130) {
            $item = rtrim(mb_substr($item, 0, 127)) . '...';
        } elseif (strlen($item) > 130) {
            $item = rtrim(substr($item, 0, 127)) . '...';
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($item) : strtolower($item);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $clean[] = $item;
        if (count($clean) >= $wanted) {
            break;
        }
    }

    return $clean;
}

function kiraApiKeys($extraKey = '') {
    $keys = [];
    $extraKey = trim((string) $extraKey);
    if ($extraKey !== '' && stripos($extraKey, 'kira_') === 0) {
        $keys[] = $extraKey;
    }
    for ($i = 1; $i <= 6; $i++) {
        $name = 'KIRA_API_KEY_' . $i;
        if (defined($name) && constant($name) !== '') {
            $keys[] = constant($name);
        }
    }
    return array_values(array_unique($keys));
}

function kiraCursorFile() {
    return LOGS_DIR . 'kira_key_cursor.txt';
}

function kiraReadCursor($count) {
    if ($count < 1) {
        return 0;
    }
    $file = kiraCursorFile();
    $raw = is_file($file) ? trim((string) file_get_contents($file)) : '0';
    $cursor = (int) $raw;
    if ($cursor < 0 || $cursor >= $count) {
        return 0;
    }
    return $cursor;
}

function kiraWriteCursor($index, $count) {
    if ($count < 1) {
        return;
    }
    if (!is_dir(LOGS_DIR)) {
        @mkdir(LOGS_DIR, 0755, true);
    }
    @file_put_contents(kiraCursorFile(), (string) (($index % $count + $count) % $count));
}

function kiraShouldFailover($httpCode) {
    return in_array((int) $httpCode, [400, 401, 402, 403, 408, 422, 429, 500, 502, 503, 504], true);
}

function generateMessageWithKira($prompt, $maxTokens = 80, $extraKey = '') {
    $keys = kiraApiKeys($extraKey);
    if ($keys === []) {
        throw new Exception('No Kira API keys configured.');
    }

    $start = kiraReadCursor(count($keys));
    $lastError = 'All Kira keys failed.';

    for ($i = 0; $i < count($keys); $i++) {
        $index = ($start + $i) % count($keys);
        $key = $keys[$index];
        try {
            $text = callKiraOnce($key, $prompt, $maxTokens);
            kiraWriteCursor($index + 1, count($keys));
            return $text;
        } catch (Exception $e) {
            $lastError = $e->getMessage();
            $code = (int) $e->getCode();
            if ($code > 0 && !kiraShouldFailover($code)) {
                throw $e;
            }
            kiraWriteCursor($index + 1, count($keys));
        }
    }

    throw new Exception($lastError);
}

function callKiraOnce($apiKey, $prompt, $maxTokens) {
    $base = defined('KIRA_BASE_URL') ? KIRA_BASE_URL : 'https://kiraai.vn/api/v1';
    $model = defined('KIRA_MODEL') ? KIRA_MODEL : 'kira-mini-1.0';
    $url = rtrim($base, '/') . '/chat/completions';

    $payload = json_encode([
        'model' => $model,
        'temperature' => 0.95,
        'max_tokens' => $maxTokens,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You write short, gender-neutral Facebook Messenger texts for a casino page. Return only what was asked.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Could not reach Kira. Check your connection.', 503);
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $errorMsg = $data['error']['message'] ?? ('Kira request failed (HTTP ' . $httpCode . ').');
        throw new Exception($errorMsg, $httpCode ?: 502);
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    if (trim($text) === '') {
        throw new Exception('Kira did not return a message.', 502);
    }

    return $text;
}

function generateMessageWithOpenAI($apiKey, $prompt, $maxTokens = 80) {
    $payload = json_encode([
        'model' => 'gpt-4o-mini',
        'temperature' => 0.95,
        'max_tokens' => $maxTokens,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You write short, gender-neutral Facebook Messenger texts for a casino page. Return only what was asked.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ]
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Could not reach OpenAI. Check your connection.');
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $errorMsg = $data['error']['message'] ?? 'OpenAI request failed.';
        throw new Exception($errorMsg);
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    if (trim($text) === '') {
        throw new Exception('OpenAI did not return a message.');
    }

    return $text;
}

function generateMessageWithGemini($apiKey, $prompt, $maxTokens = 80) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
    $payload = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.95,
            'maxOutputTokens' => $maxTokens
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Could not reach Gemini. Check your connection.');
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $errorMsg = $data['error']['message'] ?? 'Gemini request failed.';
        throw new Exception($errorMsg);
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (trim($text) === '') {
        throw new Exception('Gemini did not return a message.');
    }

    return $text;
}
?>

