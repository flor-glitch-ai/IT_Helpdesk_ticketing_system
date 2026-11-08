<?php
declare(strict_types=1);

/*
 * Update these values to match your VirtualBox MySQL server.
 * DB_HOST must be the VirtualBox VM's reachable IP address, not localhost.
 */
define('DB_HOST', '192.168.56.10');
define('DB_NAME', 'erp_helpdesk');
define('DB_USER', 'helpdesk_user');
define('DB_PASS', 'CHANGE_THIS_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('Database connection error: ' . $e->getMessage());
        http_response_code(500);
        exit('Database connection failed. Check config/database.php and the VirtualBox MySQL network settings.');
    }
    return $pdo;
}
