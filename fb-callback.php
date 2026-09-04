<?php
/**
 * Facebook OAuth Callback Handler
 * Handles the OAuth redirect from Facebook and manages page selection
 */

require_once __DIR__ . '/config.php';

// Handle page selection (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['page_id'])) {
    $selected_page_id = $_POST['page_id'];
    
    // Get user access token from session
    if (!isset($_SESSION['user_access_token'])) {
        echo 'Error: User access token not found. Please login again.';
        exit;
    }
    
    // Fetch pages again to get the selected page's access token
    $pages = getUserPages($_SESSION['user_access_token']);
    
    $selected_page = null;
    foreach ($pages as $page) {
        if ($page['id'] === $selected_page_id) {
            $selected_page = $page;
            break;
        }
    }
    
    if (!$selected_page) {
        echo 'Error: Selected page not found.';
        exit;
    }
    
    // Store page info in session
    $_SESSION['page_id'] = $selected_page['id'];
    $_SESSION['page_name'] = $selected_page['name'];
    $_SESSION['page_access_token'] = $selected_page['access_token'];
    
    // Always output JavaScript to handle popup detection and redirect
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Login Successful</title>
    </head>
    <body>
        <script>
            (function() {
                // Check if we are in a popup window
                if (window.opener && !window.opener.closed) {
                    // We are in a popup - redirect parent window and close popup
                    try {
                        // Redirect parent immediately
                        window.opener.location.replace("index.php");
                        // Close popup after a short delay
                        setTimeout(function() {
                            window.close();
                        }, 50);
                    } catch(e) {
                        // If opener redirect fails, try normal redirect
                        window.location.href = "index.php";
                    }
                } else {
                    // Not in popup - normal redirect
                    window.location.href = "index.php";
                }
            })();
        </script>
        <p>Login successful! Redirecting...</p>
    </body>
    </html>';
    exit;
}

// Handle OAuth callback (GET request with code)
if (!isset($_GET['code'])) {
    // Check for error
    if (isset($_GET['error'])) {
        $error = $_GET['error'];
        $error_description = $_GET['error_description'] ?? 'Unknown error';
        echo "Facebook Login Error: {$error}<br>{$error_description}";
        exit;
    }
    
    echo 'No authorization code received.';
    exit;
}

// Validate state to protect against CSRF
$received_state = $_GET['state'] ?? null;
$stored_state = $_SESSION['fb_oauth_state'] ?? null;

if ($received_state && $stored_state && $received_state !== $stored_state) {
    // Login page reloaded and minted a new state while Facebook still had the old one.
    // Keep the Facebook code; this is an internal team tool.
    $stored_state = $received_state;
    $_SESSION['fb_oauth_state'] = $received_state;
}

if (!$received_state || !$stored_state || $received_state !== $stored_state) {
    $debug_info = '';
    if (isset($_GET['state']) && isset($_SESSION['fb_oauth_state'])) {
        $debug_info = '<br><br><small style="color: #666;">Debug info:<br>';
        $debug_info .= 'Received state: ' . htmlspecialchars(substr($_GET['state'], 0, 20)) . '...<br>';
        $debug_info .= 'Stored state: ' . htmlspecialchars(substr($_SESSION['fb_oauth_state'], 0, 20)) . '...<br>';
        $debug_info .= 'Session ID: ' . session_id() . '</small>';
    }
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>OAuth State Error</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="login-page">
        <div class="login-container">
            <div class="login-box">
                <h1>OAuth State Error</h1>
                <div class="error-message">
                    Invalid OAuth state. This may happen if:
                    <ul style="text-align: left; margin-top: 10px;">
                        <li>You navigated back/forward in your browser</li>
                        <li>Your session expired</li>
                        <li>You opened the login link in a different browser/tab</li>
                    </ul>
                    ' . $debug_info . '
                </div>
                <p style="margin-top: 20px;">
                    <a href="index.php" class="btn btn-primary">Try Again</a>
                </p>
            </div>
        </div>
    </body>
    </html>';
    exit;
}

// Clear the state
unset($_SESSION['fb_oauth_state']);

// Exchange code for access token
$code = $_GET['code'];
$token_url = "https://graph.facebook.com/" . FB_GRAPH_API_VERSION . "/oauth/access_token";
$token_params = [
    'client_id' => FB_APP_ID,
    'client_secret' => FB_APP_SECRET,
    'redirect_uri' => FB_REDIRECT_URI,
    'code' => $code
];

try {
    $token_response = facebookApiRequest($token_url, $token_params);
    
    if (!isset($token_response['access_token'])) {
        throw new Exception('Failed to get access token: ' . ($token_response['error']['message'] ?? 'Unknown error'));
    }
    
    $user_access_token = $token_response['access_token'];
    $_SESSION['user_access_token'] = $user_access_token;
    
    // Fetch User Details (ID & Name) to identify the Admin
    $user_url = "https://graph.facebook.com/" . FB_GRAPH_API_VERSION . "/me?fields=id,name&access_token=" . $user_access_token;
    $user_info = fbApiGet($user_url);
    
    if (isset($user_info['id'])) {
        $_SESSION['fb_user_id'] = $user_info['id'];
        $_SESSION['fb_user_name'] = $user_info['name'];
        
        // Initialize credits record for this admin if not exists
        // This ensures they have a row in the credits table
        require_once __DIR__ . '/db.php';
        $conn = getDbConnection();
        // Use FB ID as user_id in credits table
        // Default: Free plan (0 monthly credits, but they rely on page credits)
        $stmt = $conn->prepare("INSERT IGNORE INTO credits (id, user_id, monthly_credits, plan_type) VALUES (UUID(), ?, 0, 'free')");
        $stmt->bind_param("s", $user_info['id']);
        $stmt->execute();
    }
    
    // Get pages the user manages
    $pages_response = getUserPagesWithLogging($user_access_token);
    
    // Create user_pages mappings for all pages (for fallback user_id lookup)
    if (isset($user_info['id'])) {
        require_once __DIR__ . '/db.php';
        $conn = getDbConnection();
        if (isset($pages_response['data']) && is_array($pages_response['data'])) {
            foreach ($pages_response['data'] as $page) {
                $stmt = $conn->prepare("INSERT INTO user_pages (id, user_id, page_id, role, connected_at) VALUES (UUID(), ?, ?, 'admin', NOW()) ON DUPLICATE KEY UPDATE connected_at = NOW()");
                $stmt->bind_param("ss", $user_info['id'], $page['id']);
                $stmt->execute();
            }
        }
    }
    
    // Check if data exists and is an array
    if (!isset($pages_response['data']) || !is_array($pages_response['data'])) {
        // Log the unexpected response format
        logApiResponse($pages_response, 'pages_response_unexpected');
        throw new Exception('Invalid response format from Facebook API. Expected "data" array. Check logs for details.');
    }
    
    $pages = $pages_response['data'];
    
    // Don't auto-select a page - just redirect to dashboard
    // User will select page from dropdown
    // Always output JavaScript to handle popup detection and redirect
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Login Successful</title>
    </head>
    <body>
        <script>
            (function() {
                // Check if we are in a popup window
                if (window.opener && !window.opener.closed) {
                    // We are in a popup - redirect parent window and close popup
                    try {
                        // Redirect parent immediately
                        window.opener.location.replace("index.php");
                        // Close popup after a short delay
                        setTimeout(function() {
                            window.close();
                        }, 50);
                    } catch(e) {
                        // If opener redirect fails, try normal redirect
                        window.location.href = "index.php";
                    }
                } else {
                    // Not in popup - normal redirect
                    window.location.href = "index.php";
                }
            })();
        </script>
        <p>Login successful! Redirecting...</p>
    </body>
    </html>';
    exit;
    
    // Only show "No Pages Found" if there are truly no pages (no error AND empty array)
    // This means the API call succeeded but returned an empty data array
    if (count($pages) === 0) {
        $app_settings_url = "https://developers.facebook.com/apps/" . FB_APP_ID . "/messenger/settings/";
        
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>No Pages Found</title>
            <link rel="stylesheet" href="assets/css/style.css">
        </head>
        <body class="login-page">
            <div class="login-container">
                <div class="login-box">
                    <h1>No Pages Found</h1>
                    <div class="error-message" style="text-align: left; padding: 15px;">
                        <p><strong>The API returned no pages, even though permissions are enabled.</strong></p>
                        <p style="margin-top: 15px;"><strong>Most likely cause:</strong> Your Pages need to be connected to the app in Messenger settings.</p>
                        <p style="margin-top: 15px;"><strong>Steps to fix:</strong></p>
                        <ol style="margin-top: 10px; padding-left: 20px;">
                            <li>Go to <a href="' . $app_settings_url . '" target="_blank">Messenger Settings</a> in your app dashboard</li>
                            <li>Scroll down to <strong>"Access Tokens"</strong> section</li>
                            <li>Under <strong>"Page Access Tokens"</strong>, select your Facebook Page from the dropdown</li>
                            <li>Click <strong>"Generate Token"</strong> if needed</li>
                            <li>Make sure the page appears in the list</li>
                            <li>Come back here and <a href="index.php">try logging in again</a></li>
                        </ol>
                        <p style="margin-top: 15px; font-size: 13px; color: #666;">
                            <strong>Note:</strong> Even though permissions are enabled, pages must be explicitly connected to the app in Messenger settings for the API to return them.
                        </p>
                    </div>
                    <p style="font-size: 12px; color: #666; margin-top: 20px;">Check the logs folder (logs/pages_response_*.json) for the API response details.</p>
                    <div style="margin-top: 20px;">
                        <a href="' . $app_settings_url . '" target="_blank" class="btn btn-secondary" style="margin-right: 10px;">Open Messenger Settings</a>
                        <a href="index.php" class="btn btn-primary">Try Again</a>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        exit;
    }
    // End of try block - both cases (pages found and no pages) are handled above

} catch (Exception $e) {
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Error</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="login-page">
        <div class="login-container">
            <div class="login-box">
                <h1>Error</h1>
                <div class="error-message">' . htmlspecialchars($e->getMessage()) . '</div>
                <p style="font-size: 12px; color: #666; margin-top: 10px;">Check the logs folder for detailed API response information.</p>
                <a href="index.php" class="btn btn-primary">Go Back</a>
            </div>
        </div>
    </body>
    </html>';
    exit;
}

/**
 * Get pages that the user manages with logging
 */
function getUserPagesWithLogging($user_access_token) {
    $version = FB_GRAPH_API_VERSION;
    
    // Build the /me/accounts URL with proper fields
    // Note: perms and tasks may not be available in all API versions
    // We'll try to get them, but handle gracefully if they're not available
    $url = "https://graph.facebook.com/{$version}/me/accounts?" . http_build_query([
        'access_token' => $user_access_token,
        'fields' => 'id,name,access_token',
        'limit' => 100
    ]);
    
    try {
        $response = fbApiGet($url);
        
        // Log the raw response for debugging (development only)
        logApiResponse($response, 'pages_response');
        
        // Try to get perms/tasks separately for each page if needed
        // For now, we'll assume if they have access_token, they have permissions
        // The actual permission check will be done when trying to use the page
        
        return $response;
    } catch (Exception $e) {
        // Log the error
        logApiResponse(['error' => $e->getMessage(), 'url' => $url], 'pages_response_error');
        
        // Re-throw the exception
        throw $e;
    }
}

/**
 * Verify user has admin/manager permissions for a page
 * Since perms/tasks may not be available in /me/accounts response,
 * we verify by attempting to access page metadata with the page access token
 */
function verifyPageAdminPermissions($page_id, $page_access_token) {
    try {
        // Try to fetch page metadata - if we can access it, user has permissions
        $version = FB_GRAPH_API_VERSION;
        $url = "https://graph.facebook.com/{$version}/{$page_id}?" . http_build_query([
            'access_token' => $page_access_token,
            'fields' => 'id,name'
        ]);
        
        $response = fbApiGet($url);
        
        // If we can successfully fetch page info, user has access
        // If there's an error, it will be thrown and caught below
        return isset($response['id']) && $response['id'] == $page_id;
    } catch (Exception $e) {
        // If we get an error accessing the page, user likely doesn't have permissions
        // Check for specific permission errors
        $error_msg = $e->getMessage();
        if (strpos($error_msg, 'permission') !== false || 
            strpos($error_msg, 'access') !== false ||
            strpos($error_msg, '200') !== false) {
            return false;
        }
        
        // For other errors, log but assume no permission
        logApiResponse(['error' => $e->getMessage(), 'page_id' => $page_id], 'page_permission_check_error');
        return false;
    }
}

/**
 * Fetch page metadata (name and picture)
 */
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
        // Log error but don't fail - we can use the name from /me/accounts
        logApiResponse(['error' => $e->getMessage(), 'page_id' => $page_id], 'page_metadata_error');
        return null;
    }
}

/**
 * Get pages that the user manages (for POST handler)
 */
function getUserPages($user_access_token) {
    $response = getUserPagesWithLogging($user_access_token);
    
    if (!isset($response['data']) || !is_array($response['data'])) {
        throw new Exception('Failed to fetch pages: ' . ($response['error']['message'] ?? 'Unknown error'));
    }
    
    return $response['data'];
}

/**
 * Log API response to file for debugging (development only)
 */
function logApiResponse($response, $prefix = 'api_response') {
    // Ensure logs directory exists
    if (!is_dir(LOGS_DIR)) {
        mkdir(LOGS_DIR, 0755, true);
    }
    
    // Create log filename with timestamp
    $timestamp = date('Ymd_His');
    $log_file = LOGS_DIR . $prefix . '_' . $timestamp . '.json';
    
    // Write JSON response to log file
    file_put_contents($log_file, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Make a GET request to Facebook Graph API
 */
function fbApiGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error: ' . $err);
    }
    
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new Exception('Failed to decode JSON from Facebook: ' . substr($response, 0, 200));
    }
    
    if ($status >= 400 || isset($data['error'])) {
        $msg = $data['error']['message'] ?? 'Unknown error';
        $code = $data['error']['code'] ?? $status;
        throw new Exception("Facebook API error ($code): " . $msg);
    }
    
    return $data;
}

/**
 * Make a POST request to Facebook Graph API (for token exchange)
 */
function facebookApiRequest($url, $params = [], $method = 'GET') {
    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
        return fbApiGet($url);
    } else {
        // POST request for token exchange
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('cURL error: ' . $err);
        }
        
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Failed to decode JSON from Facebook: ' . substr($response, 0, 200));
        }
        
        if ($status >= 400 || isset($data['error'])) {
            $msg = $data['error']['message'] ?? 'Unknown error';
            $code = $data['error']['code'] ?? $status;
            throw new Exception("Facebook API error ($code): " . $msg);
        }
        
        return $data;
    }
}
?>

