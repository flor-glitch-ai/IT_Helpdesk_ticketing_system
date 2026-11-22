
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

<div class="staff-page staff-dashboard">

    <section class="staff-hero">
        <span class="staff-hero-label">Technical Support Workspace</span>
        <h2>Hello, <?= e($user['name']) ?>!</h2>
        <p>Review assigned support requests, monitor ticket resolution, and assist users.</p>
    </section>

    <section>
        <div class="section-heading">
            <div>
                <h2>Overview</h2>
                <p>Summary of your current support activity</p>
            </div>
            <a href="tickets.php" class="staff-view-all">View All Tickets →</a>
        </div>

        <div class="staff-stats-grid">
            <a href="tickets.php" class="staff-stat-card">
                <div class="staff-stat-top">
                    <span>Assigned Tickets</span>
                    <span class="staff-stat-icon" aria-hidden="true">▤</span>
                </div>
                <h3><?= number_format($assigned) ?></h3>
                <p>Tickets currently assigned to you</p>
            </a>

            <a href="tickets.php" class="staff-stat-card">
                <div class="staff-stat-top">
                    <span>Active Tickets</span>
                    <span class="staff-stat-icon" aria-hidden="true">◷</span>
                </div>
                <h3><?= number_format($active) ?></h3>
                <p>Assigned tickets needing attention</p>
            </a>

            <div class="staff-stat-card">
                <div class="staff-stat-top">
                    <span>Resolved Tickets</span>
                    <span class="staff-stat-icon" aria-hidden="true">✓</span>
                </div>
                <h3><?= number_format($resolved) ?></h3>
                <p>Successfully completed tickets</p>
            </div>

            <div class="staff-stat-card">
                <div class="staff-stat-top">
                    <span>Critical Tickets</span>
                    <span class="staff-stat-icon" aria-hidden="true">!</span>
                </div>
                <h3><?= number_format($critical) ?></h3>
                <p>Urgent tickets requiring action</p>
            </div>
        </div>
    </section>

    <section>
        <div class="section-heading">
            <div>
                <h2>System Queue</h2>
                <p>Requests waiting for technical staff assignment</p>
            </div>
        </div>

        <div class="staff-system-overview">
            <a class="staff-system-item" href="tickets.php">
                <div class="staff-system-icon" aria-hidden="true">?</div>
                <div>
                    <h3><?= number_format($unassigned) ?></h3>
                    <p>Unassigned Active Tickets Awaiting Action</p>
                </div>
            </a>
        </div>
    </section>

    <section>
        <div class="section-heading">
            <div>
                <h2>Recent Support Tickets</h2>
                <p>Your assigned tickets and available unassigned requests</p>
            </div>
            <a href="tickets.php" class="staff-view-all">View All Tickets →</a>
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
                                No tickets currently available.
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
                                <?php if (!empty($t['category'])): ?>
                                    <small class="table-sub">
                                        <?= e($t['category']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td><?= e($t['requester'] ?? 'Unknown') ?></td>

                            <td>
                                <span class="priority <?= strtolower(e($t['priority'])) ?>">
                                    <?= e($t['priority']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="status <?= strtolower(str_replace([' ', '_'], '-', $t['status'])) ?>">
                                    <?= e($t['status']) ?>
                                </span>
                            </td>

                            <td>
                                <?= e(date('M j, Y', strtotime($t['created_at']))) ?>
                            </td>

                            <td>
                                <a class="text-link" href="ticket_view.php?id=<?= (int)$t['id'] ?>">
                                    Open →
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
