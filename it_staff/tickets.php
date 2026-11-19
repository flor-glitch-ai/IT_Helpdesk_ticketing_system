
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

$userId = (int)$user['id'];

// Get assigned tickets and unassigned active tickets.
$stmt = $pdo->prepare("
    SELECT
        t.*,
        CONCAT(u.first_name, ' ', u.last_name) AS requester,
        CONCAT(a.first_name, ' ', a.last_name) AS assignee
    FROM tickets t
    LEFT JOIN users u ON u.id = t.requester_id
    LEFT JOIN users a ON a.id = t.assigned_to
    WHERE t.assigned_to = ?
       OR (
            t.assigned_to IS NULL
            AND t.status NOT IN ('Resolved', 'Closed')
       )
    ORDER BY
        CASE WHEN t.priority = 'Critical' THEN 1
             WHEN t.priority = 'High' THEN 2
             WHEN t.priority = 'Medium' THEN 3
             ELSE 4
        END,
        t.created_at DESC
");

$stmt->execute([$userId]);
$tickets = $stmt->fetchAll();

// Ticket statistics.
$countStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status NOT IN ('Resolved', 'Closed')) AS active,
        SUM(status IN ('Resolved', 'Closed')) AS resolved,
        SUM(priority = 'Critical' AND status NOT IN ('Resolved', 'Closed')) AS critical
    FROM tickets
    WHERE assigned_to = ?
");

$countStmt->execute([$userId]);
$stats = $countStmt->fetch();

$unassigned = (int)$pdo->query("
    SELECT COUNT(*)
    FROM tickets
    WHERE assigned_to IS NULL
      AND status NOT IN ('Resolved', 'Closed')
")->fetchColumn();

$pageTitle = 'Ticket Management';

require __DIR__ . '/../includes/header.php';
?>

<div class="welcome-row">
    <div>
        <h2>Support Tickets</h2>
        <p class="muted">
            Manage your assigned tickets and review requests awaiting assignment.
        </p>
    </div>
</div>

<div class="stats-grid">

    <div class="stat-card">
        <span class="stat-icon blue">▤</span>
        <div>
            <small>My Assigned Tickets</small>
            <strong><?= (int)($stats['total'] ?? 0) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon amber">◷</span>
        <div>
            <small>Active Tickets</small>
            <strong><?= (int)($stats['active'] ?? 0) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon green">✓</span>
        <div>
            <small>Resolved / Closed</small>
            <strong><?= (int)($stats['resolved'] ?? 0) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon red">!</span>
        <div>
            <small>Critical Tickets</small>
            <strong><?= (int)($stats['critical'] ?? 0) ?></strong>
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
        <h2>Available Tickets</h2>
        <p class="muted">
            Tickets assigned to you and active tickets that have not yet been assigned.
        </p>
    </div>
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
                    <th>Assigned To</th>
                    <th>Date Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php if (!$tickets): ?>
                <tr>
                    <td colspan="8" class="empty">
                        No assigned or unassigned tickets available.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($tickets as $ticket): ?>
                <tr>

                    <td class="ticket-no">
                        <?= e($ticket['ticket_number']) ?>
                    </td>

                    <td>
                        <strong><?= e($ticket['title']) ?></strong>
                        <small class="table-sub">
                            <?= e($ticket['category'] ?? '') ?>
                        </small>
                    </td>

                    <td>
                        <?= e($ticket['requester'] ?? 'Unknown') ?>
                    </td>

                    <td>
                        <span class="priority <?= e(strtolower($ticket['priority'])) ?>">
                            <?= e($ticket['priority']) ?>
                        </span>
                    </td>

                    <td>
                        <span class="status">
                            <?= e($ticket['status']) ?>
                        </span>
                    </td>

                    <td>
                        <?= e($ticket['assignee'] ?: 'Unassigned') ?>
                    </td>

                    <td>
                        <?= e(date('M j, Y', strtotime($ticket['created_at']))) ?>
                    </td>

                    <td>
                        <a
                            class="text-link"
                            href="ticket_view.php?id=<?= (int)$ticket['id'] ?>"
                        >
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
