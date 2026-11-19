
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_admin();

$pdo = db();
$user = current_user();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    http_response_code(404);
    exit('Invalid ticket ID.');
}

// Fetch ticket information.
$stmt = $pdo->prepare("
    SELECT
        t.*,
        CONCAT_WS(' ', u.first_name, u.last_name) AS requester,
        CONCAT_WS(' ', a.first_name, a.last_name) AS assignee
    FROM tickets t
    LEFT JOIN users u ON u.id = t.requester_id
    LEFT JOIN users a ON a.id = t.assigned_to
    WHERE t.id = ?
    LIMIT 1
");

$stmt->execute([$id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    http_response_code(404);
    exit('Ticket not found.');
}

$error = '';

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    // Add comment.
    if ($action === 'comment') {

        $comment = trim($_POST['comment'] ?? '');

        if ($comment === '') {
            $error = 'Comment cannot be empty.';
        } else {

            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments
                    (ticket_id, user_id, comment)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $id,
                $user['id'],
                $comment
            ]);

            if ((int)$ticket['requester_id'] !== (int)$user['id']) {
                notify_user(
                    $pdo,
                    (int)$ticket['requester_id'],
                    'Ticket update',
                    'A new comment was added to ' . $ticket['ticket_number']
                );
            }

            log_audit(
                $pdo,
                (int)$user['id'],
                'Ticket comment',
                $ticket['ticket_number']
            );

            flash('success', 'Comment added successfully.');

            redirect('ticket_view.php?id=' . $id);
        }
    }

    // Update ticket information.
    elseif ($action === 'update') {

        $newStatus = $_POST['status'] ?? $ticket['status'];
        $newPriority = $_POST['priority'] ?? $ticket['priority'];

        $assignedInput = $_POST['assigned_to'] ?? '';

        $assigned = $assignedInput === ''
            ? null
            : filter_var($assignedInput, FILTER_VALIDATE_INT);

        if (
            !in_array($newStatus, ticket_statuses(), true) ||
            !in_array($newPriority, priorities(), true) ||
            ($assignedInput !== '' && $assigned === false)
        ) {
            $error = 'Invalid ticket information.';
        } else {

            // Validate assigned staff account.
            if ($assigned !== null) {

                $staffCheck = $pdo->prepare("
                    SELECT u.id
                    FROM users u
                    INNER JOIN roles r ON r.id = u.role_id
                    WHERE u.id = ?
                      AND u.is_active = 1
                      AND r.name IN ('admin', 'it_staff')
                    LIMIT 1
                ");

                $staffCheck->execute([$assigned]);

                if (!$staffCheck->fetchColumn()) {
                    $error = 'Selected staff account is invalid or inactive.';
                }
            }

            if ($error === '') {

                $pdo->beginTransaction();

                try {

                    // Record status changes.
                    if ($newStatus !== $ticket['status']) {
                        log_history(
                            $pdo,
                            $id,
                            $user['id'],
                            'status',
                            $ticket['status'],
                            $newStatus
                        );
                    }

                    // Record priority changes.
                    if ($newPriority !== $ticket['priority']) {
                        log_history(
                            $pdo,
                            $id,
                            $user['id'],
                            'priority',
                            $ticket['priority'],
                            $newPriority
                        );
                    }

                    // Record assignment changes.
                    $oldAssigned = $ticket['assigned_to'] === null
                        ? null
                        : (string)$ticket['assigned_to'];

                    $newAssigned = $assigned === null
                        ? null
                        : (string)$assigned;

                    if ($oldAssigned !== $newAssigned) {

                        log_history(
                            $pdo,
                            $id,
                            $user['id'],
                            'assigned_to',
                            $oldAssigned,
                            $newAssigned
                        );
                    }

                    // Save ticket changes.
                    $update = $pdo->prepare("
                        UPDATE tickets
                        SET status = ?,
                            priority = ?,
                            assigned_to = ?
                        WHERE id = ?
                    ");

                    $update->execute([
                        $newStatus,
                        $newPriority,
                        $assigned,
                        $id
                    ]);

                    // Notify newly assigned staff.
                    if (
                        $assigned !== null &&
                        $assigned !== (int)($ticket['assigned_to'] ?? 0)
                    ) {
                        notify_user(
                            $pdo,
                            $assigned,
                            'Ticket assigned',
                            'You have been assigned ticket ' . $ticket['ticket_number']
                        );
                    }

                    log_audit(
                        $pdo,
                        (int)$user['id'],
                        'Ticket updated',
                        $ticket['ticket_number']
                    );

                    $pdo->commit();

                    flash('success', 'Ticket updated successfully.');

                    redirect('ticket_view.php?id=' . $id);

                } catch (Throwable $exception) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log($exception->getMessage());

                    $error = 'Unable to update ticket. Please check the server error log.';
                }
            }
        }
    }
}

// Fetch comments.
$commentQuery = $pdo->prepare("
    SELECT
        c.*,
        CONCAT_WS(' ', u.first_name, u.last_name) AS author,
        r.name AS role
    FROM ticket_comments c
    INNER JOIN users u ON u.id = c.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE c.ticket_id = ?
    ORDER BY c.created_at ASC
");

$commentQuery->execute([$id]);
$comments = $commentQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch activity history.
$historyQuery = $pdo->prepare("
    SELECT
        h.*,
        CONCAT_WS(' ', u.first_name, u.last_name) AS actor
    FROM ticket_history h
    LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.ticket_id = ?
    ORDER BY h.created_at DESC
");

$historyQuery->execute([$id]);
$history = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch active Admin and IT Staff accounts.
$staffQuery = $pdo->query("
    SELECT
        u.id,
        CONCAT_WS(' ', u.first_name, u.last_name) AS name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE r.name IN ('admin', 'it_staff')
      AND u.is_active = 1
    ORDER BY name ASC
");

$staff = $staffQuery->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Ticket Details';

require __DIR__ . '/../includes/header.php';
?>

<div class="back-row">
    <a class="text-link" href="tickets.php">
        ← Back to Tickets
    </a>

    <span class="ticket-no">
        <?= e($ticket['ticket_number']) ?>
    </span>
</div>

<?php if ($error): ?>
    <div class="alert error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="ticket-layout">

    <!-- MAIN COLUMN -->
    <div class="main-column">

        <!-- TICKET DETAILS -->
        <div class="form-card">

            <div class="ticket-title-row">

                <div>
                    <span class="eyebrow">
                        <?= e($ticket['category'] ?? 'Support Request') ?>
                    </span>

                    <h2><?= e($ticket['title']) ?></h2>

                    <p class="muted">
                        Submitted by
                        <?= e($ticket['requester'] ?: 'Unknown') ?>

                        ·

                        <?= e(date(
                            'M j, Y g:i A',
                            strtotime($ticket['created_at'])
                        )) ?>
                    </p>
                </div>

                <span class="priority <?= strtolower(e($ticket['priority'])) ?>">
                    <?= e($ticket['priority']) ?>
                </span>

            </div>

            <div class="description">
                <?= nl2br(e($ticket['description'])) ?>
            </div>

        </div>

        <!-- CONVERSATION -->
        <div class="form-card">

            <div class="card-heading">
                <h2>Conversation</h2>
                <p class="muted">
                    Comments and updates for this ticket.
                </p>
            </div>

            <div class="comment-list">

                <?php if (empty($comments)): ?>
                    <p class="empty">No comments yet.</p>
                <?php endif; ?>

                <?php foreach ($comments as $comment): ?>

                    <div class="comment">

                        <div class="comment-avatar">
                            <?= e(strtoupper(substr($comment['author'] ?: 'U', 0, 1))) ?>
                        </div>

                        <div class="comment-body">

                            <div class="comment-meta">

                                <strong>
                                    <?= e($comment['author'] ?: 'Unknown User') ?>
                                </strong>

                                <span>
                                    <?= e($comment['role'] ?? '') ?>
                                    ·
                                    <?= e(date(
                                        'M j, Y g:i A',
                                        strtotime($comment['created_at'])
                                    )) ?>
                                </span>

                            </div>

                            <p>
                                <?= nl2br(e($comment['comment'])) ?>
                            </p>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

            <!-- ADD COMMENT -->
            <form method="POST" class="form-stack comment-form">

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
                    Add a comment

                    <textarea
                        name="comment"
                        rows="3"
                        required
                        placeholder="Write an update or ask a question..."
                    ></textarea>
                </label>

                <div class="form-actions">
                    <button type="submit" class="btn primary">
                        Post Comment
                    </button>
                </div>

            </form>

        </div>

        <!-- ACTIVITY HISTORY -->
        <div class="form-card">

            <div class="card-heading">
                <h2>Activity History</h2>
                <p class="muted">
                    Recorded changes for this ticket.
                </p>
            </div>

            <?php if (empty($history)): ?>

                <p class="muted">
                    No recorded changes.
                </p>

            <?php else: ?>

                <div class="history-list">

                    <?php foreach ($history as $item): ?>

                        <div class="history-item">

                            <span class="history-dot"></span>

                            <div>

                                <strong>
                                    <?= e($item['field_changed']) ?> changed
                                </strong>

                                <p>
                                    <?= e($item['old_value'] ?? '—') ?>
                                    →
                                    <?= e($item['new_value'] ?? '—') ?>
                                </p>

                                <small>
                                    <?= e($item['actor'] ?: 'System') ?>

                                    ·

                                    <?= e(date(
                                        'M j, Y g:i A',
                                        strtotime($item['created_at'])
                                    )) ?>
                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>

    <!-- SIDE COLUMN -->
    <aside class="side-column">

        <!-- TICKET INFORMATION -->
        <div class="form-card">

            <h3>Ticket Information</h3>

            <div class="detail-pair">
                <span>Ticket Number</span>
                <strong><?= e($ticket['ticket_number']) ?></strong>
            </div>

            <div class="detail-pair">
                <span>Status</span>
                <strong><?= e($ticket['status']) ?></strong>
            </div>

            <div class="detail-pair">
                <span>Priority</span>
                <strong><?= e($ticket['priority']) ?></strong>
            </div>

            <div class="detail-pair">
                <span>Assigned To</span>
                <strong>
                    <?= e($ticket['assignee'] ?: 'Unassigned') ?>
                </strong>
            </div>

            <div class="detail-pair">
                <span>Last Updated</span>
                <strong>
                    <?= e(date(
                        'M j, Y g:i A',
                        strtotime($ticket['updated_at'])
                    )) ?>
                </strong>
            </div>

        </div>

        <!-- MANAGE TICKET -->
        <div class="form-card">

            <h3>Manage Ticket</h3>

            <form method="POST" class="form-stack">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="update"
                >

                <label>
                    Assign To

                    <select name="assigned_to">

                        <option value="">
                            Unassigned
                        </option>

                        <?php foreach ($staff as $member): ?>

                            <option
                                value="<?= (int)$member['id'] ?>"
                                <?= (int)$ticket['assigned_to'] === (int)$member['id'] ? 'selected' : '' ?>
                            >
                                <?= e($member['name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </label>

                <label>
                    Status

                    <select name="status">

                        <?php foreach (ticket_statuses() as $status): ?>

                            <option
                                value="<?= e($status) ?>"
                                <?= $ticket['status'] === $status ? 'selected' : '' ?>
                            >
                                <?= e($status) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </label>

                <label>
                    Priority

                    <select name="priority">

                        <?php foreach (priorities() as $priority): ?>

                            <option
                                value="<?= e($priority) ?>"
                                <?= $ticket['priority'] === $priority ? 'selected' : '' ?>
                            >
                                <?= e($priority) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </label>

                <button type="submit" class="btn primary full">
                    Save Changes
                </button>

            </form>

        </div>

    </aside>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
