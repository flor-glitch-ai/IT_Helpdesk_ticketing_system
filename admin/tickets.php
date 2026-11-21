
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_admin();

$pdo = db();

// Get all tickets, including requester and assigned staff information.
$sql = "
    SELECT
        t.id,
        t.ticket_number,
        t.title,
        t.category,
        t.priority,
        t.status,
        t.created_at,
        t.updated_at,
        t.requester_id,
        t.assigned_to,
        CONCAT_WS(' ', u.first_name, u.last_name) AS requester,
        CONCAT_WS(' ', a.first_name, a.last_name) AS assignee
    FROM tickets t
    LEFT JOIN users u ON u.id = t.requester_id
    LEFT JOIN users a ON a.id = t.assigned_to
    ORDER BY t.created_at DESC
";

$stmt = $pdo->query($sql);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Ticket Management';

require __DIR__ . '/../includes/header.php';
?>

<div class="section-heading">
    <div>
        <h2>All Support Tickets</h2>
        <p class="muted">
            View and manage all submitted support requests.
        </p>
    </div>
</div>

<div class="table-card">

    <div class="card-heading">
        <h2>Ticket Records</h2>
        <p class="muted">
            <?= count($tickets) ?> ticket(s) found
        </p>
    </div>

    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Subject</th>
                    <th>Requester</th>
                    <th>Assigned To</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Date Submitted</th>
                    <th>Last Update</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($tickets)): ?>

                <tr>
                    <td colspan="9" class="empty">
                        No tickets found.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($tickets as $ticket): ?>

                    <tr>
                        <td class="ticket-no">
                            <?= e($ticket['ticket_number']) ?>
                        </td>

                        <td>
                            <strong>
                                <?= e($ticket['title']) ?>
                            </strong>

                            <small class="table-sub">
                                <?= e($ticket['category'] ?? 'Other') ?>
                            </small>
                        </td>

                        <td>
                            <?= e(trim($ticket['requester'] ?? '') ?: 'Unknown') ?>
                        </td>

                        <td>
                            <?= e(trim($ticket['assignee'] ?? '') ?: 'Unassigned') ?>
                        </td>

                        <td>
                            <span class="priority <?= strtolower(e($ticket['priority'])) ?>">
                                <?= e($ticket['priority']) ?>
                            </span>
                        </td>

                        <td>
                            <span class="status">
                                <?= e($ticket['status']) ?>
                            </span>
                        </td>

                        <td>
                            <?= e(date(
                                'M j, Y g:i A',
                                strtotime($ticket['created_at'])
                            )) ?>
                        </td>

                        <td>
                            <?= e(date(
                                'M j, Y g:i A',
                                strtotime($ticket['updated_at'])
                            )) ?>
                        </td>

                        <td>
                            <a
                                class="text-link"
                                href="ticket_view.php?id=<?= (int)$ticket['id'] ?>"
                            >
                                View Details →
                            </a>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
