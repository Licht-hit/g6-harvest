<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$searchValue = $_GET['search'] ?? $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $requestedPage && $requestedPage > 0 ? $requestedPage : 1;
$pageSize = 25;
$filterError = '';

if (($from !== '' && !is_valid_date($from)) || ($to !== '' && !is_valid_date($to))) {
    $filterError = 'Enter dates in the required year-month-day format.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The start date must be on or before the end date.';
}

$summary = $db->query(
    "SELECT
        COUNT(*) AS total,
        SUM(action = 'login') AS logins,
        SUM(action IN ('account_created', 'email_verified')) AS account_changes,
        SUM(created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)) AS recent
     FROM activity_logs"
)->fetch();

$conditions = [];
$parameters = [];
if ($from !== '') {
    $conditions[] = 'l.created_at >= ?';
    $parameters[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $conditions[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $parameters[] = $to;
}
if ($search !== '') {
    $conditions[] = '(
        l.action LIKE ?
        OR l.entity_type LIKE ?
        OR CAST(l.entity_id AS CHAR) LIKE ?
        OR l.details LIKE ?
        OR u.name LIKE ?
        OR u.email LIKE ?
    )';
    $searchTerm = '%' . $search . '%';
    array_push($parameters, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$matchingActivities = 0;
$logs = [];
$pageCount = 1;

if ($filterError === '') {
    $countStatement = $db->prepare(
        'SELECT COUNT(*)
         FROM activity_logs AS l
         LEFT JOIN users AS u ON u.id = l.user_id
         ' . $whereSql
    );
    $countStatement->execute($parameters);
    $matchingActivities = (int) $countStatement->fetchColumn();
    $pageCount = max(1, (int) ceil($matchingActivities / $pageSize));
    $page = min($page, $pageCount);
    $offset = ($page - 1) * $pageSize;

    $statement = $db->prepare(
        'SELECT
            l.id,
            l.action,
            l.entity_type,
            l.entity_id,
            l.details,
            l.created_at,
            u.name AS actor,
            u.email AS actor_email,
            u.role
         FROM activity_logs AS l
         LEFT JOIN users AS u ON u.id = l.user_id
         ' . $whereSql . '
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT ? OFFSET ?'
    );
    $parameters[] = $pageSize;
    $parameters[] = $offset;
    $statement->execute($parameters);
    $logs = $statement->fetchAll();
}

$firstRecord = $matchingActivities > 0 ? (($page - 1) * $pageSize) + 1 : 0;
$lastRecord = min($page * $pageSize, $matchingActivities);
$pageTitle = 'Activity log';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Audit trail</p>
        <h1>Activity log</h1>
        <p>Review account and farm activity recorded by your team.</p>
    </div>
</section>

<section class="module-stat-grid" aria-label="Activity summary">
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">◷</span>
        <span>Total activities</span>
        <strong><?= number_format((int) $summary['total']) ?></strong>
        <small>All recorded actions</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">↪</span>
        <span>Login activities</span>
        <strong><?= number_format((int) $summary['logins']) ?></strong>
        <small>Successful sign-ins</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">＋</span>
        <span>Account changes</span>
        <strong><?= number_format((int) $summary['account_changes']) ?></strong>
        <small>Registrations and verifications</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">▤</span>
        <span>Recent actions</span>
        <strong><?= number_format((int) $summary['recent']) ?></strong>
        <small>During the last 7 days</small>
    </article>
</section>

<section class="panel section module-filter-panel">
    <form method="get" class="module-filter-form">
        <div class="field">
            <label for="from">Start date</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div class="field">
            <label for="to">End date</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div class="field module-search-field">
            <label for="search">Search activity</label>
            <input
                id="search"
                name="search"
                value="<?= h($search) ?>"
                maxlength="100"
                placeholder="User, action, or related record"
            >
        </div>
        <div class="module-filter-actions">
            <button type="submit">Apply filters</button>
            <a class="button secondary" href="<?= h(app_url('modules/activity_logs.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<?php if ($filterError !== ''): ?>
    <div class="notice error" role="alert"><?= h($filterError) ?></div>
<?php endif; ?>

<section class="panel section module-table-panel">
    <div class="module-table-heading">
        <div>
            <h2>Audit events</h2>
            <p class="muted">
                <?= number_format($matchingActivities) ?> matching
                <?= $matchingActivities === 1 ? 'activity' : 'activities' ?>
            </p>
        </div>
        <?php if ($matchingActivities > 0): ?>
            <span class="module-record-range">
                Showing <?= number_format($firstRecord) ?>–<?= number_format($lastRecord) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($filterError !== ''): ?>
        <div class="module-empty-state">
            <span class="module-empty-icon" aria-hidden="true">⌕</span>
            <h3>Check the date range</h3>
            <p>Correct the dates above to view matching activity.</p>
        </div>
    <?php elseif (!$logs): ?>
        <div class="module-empty-state">
            <span class="module-empty-icon" aria-hidden="true">◷</span>
            <h3>No activities found</h3>
            <p>Try changing your dates or search terms to find other activity.</p>
            <?php if ($from !== '' || $to !== '' || $search !== ''): ?>
                <a class="button secondary" href="<?= h(app_url('modules/activity_logs.php')) ?>">Clear filters</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table activity-table">
                <thead>
                    <tr>
                        <th>Date and time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Related record</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $actionType = 'other';
                        if ($log['action'] === 'login') {
                            $actionType = 'login';
                        } elseif ($log['action'] === 'logout') {
                            $actionType = 'logout';
                        } elseif ($log['action'] === 'account_created') {
                            $actionType = 'created';
                        } elseif ($log['action'] === 'email_verified') {
                            $actionType = 'verified';
                        } elseif (strpos($log['action'], 'deleted') !== false
                            || strpos($log['action'], 'removed') !== false) {
                            $actionType = 'deleted';
                        } elseif (strpos($log['action'], 'updated') !== false
                            || strpos($log['action'], 'changed') !== false
                            || strpos($log['action'], 'adjusted') !== false) {
                            $actionType = 'updated';
                        }

                        $roleLabels = [
                            'admin' => 'Administrator',
                            'staff' => 'Staff',
                            'farmer' => 'Farmer',
                            'buyer' => 'Buyer',
                        ];
                        $actorName = $log['actor'] ?: 'Deleted user';
                        $initial = strtoupper(substr($actorName, 0, 1));
                        $actionLabel = ucwords(str_replace('_', ' ', $log['action']));
                        ?>
                        <tr>
                            <td>
                                <strong><?= h(date('M j, Y', strtotime($log['created_at']))) ?></strong>
                                <small class="table-secondary"><?= h(date('g:i a', strtotime($log['created_at']))) ?></small>
                            </td>
                            <td>
                                <span class="activity-user">
                                    <span class="activity-avatar" aria-hidden="true"><?= h($initial) ?></span>
                                    <strong><?= h($actorName) ?></strong>
                                </span>
                                <?php if ($log['actor_email']): ?>
                                    <small class="table-secondary"><?= h($log['actor_email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $log['role']
                                    ? h($roleLabels[$log['role']] ?? ucfirst($log['role']))
                                    : '<span class="muted">Unavailable</span>' ?>
                            </td>
                            <td><span class="activity-badge activity-badge--<?= h($actionType) ?>"><?= h($actionLabel) ?></span></td>
                            <td>
                                <?= h(ucfirst(str_replace('_', ' ', $log['entity_type']))) ?>
                                <?= $log['entity_id'] ? '#' . (int) $log['entity_id'] : '' ?>
                            </td>
                            <td><?= h($log['details'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pageCount > 1): ?>
            <nav class="module-pagination" aria-label="Activity log pages">
                <?php
                $filterParameters = array_filter(
                    ['from' => $from, 'to' => $to, 'search' => $search],
                    static fn (string $value): bool => $value !== ''
                );
                ?>
                <?php if ($page > 1): ?>
                    <a href="?<?= h(http_build_query($filterParameters + ['page' => $page - 1])) ?>">Previous</a>
                <?php else: ?>
                    <span class="is-disabled">Previous</span>
                <?php endif; ?>
                <span>Page <?= number_format($page) ?> of <?= number_format($pageCount) ?></span>
                <?php if ($page < $pageCount): ?>
                    <a href="?<?= h(http_build_query($filterParameters + ['page' => $page + 1])) ?>">Next</a>
                <?php else: ?>
                    <span class="is-disabled">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
