
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

<div class="welcome-row">
    <div>
        <h2>Ticket Details</h2>
        <p class="muted">
            View your support request and communicate with the IT team.
        </p>
    </div>

    <a href="tickets.php" class="btn secondary">
        ← Back to My Tickets
    </a>
</div>

<?php if ($error): ?>
    <div class="alert error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="section-heading">
    <div>
        <h2>
            <?= e($ticket['title']) ?>
        </h2>

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

        <div class="table-card" style="padding:24px;">

            <h3>Ticket Information</h3>

            <div class="detail-list">

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
                    <span class="muted">Assigned IT Staff</span>
                    <strong>
                        <?= e($ticket['assignee'] ?: 'Not yet assigned') ?>
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

        <div class="table-card" style="padding:24px; margin-top:20px;">

            <div class="section-heading">
                <div>
                    <h3>Conversation</h3>
                    <p class="muted">
                        Messages related to this support request.
                    </p>
                </div>
            </div>

            <?php if (!$comments): ?>

                <p class="empty">
                    No comments yet. You can send a message to the IT team below.
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

        <?php if ($ticket['status'] !== 'Closed'): ?>

            <div class="table-card" style="padding:24px; margin-top:20px;">

                <h3>Send a Comment</h3>

                <p class="muted">
                    Send additional information or ask for an update about your ticket.
                </p>

                <form method="POST" class="form-stack">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrf_token()) ?>"
                    >

                    <label>
                        Your Message

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
                            Send Comment
                        </button>
                    </div>

                </form>

            </div>

        <?php else: ?>

            <div class="alert">
                This ticket is closed. Comments are no longer available.
            </div>

        <?php endif; ?>

    </div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
