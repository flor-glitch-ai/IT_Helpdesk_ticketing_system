<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

require_admin();

$pdo  = db();
$user = current_user();

/* Ticket statistics (one query) */
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total_tickets,
        SUM(status NOT IN ('Resolved', 'Closed')) AS active_tickets,
        SUM(status IN ('Resolved', 'Closed')) AS resolved_tickets,
        SUM(priority = 'Critical' AND status NOT IN ('Resolved', 'Closed')) AS critical_tickets,
        SUM(assigned_to IS NULL AND status NOT IN ('Resolved', 'Closed')) AS unassigned_tickets
    FROM tickets
")->fetch(PDO::FETCH_ASSOC) ?: [];

$totalTickets      = (int) ($stats['total_tickets'] ?? 0);
$activeTickets     = (int) ($stats['active_tickets'] ?? 0);
$resolvedTickets   = (int) ($stats['resolved_tickets'] ?? 0);
$criticalTickets   = (int) ($stats['critical_tickets'] ?? 0);
$unassignedTickets = (int) ($stats['unassigned_tickets'] ?? 0);

/* Active user + IT staff counts (one query instead of two) */
$roleCounts = $pdo->query("
    SELECT r.name, COUNT(*)
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.is_active = 1
      AND r.name IN ('user', 'it_staff')
    GROUP BY r.name
")->fetchAll(PDO::FETCH_KEY_PAIR);

$totalUsers = (int) ($roleCounts['user'] ?? 0);
$totalStaff = (int) ($roleCounts['it_staff'] ?? 0);

/* Recent tickets */
$recentTickets = $pdo->query("
    SELECT t.id, t.ticket_number, t.title, t.category, t.priority, t.status, t.created_at,
           CONCAT_WS(' ', u.first_name, u.last_name) AS requester
    FROM tickets t
    LEFT JOIN users u ON u.id = t.requester_id
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

/* Cards: [label, icon, value, caption, link] — a link makes the card clickable */
$statCards = [
    ['Total Tickets',    '▤', $totalTickets,    'All submitted tickets',      'tickets.php'],
    ['Active Tickets',   '◷', $activeTickets,   'Tickets needing attention',  'tickets.php?status=active'],
    ['Resolved Tickets', '✓', $resolvedTickets, 'Resolved and closed tickets', null],
    ['Critical Tickets', '!', $criticalTickets, 'Unresolved critical tickets', 'tickets.php?status=active&priority=Critical'],
];

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="admin-page admin-dashboard">

    <section class="admin-hero">
        <span class="admin-hero-label">IT Support Portal</span>
        <h2>Welcome back, <?= e($user['name'] ?? 'Administrator') ?>!</h2>
        <p>Manage support requests and monitor your IT operations from one place.</p>
    </section>

    <section>
        <div class="admin-section-heading">
            <div>
                <h2>Overview</h2>
                <p>Summary of your help desk activities</p>
            </div>
        </div>

        <div class="admin-stats-grid">
            <?php foreach ($statCards as [$label, $icon, $value, $caption, $href]): ?>
                <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?> class="admin-stat-card">
                    <div class="admin-stat-top">
                        <span><?= e($label) ?></span>
                        <span class="admin-stat-icon" aria-hidden="true"><?= $icon ?></span>
                    </div>
                    <h3><?= number_format($value) ?></h3>
                    <p><?= e($caption) ?></p>
                </<?= $href ? 'a' : 'div' ?>>
            <?php endforeach; ?>
        </div>
    </section>

    <section>
        <div class="admin-section-heading">
            <div>
                <h2>System Overview</h2>
                <p>Active accounts and tickets waiting for an owner</p>
            </div>
        </div>

        <div class="admin-system-overview">
            <div class="admin-system-item">
                <div class="admin-system-icon" aria-hidden="true">♙</div>
                <div>
                    <h3><?= number_format($totalUsers) ?></h3>
                    <p>Active Users</p>
                </div>
            </div>

            <div class="admin-system-item">
                <div class="admin-system-icon" aria-hidden="true">✚</div>
                <div>
                    <h3><?= number_format($totalStaff) ?></h3>
                    <p>IT Staff</p>
                </div>
            </div>

            <a class="admin-system-item" href="tickets.php?status=active&amp;assigned=unassigned">
                <div class="admin-system-icon" aria-hidden="true">?</div>
                <div>
                    <h3><?= number_format($unassignedTickets) ?></h3>
                    <p>Unassigned Active Tickets</p>
                </div>
            </a>
        </div>
    </section>

    <section>
        <div class="admin-section-heading">
            <div>
                <h2>Recent Tickets</h2>
                <p>Latest support requests submitted to the system</p>
            </div>
            <a href="tickets.php" class="admin-view-all">View All Tickets →</a>
        </div>

        <div class="admin-table-search">
            <span aria-hidden="true">⌕</span>
            <input
                type="search"
                placeholder="Search these tickets by number, title, requester..."
                aria-label="Search recent tickets"
                data-table-search="#ticketTableBody"
                data-empty-message="#noSearchResults"
            >
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-tickets-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Requester</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Date Submitted</th>
                        <th><span class="sr-only">Action</span></th>
                    </tr>
                </thead>

                <tbody id="ticketTableBody">
                <?php if (!$recentTickets): ?>
                    <tr>
                        <td colspan="7" class="admin-empty-state">No tickets found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($recentTickets as $ticket): ?>
                    <tr data-searchable>
                        <td>
                            <div class="admin-ticket-info">
                                <strong><?= e($ticket['ticket_number'] ?? '') ?></strong>
                                <span><?= e($ticket['title'] ?? '') ?></span>
                            </div>
                        </td>
                        <td><?= e(trim($ticket['requester'] ?? '') ?: 'Unknown User') ?></td>
                        <td><?= e($ticket['category'] ?? 'N/A') ?></td>
                        <td><?= badge('priority', $ticket['priority']) ?></td>
                        <td><?= badge('status', $ticket['status']) ?></td>
                        <td><?= e(fmt_dt($ticket['created_at'], 'M d, Y', 'N/A')) ?></td>
                        <td>
                            <a
                                href="ticket_view.php?id=<?= (int) $ticket['id'] ?>"
                                class="admin-action-link"
                                aria-label="View ticket <?= e($ticket['ticket_number'] ?? '') ?>"
                            >View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div id="noSearchResults" class="admin-empty-state" hidden>No matching tickets found.</div>
        </div>
    </section>

</div>

<?php
require __DIR__ . '/../includes/admin_table_search.php';
require __DIR__ . '/../includes/footer.php';