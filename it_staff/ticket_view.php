
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

$ticketId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$ticketId || $ticketId < 1) {
    http_response_code(404);
    exit('Ticket not found.');
}

// Get only tickets assigned to this staff member
// or active tickets that are still unassigned.
function getStaffTicket(PDO $pdo, int $ticketId, int $userId): array|false
{
    $stmt = $pdo->prepare("
        SELECT
            t.*,
            CONCAT(u.first_name, ' ', u.last_name) AS requester,
            u.email AS requester_email,
            CONCAT(a.first_name, ' ', a.last_name) AS assignee
        FROM tickets t
        LEFT JOIN users u ON u.id = t.requester_id
        LEFT JOIN users a ON a.id = t.assigned_to
        WHERE t.id = ?
          AND (
                t.assigned_to = ?
                OR (
                    t.assigned_to IS NULL
                    AND t.status NOT IN ('Resolved', 'Closed')
                )
          )
        LIMIT 1
    ");

    $stmt->execute([$ticketId, $userId]);

    return $stmt->fetch();
}

$ticket = getStaffTicket($pdo, $ticketId, $userId);

if (!$ticket) {
    http_response_code(404);
    exit('Ticket not found or access denied.');
}

$error = '';

// Statuses that IT Staff can select.
$allowedStatuses = [
    'Reviewed',
    'In Progress',
    'Pending User',
    'Resolved'
];

// Handle actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {

        // Claim an unassigned ticket.
        if ($action === 'claim') {

            if ($ticket['assigned_to'] !== null) {
                throw new RuntimeException('This ticket is no longer available for claiming.');
            }

            if (in_array($ticket['status'], ['Resolved', 'Closed'], true)) {
                throw new RuntimeException('This ticket is already completed.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE tickets
                SET assigned_to = ?,
                    status = 'Assigned',
                    updated_at = NOW()
                WHERE id = ?
                  AND assigned_to IS NULL
                  AND status NOT IN ('Resolved', 'Closed')
            ");

            $stmt->execute([$userId, $ticketId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Another staff member may have claimed this ticket.');
            }

            $history = $pdo->prepare("
                INSERT INTO ticket_history (
                    ticket_id,
                    changed_by,
                    field_changed,
                    old_value,
                    new_value,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            $history->execute([
                $ticketId,
                $userId,
                'assigned_to',
                'Unassigned',
                $user['name']
            ]);

            $history->execute([
                $ticketId,
                $userId,
                'status',
                $ticket['status'],
                'Assigned'
            ]);

            $pdo->commit();

            flash('success', 'You have successfully claimed this ticket.');

            redirect('ticket_view.php?id=' . $ticketId);
        }

        // Update ticket status.
        elseif ($action === 'update_status') {

            if ((int)$ticket['assigned_to'] !== $userId) {
                throw new RuntimeException('Claim this ticket before updating its status.');
            }

            if (in_array($ticket['status'], ['Resolved', 'Closed'], true)) {
                throw new RuntimeException('This ticket is already completed.');
            }

            $newStatus = trim($_POST['status'] ?? '');

            if (!in_array($newStatus, $allowedStatuses, true)) {
                throw new RuntimeException('Please select a valid ticket status.');
            }

            if ($newStatus !== $ticket['status']) {

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE tickets
                    SET status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND assigned_to = ?
                      AND status NOT IN ('Resolved', 'Closed')
                ");

                $stmt->execute([
                    $newStatus,
                    $ticketId,
                    $userId
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Unable to update this ticket.');
                }

                $history = $pdo->prepare("
                    INSERT INTO ticket_history (
                        ticket_id,
                        changed_by,
                        field_changed,
                        old_value,
                        new_value,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");

                $history->execute([
                    $ticketId,
                    $userId,
                    'status',
                    $ticket['status'],
                    $newStatus
                ]);

                $pdo->commit();

                flash('success', 'Ticket status updated successfully.');

                redirect('ticket_view.php?id=' . $ticketId);
            }

            flash('success', 'No status changes were made.');

            redirect('ticket_view.php?id=' . $ticketId);
        }

        // Add a comment to the ticket.
        elseif ($action === 'comment') {

            if ((int)$ticket['assigned_to'] !== $userId) {
                throw new RuntimeException('You must claim this ticket before sending a comment.');
            }

            if (in_array($ticket['status'], ['Resolved', 'Closed'], true)) {
                throw new RuntimeException('This ticket is already completed.');
            }

            $comment = trim($_POST['comment'] ?? '');

            if ($comment === '') {
                throw new RuntimeException('Please enter a comment.');
            }

            if (strlen($comment) > 5000) {
                throw new RuntimeException('Comment must not exceed 5,000 characters.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (
                    ticket_id,
                    user_id,
                    comment,
                    created_at
                )
                VALUES (?, ?, ?, NOW())
            ");

            $stmt->execute([
                $ticketId,
                $userId,
                $comment
            ]);

            flash('success', 'Your comment has been sent.');

            redirect('ticket_view.php?id=' . $ticketId);
        }

        else {
            throw new RuntimeException('Invalid action.');
        }

    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($exception->getMessage());

        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'An unexpected error occurred. Please try again.';
    }

    // Refresh ticket information after a failed action.
    $ticket = getStaffTicket($pdo, $ticketId, $userId);

    if (!$ticket) {
        http_response_code(404);
        exit('Ticket not found or access denied.');
    }
}

// Get ticket comments.
$commentStmt = $pdo->prepare("
    SELECT
        c.*,
        CONCAT(u.first_name, ' ', u.last_name) AS commenter,
        r.name AS commenter_role
    FROM ticket_comments c
    INNER JOIN users u ON u.id = c.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE c.ticket_id = ?
    ORDER BY c.created_at ASC
");

$commentStmt->execute([$ticketId]);
$comments = $commentStmt->fetchAll();

// Get ticket history.
$historyStmt = $pdo->prepare("
    SELECT
        h.*,
        CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
    FROM ticket_history h
    LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.ticket_id = ?
    ORDER BY h.created_at DESC
");

$historyStmt->execute([$ticketId]);
$historyRecords = $historyStmt->fetchAll();

$isAssignedToMe = (int)($ticket['assigned_to'] ?? 0) === $userId;
$isUnassigned = $ticket['assigned_to'] === null;
$isCompleted = in_array($ticket['status'], ['Resolved', 'Closed'], true);

$pageTitle = 'Ticket Details';

require __DIR__ . '/../includes/header.php';
?>

<div class="staff-page">

    <div class="back-row">
        <a class="text-link" href="tickets.php">← Back to Tickets</a>
        <span class="ticket-no"><?= e($ticket['ticket_number']) ?></span>
    </div>

    <?php if ($error): ?>
        <div class="alert error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="ticket-layout">

        <!-- MAIN COLUMN -->
        <div class="main-column">

            <!-- Title & Description -->
            <div class="form-card">
                <div class="ticket-title-row">
                    <div>
                        <span class="eyebrow"><?= e($ticket['category'] ?? 'Support Request') ?></span>
                        <h2><?= e($ticket['title']) ?></h2>
                        <p class="muted">
                            Submitted by <?= e($ticket['requester'] ?? 'Unknown') ?>
                            · <?= e(date('M j, Y g:i A', strtotime($ticket['created_at']))) ?>
                        </p>
                    </div>
                    <span class="priority <?= e(strtolower($ticket['priority'])) ?>">
                        <?= e($ticket['priority']) ?>
                    </span>
                </div>

                <div class="description"><?= nl2br(e($ticket['description'])) ?></div>
            </div>

            <!-- Conversation -->
            <div class="form-card">
                <div class="card-heading">
                    <h2>Conversation</h2>
                    <p>Messages between the requester and IT Staff.</p>
                </div>

                <div class="comment-list">
                    <?php if (!$comments): ?>
                        <p class="empty">No comments have been added yet.</p>
                    <?php endif; ?>

                    <?php foreach ($comments as $comment): ?>
                        <div class="comment">
                            <div class="comment-avatar" aria-hidden="true">
                                <?= e(strtoupper(substr($comment['commenter'] ?: 'U', 0, 1))) ?>
                            </div>

                            <div class="comment-body">
                                <div class="comment-meta">
                                    <strong><?= e($comment['commenter'] ?: 'Unknown User') ?></strong>
                                    <span><?= e(ucwords(str_replace('_', ' ', $comment['commenter_role'] ?? 'User'))) ?> · <?= e(date('M j, Y g:i A', strtotime($comment['created_at']))) ?></span>
                                </div>
                                <p><?= nl2br(e($comment['comment'])) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Add Comment Form -->
                <?php if ($isAssignedToMe && !$isCompleted): ?>
                    <form method="POST" class="form-stack comment-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="comment">

                        <label>
                            Send a message
                            <textarea
                                name="comment"
                                rows="4"
                                maxlength="5000"
                                required
                                placeholder="Write an update or ask the requester for details..."
                            ></textarea>
                        </label>

                        <div class="form-actions">
                            <button type="submit" class="btn primary">Send Message</button>
                        </div>
                    </form>
                <?php elseif ($isCompleted): ?>
                    <div class="alert">
                        This ticket is completed. Comments are no longer available.
                    </div>
                <?php elseif ($isUnassigned): ?>
                    <p class="muted">
                        Claim this ticket to communicate with the requester.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Ticket History -->
            <div class="form-card">
                <div class="card-heading">
                    <h2>Ticket History</h2>
                    <p>Recorded changes for this ticket.</p>
                </div>

                <?php if (!$historyRecords): ?>
                    <p class="empty">No history records available.</p>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Changed By</th>
                                    <th>Field</th>
                                    <th>Previous Value</th>
                                    <th>New Value</th>
                                    <th>Date</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($historyRecords as $history): ?>
                                    <tr>
                                        <td><strong><?= e($history['changed_by_name'] ?? 'Unknown') ?></strong></td>
                                        <td><?= e(ucwords(str_replace('_', ' ', $history['field_changed']))) ?></td>
                                        <td><?= e($history['old_value'] ?? '—') ?></td>
                                        <td><?= e($history['new_value'] ?? '—') ?></td>
                                        <td><?= e(date('M j, Y g:i A', strtotime($history['created_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- SIDE COLUMN -->
        <aside class="side-column">

            <!-- Ticket Information -->
            <div class="form-card">
                <h3>Ticket Information</h3>

                <div class="detail-pair">
                    <span>Ticket Number</span>
                    <strong><?= e($ticket['ticket_number']) ?></strong>
                </div>

                <div class="detail-pair">
                    <span>Status</span>
                    <span class="status <?= e(strtolower(str_replace([' ', '_'], '-', $ticket['status']))) ?>">
                        <?= e($ticket['status']) ?>
                    </span>
                </div>

                <div class="detail-pair">
                    <span>Priority</span>
                    <span class="priority <?= e(strtolower($ticket['priority'])) ?>">
                        <?= e($ticket['priority']) ?>
                    </span>
                </div>

                <div class="detail-pair">
                    <span>Category</span>
                    <strong><?= e($ticket['category']) ?></strong>
                </div>

                <div class="detail-pair">
                    <span>Requester</span>
                    <strong><?= e($ticket['requester'] ?? 'Unknown') ?></strong>
                </div>

                <div class="detail-pair">
                    <span>Requester Email</span>
                    <strong><?= e($ticket['requester_email'] ?? 'Not available') ?></strong>
                </div>

                <div class="detail-pair">
                    <span>Assigned To</span>
                    <strong><?= e($ticket['assignee'] ?: 'Unassigned') ?></strong>
                </div>

                <div class="detail-pair">
                    <span>Date Submitted</span>
                    <strong><?= e(date('M j, Y g:i A', strtotime($ticket['created_at']))) ?></strong>
                </div>

                <div class="detail-pair">
                    <span>Last Updated</span>
                    <strong><?= e(date('M j, Y g:i A', strtotime($ticket['updated_at']))) ?></strong>
                </div>
            </div>

            <!-- Manage Ticket -->
            <div class="form-card">
                <h3>Manage Ticket</h3>

                <?php if ($isUnassigned): ?>
                    <p class="muted" style="margin-bottom:15px;">
                        This ticket is currently unassigned. You can claim it to take ownership.
                    </p>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="claim">

                        <button
                            type="submit"
                            class="btn primary full"
                            onclick="return confirm('Do you want to claim this ticket?');"
                        >
                            Claim This Ticket
                        </button>
                    </form>

                <?php elseif ($isAssignedToMe && !$isCompleted): ?>
                    <form method="POST" class="form-stack">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_status">

                        <label>
                            Update Status
                            <select name="status" required>
                                <?php foreach ($allowedStatuses as $status): ?>
                                    <option
                                        value="<?= e($status) ?>"
                                        <?= $ticket['status'] === $status ? 'selected' : '' ?>
                                    >
                                        <?= e($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <button type="submit" class="btn primary full">
                            Save Status
                        </button>
                    </form>

                <?php elseif ($isCompleted): ?>
                    <div class="alert" style="margin-bottom:0;">
                        This ticket is marked as <?= e($ticket['status']) ?>.
                    </div>

                <?php else: ?>
                    <p class="muted">
                        Assigned to <?= e($ticket['assignee']) ?>.
                    </p>
                <?php endif; ?>
            </div>

        </aside>

    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
