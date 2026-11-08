<?php
$user = current_user();
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | IT Help Desk</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="dashboard.php"><span class="brand-mark">HD</span><span>IT Help Desk</span></a>
        <div class="sidebar-label">WORKSPACE</div>
        <nav>
            <a href="dashboard.php">▦ <span>Dashboard</span></a>
            <a href="tickets.php">▤ <span>Tickets</span></a>
            <?php if (is_admin()): ?>
                <a href="users.php">♙ <span>User Management</span></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="user-mini">
                <div class="avatar"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
                <div><strong><?= e($user['name'] ?? '') ?></strong><small><?= e(str_replace('_', ' ', ucfirst($user['role'] ?? ''))) ?></small></div>
            </div>
            <a class="logout-link" href="logout.php">↪ Sign out</a>
        </div>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" type="button" aria-label="Toggle menu">☰</button>
            <div><span class="eyebrow">SUPPORT CENTER</span><h1><?= e($pageTitle) ?></h1></div>
            <div class="topbar-right"><span class="status-dot"></span> System workspace</div>
        </header>
        <section class="page-content">
            <?php show_flash(); ?>
