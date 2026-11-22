
<?php
$user = current_user();
$pageTitle = $pageTitle ?? 'Dashboard';

$role = strtolower(str_replace(
    [' ', '-'],
    '_',
    trim($user['role'] ?? '')
));

$isUser = ($role === 'user');
$isStaff = ($role === 'it_staff');
$isAdmin = function_exists('is_admin') && is_admin();

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | IT Help Desk</title>

    <!-- Global shared design system -->
    <link rel="stylesheet" href="/erp-helpdesk/assets/css/shared.css">

    <!-- Role-specific stylesheets -->
    <?php if ($isAdmin): ?>
        <link rel="stylesheet" href="/erp-helpdesk/assets/css/admin.css">
    <?php elseif ($isStaff): ?>
        <link rel="stylesheet" href="/erp-helpdesk/assets/css/it-staff.css">
    <?php elseif ($isUser): ?>
        <link rel="stylesheet" href="/erp-helpdesk/assets/css/user.css">
    <?php endif; ?>
</head>

<body>

<div class="app-shell">

    <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

    <aside class="sidebar" id="sidebar">

        <a class="brand" href="/erp-helpdesk/">
            <span class="brand-mark">HD</span>
            <span>IT Help Desk</span>
        </a>

        <div class="sidebar-label">WORKSPACE</div>

        <nav>

            <a href="dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                </span>
                <span>Dashboard</span>
            </a>

            <?php if ($isUser): ?>

                <a href="create_ticket.php" class="<?= $currentPage === 'create_ticket.php' ? 'active' : '' ?>">
                    <span class="nav-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="16"></line>
                            <line x1="8" y1="12" x2="16" y2="12"></line>
                        </svg>
                    </span>
                    <span>Create Ticket</span>
                </a>

                <a href="tickets.php" class="<?= in_array($currentPage, ['tickets.php', 'ticket_view.php'], true) ? 'active' : '' ?>">
                    <span class="nav-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                    </span>
                    <span>My Tickets</span>
                </a>

                <a href="profile.php" class="<?= $currentPage === 'profile.php' ? 'active' : '' ?>">
                    <span class="nav-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                    <span>My Profile</span>
                </a>

            <?php else: ?>

                <a href="tickets.php" class="<?= in_array($currentPage, ['tickets.php', 'ticket_view.php'], true) ? 'active' : '' ?>">
                    <span class="nav-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                    </span>
                    <span>Tickets</span>
                </a>

                <?php if ($isAdmin): ?>
                    <a href="users.php" class="<?= $currentPage === 'users.php' ? 'active' : '' ?>">
                        <span class="nav-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </span>
                        <span>User Management</span>
                    </a>
                <?php endif; ?>

            <?php endif; ?>

        </nav>

        <div class="sidebar-bottom">

            <div class="user-mini">

                <div class="avatar">
                    <?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?>
                </div>

                <div>
                    <strong><?= e($user['name'] ?? '') ?></strong>

                    <small>
                        <?= e(str_replace('_', ' ', ucfirst($user['role'] ?? ''))) ?>
                    </small>
                </div>

            </div>

            <a class="logout-link" href="/erp-helpdesk/logout.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                <span>Sign out</span>
            </a>

        </div>

    </aside>

    <main class="main-content">

        <header class="topbar">

            <button
                class="menu-toggle"
                id="menuToggle"
                type="button"
                aria-label="Toggle navigation menu"
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>

            <div>
                <span class="eyebrow">SUPPORT CENTER</span>
                <h1><?= e($pageTitle) ?></h1>
            </div>

            <div class="topbar-right">
                <span class="status-dot"></span>
                <span>System workspace</span>
            </div>

        </header>

        <section class="page-content">

            <?php show_flash(); ?>
