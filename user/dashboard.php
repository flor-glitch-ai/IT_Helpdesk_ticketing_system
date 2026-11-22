
<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$pdo = db();
$user = current_user();

/* =========================================
   1. ACCESS CONTROL
========================================= */

$role = strtolower(trim($user['role'] ?? ''));

if ($role !== 'user') {
    http_response_code(403);
    exit('Access denied.');
}

$userId = (int) $user['id'];

/* =========================================
   2. USER STATISTICS
========================================= */

$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(
            CASE
                WHEN status NOT IN ('Resolved', 'Closed')
                THEN 1 ELSE 0
            END
        ), 0) AS active,
        COALESCE(SUM(
            CASE WHEN status = 'Resolved'
            THEN 1 ELSE 0 END
        ), 0) AS resolved,
        COALESCE(SUM(
            CASE WHEN status = 'Closed'
            THEN 1 ELSE 0 END
        ), 0) AS closed
    FROM tickets
    WHERE requester_id = ?
");

$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'active' => 0,
    'resolved' => 0,
    'closed' => 0
];

/* =========================================
   3. RECENT TICKETS
========================================= */

$recentStmt = $pdo->prepare("
    SELECT
        t.id,
        t.ticket_number,
        t.title,
        t.priority,
        t.status,
        t.created_at,
        CONCAT(s.first_name, ' ', s.last_name) AS assignee_name
    FROM tickets t
    LEFT JOIN users s
        ON s.id = t.assigned_to
    WHERE t.requester_id = ?
    ORDER BY t.created_at DESC
    LIMIT 5
");

$recentStmt->execute([$userId]);
$recentTickets = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   4. USER INFORMATION
========================================= */

$firstName = trim($user['first_name'] ?? '');
$lastName = trim($user['last_name'] ?? '');

$fullName = trim($firstName . ' ' . $lastName);

if ($fullName === '') {
    $fullName = $user['name'] ?? 'User';
}

$initial = strtoupper(substr($firstName ?: $fullName, 0, 1));

$pageTitle = 'User Dashboard';

require __DIR__ . '/../includes/header.php';
?>

<div class="user-page user-dashboard">

    <!-- =====================================
         WELCOME SECTION
    ====================================== -->

    <section class="user-welcome">

        <div class="welcome-content">

            <span class="welcome-label">
                IT SUPPORT PORTAL
            </span>

            <h2>
                Welcome back, <?= e($fullName) ?>!
            </h2>

            <p>
                Manage your IT support requests and monitor
                your ticket progress in one place.
            </p>

            <a href="create_ticket.php" class="user-welcome-btn">
                <span>+</span>
                Submit New Ticket
            </a>

        </div>

        <div class="welcome-decoration"></div>

    </section>


    <!-- =====================================
         DASHBOARD OVERVIEW
    ====================================== -->

    <section class="user-dashboard-section">

        <div class="user-section-heading">

            <div>
                <span class="section-label">
                    YOUR ACTIVITY
                </span>

                <h2>Dashboard Overview</h2>

                <p>
                    Summary of your IT support requests.
                </p>
            </div>

        </div>


        <!-- STATISTICS -->

        <div class="user-stats-grid">

            <!-- TOTAL TICKETS -->

            <div class="user-stat-card">

                <div class="user-stat-top">
                    <span>Total Tickets</span>

                    <div class="user-stat-icon blue">
                        <span>▤</span>
                    </div>
                </div>

                <h3>
                    <?= (int) $stats['total'] ?>
                </h3>

                <p>All submitted support requests</p>

            </div>


            <!-- ACTIVE TICKETS -->

            <div class="user-stat-card">

                <div class="user-stat-top">
                    <span>Active Tickets</span>

                    <div class="user-stat-icon amber">
                        <span>◷</span>
                    </div>
                </div>

                <h3>
                    <?= (int) $stats['active'] ?>
                </h3>

                <p>Tickets currently being handled</p>

            </div>


            <!-- RESOLVED -->

            <div class="user-stat-card">

                <div class="user-stat-top">
                    <span>Resolved Tickets</span>

                    <div class="user-stat-icon green">
                        <span>✓</span>
                    </div>
                </div>

                <h3>
                    <?= (int) $stats['resolved'] ?>
                </h3>

                <p>Successfully resolved requests</p>

            </div>


            <!-- CLOSED -->

            <div class="user-stat-card">

                <div class="user-stat-top">
                    <span>Closed Tickets</span>

                    <div class="user-stat-icon purple">
                        <span>▣</span>
                    </div>
                </div>

                <h3>
                    <?= (int) $stats['closed'] ?>
                </h3>

                <p>Completed and closed requests</p>

            </div>

        </div>

    </section>


    <!-- =====================================
         RECENT TICKETS
    ====================================== -->

    <section class="user-content-card">

        <div class="user-card-heading">

            <div>
                <span class="section-label">
                    SUPPORT REQUESTS
                </span>

                <h2>Recent Tickets</h2>

                <p>
                    Keep track of your latest IT support requests.
                </p>
            </div>

            <a href="tickets.php" class="user-view-all">
                View All Tickets
                <span>→</span>
            </a>

        </div>


        <?php if (empty($recentTickets)): ?>

            <div class="user-empty-state">

                <div class="empty-icon">
                    ▤
                </div>

                <h3>No Tickets Yet</h3>

                <p>
                    You haven't submitted any support requests.
                    Create your first ticket to get started.
                </p>

                <a href="create_ticket.php" class="user-primary-btn">
                    + Submit Your First Ticket
                </a>

            </div>

        <?php else: ?>

            <div class="user-table-wrapper">

                <table class="user-data-table">

                    <thead>
                        <tr>
                            <th>Ticket Number</th>
                            <th>Title</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Assigned IT Staff</th>
                            <th>Date Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($recentTickets as $ticket): ?>

                            <?php
                            $priority = strtolower(
                                trim($ticket['priority'] ?? 'low')
                            );

                            $status = strtolower(
                                trim($ticket['status'] ?? '')
                            );

                            $statusClass = str_replace(
                                [' ', '_'],
                                '-',
                                $status
                            );

                            $dateSubmitted = !empty($ticket['created_at'])
                                ? date(
                                    'M d, Y',
                                    strtotime($ticket['created_at'])
                                )
                                : 'N/A';
                            ?>

                            <tr>

                                <td>
                                    <span class="user-ticket-number">
                                        <?= e($ticket['ticket_number']) ?>
                                    </span>
                                </td>

                                <td>
                                    <strong class="user-ticket-title">
                                        <?= e($ticket['title']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <span class="user-priority <?= e($priority) ?>">
                                        <?= e(ucfirst($priority)) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="user-status <?= e($statusClass) ?>">
                                        <?= e($ticket['status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e(
                                        $ticket['assignee_name']
                                        ?: 'Not yet assigned'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e($dateSubmitted) ?>
                                </td>

                                <td>
                                    <a
                                        href="ticket_view.php?id=<?= (int) $ticket['id'] ?>"
                                        class="user-action-link"
                                    >
                                        View Details →
                                    </a>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>


    <!-- =====================================
         ACCOUNT SECTION
    ====================================== -->

    <section class="user-account-section">

        <div class="user-content-card account-card">

            <div class="account-information">

                <div class="account-icon">
                    <span>♙</span>
                </div>

                <div>
                    <span class="section-label">
                        ACCOUNT SETTINGS
                    </span>

                    <h2>Manage Your Account</h2>

                    <p>
                        Update your personal information
                        and manage your account password.
                    </p>
                </div>

            </div>

            <a href="profile.php" class="user-secondary-btn">
                My Profile
                <span>→</span>
            </a>

        </div>

    </section>


    <!-- =====================================
         QUICK INFORMATION
    ====================================== -->

    <section class="user-quick-info">

        <div class="quick-info-item">
            <span class="quick-info-icon">✓</span>

            <div>
                <strong>Track Your Requests</strong>
                <p>Monitor your ticket status anytime.</p>
            </div>
        </div>

        <div class="quick-info-item">
            <span class="quick-info-icon">+</span>

            <div>
                <strong>Need IT Assistance?</strong>
                <p>Submit a support ticket whenever needed.</p>
            </div>
        </div>

    </section>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
