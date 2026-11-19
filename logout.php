
<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Record logout activity.
$user = current_user();

if ($user) {
    log_audit(
        db(),
        (int) $user['id'],
        'Logout',
        'User logged out.'
    );
}

// Clear session data.
$_SESSION = [];

// Remove session cookie.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session.
session_destroy();

// Return to login page.
header('Location: /erp-helpdesk/login.php');
exit;
