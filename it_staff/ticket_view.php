
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

<div class="welcome-row">
    <div>
        <h2>Ticket Details</h2>
        <p class="muted">
            Review the request, manage its status, and communicate with the requester.
        </p>
    </div>

    <a href="tickets.php" class="btn secondary">
        ← Back to Tickets
    </a>
</div>

<?php if ($error): ?>
    <div class="alert error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="section-heading">
    <div>
        <h2><?= e($ticket['title']) ?></h2>
        <p class="muted">
            Ticket No.:
            <strong><?= e($ticket['ticket_number']) ?></strong>
        </p>
    </div>

    <span class="status">
        <?= e($ticket['status']) ?>
    </span>
</div>

<div class="detail-grid">

    <div class="main-column">

        <!-- Ticket Information -->
        <div class="table-card" style="padding:24px;">

            <h3>Ticket Information</h3>

            <div class="detail-list">

                <div class="detail-item">
                    <span class="muted">Requester</span>
                    <strong><?= e($ticket['requester'] ?? 'Unknown') ?></strong>
                </div>

                <div class="detail-item">
                    <span class="muted">Requester Email</span>
                    <strong><?= e($ticket['requester_email'] ?? 'Not available') ?></strong>
                </div>

                <div class="detail-item">
                    <span class="muted">Category</span>
                    <strong><?= e($ticket['category']) ?></strong>
                </div>

                <div class="detail-item">
                    <span class="muted">Priority</span>
                    <strong>
                        <span class="priority <?= e(strtolower($ticket['priority'])) ?>">
                            <?= e($ticket['priority']) ?>
                        </span>
                    </strong>
                </div>

                <div class="detail-item">
                    <span class="muted">Assigned To</span>
                    <strong>
                        <?= e($ticket['assignee'] ?: 'Unassigned') ?>
                    </strong>
                </div>

                <div class="detail-item">
                    <span class="muted">Date Submitted</span>
                    <strong>
                        <?= e(date('M j, Y h:i A', strtotime($ticket['created_at']))) ?>
                    </strong>
                </div>

                <div class="detail-item">
                    <span class="muted">Last Updated</span>
                    <strong>
                        <?= e(date('M j, Y h:i A', strtotime($ticket['updated_at']))) ?>
                    </strong>
                </div>

            </div>

            <hr>

            <h3>Description</h3>

            <div class="ticket-description">
                <?= nl2br(e($ticket['description'])) ?>
            </div>

        </div>

        <!-- Claim Ticket -->
        <?php if ($isUnassigned): ?>

            <div class="table-card" style="padding:24px; margin-top:20px;">

                <h3>Unassigned Ticket</h3>

                <p class="muted">
                    This ticket has not yet been assigned to an IT Staff member.
                    You may claim it to start working on the request.
                </p>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="claim"
                    >

                    <button
                        type="submit"
                        class="btn primary"
                        onclick="return confirm('Do you want to claim this ticket?');"
                    >
                        Claim This Ticket
                    </button>

                </form>

            </div>

        <?php endif; ?>

        <!-- Update Status -->
        <?php if ($isAssignedToMe && !$isCompleted): ?>

            <div class="table-card" style="padding:24px; margin-top:20px;">

                <h3>Update Ticket Status</h3>

                <p class="muted">
                    Select the current progress of this support request.
                </p>

                <form method="POST" class="form-stack">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="update_status"
                    >

                    <label>
                        Ticket Status

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

                    <div class="form-actions">
                        <button type="submit" class="btn primary">
                            Save Status
                        </button>
                    </div>

                </form>

            </div>

        <?php elseif ($isCompleted): ?>

            <div class="alert" style="margin-top:20px;">
                This ticket has been marked as <?= e($ticket['status']) ?>.
            </div>

        <?php endif; ?>

        <!-- Conversation -->
        <div class="table-card" style="padding:24px; margin-top:20px;">

            <div class="section-heading">
                <div>
                    <h3>Conversation</h3>
                    <p class="muted">
                        Messages between the requester and IT Staff.
                    </p>
                </div>
            </div>

            <?php if (!$comments): ?>

                <p class="empty">
                    No comments have been added yet.
                </p>

            <?php else: ?>

                <div class="comment-list">

                    <?php foreach ($comments as $comment): ?>

                        <div class="comment-item">

                            <div class="comment-header">

                                <strong>
                                    <?= e($comment['commenter']) ?>
                                </strong>

                                <span class="muted">
                                    <?= e($comment['commenter_role'] ?? 'User') ?>
                                </span>

                                <small class="muted">
                                    <?= e(date('M j, Y h:i A', strtotime($comment['created_at']))) ?>
                                </small>

                            </div>

                            <div class="comment-body">
                                <?= nl2br(e($comment['comment'])) ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

        <!-- Add Comment -->
        <?php if ($isAssignedToMe && !$isCompleted): ?>

            <div class="table-card" style="padding:24px; margin-top:20px;">

                <h3>Send a Message</h3>

                <p class="muted">
                    Add an update or ask the requester for additional information.
                </p>

                <form method="POST" class="form-stack">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="comment"
                    >

                    <label>
                        Message

                        <textarea
                            name="comment"
                            rows="5"
                            maxlength="5000"
                            required
                            placeholder="Type your message here..."
                        ></textarea>
                    </label>

                    <div class="form-actions">
                        <button type="submit" class="btn primary">
                            Send Message
                        </button>
                    </div>

                </form>

            </div>

        <?php endif; ?>

        <!-- Ticket History -->
        <div class="table-card" style="padding:24px; margin-top:20px;">

            <h3>Ticket History</h3>

            <?php if (!$historyRecords): ?>

                <p class="empty">
                    No history records available.
                </p>

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
                                    <td>
                                        <?= e($history['changed_by_name'] ?? 'Unknown') ?>
                                    </td>

                                    <td>
                                        <?= e(ucwords(str_replace('_', ' ', $history['field_changed']))) ?>
                                    </td>

                                    <td>
                                        <?= e($history['old_value'] ?? '-') ?>
                                    </td>

                                    <td>
                                        <?= e($history['new_value'] ?? '-') ?>
                                    </td>

                                    <td>
                                        <?= e(date('M j, Y h:i A', strtotime($history['created_at']))) ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>
                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
