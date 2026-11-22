<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

require_admin();

$pdo = db();

/* ---------- Filters (all via prepared statements) ---------- */

$perPage  = 20;
$search   = trim((string) ($_GET['q'] ?? ''));
$status   = (string) ($_GET['status'] ?? '');
$priority = (string) ($_GET['priority'] ?? '');
$assigned = (string) ($_GET['assigned'] ?? '');
$page     = max(1, (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT));

$where  = [];
$params = [];

if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where[] = "(t.ticket_number LIKE ? OR t.title LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?)";
    array_push($params, $like, $like, $like);
}

if ($status === 'active') {
    $where[] = "t.status NOT IN ('Resolved', 'Closed')";
} elseif (in_array($status, ticket_statuses(), true)) {
    $where[]  = 't.status = ?';
    $params[] = $status;
} else {
    $status = '';
}

if (in_array($priority, priorities(), true)) {
    $where[]  = 't.priority = ?';
    $params[] = $priority;
} else {
    $priority = '';
}

if ($assigned === 'unassigned') {
    $where[] = 't.assigned_to IS NULL';
} elseif (ctype_digit($assigned)) {
    $where[]  = 't.assigned_to = ?';
    $params[] = (int) $assigned;
} else {
    $assigned = '';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------- Count + page ---------- */

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tickets t
    LEFT JOIN users u ON u.id = t.requester_id
    $whereSql
");
$countStmt->execute($params);

$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT
        t.id, t.ticket_number, t.title, t.category, t.priority, t.status,
        t.created_at, t.updated_at,
        CONCAT_WS(' ', u.first_name, u.last_name) AS requester,
        CONCAT_WS(' ', a.first_name, a.last_name) AS assignee
    FROM tickets t
    LEFT JOIN users u ON u.id = t.requester_id
    LEFT JOIN users a ON a.id = t.assigned_to
    $whereSql
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$staff = $pdo->query("
    SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE r.name IN ('admin', 'it_staff') AND u.is_active = 1
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Build a pagination URL that keeps the current filters */
$filters  = ['q' => $search, 'status' => $status, 'priority' => $priority, 'assigned' => $assigned];
$pageUrl  = static function (int $n) use ($filters): string {
    $query = array_filter($filters, static fn($v) => $v !== '');
    if ($n > 1) {
        $query['page'] = $n;
    }
    return 'tickets.php' . ($query ? '?' . http_build_query($query) : '');
};
$isFiltered = array_filter($filters, static fn($v) => $v !== '') !== [];

$pageTitle = 'Ticket Management';
require __DIR__ . '/../includes/header.php';
?>

<div class="admin-page">

    <div class="table-card">

        <div class="card-heading">
            <h2>Ticket Records</h2>
            <p>
                <?= number_format($totalRows) ?> ticket(s) found<?= $isFiltered ? ' matching your filters' : '' ?>
            </p>
        </div>

        <form method="GET" class="filter-bar" role="search">
            <label>
                Search
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Ticket no., subject, requester...">
            </label>

            <label>
                Status
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>All active</option>
                    <?php foreach (ticket_statuses() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Priority
                <select name="priority">
                    <option value="">All priorities</option>
                    <?php foreach (priorities() as $p): ?>
                        <option value="<?= e($p) ?>" <?= $priority === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Assigned to
                <select name="assigned">
                    <option value="">Anyone</option>
                    <option value="unassigned" <?= $assigned === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                    <?php foreach ($staff as $member): ?>
                        <option value="<?= (int) $member['id'] ?>" <?= $assigned === (string) $member['id'] ? 'selected' : '' ?>>
                            <?= e($member['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="filter-actions">
                <button type="submit" class="btn primary">Apply</button>
                <?php if ($isFiltered): ?>
                    <a href="tickets.php" class="btn secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-scroll">
            <table class="admin-tickets-table">
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
                        <th><span class="sr-only">Action</span></th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$tickets): ?>
                    <tr>
                        <td colspan="9" class="empty">
                            <?= $isFiltered ? 'No tickets match your filters.' : 'No tickets found.' ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td class="ticket-no"><?= e($ticket['ticket_number']) ?></td>
                        <td>
                            <strong><?= e($ticket['title']) ?></strong>
                            <small class="table-sub"><?= e($ticket['category'] ?? 'Other') ?></small>
                        </td>
                        <td><?= e(trim($ticket['requester'] ?? '') ?: 'Unknown') ?></td>
                        <td><?= e(trim($ticket['assignee'] ?? '') ?: 'Unassigned') ?></td>
                        <td><?= badge('priority', $ticket['priority']) ?></td>
                        <td><?= badge('status', $ticket['status']) ?></td>
                        <td><?= e(fmt_dt($ticket['created_at'])) ?></td>
                        <td><?= e(fmt_dt($ticket['updated_at'])) ?></td>
                        <td>
                            <a
                                class="text-link"
                                href="ticket_view.php?id=<?= (int) $ticket['id'] ?>"
                                aria-label="View details for <?= e($ticket['ticket_number']) ?>"
                            >View Details →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Ticket pages">
                <span>Page <?= $page ?> of <?= $totalPages ?></span>

                <div class="pagination-links">
                    <?php if ($page > 1): ?>
                        <a href="<?= e($pageUrl($page - 1)) ?>" rel="prev">← Prev</a>
                    <?php endif; ?>

                    <?php
                    $from = max(1, $page - 2);
                    $to   = min($totalPages, $page + 2);
                    for ($n = $from; $n <= $to; $n++):
                        if ($n === $page): ?>
                            <span class="current" aria-current="page"><?= $n ?></span>
                        <?php else: ?>
                            <a href="<?= e($pageUrl($n)) ?>"><?= $n ?></a>
                        <?php endif;
                    endfor;
                    ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= e($pageUrl($page + 1)) ?>" rel="next">Next →</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>