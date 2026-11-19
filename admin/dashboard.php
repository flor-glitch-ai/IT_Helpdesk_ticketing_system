
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_admin();

$pdo = db();
$user = current_user();

// Dashboard statistics
$stats = [
    'all' => (int)$pdo->query(
        "SELECT COUNT(*) FROM tickets"
    )->fetchColumn(),

    'open' => (int)$pdo->query(
        "SELECT COUNT(*) FROM tickets
         WHERE status NOT IN ('Resolved','Closed')"
    )->fetchColumn(),

    'resolved' => (int)$pdo->query(
        "SELECT COUNT(*) FROM tickets
         WHERE status IN ('Resolved','Closed')"
    )->fetchColumn(),

    'critical' => (int)$pdo->query(
        "SELECT COUNT(*) FROM tickets
         WHERE priority = 'Critical'
         AND status NOT IN ('Resolved','Closed')"
    )->fetchColumn(),

    'users' => (int)$pdo->query(
        "SELECT COUNT(*)
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE r.name = 'user' AND u.is_active = 1"
    )->fetchColumn(),

    'staff' => (int)$pdo->query(
        "SELECT COUNT(*)
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE r.name = 'it_staff' AND u.is_active = 1"
    )->fetchColumn()
];

// Recent tickets
$stmt = $pdo->query("
    SELECT t.*,
           CONCAT(u.first_name, ' ', u.last_name) AS requester
    FROM tickets t
    LEFT JOIN users u ON u.id = t.requester_id
    ORDER BY t.created_at DESC
    LIMIT 8
");

$recent = $stmt->fetchAll();

$pageTitle = 'Admin Dashboard';

require __DIR__ . '/../includes/header.php';
?>

<div class="welcome-row">
    <div>
        <h2>Welcome, <?= e($user['name']) ?>.</h2>
        <p class="muted">
            Here's an overview of your IT Help Desk system.
        </p>
    </div>

    <a href="users.php" class="btn primary">
        + Manage Users
    </a>
</div>

<div class="stats-grid">

    <div class="stat-card">
        <span class="stat-icon blue">▤</span>
        <div>
            <small>Total Tickets</small>
            <strong><?= $stats['all'] ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon amber">◷</span>
        <div>
            <small>Active Tickets</small>
            <strong><?= $stats['open'] ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon green">✓</span>
        <div>
            <small>Resolved / Closed</small>
            <strong><?= $stats['resolved'] ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon red">!</span>
        <div>
            <small>Critical Issues</small>
            <strong><?= $stats['critical'] ?></strong>
        </div>
    </div>

</div>

<div class="stats-grid">

    <div class="stat-card">
        <span class="stat-icon blue">♙</span>
        <div>
            <small>Registered Users</small>
            <strong><?= $stats['users'] ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon green">♙</span>
        <div>
            <small>IT Staff</small>
            <strong><?= $stats['staff'] ?></strong>
        </div>
    </div>

</div>

<div class="section-heading">
    <div>
        <h2>Recent Tickets</h2>
        <p class="muted">Latest support requests in the system.</p>
    </div>

    <a href="tickets.php" class="text-link">
        View All Tickets →
    </a>
</div>

<div class="table-card">
<div class="table-scroll">
<table>
<thead>
<tr>
    <th>Ticket No.</th>
    <th>Subject</th>
    <th>Requester</th>
    <th>Priority</th>
    <th>Status</th>
    <th>Created</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$recent): ?>
<tr>
    <td colspan="7" class="empty">
        No tickets available.
    </td>
</tr>
<?php endif; ?>

<?php foreach ($recent as $t): ?>
<tr>
    <td class="ticket-no">
        <?= e($t['ticket_number']) ?>
    </td>

    <td>
        <strong><?= e($t['title']) ?></strong>
        <small class="table-sub">
            <?= e($t['category'] ?? '') ?>
        </small>
    </td>

    <td><?= e($t['requester'] ?? 'Unknown') ?></td>

    <td>
        <span class="priority <?= strtolower(e($t['priority'])) ?>">
            <?= e($t['priority']) ?>
        </span>
    </td>

    <td>
        <span class="status">
            <?= e($t['status']) ?>
        </span>
    </td>

    <td>
        <?= e(date('M j, Y', strtotime($t['created_at']))) ?>
    </td>

    <td>
        <a class="text-link"
           href="ticket_view.php?id=<?= (int)$t['id'] ?>">
            View
        </a>
    </td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
