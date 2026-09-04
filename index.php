<?php
/**
 * Main entry point for Facebook Messenger Bulk Sender
 * Handles Facebook OAuth login and displays the dashboard
 */

require_once __DIR__ . '/config.php';

// Check if user is logged in with Facebook (has user_access_token)
$is_logged_in = isset($_SESSION['user_access_token']);
$has_page_selected = isset($_SESSION['page_id']) && isset($_SESSION['page_access_token']);

// If not logged in, show Facebook Login
if (!$is_logged_in) {
    if (empty($_SESSION['fb_oauth_state'])) {
        $_SESSION['fb_oauth_state'] = bin2hex(random_bytes(16));
    }
    $state = $_SESSION['fb_oauth_state'];
    session_write_close();

    $fbLoginUrl = 'https://www.facebook.com/' . FB_GRAPH_API_VERSION . '/dialog/oauth?' . http_build_query([
        'client_id' => FB_APP_ID,
        'redirect_uri' => FB_REDIRECT_URI,
        'scope' => 'public_profile,pages_show_list,pages_read_engagement,pages_messaging,pages_manage_metadata',
        'response_type' => 'code',
        'state' => $state,
    ]);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
        <title>Log in to Facebook</title>
        
        <!-- SEO Meta Tags -->
        <meta name="description" content="BulkZen is a professional Facebook Messenger bulk sender for pages and businesses. Send compliant mass messages to customers, manage conversations, and track live delivery from one powerful dashboard at bulkzen.com.">
        <meta name="keywords" content="BulkZen, bulkzen.com, facebook messenger bulk sender, facebook bulk message, mass message facebook, facebook automation, messenger bulk tool, facebook page messaging, send bulk messages facebook, facebook messenger marketing, facebook broadcast tool, messenger automation, bulk messaging software, facebook business tools, messenger bulk sender free, facebook mass messenger, facebook page bulk message, messenger broadcast, facebook customer messaging, facebook messenger bot, bulk message app, facebook marketing automation, messenger bulk sender 2024">
        <meta name="author" content="BulkZen">
        <meta name="robots" content="index, follow">
        <meta name="language" content="English">
        <meta name="revisit-after" content="7 days">
        
        <!-- Open Graph / Facebook Meta Tags -->
        <meta property="og:type" content="website">
        <meta property="og:title" content="BulkZen - Facebook Messenger Bulk Sender">
        <meta property="og:description" content="BulkZen lets you send compliant bulk messages to all customers who interacted with your Facebook Page, with live progress tracking and instant stop controls.">
        <meta property="og:url" content="https://bulkzen.com/">
        <meta property="og:site_name" content="BulkZen">
        <meta property="og:image" content="https://bulkzen.com/logow.png">
        
        <!-- Twitter Meta Tags -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="BulkZen - Facebook Messenger Bulk Sender">
        <meta name="twitter:description" content="BulkZen helps you reach every customer who messaged your Facebook Page with fast, reliable bulk messaging.">
        <meta name="twitter:image" content="https://bulkzen.com/og-image.png">
        
        <!-- Additional SEO -->
        <meta name="theme-color" content="#18191A">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="BulkZen">
        <!-- Canonical: always point to primary production domain -->
        <link rel="canonical" href="https://bulkzen.com/">
        
        <!-- Favicon -->
        <link rel="icon" type="image/png" href="fav.png" sizes="16x16">
        <link rel="icon" type="image/png" href="fav.png" sizes="32x32">
        <link rel="icon" type="image/png" href="fav.png" sizes="48x48">
        <link rel="icon" type="image/png" href="fav.png" sizes="64x64">
        <link rel="icon" type="image/png" href="fav.png" sizes="96x96">
        <link rel="shortcut icon" href="fav.png">
        <link rel="apple-touch-icon" href="appicon.png" sizes="180x180">
        <link rel="icon" type="image/png" href="appicon.png" sizes="192x192">
        <link rel="icon" type="image/png" href="appicon.png" sizes="512x512">
        
        <link rel="stylesheet" href="assets/css/style.css">
        <style>
            .fb-mark svg, .fb-login-button svg { width: 1.1em; height: 1.1em; fill: currentColor; display: inline-block; vertical-align: -0.15em; }
        </style>
    </head>
    <body class="login-page connect-only fb-login-look">
        <main class="fb-login-shell">
            <div class="fb-login-brand">
                <div class="fb-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.84c0-2.5 1.49-3.89 3.78-3.89 1.1 0 2.24.2 2.24.2v2.47h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.44 2.91h-2.34V22c4.78-.76 8.44-4.92 8.44-9.94z"/></svg>
                </div>
                <h1>facebook</h1>
                <p>Log in to open your page inbox and send messages.</p>
            </div>
            <div class="login-card connect-card fb-login-card">
                <button class="fb-login-button" id="fb-login-button" onclick="loginWithFacebook()">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.84c0-2.5 1.49-3.89 3.78-3.89 1.1 0 2.24.2 2.24.2v2.47h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.44 2.91h-2.34V22c4.78-.76 8.44-4.92 8.44-9.94z"/></svg>
                    <span>Log in with Facebook</span>
                </button>
                <p class="connect-note">Log in with Facebook inside the app. Team use only.</p>
            </div>
        </main>

        <script>
            function loginWithFacebook() {
                window.location.href = <?php echo json_encode($fbLoginUrl); ?>;
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// User is logged in, show dashboard
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>BulkZen</title>
    
    <!-- SEO Meta Tags -->
    <meta name="description" content="BulkZen Dashboard - Send bulk messages to your Facebook Page customers. Real-time progress tracking, instant stop control, and automated message delivery.">
    <meta name="keywords" content="bulkzen, messenger bulk sender dashboard, facebook bulk message tool, send bulk messages, facebook page messaging, messenger automation dashboard, bulk message sender, facebook mass message, messenger broadcast tool">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#18191A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="fav.png" sizes="16x16">
    <link rel="icon" type="image/png" href="fav.png" sizes="32x32">
    <link rel="icon" type="image/png" href="fav.png" sizes="48x48">
    <link rel="icon" type="image/png" href="fav.png" sizes="64x64">
    <link rel="icon" type="image/png" href="fav.png" sizes="96x96">
    <link rel="shortcut icon" href="fav.png">
    <link rel="apple-touch-icon" href="appicon.png" sizes="180x180">
    <link rel="icon" type="image/png" href="appicon.png" sizes="192x192">
    <link rel="icon" type="image/png" href="appicon.png" sizes="512x512">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app.js"></script>
</head>
<body class="dashboard-page">
    <div class="dashboard-container">
        <!-- Top Bar -->
        <header class="top-bar">
            <div class="top-bar-content">
                <div class="top-left">
                    <?php
                    $pageName = $_SESSION['page_name'] ?? 'Select a Page';
                    $pagePic = $_SESSION['page_picture_url'] ?? null;
                    $pageId = $_SESSION['page_id'] ?? '';
                    ?>
                    <!-- Mobile: Logo and brand name (appears first in mobile) -->
                    <div class="dashboard-logo-mobile mobile-only">
                        <img src="appicon.png" alt="BulkZen" class="dashboard-logo">
                        <span>BulkZen</span>
                    </div>
                    <?php if ($pagePic): ?>
                        <img src="<?php echo htmlspecialchars($pagePic); ?>" alt="Page Avatar" class="page-avatar">
                    <?php else: ?>
                        <div class="page-avatar placeholder">P</div>
                    <?php endif; ?>
                    <div class="page-info">
                        <div class="page-title desktop-only">
                            <img src="appicon.png" alt="BulkZen" class="dashboard-logo">
                            <span>BulkZen</span>
                        </div>
                        <div class="page-subtitle desktop-only">
                            <select id="page-selector" class="page-selector">
                                <?php if ($pageId): ?>
                                    <option value="<?php echo htmlspecialchars($pageId); ?>" selected>
                                        <?php echo htmlspecialchars($pageName); ?>
                                        (ID: <?php echo htmlspecialchars($pageId); ?>)
                                    </option>
                                <?php else: ?>
                                    <option value="" selected>Select a Page...</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <!-- Mobile: Page selector in header -->
                        <div class="page-subtitle mobile-only">
                            <select id="page-selector-header-mobile" class="page-selector-header-mobile">
                                <?php if ($pageId): ?>
                                    <option value="<?php echo htmlspecialchars($pageId); ?>" selected>
                                        <?php echo htmlspecialchars($pageName); ?>
                                    </option>
                                <?php else: ?>
                                    <option value="" selected>Select a Page...</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <p class="subtitle desktop-only">People who already messaged this Page</p>
                    </div>
                </div>
                <div class="top-right">
                    <div id="header-credits" class="header-credits desktop-only"></div>
                    <button class="nav-toggle dashboard-nav-toggle" id="dashboard-nav-toggle" aria-label="Toggle menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <button type="button" class="logout-btn desktop-only" id="logout-btn-desktop">Logout</button>
                </div>
            </div>
        </header>
        
        <!-- Dashboard Navigation Menu (App Drawer) -->
        <div class="nav-menu-overlay dashboard-nav-overlay" id="dashboard-nav-overlay"></div>
        <nav class="nav-menu dashboard-nav-menu" id="dashboard-nav-menu">
            <div class="nav-menu-header">
                <h3>Menu</h3>
            </div>
            <button type="button" class="nav-menu-item nav-menu-item-logout" id="logout-btn-mobile">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>
        </nav>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Left Panel: Conversations List -->
            <div class="panel conversations-panel">
                <div class="panel-header">
                    <h2>Conversations</h2>
                    <div class="summary-stats">
                        <span id="total-conversations">Total: 0</span>
                        <span id="eligible-count">Within 24h: 0</span>
                    </div>
                </div>
                
                <div class="panel-controls">
                    <input type="text" id="search-box" placeholder="Search by name or PSID..." class="search-input">
                    <div class="button-group">
                        <button id="select-all-eligible" class="btn btn-secondary">Select All</button>
                        <button id="select-none" class="btn btn-secondary">Select None</button>
                        <button id="refresh-conversations" class="btn btn-secondary">Refresh</button>
                    </div>
                </div>

                <div class="table-container">
                    <table id="conversations-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all-checkbox"></th>
                                <th>Avatar</th>
                                <th>Name</th>
                                <th>PSID</th>
                                <th>Last Message</th>
                                <th>Last Interaction</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="conversations-tbody">
                            <tr>
                                <td colspan="7" class="loading-message">Please select a page to start</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Panel: Message Composer & Progress -->
            <div class="panel composer-panel">
                <div class="panel-header">
                    <h2>Compose Message</h2>
                </div>

                <div class="composer-form">
                    <div class="mode-switch" id="compose-mode-switch" role="tablist" aria-label="Compose mode">
                        <button type="button" class="mode-tab" id="mode-tab-manual" data-mode="manual" role="tab" aria-selected="false">Manual</button>
                        <button type="button" class="mode-tab active" id="mode-tab-ai" data-mode="ai" role="tab" aria-selected="true">AI</button>
                        <span class="mode-slider" id="mode-slider" aria-hidden="true"></span>
                    </div>

                    <div class="mode-viewport" id="mode-viewport">
                        <div class="mode-track ai-active" id="mode-track">
                            <div class="mode-pane" id="manual-pane">
                                <div class="form-group">
                                    <label for="message-text">Message Text <span class="required">*</span></label>
                                    <textarea id="message-text" rows="6" placeholder="Write one message for a specific person. Use this only when you want the same text yourself." required></textarea>
                                    <div class="char-count"><span id="char-count">0</span> characters</div>
                                </div>
                            </div>

                            <div class="mode-pane" id="ai-pane">
                                <div class="ai-composer" id="ai-composer">
                                    <div class="ai-composer-top">
                                        <div class="ai-composer-title">
                                            <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
                                            <div>
                                                <strong>AI Message Studio</strong>
                                                <span>Writes one unique line per selected person. Review first, then send.</span>
                                            </div>
                                        </div>
                                    </div>
                                    <label for="ai-topic">What bonus or offer should each message talk about?</label>
                                    <textarea id="ai-topic" class="ai-topic-input" rows="3" placeholder="Example: 150% welcome bonus for new players, or 50 free spins if they have not played this week"></textarea>
                                    <div class="ai-tones" role="group" aria-label="Message tone">
                                        <button type="button" class="ai-tone-btn active" data-tone="friendly">Friendly</button>
                                        <button type="button" class="ai-tone-btn" data-tone="curious">Curious</button>
                                        <button type="button" class="ai-tone-btn" data-tone="warm">Warm</button>
                                        <button type="button" class="ai-tone-btn" data-tone="promo">Bonus</button>
                                    </div>
                                    <button type="button" class="btn ai-generate-btn" id="ai-generate-btn">
                                        <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
                                        <span id="ai-generate-label">Generate messages</span>
                                    </button>
                                    <p class="ai-status" id="ai-status" role="status"></p>
                                    <div class="ai-preview" id="ai-preview" hidden>
                                        <div class="ai-preview-title" id="ai-preview-heading">Preview · 0 unique lines</div>
                                        <div class="ai-preview-list" id="ai-preview-list"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="image-upload">Image (Optional)</label>
                        <input type="file" id="image-upload" accept=".jpg,.jpeg,.png" />
                        <small class="help-text">Accepted formats: JPG, JPEG, PNG (max 5MB)</small>
                        <div id="image-preview" class="image-preview" style="display: none;">
                            <img id="preview-img" src="" alt="Preview">
                            <button type="button" id="remove-image" class="btn-remove">Remove</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <button id="send-broadcast" class="btn btn-primary btn-large">
                            <span id="send-btn-text">Send Bulk Message</span>
                            <span id="send-btn-loading" style="display: none;">Sending...</span>
                        </button>
                        <button id="stop-broadcast" class="btn btn-danger btn-large" style="display: none; margin-top: 10px; width: 100%;">
                            Stop Sending Messages
                        </button>
                        <div id="selected-count" class="selected-count">0 users selected</div>
                        <p class="send-pace-hint">One person at a time, 3–4 seconds apart. AI mode never repeats the same line.</p>
                    </div>
                </div>

                <div class="progress-section">
                    <div class="panel-header">
                        <h3>Message Sending Progress</h3>
                    </div>
                    <div id="progress-log" class="progress-log">
                        <p class="empty-log">No broadcast started yet. Select users and compose a message to begin.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Confirmation Modal -->
    <div id="confirm-modal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3>Confirm Broadcast</h3>
            </div>
            <div class="modal-body">
                <div class="modal-icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" fill="currentColor"/>
                    </svg>
                </div>
                <p id="confirm-message">Are you sure you want to send this message?</p>
            </div>
            <div class="modal-footer">
                <button id="modal-cancel" class="btn-modal btn-cancel">Cancel</button>
                <button id="modal-confirm" class="btn-modal btn-confirm">Send</button>
            </div>
        </div>
    </div>
    
    <!-- Scroll Down Button -->
    <button id="scroll-down-btn" class="scroll-down-btn" aria-label="Scroll down">
        <img src="arrow.png" alt="Scroll down">
    </button>
    
    <!-- Success Notification -->
    <div id="success-notification" class="success-notification">
        <div class="notification-icon">
            <img src="check.png" alt="Success">
        </div>
        <div class="notification-content">
            <div class="notification-title">Messages Sent!</div>
            <div class="notification-message">All messages have been delivered successfully.</div>
        </div>
    </div>
    
    <!-- Notification Sound -->
    <audio id="notification-sound" preload="auto">
        <source src="notification.mp3" type="audio/mpeg">
    </audio>
    
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>

