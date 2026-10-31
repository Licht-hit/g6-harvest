<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';
    $plantingId = (int) ($_POST['planting_id'] ?? 0);

    if ($action === 'archive') {
        $statement = $db->prepare(
            "UPDATE plantings SET status = 'cancelled'
             WHERE id = ? AND status = 'growing'"
        );
        $statement->execute([$plantingId]);
        if ($statement->rowCount() !== 1) {
            $error = 'Only a growing planting can be archived.';
        } else {
            log_activity('planting_archived', 'planting', $plantingId, 'Growing planting archived.');
            flash('success', 'Planting archived.');
            redirect(app_url('modules/plantings.php'));
        }
    } else {
        $vegetableId = (int) ($_POST['vegetable_id'] ?? 0);
        $quantity = filter_var($_POST['quantity_planted'] ?? null, FILTER_VALIDATE_FLOAT);
        $plantedOn = $_POST['planted_on'] ?? '';
        $expectedHarvestDate = $_POST['expected_harvest_on'] ?? '';

        if (!in_array($action, ['create', 'update'], true)) {
            $error = 'Choose a valid planting action.';
        } elseif ($vegetableId < 1
            || $quantity === false
            || $quantity <= 0
            || !is_valid_date($plantedOn)
            || ($expectedHarvestDate !== '' && !is_valid_date($expectedHarvestDate))) {
            $error = 'Choose a vegetable and enter a positive quantity and planting date.';
        } elseif ($action === 'update' && $plantingId < 1) {
            $error = 'Choose a valid planting to update.';
        } else {
            $vegetableCheck = $db->prepare('SELECT id FROM vegetables WHERE id = ? AND active = 1');
            $vegetableCheck->execute([$vegetableId]);
            if (!$vegetableCheck->fetch()) {
                $error = 'Choose an active vegetable.';
            } elseif ($action === 'update') {
                $existing = $db->prepare(
                    "SELECT id FROM plantings WHERE id = ? AND status = 'growing'"
                );
                $existing->execute([$plantingId]);
                if (!$existing->fetch()) {
                    $error = 'That planting is no longer available to edit.';
                } else {
                    $statement = $db->prepare(
                        "UPDATE plantings
                         SET vegetable_id = ?, quantity_planted = ?, planted_on = ?, expected_harvest_on = ?
                         WHERE id = ? AND status = 'growing'"
                    );
                    $statement->execute([
                        $vegetableId,
                        $quantity,
                        $plantedOn,
                        $expectedHarvestDate ?: null,
                        $plantingId,
                    ]);
                    log_activity('planting_updated', 'planting', $plantingId, 'Planting details updated.');
                    flash('success', 'Planting updated.');
                    redirect(app_url('modules/plantings.php'));
                }
            } else {
                $statement = $db->prepare(
                    'INSERT INTO plantings (
                        vegetable_id,
                        quantity_planted,
                        planted_on,
                        expected_harvest_on
                    ) VALUES (?, ?, ?, ?)'
                );
                $statement->execute([
                    $vegetableId,
                    $quantity,
                    $plantedOn,
                    $expectedHarvestDate ?: null,
                ]);
                $plantingId = (int) $db->lastInsertId();
                log_activity('planting_created', 'planting', $plantingId, 'Planting recorded.');
                flash('success', 'Planting recorded.');
                redirect(app_url('modules/plantings.php'));
            }
        }
    }
}

$editingPlanting = null;
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($editId && $editId > 0) {
    $statement = $db->prepare(
        "SELECT * FROM plantings WHERE id = ? AND status = 'growing'"
    );
    $statement->execute([$editId]);
    $editingPlanting = $statement->fetch() ?: null;
}

$vegetables = $db->query(
    'SELECT id, name
     FROM vegetables
     WHERE active = 1
     ORDER BY name'
)->fetchAll();
$plantings = $db->query(
    'SELECT p.*, v.name AS vegetable
     FROM plantings AS p
     JOIN vegetables AS v ON v.id = p.vegetable_id
     ORDER BY p.planted_on DESC, p.id DESC'
)->fetchAll();

$pageTitle = 'Plantings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading"><h1>Plantings</h1></div>

<section class="panel section">
    <h2><?= $editingPlanting ? 'Edit planting' : 'Record planting' ?></h2>

    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$vegetables): ?>
        <p class="muted">Add an active vegetable before recording plantings.</p>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <?php if ($editingPlanting): ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="planting_id" value="<?= (int) $editingPlanting['id'] ?>">
                <p><a href="<?= h(app_url('modules/plantings.php')) ?>">Cancel editing</a></p>
            <?php endif; ?>

            <div class="form-grid">
                <div class="field">
                    <label for="vegetable_id">Vegetable</label>
                    <select id="vegetable_id" name="vegetable_id" required>
                        <option value="">Choose one</option>
                        <?php foreach ($vegetables as $vegetable): ?>
                            <option
                                value="<?= (int) $vegetable['id'] ?>"
                                <?= $editingPlanting && (int) $editingPlanting['vegetable_id'] === (int) $vegetable['id'] ? 'selected' : '' ?>>
                                <?= h($vegetable['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="quantity_planted">Quantity planted</label>
                    <input
                        id="quantity_planted"
                        type="number"
                        min="0.01"
                        step="0.01"
                        name="quantity_planted"
                        value="<?= h($editingPlanting['quantity_planted'] ?? '') ?>"
                        required
                    >
                </div>
                <div class="field">
                    <label for="planted_on">Planted on</label>
                    <input
                        id="planted_on"
                        type="date"
                        name="planted_on"
                        required
                        value="<?= h($editingPlanting['planted_on'] ?? date('Y-m-d')) ?>"
                    >
                </div>
                <div class="field">
                    <label for="expected_harvest_on">Expected harvest</label>
                    <input
                        id="expected_harvest_on"
                        type="date"
                        name="expected_harvest_on"
                        value="<?= h($editingPlanting['expected_harvest_on'] ?? '') ?>"
                    >
                </div>
            </div>

            <button type="submit"><?= $editingPlanting ? 'Save changes' : 'Save planting' ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="panel section">
    <h2>Planting records</h2>

    <?php if (!$plantings): ?>
        <p class="empty">No plantings recorded.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Vegetable</th>
                        <th>Quantity</th>
                        <th>Planted</th>
                        <th>Expected harvest</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plantings as $planting): ?>
                        <tr>
                            <td><?= h($planting['vegetable']) ?></td>
                            <td>
                                <?= number_format((float) $planting['quantity_planted'], 2) ?>
                            </td>
                            <td><?= h($planting['planted_on']) ?></td>
                            <td><?= h($planting['expected_harvest_on'] ?? '—') ?></td>
                            <td>
                                <span class="status-badge status-<?= h($planting['status']) ?>">
                                    <?= h(ucfirst($planting['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($planting['status'] === 'growing'): ?>
                                    <a href="<?= h(app_url('modules/plantings.php?edit=' . (int) $planting['id'])) ?>">Edit</a>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="planting_id" value="<?= (int) $planting['id'] ?>">
                                        <button class="button secondary" type="submit">Archive</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">History retained</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
