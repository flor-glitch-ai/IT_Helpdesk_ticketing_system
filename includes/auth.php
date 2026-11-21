<?php
declare(strict_types=1);

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
}

function require_admin(): void
{
    require_login();
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access denied. Administrator permission is required.');
    }
}

function require_staff(): void
{
    require_login();
    if (!in_array($_SESSION['user']['role'] ?? '', ['admin', 'it_staff'], true)) {
        http_response_code(403);
        exit('Access denied. IT staff permission is required.');
    }
}
