<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$filterError = '';

if (($from !== '' && !is_valid_date($from)) || ($to !== '' && !is_valid_date($to))) {
    $filterError = 'Enter dates in the required year-month-day format.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The start date must be on or before the end date.';
}

$logs = [];
if ($filterError === '') {
    $conditions = ['1 = 1'];
    $parameters = [];
    if ($from !== '') {
        $conditions[] = 'DATE(l.created_at) >= ?';
        $parameters[] = $from;
    }
    if ($to !== '') {
        $conditions[] = 'DATE(l.created_at) <= ?';
        $parameters[] = $to;
    }
    if ($search !== '') {
        $conditions[] = '(l.action LIKE ? OR l.entity_type LIKE ? OR l.details LIKE ? OR u.name LIKE ?)';
        $searchTerm = '%' . $search . '%';
        array_push($parameters, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    $statement = $db->prepare(
        'SELECT l.id, l.action, l.entity_type, l.entity_id, l.details, l.created_at,
                COALESCE(u.name, "Deleted user") AS actor
         FROM activity_logs AS l
         LEFT JOIN users AS u ON u.id = l.user_id
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT 250'
    );
    $statement->execute($parameters);
    $logs = $statement->fetchAll();
}

$pageTitle = 'Activity log';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Activity log</h1>
        <p class="muted">Recent account and farm operation actions for accountability.</p>
    </div>
</div>

<section class="panel section">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <div class="field">
            <label for="q">Search activity</label>
            <input id="q" name="q" value="<?= h($search) ?>" maxlength="100">
        </div>
        <div class="field report-filter-actions">
            <button type="submit">Search</button>
            <a class="button secondary" href="<?= h(app_url('modules/activity_logs.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<?php if ($filterError !== ''): ?>
    <div class="notice error" role="alert"><?= h($filterError) ?></div>
<?php endif; ?>

<section class="panel section">
    <?php if (!$logs): ?>
        <p class="empty">No activity matches those filters.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date and time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Related record</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= h($log['created_at']) ?></td>
                            <td><?= h($log['actor']) ?></td>
                            <td><?= h(str_replace('_', ' ', ucfirst($log['action']))) ?></td>
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
        <p class="muted">Showing up to 250 recent matching actions.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
