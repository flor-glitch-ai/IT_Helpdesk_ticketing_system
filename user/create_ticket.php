
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

$pageTitle = 'Create Ticket';

$error = '';

$categories = [
    'Hardware',
    'Software',
    'Network',
    'Account Access',
    'Email',
    'Printer',
    'Other'
];

$priorities = [
    'Low',
    'Medium',
    'High',
    'Critical'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $priority = trim($_POST['priority'] ?? '');

    if ($title === '' || $description === '') {
        $error = 'Please complete the ticket subject and description.';
    } elseif (strlen($title) > 150) {
        $error = 'The subject must not exceed 150 characters.';
    } elseif (!in_array($category, $categories, true)) {
        $error = 'Please select a valid category.';
    } elseif (!in_array($priority, $priorities, true)) {
        $error = 'Please select a valid priority.';
    } else {

        try {

            // Generate a unique ticket number.
            do {
                $ticketNumber = 'HD-' . date('Ymd') . '-' .
                    strtoupper(bin2hex(random_bytes(3)));

                $check = $pdo->prepare("
                    SELECT id
                    FROM tickets
                    WHERE ticket_number = ?
                    LIMIT 1
                ");

                $check->execute([$ticketNumber]);

            } while ($check->fetchColumn());

            $stmt = $pdo->prepare("
                INSERT INTO tickets (
                    ticket_number,
                    title,
                    description,
                    category,
                    priority,
                    status,
                    requester_id,
                    assigned_to,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, 'Submitted', ?, NULL, NOW(), NOW())
            ");

            $stmt->execute([
                $ticketNumber,
                $title,
                $description,
                $category,
                $priority,
                (int)$user['id']
            ]);

            $ticketId = (int)$pdo->lastInsertId();

            flash(
                'success',
                'Your support ticket has been submitted successfully. Ticket No: ' . $ticketNumber
            );

            redirect('ticket_view.php?id=' . $ticketId);

        } catch (Throwable $exception) {

            error_log($exception->getMessage());

            $error = 'Unable to submit your ticket. Please try again.';
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="user-page">

<div class="welcome-row">
    <div>
        <h2>Create Support Ticket</h2>
        <p class="muted">
            Submit your IT concern or technical issue.
            Our IT team will review your request.
        </p>
    </div>

    <a href="tickets.php" class="btn secondary">
        My Tickets
    </a>
</div>

<div class="form-card" style="max-width:850px;">

    <div class="section-heading">
        <div>
            <h2>Ticket Information</h2>
            <p class="muted">
                Provide the necessary details about your concern.
            </p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-stack">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrf_token()) ?>"
        >

        <label>
            Subject / Ticket Title

            <input
                type="text"
                name="title"
                maxlength="150"
                required
                value="<?= e($_POST['title'] ?? '') ?>"
                placeholder="Example: Unable to connect to the internet"
            >
        </label>

        <label>
            Category

            <select name="category" required>
                <option value="">Select a category</option>

                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?= e($category) ?>"
                        <?= (($_POST['category'] ?? '') === $category) ? 'selected' : '' ?>
                    >
                        <?= e($category) ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </label>

        <label>
            Priority

            <select name="priority" required>
                <option value="">Select priority</option>

                <?php foreach ($priorities as $priority): ?>
                    <option
                        value="<?= e($priority) ?>"
                        <?= (($_POST['priority'] ?? 'Medium') === $priority) ? 'selected' : '' ?>
                    >
                        <?= e($priority) ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </label>

        <label>
            Description

            <textarea
                name="description"
                rows="7"
                required
                placeholder="Describe your issue. Include any error messages and explain when the problem started."
            ><?= e($_POST['description'] ?? '') ?></textarea>
        </label>

        <div class="form-actions">
            <a href="dashboard.php" class="btn secondary">
                Cancel
            </a>

            <button type="submit" class="btn primary">
                Submit Ticket
            </button>
        </div>

    </form>

</div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
