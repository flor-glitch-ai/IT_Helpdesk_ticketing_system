<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

require_admin();

$pdo  = db();
$user = current_user();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    http_response_code(404);
    exit('Invalid ticket ID.');
}

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

$currentAssigned = $ticket['assigned_to'] !== null ? (int) $ticket['assigned_to'] : null;
$requesterId     = !empty($ticket['requester_id']) ? (int) $ticket['requester_id'] : null;

$error        = '';
$commentDraft = '';

/* ---------- Form handling ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    /* Add comment */
    if ($action === 'comment') {

        $commentDraft = trim($_POST['comment'] ?? '');

        if ($commentDraft === '') {
            $error = 'Comment cannot be empty.';
        } else {

            $pdo->prepare("INSERT INTO ticket_comments (ticket_id, user_id, comment) VALUES (?, ?, ?)")
                ->execute([$id, $user['id'], $commentDraft]);

            // Tell the requester and the assignee, but never the person who just commented.
            $recipients = array_unique(array_filter([$requesterId, $currentAssigned]));

            foreach ($recipients as $recipientId) {
                if ($recipientId !== (int) $user['id']) {
                    notify_user(
                        $pdo,
                        $recipientId,
                        'Ticket update',
                        'A new comment was added to ' . $ticket['ticket_number']
                    );
                }
            }

            log_audit($pdo, (int) $user['id'], 'Ticket comment', $ticket['ticket_number']);
            flash('success', 'Comment added successfully.');
            redirect('ticket_view.php?id=' . $id);
        }
    }

    /* Update ticket */
    elseif ($action === 'update') {

        $newStatus   = $_POST['status'] ?? $ticket['status'];
        $newPriority = $_POST['priority'] ?? $ticket['priority'];
        $assignedRaw = $_POST['assigned_to'] ?? '';
        $assigned    = $assignedRaw === '' ? null : filter_var($assignedRaw, FILTER_VALIDATE_INT);

        if (
            !in_array($newStatus, ticket_statuses(), true) ||
            !in_array($newPriority, priorities(), true) ||
            ($assignedRaw !== '' && $assigned === false)
        ) {
            $error = 'Invalid ticket information.';
        }

        // Only validate the staff account when the assignee is actually changing.
        // (Re-saving a ticket that is assigned to a since-deactivated user must not fail or silently unassign.)
        if ($error === '' && $assigned !== null && $assigned !== $currentAssigned) {

            $staffCheck = $pdo->prepare("
                SELECT u.id
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.id = ? AND u.is_active = 1 AND r.name IN ('admin', 'it_staff')
                LIMIT 1
            ");
            $staffCheck->execute([$assigned]);

            if (!$staffCheck->fetchColumn()) {
                $error = 'Selected staff account is invalid or inactive.';
            }
        }

        if ($error === '') {

            $statusChanged   = $newStatus !== $ticket['status'];
            $priorityChanged = $newPriority !== $ticket['priority'];
            $assignChanged   = $assigned !== $currentAssigned;

            if (!$statusChanged && !$priorityChanged && !$assignChanged) {
                $error = 'No changes to save.';
            } else {

                $pdo->beginTransaction();

                try {
                    if ($statusChanged) {
                        log_history($pdo, $id, $user['id'], 'status', $ticket['status'], $newStatus);
                    }

                    if ($priorityChanged) {
                        log_history($pdo, $id, $user['id'], 'priority', $ticket['priority'], $newPriority);
                    }

                    if ($assignChanged) {
                        log_history(
                            $pdo, $id, $user['id'], 'assigned_to',
                            $currentAssigned === null ? null : (string) $currentAssigned,
                            $assigned === null ? null : (string) $assigned
                        );
                    }

                    $pdo->prepare("UPDATE tickets SET status = ?, priority = ?, assigned_to = ? WHERE id = ?")
                        ->execute([$newStatus, $newPriority, $assigned, $id]);

                    if ($assignChanged && $assigned !== null && $assigned !== (int) $user['id']) {
                        notify_user($pdo, $assigned, 'Ticket assigned', 'You have been assigned ticket ' . $ticket['ticket_number']);
                    }

                    // Let the requester know when the status moves.
                    if ($statusChanged && $requesterId !== null && $requesterId !== (int) $user['id']) {
                        notify_user(
                            $pdo,
                            $requesterId,
                            'Ticket status changed',
                            $ticket['ticket_number'] . ' is now ' . $newStatus
                        );
                    }

                    log_audit($pdo, (int) $user['id'], 'Ticket updated', $ticket['ticket_number']);

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

/* ---------- Page data ---------- */

$commentQuery = $pdo->prepare("
    SELECT c.*, CONCAT_WS(' ', u.first_name, u.last_name) AS author, r.name AS role
    FROM ticket_comments c
    INNER JOIN users u ON u.id = c.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE c.ticket_id = ?
    ORDER BY c.created_at ASC
");
$commentQuery->execute([$id]);
$comments = $commentQuery->fetchAll(PDO::FETCH_ASSOC);

// Resolve assignee IDs stored in history to real names.
$historyQuery = $pdo->prepare("
    SELECT
        h.*,
        CONCAT_WS(' ', u.first_name, u.last_name)   AS actor,
        CONCAT_WS(' ', uo.first_name, uo.last_name) AS old_name,
        CONCAT_WS(' ', un.first_name, un.last_name) AS new_name
    FROM ticket_history h
    LEFT JOIN users u  ON u.id = h.changed_by
    LEFT JOIN users uo ON h.field_changed = 'assigned_to' AND uo.id = CAST(h.old_value AS UNSIGNED)
    LEFT JOIN users un ON h.field_changed = 'assigned_to' AND un.id = CAST(h.new_value AS UNSIGNED)
    WHERE h.ticket_id = ?
    ORDER BY h.created_at DESC, h.id DESC
");
$historyQuery->execute([$id]);
$history = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

// Active staff, plus the current assignee even if they were deactivated (so the select never silently unassigns).
$staffQuery = $pdo->prepare("
    SELECT u.id, u.is_active, CONCAT_WS(' ', u.first_name, u.last_name) AS name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE (r.name IN ('admin', 'it_staff') AND u.is_active = 1)
       OR u.id = ?
    ORDER BY name ASC
");
$staffQuery->execute([$currentAssigned ?? 0]);
$staff = $staffQuery->fetchAll(PDO::FETCH_ASSOC);

/** Friendly rendering of a history value (assignee IDs → names). */
$historyValue = static function (array $item, string $side): string {
    $raw  = $item[$side . '_value'] ?? null;
    $name = trim((string) ($item[$side . '_name'] ?? ''));

    if ($item['field_changed'] === 'assigned_to') {
        if ($raw === null || $raw === '') {
            return 'Unassigned';
        }
        return $name !== '' ? $name : 'User #' . $raw;
    }

    return ($raw === null || $raw === '') ? '—' : (string) $raw;
};

$pageTitle = 'Ticket Details';
require __DIR__ . '/../includes/header.php';
?>

<div class="admin-page">

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

            <div class="form-card">
                <div class="ticket-title-row">
                    <div>
                        <span class="eyebrow"><?= e($ticket['category'] ?? 'Support Request') ?></span>
                        <h2><?= e($ticket['title']) ?></h2>
                        <p class="muted">
                            Submitted by <?= e($ticket['requester'] ?: 'Unknown') ?>
                            · <?= e(fmt_dt($ticket['created_at'])) ?>
                        </p>
                    </div>
                    <?= badge('priority', $ticket['priority']) ?>
                </div>

                <!-- No nl2br(): .description uses white-space: pre-wrap, so nl2br would double every line break. -->
                <div class="description"><?= e($ticket['description']) ?></div>
            </div>

            <div class="form-card">
                <div class="card-heading">
                    <h2>Conversation</h2>
                    <p>Comments and updates for this ticket.</p>
                </div>

                <div class="comment-list">
                    <?php if (!$comments): ?>
                        <p class="empty">No comments yet.</p>
                    <?php endif; ?>

                    <?php foreach ($comments as $comment): ?>
                        <div class="comment">
                            <div class="comment-avatar" aria-hidden="true">
                                <?= e(strtoupper(substr($comment['author'] ?: 'U', 0, 1))) ?>
                            </div>

                            <div class="comment-body">
                                <div class="comment-meta">
                                    <strong><?= e($comment['author'] ?: 'Unknown User') ?></strong>
                                    <span><?= e(role_label($comment['role'] ?? '')) ?> · <?= e(fmt_dt($comment['created_at'])) ?></span>
                                </div>
                                <p><?= nl2br(e($comment['comment'])) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="POST" class="form-stack comment-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="comment">

                    <label>
                        Add a comment
                        <textarea name="comment" rows="3" required placeholder="Write an update or ask a question..."><?= e($commentDraft) ?></textarea>
                    </label>

                    <div class="form-actions">
                        <button type="submit" class="btn primary">Post Comment</button>
                    </div>
                </form>
            </div>

            <div class="form-card">
                <div class="card-heading">
                    <h2>Activity History</h2>
                    <p>Recorded changes for this ticket.</p>
                </div>

                <?php if (!$history): ?>
                    <p class="muted">No recorded changes.</p>
                <?php else: ?>
                    <div class="history-list">
                        <?php foreach ($history as $item): ?>
                            <div class="history-item">
                                <span class="history-dot" aria-hidden="true"></span>
                                <div>
                                    <strong><?= e(ucfirst(str_replace('_', ' ', $item['field_changed']))) ?> changed</strong>
                                    <p><?= e($historyValue($item, 'old')) ?> → <?= e($historyValue($item, 'new')) ?></p>
                                    <small><?= e($item['actor'] ?: 'System') ?> · <?= e(fmt_dt($item['created_at'])) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- SIDE COLUMN -->
        <aside class="side-column">

            <div class="form-card">
                <h3>Ticket Information</h3>

                <div class="detail-pair">
                    <span>Ticket Number</span>
                    <strong><?= e($ticket['ticket_number']) ?></strong>
                </div>
                <div class="detail-pair">
                    <span>Status</span>
                    <?= badge('status', $ticket['status']) ?>
                </div>
                <div class="detail-pair">
                    <span>Priority</span>
                    <?= badge('priority', $ticket['priority']) ?>
                </div>
                <div class="detail-pair">
                    <span>Assigned To</span>
                    <strong><?= e(trim($ticket['assignee'] ?? '') ?: 'Unassigned') ?></strong>
                </div>
                <div class="detail-pair">
                    <span>Last Updated</span>
                    <strong><?= e(fmt_dt($ticket['updated_at'])) ?></strong>
                </div>
            </div>

            <div class="form-card">
                <h3>Manage Ticket</h3>

                <form method="POST" class="form-stack">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update">

                    <label>
                        Assign To
                        <select name="assigned_to">
                            <option value="">Unassigned</option>
                            <?php foreach ($staff as $member): ?>
                                <option
                                    value="<?= (int) $member['id'] ?>"
                                    <?= $currentAssigned === (int) $member['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($member['name']) ?><?= (int) $member['is_active'] ? '' : ' (inactive)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Status
                        <select name="status">
                            <?php foreach (ticket_statuses() as $s): ?>
                                <option value="<?= e($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Priority
                        <select name="priority">
                            <?php foreach (priorities() as $p): ?>
                                <option value="<?= e($p) ?>" <?= $ticket['priority'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button type="submit" class="btn primary full">Save Changes</button>
                </form>
            </div>

        </aside>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>