<?php
require_once __DIR__ . '/config/database.php';
try {
    $pdo = db();
    echo '<h2>Database connection successful.</h2>';
    echo '<p>Connected database: ' . htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8') . '</p>';
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo '<h3>Tables found</h3><ul>';
    foreach ($tables as $table) echo '<li>' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '</li>';
    echo '</ul>';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Connection test failed.';
}
