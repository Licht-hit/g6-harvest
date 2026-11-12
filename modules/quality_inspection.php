<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff', 'farmer');

$gradeLabels = [
    'A' => 'Excellent',
    'B' => 'Good',
    'C' => 'Fair',
    'Rejected' => 'Poor',
];
$validGrades = ['', 'A', 'B', 'C', 'Rejected'];
$error = '';
$selectedHarvest = '';
$selectedGrade = 'A';
$inspectionDate = date('Y-m-d');
$notes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $rawHarvestId = $_POST['harvest_id'] ?? '';
    $selectedHarvest = is_string($rawHarvestId) ? $rawHarvestId : '';
    $rawGrade = $_POST['grade'] ?? '';
    $selectedGrade = is_string($rawGrade) ? $rawGrade : '';
    $rawInspectionDate = $_POST['inspected_on'] ?? '';
    $inspectionDate = is_string($rawInspectionDate) ? trim($rawInspectionDate) : '';
    $rawNotes = $_POST['notes'] ?? '';
    $notes = is_string($rawNotes) ? trim($rawNotes) : '';
    $harvestId = filter_var($selectedHarvest, FILTER_VALIDATE_INT);

    if (!$harvestId || $harvestId < 1
        || !in_array($selectedGrade, ['A', 'B', 'C', 'Rejected'], true)
        || !is_valid_date($inspectionDate)
        || strlen($notes) > 4000) {
        $error = 'Choose a harvest and quality grade, enter a valid inspection date, and keep notes under 4,000 characters.';
    } else {
        $harvestCheck = $db->prepare(
            'SELECT id
             FROM harvests
             WHERE id = ? AND archived_at IS NULL'
        );
        $harvestCheck->execute([$harvestId]);

        if (!$harvestCheck->fetch()) {
            $error = 'Choose an active harvest record.';
        } else {
            $statement = $db->prepare(
                'INSERT INTO quality_inspections (
                    harvest_id,
                    inspector_id,
                    grade,
                    notes,
                    inspected_on
                ) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $harvestId,
                (int) current_user()['id'],
                $selectedGrade,
                $notes !== '' ? $notes : null,
                $inspectionDate,
            ]);
            $inspectionId = (int) $db->lastInsertId();
            log_activity(
                'quality_inspection_created',
                'quality_inspection',
                $inspectionId,
                'Quality inspection recorded.'
            );
            flash('success', 'Quality inspection recorded.');
            redirect(app_url('modules/quality_inspection.php'));
        }
    }
}

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$gradeFilter = is_string($_GET['grade'] ?? null) ? trim($_GET['grade']) : '';
$filterError = '';

if (($from !== '' && !is_valid_date($from)) || ($to !== '' && !is_valid_date($to))) {
    $filterError = 'Enter dates in the required year-month-day format.';
} elseif ($from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The start date must be on or before the end date.';
} elseif (!in_array($gradeFilter, $validGrades, true)) {
    $filterError = 'Choose a valid inspection grade.';
}

$conditions = [];
$parameters = [];
if ($from !== '') {
    $conditions[] = 'q.inspected_on >= ?';
    $parameters[] = $from;
}
if ($to !== '') {
    $conditions[] = 'q.inspected_on <= ?';
    $parameters[] = $to;
}
if ($gradeFilter !== '') {
    $conditions[] = 'q.grade = ?';
    $parameters[] = $gradeFilter;
}
if ($search !== '') {
    $conditions[] = '(v.name LIKE ? OR u.name LIKE ? OR q.notes LIKE ? OR CAST(q.harvest_id AS CHAR) LIKE ?)';
    $searchTerm = '%' . $search . '%';
    array_push($parameters, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$summary = ['total' => 0, 'excellent' => 0, 'good' => 0, 'attention' => 0];
$inspections = [];
$matchingInspections = 0;

if ($filterError === '') {
    $summaryStatement = $db->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(q.grade = \'A\') AS excellent,
            SUM(q.grade = \'B\') AS good,
            SUM(q.grade IN (\'C\', \'Rejected\')) AS attention
         FROM quality_inspections AS q
         JOIN harvests AS h ON h.id = q.harvest_id
         JOIN plantings AS p ON p.id = h.planting_id
         JOIN vegetables AS v ON v.id = p.vegetable_id
         JOIN users AS u ON u.id = q.inspector_id
         ' . $whereSql
    );
    $summaryStatement->execute($parameters);
    $summary = $summaryStatement->fetch();
    $matchingInspections = (int) $summary['total'];

    $statement = $db->prepare(
        'SELECT
            q.id,
            q.harvest_id,
            q.grade,
            q.notes,
            q.inspected_on,
            h.harvested_on,
            h.quantity,
            v.name AS vegetable,
            v.unit,
            u.name AS inspector
         FROM quality_inspections AS q
         JOIN harvests AS h ON h.id = q.harvest_id
         JOIN plantings AS p ON p.id = h.planting_id
         JOIN vegetables AS v ON v.id = p.vegetable_id
         JOIN users AS u ON u.id = q.inspector_id
         ' . $whereSql . '
         ORDER BY q.inspected_on DESC, q.id DESC
         LIMIT 300'
    );
    $statement->execute($parameters);
    $inspections = $statement->fetchAll();
}

$harvests = $db->query(
    'SELECT
        h.id,
        h.harvested_on,
        h.quantity,
        v.name AS vegetable,
        v.unit
     FROM harvests AS h
     JOIN plantings AS p ON p.id = h.planting_id
     JOIN vegetables AS v ON v.id = p.vegetable_id
     WHERE h.archived_at IS NULL
     ORDER BY h.harvested_on DESC, h.id DESC'
)->fetchAll();

$pageTitle = 'Quality inspection';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Produce standards</p>
        <h1>Quality inspection</h1>
        <p>Record and review quality checks for active harvest records.</p>
    </div>
</section>

<section class="module-stat-grid" aria-label="Inspection summary">
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">✓</span>
        <span>Total inspections</span>
        <strong><?= number_format((int) $summary['total']) ?></strong>
        <small>Matching the selected filters</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">★</span>
        <span>Excellent quality</span>
        <strong><?= number_format((int) $summary['excellent']) ?></strong>
        <small>Grade A</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">＋</span>
        <span>Good quality</span>
        <strong><?= number_format((int) $summary['good']) ?></strong>
        <small>Grade B</small>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon" aria-hidden="true">!</span>
        <span>Needs attention</span>
        <strong><?= number_format((int) $summary['attention']) ?></strong>
        <small>Fair or poor grades</small>
    </article>
</section>

<section class="panel section quality-form-panel">
    <div class="module-table-heading">
        <div>
            <h2>Record an inspection</h2>
            <p class="muted">Select an active harvest and record its grade and observations.</p>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="notice error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$harvests): ?>
        <div class="module-empty-state module-empty-state--compact">
            <span class="module-empty-icon" aria-hidden="true">⌁</span>
            <h3>No harvest records available</h3>
            <p>Record a harvest before inspecting produce.</p>
            <a class="button" href="<?= h(app_url('modules/harvests.php')) ?>">Go to harvests</a>
        </div>
    <?php else: ?>
        <form method="post" class="quality-form">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="harvest_id">Harvest record</label>
                    <select id="harvest_id" name="harvest_id" required>
                        <option value="">Choose a harvest</option>
                        <?php foreach ($harvests as $harvest): ?>
                            <option
                                value="<?= (int) $harvest['id'] ?>"
                                <?= (string) $harvest['id'] === $selectedHarvest ? 'selected' : '' ?>
                            >
                                #<?= (int) $harvest['id'] ?> · <?= h($harvest['vegetable']) ?> ·
                                <?= h($harvest['harvested_on']) ?> ·
                                <?= number_format((float) $harvest['quantity'], 2) ?> <?= h($harvest['unit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="grade">Quality grade</label>
                    <select id="grade" name="grade" required>
                        <?php foreach ($gradeLabels as $grade => $label): ?>
                            <option value="<?= h($grade) ?>" <?= $selectedGrade === $grade ? 'selected' : '' ?>>
                                <?= h($label) ?> (<?= h($grade) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="inspected_on">Inspection date</label>
                    <input
                        id="inspected_on"
                        type="date"
                        name="inspected_on"
                        value="<?= h($inspectionDate) ?>"
                        required
                    >
                </div>
            </div>
            <div class="field">
                <label for="notes">Inspection notes</label>
                <textarea
                    id="notes"
                    name="notes"
                    maxlength="4000"
                    placeholder="Describe the produce and any condition concerns."
                ><?= h($notes) ?></textarea>
                <small class="field-help">
                    Notes can include physical condition. The existing database stores grade and notes, but has no separate condition field.
                </small>
            </div>
            <div class="module-form-actions">
                <button type="submit">Save inspection</button>
                <button class="button secondary" type="reset">Clear form</button>
            </div>
        </form>
    <?php endif; ?>
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
            <label for="q">Search inspections</label>
            <input
                id="q"
                name="q"
                value="<?= h($search) ?>"
                maxlength="100"
                placeholder="Vegetable, inspector, note, or harvest ID"
            >
        </div>
        <div class="field">
            <label for="grade_filter">Quality grade</label>
            <select id="grade_filter" name="grade">
                <option value="">All grades</option>
                <?php foreach ($gradeLabels as $grade => $label): ?>
                    <option value="<?= h($grade) ?>" <?= $gradeFilter === $grade ? 'selected' : '' ?>>
                        <?= h($label) ?> (<?= h($grade) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="module-filter-actions">
            <button type="submit">Apply filters</button>
            <a class="button secondary" href="<?= h(app_url('modules/quality_inspection.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<?php if ($filterError !== ''): ?>
    <div class="notice error" role="alert"><?= h($filterError) ?></div>
<?php endif; ?>

<section class="panel section module-table-panel">
    <div class="module-table-heading">
        <div>
            <h2>Inspection history</h2>
            <p class="muted"><?= number_format($matchingInspections) ?> matching records</p>
        </div>
        <?php if ($matchingInspections > 300): ?>
            <span class="module-record-range">Showing the latest 300 inspections</span>
        <?php endif; ?>
    </div>

    <?php if ($filterError !== ''): ?>
        <div class="module-empty-state">
            <span class="module-empty-icon" aria-hidden="true">⌕</span>
            <h3>Check the filters</h3>
            <p>Correct your dates or grade to view matching inspections.</p>
        </div>
    <?php elseif (!$inspections): ?>
        <div class="module-empty-state">
            <span class="module-empty-icon" aria-hidden="true">✓</span>
            <h3>No inspections found</h3>
            <p>
                <?= $matchingInspections === 0 && !$harvests
                    ? 'Record a harvest before starting an inspection.'
                    : 'Record an inspection or adjust the selected filters.' ?>
            </p>
            <?php if ($from !== '' || $to !== '' || $search !== '' || $gradeFilter !== ''): ?>
                <a class="button secondary" href="<?= h(app_url('modules/quality_inspection.php')) ?>">Clear filters</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Inspection date</th>
                        <th>Harvest</th>
                        <th>Vegetable</th>
                        <th>Quantity</th>
                        <th>Grade</th>
                        <th>Inspector</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inspections as $inspection): ?>
                        <?php
                        $gradeClass = strtolower($inspection['grade']);
                        $gradeLabel = $gradeLabels[$inspection['grade']] ?? $inspection['grade'];
                        ?>
                        <tr>
                            <td><?= h(date('M j, Y', strtotime($inspection['inspected_on']))) ?></td>
                            <td>
                                <strong>#<?= (int) $inspection['harvest_id'] ?></strong>
                                <small class="table-secondary">Harvested <?= h($inspection['harvested_on']) ?></small>
                            </td>
                            <td><?= h($inspection['vegetable']) ?></td>
                            <td><?= number_format((float) $inspection['quantity'], 2) ?> <?= h($inspection['unit']) ?></td>
                            <td>
                                <span class="status-badge status-grade-<?= h($gradeClass) ?>">
                                    <?= h($gradeLabel) ?> (<?= h($inspection['grade']) ?>)
                                </span>
                            </td>
                            <td><?= h($inspection['inspector']) ?></td>
                            <td><?= h($inspection['notes'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
