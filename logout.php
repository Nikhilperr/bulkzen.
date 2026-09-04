<?php
/**
 * Logout handler - clears session and redirects to login
 */

require_once __DIR__ . '/config.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: index.php');
exit;
?>

