
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
$ticketId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$ticketId || $ticketId < 1) {
    http_response_code(404);
    exit('Ticket not found.');
}

// Get the ticket only if it belongs to the logged-in user.
$stmt = $pdo->prepare("
    SELECT
        t.*,
        CONCAT(a.first_name, ' ', a.last_name) AS assignee
    FROM tickets t
    LEFT JOIN users a ON a.id = t.assigned_to
    WHERE t.id = ?
      AND t.requester_id = ?
    LIMIT 1
");

$stmt->execute([$ticketId, $userId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    http_response_code(404);
    exit('Ticket not found or access denied.');
}

$error = '';

// Process user comment.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $comment = trim($_POST['comment'] ?? '');

    if ($ticket['status'] === 'Closed') {
        $error = 'This ticket is already closed. You cannot add a comment.';
    } elseif ($comment === '') {
        $error = 'Please enter a comment before submitting.';
    } elseif (strlen($comment) > 5000) {
        $error = 'Your comment must not exceed 5,000 characters.';
    } else {

        try {

            $insert = $pdo->prepare("
                INSERT INTO ticket_comments (
                    ticket_id,
                    user_id,
                    comment,
                    created_at
                )
                VALUES (?, ?, ?, NOW())
            ");

            $insert->execute([
                $ticketId,
                $userId,
                $comment
            ]);

            flash('success', 'Your comment has been submitted successfully.');

            redirect('ticket_view.php?id=' . $ticketId);

        } catch (Throwable $exception) {

            error_log($exception->getMessage());

            $error = 'Unable to submit your comment. Please try again.';
        }
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

$pageTitle = 'Ticket Details';

require __DIR__ . '/../includes/header.php';
?>

<div class="user-page">

    <div class="back-row">
        <a class="text-link" href="tickets.php">← Back to My Tickets</a>
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
                            Submitted on <?= e(date('M j, Y g:i A', strtotime($ticket['created_at']))) ?>
                        </p>
                    </div>
                    <span class="priority <?= e(strtolower($ticket['priority'])) ?>">
                        <?= e($ticket['priority']) ?>
                    </span>
                </div>

                <div class="description"><?= nl2br(e($ticket['description'])) ?></div>
            </div>

            <div class="form-card">
                <div class="card-heading">
                    <h2>Conversation</h2>
                    <p>Messages and updates for this ticket.</p>
                </div>

                <div class="comment-list">
                    <?php if (!$comments): ?>
                        <p class="empty">No comments yet. You can send a message to the IT team below.</p>
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

                <?php if ($ticket['status'] !== 'Closed'): ?>
                    <form method="POST" class="form-stack comment-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                        <label>
                            Add a comment
                            <textarea
                                name="comment"
                                rows="4"
                                maxlength="5000"
                                required
                                placeholder="Type your message here..."
                            ></textarea>
                        </label>

                        <div class="form-actions">
                            <button type="submit" class="btn primary">Send Comment</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert">
                        This ticket is closed. Comments are no longer available.
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
                    <span>Assigned IT Staff</span>
                    <strong><?= e($ticket['assignee'] ?: 'Not yet assigned') ?></strong>
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

        </aside>

    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
