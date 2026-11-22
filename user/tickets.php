
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

if ($role !== 'user') {
    http_response_code(403);
    exit('Access denied.');
}

$userId = (int)$user['id'];

$stmt = $pdo->prepare("
    SELECT
        t.*,
        CONCAT(a.first_name, ' ', a.last_name) AS assignee
    FROM tickets t
    LEFT JOIN users a ON a.id = t.assigned_to
    WHERE t.requester_id = ?
    ORDER BY t.created_at DESC
");

$stmt->execute([$userId]);
$tickets = $stmt->fetchAll();

$pageTitle = 'My Tickets';

require __DIR__ . '/../includes/header.php';
?>

<div class="user-page">

<div class="welcome-row">
    <div>
        <h2>My Tickets</h2>
        <p class="muted">
            View and track all your submitted support requests.
        </p>
    </div>

    <a href="create_ticket.php" class="btn primary">
        + Create Ticket
    </a>
</div>

<div class="stats-grid">

    <div class="stat-card">
        <span class="stat-icon blue">▤</span>
        <div>
            <small>Total Tickets</small>
            <strong><?= count($tickets) ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon amber">◷</span>
        <div>
            <small>Active Tickets</small>
            <strong>
                <?php
                $active = 0;

                foreach ($tickets as $ticket) {
                    if (!in_array($ticket['status'], ['Resolved', 'Closed'], true)) {
                        $active++;
                    }
                }

                echo $active;
                ?>
            </strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon green">✓</span>
        <div>
            <small>Resolved / Closed</small>
            <strong>
                <?= count($tickets) - $active ?>
            </strong>
        </div>
    </div>

</div>

<div class="section-heading">
    <div>
        <h2>All Support Requests</h2>
        <p class="muted">
            Your ticket history and current status.
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
                    <th>Category</th>
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
                        You haven't submitted any support tickets yet.
                        <br><br>
                        <a href="create_ticket.php" class="text-link">
                            Create your first ticket →
                        </a>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($tickets as $ticket): ?>
                <tr>

                    <td class="ticket-no">
                        <?= e($ticket['ticket_number']) ?>
                    </td>

                    <td>
                        <strong>
                            <?= e($ticket['title']) ?>
                        </strong>
                    </td>

                    <td>
                        <?= e($ticket['category']) ?>
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
                        <?= e($ticket['assignee'] ?: 'Not yet assigned') ?>
                    </td>

                    <td>
                        <?= e(date('M j, Y', strtotime($ticket['created_at']))) ?>
                    </td>

                    <td>
                        <a
                            class="text-link"
                            href="ticket_view.php?id=<?= (int)$ticket['id'] ?>"
                        >
                            View Details
                        </a>
                    </td>

                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>

    </div>
</div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
