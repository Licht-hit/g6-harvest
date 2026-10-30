<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff');

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$filterError = '';

if (($from !== '' && !is_valid_date($from)) || ($to !== '' && !is_valid_date($to))) {
    $filterError = 'Enter dates in the required year-month-day format.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The start date must be on or before the end date.';
}

$sales = [];
$harvests = [];
if ($filterError === '') {
    $salesConditions = ["s.status = 'paid'"];
    $salesParameters = [];
    if ($from !== '') {
        $salesConditions[] = 'DATE(s.created_at) >= ?';
        $salesParameters[] = $from;
    }
    if ($to !== '') {
        $salesConditions[] = 'DATE(s.created_at) <= ?';
        $salesParameters[] = $to;
    }
    if ($search !== '') {
        $salesConditions[] = '(s.order_code LIKE ? OR v.name LIKE ? OR u.name LIKE ?)';
        $searchTerm = '%' . $search . '%';
        array_push($salesParameters, $searchTerm, $searchTerm, $searchTerm);
    }

    $statement = $db->prepare(
        'SELECT
            DATE(s.created_at) AS day,
            COUNT(DISTINCT s.order_code) AS order_count,
            SUM(s.quantity) AS quantity,
            SUM(s.total) AS revenue
         FROM sales AS s
         JOIN vegetables AS v ON v.id = s.vegetable_id
         JOIN users AS u ON u.id = s.buyer_id
         WHERE ' . implode(' AND ', $salesConditions) . '
         GROUP BY DATE(s.created_at)
         ORDER BY day DESC
         LIMIT 30'
    );
    $statement->execute($salesParameters);
    $sales = $statement->fetchAll();

    $harvestConditions = ['h.archived_at IS NULL'];
    $harvestParameters = [];
    if ($from !== '') {
        $harvestConditions[] = 'h.harvested_on >= ?';
        $harvestParameters[] = $from;
    }
    if ($to !== '') {
        $harvestConditions[] = 'h.harvested_on <= ?';
        $harvestParameters[] = $to;
    }
    if ($search !== '') {
        $harvestConditions[] = 'v.name LIKE ?';
        $harvestParameters[] = '%' . $search . '%';
    }

    $statement = $db->prepare(
        'SELECT v.name, SUM(h.quantity) AS quantity, v.unit
         FROM harvests AS h
         JOIN plantings AS p ON p.id = h.planting_id
         JOIN vegetables AS v ON v.id = p.vegetable_id
         WHERE ' . implode(' AND ', $harvestConditions) . '
         GROUP BY v.id, v.name, v.unit
         ORDER BY v.name'
    );
    $statement->execute($harvestParameters);
    $harvests = $statement->fetchAll();
}

$pageTitle = 'Reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Reports</h1>
        <p class="muted">
            Filter sales and harvest totals by date or search term, then print the results.
        </p>
    </div>
    <button type="button" class="print-button" onclick="window.print()">Print report</button>
</div>

<section class="panel section report-filters">
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
            <label for="q">Search orders, buyers, or vegetables</label>
            <input id="q" name="q" value="<?= h($search) ?>" maxlength="100">
        </div>
        <div class="field report-filter-actions">
            <button type="submit">Apply filters</button>
            <a class="button secondary" href="<?= h(app_url('modules/reports.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<?php if ($filterError !== ''): ?>
    <div class="notice error" role="alert"><?= h($filterError) ?></div>
<?php endif; ?>

<div class="report-content">
<section class="panel section">
    <h2>Daily sales</h2>

    <?php if (!$sales): ?>
        <p class="empty">No sales to report.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Orders</th>
                        <th>Quantity</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $row): ?>
                        <tr>
                            <td><?= h($row['day']) ?></td>
                            <td><?= (int) $row['order_count'] ?></td>
                            <td><?= number_format((float) $row['quantity'], 2) ?></td>
                            <td><?= money($row['revenue']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel section">
    <h2>Harvest totals by vegetable</h2>

    <?php if (!$harvests): ?>
        <p class="empty">No harvests to report.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Vegetable</th>
                        <th>Total harvested</th>
                        <th>Unit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($harvests as $row): ?>
                        <tr>
                            <td><?= h($row['name']) ?></td>
                            <td><?= number_format((float) $row['quantity'], 2) ?></td>
                            <td><?= h($row['unit']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
