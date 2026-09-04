<?php
/**
 * Logout handler - clears session and redirects to login
 */

require_once __DIR__ . '/config.php';

// Clear all session data
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: index.php');
exit;
?>

