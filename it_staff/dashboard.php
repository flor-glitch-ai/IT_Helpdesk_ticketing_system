
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$pdo = db();
$user = current_user();

$role = strtolower(str_replace(
    [' ', '-'],
    '_',
    trim($user['role'] ?? '')
));

if ($role !== 'it_staff') {
    http_response_code(403);
    exit('Access denied.');
}

$id = (int)$user['id'];

// Assigned tickets
$q = $pdo->prepare("
    SELECT COUNT(*) FROM tickets
    WHERE assigned_to = ?
");
$q->execute([$id]);
$assigned = (int)$q->fetchColumn();

// Active assigned tickets
$q = $pdo->prepare("
    SELECT COUNT(*) FROM tickets
    WHERE assigned_to = ?
    AND status NOT IN ('Resolved','Closed')
");
$q->execute([$id]);
$active = (int)$q->fetchColumn();

// Resolved tickets
$q = $pdo->prepare("
    SELECT COUNT(*) FROM tickets
    WHERE assigned_to = ?
    AND status IN ('Resolved','Closed')
");
$q->execute([$id]);
$resolved = (int)$q->fetchColumn();

// Unassigned tickets awaiting attention
$unassigned = (int)$pdo->query("
    SELECT COUNT(*) FROM tickets
    WHERE assigned_to IS NULL
    AND status NOT IN ('Resolved','Closed')
")->fetchColumn();

// Critical assigned tickets
$q = $pdo->prepare("
    SELECT COUNT(*) FROM tickets
    WHERE assigned_to = ?
    AND priority = 'Critical'
    AND status NOT IN ('Resolved','Closed')
");
$q->execute([$id]);
$critical = (int)$q->fetchColumn();

// Recent assigned and unassigned tickets
$stmt = $pdo->prepare("
    SELECT t.*,
           CONCAT(u.first_name, ' ', u.last_name) AS requester
    FROM tickets t
    LEFT JOIN users u ON u.id = t.requester_id
    WHERE t.assigned_to = ?
       OR (
           t.assigned_to IS NULL
           AND t.status NOT IN ('Resolved','Closed')
       )
    ORDER BY t.created_at DESC
    LIMIT 8
");

$stmt->execute([$id]);
$recent = $stmt->fetchAll();

$pageTitle = 'IT Staff Dashboard';

require __DIR__ . '/../includes/header.php';
?>

<div class="welcome-row">
    <div>
        <h2>Hello, <?= e($user['name']) ?>.</h2>
        <p class="muted">
            Here's your technical support activity.
        </p>
    </div>

    <a href="tickets.php" class="btn primary">
        View Tickets
    </a>
</div>

<div class="stats-grid">

    <div class="stat-card">
        <span class="stat-icon blue">▤</span>
        <div>
            <small>My Assigned Tickets</small>
            <strong><?= $assigned ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon amber">◷</span>
        <div>
            <small>Active Tickets</small>
            <strong><?= $active ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon green">✓</span>
        <div>
            <small>Resolved Tickets</small>
            <strong><?= $resolved ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon red">!</span>
        <div>
            <small>Critical Tickets</small>
            <strong><?= $critical ?></strong>
        </div>
    </div>

</div>

<div class="stats-grid">

    <div class="stat-card">
        <span class="stat-icon amber">!</span>
        <div>
            <small>Unassigned Tickets</small>
            <strong><?= $unassigned ?></strong>
        </div>
    </div>

</div>

<div class="section-heading">
    <div>
        <h2>Recent Support Tickets</h2>
        <p class="muted">
            Your assigned tickets and requests awaiting assignment.
        </p>
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
            Open
        </a>
    </td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
