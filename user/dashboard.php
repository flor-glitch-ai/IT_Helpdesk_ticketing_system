 
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

$userId = (int) $user['id'];

/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tickets
    WHERE requester_id = ?
");
$stmt->execute([$userId]);
$all = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tickets
    WHERE requester_id = ?
    AND status NOT IN ('Resolved', 'Closed')
");
$stmt->execute([$userId]);
$active = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tickets
    WHERE requester_id = ?
    AND status IN ('Resolved', 'Closed')
");
$stmt->execute([$userId]);
$resolved = (int) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Search Recent Tickets
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        t.id,
        t.ticket_number,
        t.title,
        t.category,
        t.priority,
        t.status,
        t.created_at
    FROM tickets t
    WHERE t.requester_id = ?
";

$params = [$userId];

if ($search !== '') {
    $sql .= "
        AND (
            t.ticket_number LIKE ?
            OR t.title LIKE ?
            OR t.category LIKE ?
            OR t.priority LIKE ?
            OR t.status LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    $params = array_merge(
        $params,
        array_fill(0, 5, $keyword)
    );
}

$sql .= "
    ORDER BY t.created_at DESC
    LIMIT 8
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'My Dashboard';

require __DIR__ . '/../includes/header.php';
?>

<style>
.dashboard-search {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    max-width: 520px;
}

.dashboard-search input {
    flex: 1;
    min-width: 0;
    height: 43px;
    padding: 0 14px;
    border: 1px solid #dce4ee;
    border-radius: 7px;
    background: #fff;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
}

.dashboard-search input:focus {
    border-color: #386da8;
    box-shadow: 0 0 0 3px rgba(56,109,168,.10);
}

.dashboard-search button {
    height: 43px;
    padding: 0 20px;
    border: none;
    border-radius: 7px;
    background: #173b67;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}

.dashboard-search button:hover {
    background: #24558c;
}

.search-clear {
    color: #64748b;
    font-size: 12px;
    text-decoration: none;
}

.search-clear:hover {
    color: #173b67;
}

@media (max-width: 500px) {
    .dashboard-search {
        flex-wrap: wrap;
    }

    .dashboard-search input {
        width: 100%;
    }
}
</style>

<!-- WELCOME SECTION -->

<div class="welcome-row">

    <div>
        <h2>Hello, <?= e($user['name'] ?? 'User') ?>.</h2>

        <p class="muted">
            Welcome to your IT Help Desk portal.
            Track and manage your support requests here.
        </p>
    </div>

    <a href="create_ticket.php" class="btn primary">
        + Create Ticket
    </a>

</div>


<!-- STATISTICS -->

<div class="stats-grid">

    <div class="stat-card">
        <span class="stat-icon blue">▤</span>

        <div>
            <small>My Total Tickets</small>
            <strong><?= $all ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon amber">◷</span>

        <div>
            <small>Active Tickets</small>
            <strong><?= $active ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <span class="stat-icon green">✓</span>

        <div>
            <small>Resolved / Closed</small>
            <strong><?= $resolved ?></strong>
        </div>
    </div>

</div>


<!-- RECENT TICKETS HEADING -->

<div class="section-heading">

    <div>
        <h2>My Recent Tickets</h2>

        <p class="muted">
            Track the status of your latest support requests.
        </p>
    </div>

    <a href="tickets.php" class="text-link">
        View All My Tickets →
    </a>

</div>


<!-- SEARCH BAR -->

<form method="GET" action="dashboard.php" class="dashboard-search">

    <input
        type="search"
        name="search"
        placeholder="Search ticket number, subject, category..."
        value="<?= e($search) ?>"
    >

    <button type="submit">
        Search
    </button>

    <?php if ($search !== ''): ?>

        <a href="dashboard.php" class="search-clear">
            Clear
        </a>

    <?php endif; ?>

</form>


<!-- TICKET TABLE -->

<div class="table-card">

    <div class="table-scroll">

        <table>

            <thead>
                <tr>
                    <th>Ticket No.</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

                <?php if (empty($recent)): ?>

                    <tr>
                        <td colspan="6" class="empty">

                            <?php if ($search !== ''): ?>
                                No tickets found matching
                                "<?= e($search) ?>".
                            <?php else: ?>
                                You haven't created any support tickets yet.
                            <?php endif; ?>

                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($recent as $t): ?>

                        <tr>

                            <td class="ticket-no">
                                <?= e($t['ticket_number'] ?? '') ?>
                            </td>

                            <td>
                                <strong>
                                    <?= e($t['title'] ?? '') ?>
                                </strong>

                                <small class="table-sub">
                                    <?= e($t['category'] ?? '') ?>
                                </small>
                            </td>

                            <td>
                                <span class="priority <?= e(strtolower($t['priority'] ?? '')) ?>">
                                    <?= e($t['priority'] ?? '') ?>
                                </span>
                            </td>

                            <td>
                                <span class="status">
                                    <?= e($t['status'] ?? '') ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                $createdAt = strtotime($t['created_at'] ?? '');

                                echo $createdAt
                                    ? e(date('M j, Y', $createdAt))
                                    : 'N/A';
                                ?>
                            </td>

                            <td>
                                <a
                                    class="text-link"
                                    href="ticket_view.php?id=<?= (int) $t['id'] ?>"
                                >
                                    View
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
