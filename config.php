<?php
/**
 * Configuration file for Facebook Messenger Bulk Sender
 * 
 * IMPORTANT: Configure your Facebook app credentials below.
 */

// Start session with proper configuration
if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie settings for proper persistence
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    
    session_start();
}

// Set timezone
date_default_timezone_set('Asia/Kolkata');

// ============================================
// FACEBOOK APP CREDENTIALS (from Meta for Developers)
// ============================================
define('FB_APP_ID', '823988713821961');
define('FB_APP_SECRET', '50ff38c79b1596be066568a2a2801bf8');

// OAuth redirect URI follows the host the browser/app actually opened.
// Local XAMPP: http://localhost/automation/fb-callback.php
// Phone on Wi-Fi: http://YOUR-LAN-IP/automation/fb-callback.php
// Live HTTPS: https://your-domain.com/fb-callback.php (or /automation/...)
// Add every URL you use in Meta → Facebook Login → Valid OAuth Redirect URIs
if (!function_exists('bulkzen_app_base_url')) {
    function bulkzen_app_base_url() {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = $forwarded === 'https'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
        $scheme = $https ? 'https' : 'http';
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/automation/index.php'));
        if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
            $path = '';
        } else {
            $path = rtrim($dir, '/');
        }
        return $scheme . '://' . $host . $path;
    }
}
define('FB_REDIRECT_URI', rtrim(bulkzen_app_base_url(), '/') . '/fb-callback.php');

// Facebook Graph API version (update as needed when Meta releases new versions)
define('FB_GRAPH_API_VERSION', 'v21.0');

// ============================================
// FILE SYSTEM PATHS
// ============================================
define('UPLOADS_DIR', __DIR__ . '/uploads/');
define('LOGS_DIR', __DIR__ . '/logs/');
define('BROADCAST_STOP_FLAG', LOGS_DIR . '/broadcast_stop.flag');

// ============================================
// APPLICATION SETTINGS
// ============================================
// Base URL of your application (optional, used for generating image URLs)
// Example: 'https://yourdomain.com' or leave empty for auto-detection
define('BASE_URL', '');

// Maximum file upload size for images (in bytes, 5MB default)
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);

// Delay between messages in seconds (natural pacing, avoids looking automated)
define('MESSAGE_DELAY_SECONDS', 3);

// ============================================
// AI MESSAGE GENERATOR (Kira free keys + failover)
// Same vault as darkbard: rotate if one key is busy / out of daily credits
// ============================================
define('AI_API_PROVIDER', 'kira');
define('AI_API_KEY', '');
define('KIRA_BASE_URL', 'https://kiraai.vn/api/v1');
define('KIRA_MODEL', 'kira-mini-1.0');
define('KIRA_API_KEY_1', 'kira_e1bce6666050ada190cb06e70167035d');
define('KIRA_API_KEY_2', 'kira_1168c3d84f8fe5532510dc7f774bd9b0');
define('KIRA_API_KEY_3', 'kira_5f761fad975eab0f6f0d700a30f53040');
define('KIRA_API_KEY_4', 'kira_ffe60352d3cbe3fe9d03a4b558839ddb');
define('KIRA_API_KEY_5', 'kira_a91dab219015935d0bdfad455e984529');
define('KIRA_API_KEY_6', 'kira_7897162b0d76fac42236acc2332671da');

// ============================================
// SUBSCRIPTION & PAYMENT SETTINGS
// ============================================
define('FREE_TIER_LIMIT', 500); // Free messages per page
define('STANDARD_PRICE', 0.30); // 5000 Global Credits (Testing: 0.30, Production: 10.00)
define('UNLIMITED_PRICE', 0.30); // Unlimited Messages (Testing: 0.30, Production: 49.99)
define('STANDARD_CREDIT_LIMIT', 5000);
define('PREMIUM_PRICE', 0.30);   // Default/Legacy (kept for safety)
define('BINANCE_API_KEY', '92PpYzDXfGmgti5BczaGmpRsXSCK4kj3RoEe9EiBUvKLleFgRW0YI7P1JnoVtUt7');
define('BINANCE_SECRET_KEY', 'ZWZeRACFh3BJQjzTZubsXuTBLaEZECS7Zzekr7smrUfrjgqPig6WedZnxFKa7ANK');
define('BINANCE_MERCHANT_ID', ''); // Optional

// ============================================
// SECURITY NOTES
// ============================================
// - This tool must be used only by authorized admins
// - Must respect Meta's 24-hour messaging policy
// - Do not send spam or unsolicited messages
// - Ensure uploads/ and logs/ directories are writable (chmod 755 or 777)
// - Consider adding .htaccess protection to these directories in production
