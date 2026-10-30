<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $harvestId = (int) ($_POST['harvest_id'] ?? 0);
    $grade = $_POST['grade'] ?? '';
    $date = $_POST['inspected_on'] ?? '';

    if ($harvestId < 1 || !in_array($grade, ['A', 'B', 'C', 'Rejected'], true) || !is_valid_date($date)) {
        $error = 'Choose a harvest, grade, and inspection date.';
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
        $notes = trim($_POST['notes'] ?? '');
        $statement->execute([
            $harvestId,
            (int) current_user()['id'],
            $grade,
            $notes ?: null,
            $date,
        ]);
        $inspectionId = (int) $db->lastInsertId();
        log_activity('quality_inspection_created', 'quality_inspection', $inspectionId, 'Quality inspection recorded.');
        flash('success', 'Quality inspection recorded.');
        redirect(app_url('modules/quality_inspection.php'));
    }
}

$harvests = $db->query(
    'SELECT h.id, h.harvested_on, v.name
     FROM harvests AS h
     JOIN plantings AS p ON p.id = h.planting_id
     JOIN vegetables AS v ON v.id = p.vegetable_id
     WHERE h.archived_at IS NULL
     ORDER BY h.harvested_on DESC'
)->fetchAll();
$inspections = $db->query(
    'SELECT q.*, v.name AS vegetable, u.name AS inspector
     FROM quality_inspections AS q
     JOIN harvests AS h ON h.id = q.harvest_id
     JOIN plantings AS p ON p.id = h.planting_id
     JOIN vegetables AS v ON v.id = p.vegetable_id
     JOIN users AS u ON u.id = q.inspector_id
     ORDER BY q.inspected_on DESC, q.id DESC'
)->fetchAll();

$pageTitle = 'Quality inspection';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading"><h1>Quality inspection</h1></div>

<section class="panel section">
    <h2>Record inspection</h2>

    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$harvests): ?>
        <p class="muted">Record a harvest before inspecting produce.</p>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>

            <div class="form-grid">
                <div class="field">
                    <label for="harvest_id">Harvest</label>
                    <select id="harvest_id" name="harvest_id" required>
                        <option value="">Choose harvest</option>
                        <?php foreach ($harvests as $harvest): ?>
                            <option value="<?= (int) $harvest['id'] ?>">
                                #<?= (int) $harvest['id'] ?> ·
                                <?= h($harvest['name']) ?> ·
                                <?= h($harvest['harvested_on']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="grade">Grade</label>
                    <select id="grade" name="grade" required>
                        <option>A</option>
                        <option>B</option>
                        <option>C</option>
                        <option>Rejected</option>
                    </select>
                </div>
                <div class="field">
                    <label for="inspected_on">Inspection date</label>
                    <input
                        id="inspected_on"
                        type="date"
                        name="inspected_on"
                        value="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>
            </div>

            <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes"></textarea>
            </div>

            <button type="submit">Save inspection</button>
        </form>
    <?php endif; ?>
</section>

<section class="panel section">
    <h2>Inspection history</h2>

    <?php if (!$inspections): ?>
        <p class="empty">No inspections recorded.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Harvest</th>
                        <th>Vegetable</th>
                        <th>Grade</th>
                        <th>Inspector</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inspections as $inspection): ?>
                        <tr>
                            <td><?= h($inspection['inspected_on']) ?></td>
                            <td>#<?= (int) $inspection['harvest_id'] ?></td>
                            <td><?= h($inspection['vegetable']) ?></td>
                            <td><?= h($inspection['grade']) ?></td>
                            <td><?= h($inspection['inspector']) ?></td>
                            <td><?= h($inspection['notes'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
