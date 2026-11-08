<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function show_flash(): void
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        echo '<div class="alert ' . e($f['type']) . '">' . e($f['message']) . '</div>';
        unset($_SESSION['flash']);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Invalid form token. Go back, refresh the page, and try again.');
    }
}

function log_history(PDO $pdo, int $ticketId, int $userId, string $field, ?string $old, ?string $new): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO ticket_history (ticket_id, changed_by, field_changed, old_value, new_value)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$ticketId, $userId, $field, $old, $new]);
}

function log_audit(PDO $pdo, ?int $userId, string $action, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        // Audit logging must not break the main user action.
        error_log('Audit log error: ' . $e->getMessage());
    }
}

function notify_user(PDO $pdo, int $userId, string $title, string $message): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $title, $message]);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function is_staff(): bool
{
    return in_array(current_user()['role'] ?? '', ['admin', 'it_staff'], true);
}

function ticket_statuses(): array
{
    return ['Submitted', 'Reviewed', 'Assigned', 'In Progress', 'Pending User', 'Resolved', 'Closed'];
}

function priorities(): array
{
    return ['Low', 'Medium', 'High', 'Critical'];
}

function ticket_categories(): array
{
    return ['Hardware', 'Software', 'Network', 'Printer', 'Account Access', 'Other'];
}
