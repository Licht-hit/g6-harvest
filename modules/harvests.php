<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/fifo.php';
require_role('admin', 'staff', 'farmer');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';
    $harvestId = (int) ($_POST['harvest_id'] ?? 0);
    $plantingId = (int) ($_POST['planting_id'] ?? 0);
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
    $harvestedOn = $_POST['harvested_on'] ?? '';
    $notesInput = $_POST['quality_notes'] ?? '';
    $notes = is_string($notesInput) ? trim($notesInput) : null;
    $notesLength = $notes === null
        ? null
        : (function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes));

    if ($action === 'create'
        && ($plantingId < 1 || $quantity === false || $quantity <= 0 || !is_valid_date($harvestedOn)
            || $notes === null || $notesLength > 4000)) {
        $error = 'Choose a planting, enter a positive quantity and valid harvest date, and keep notes to 4,000 characters or fewer.';
    } elseif ($action === 'update'
        && ($harvestId < 1 || $quantity === false || $quantity <= 0 || !is_valid_date($harvestedOn)
            || $notes === null || $notesLength > 4000)) {
        $error = 'Enter a positive quantity and valid harvest date, and keep notes to 4,000 characters or fewer.';
    } elseif (in_array($action, ['create', 'update', 'archive'], true)) {
        try {
            $db->beginTransaction();

            if ($action === 'create') {
                // Lock the planting so two staff members cannot harvest it at once.
                $statement = $db->prepare(
                    "SELECT id, vegetable_id
                     FROM plantings
                     WHERE id = ? AND status = 'growing'
                     FOR UPDATE"
                );
                $statement->execute([$plantingId]);
                $planting = $statement->fetch();
                if (!$planting) {
                    throw new RuntimeException('That planting is no longer available for harvest.');
                }

                $insert = $db->prepare(
                    'INSERT INTO harvests (planting_id, quantity, harvested_on, quality_notes)
                     VALUES (?, ?, ?, ?)'
                );
                $insert->execute([$plantingId, $quantity, $harvestedOn, $notes ?: null]);
                $harvestId = (int) $db->lastInsertId();

                $stockId = (int) $planting['vegetable_id'];
                $stockCheck = $db->prepare(
                    'SELECT quantity FROM inventory WHERE vegetable_id = ? FOR UPDATE'
                );
                $stockCheck->execute([$stockId]);
                $currentQuantity = $stockCheck->fetchColumn();
                $quantityBefore = $currentQuantity === false ? 0.0 : (float) $currentQuantity;
                if ($currentQuantity === false) {
                    $stock = $db->prepare(
                        'INSERT INTO inventory (vegetable_id, quantity) VALUES (?, ?)'
                    );
                    $stock->execute([$stockId, $quantity]);
                } else {
                    $stock = $db->prepare(
                        'UPDATE inventory SET quantity = quantity + ? WHERE vegetable_id = ?'
                    );
                    $stock->execute([$quantity, $stockId]);
                }
                fifo_add_inventory_lot(
                    $db,
                    $stockId,
                    (float) $quantity,
                    'harvest',
                    $harvestedOn,
                    $harvestId
                );
                record_inventory_movement(
                    $db,
                    $stockId,
                    $quantityBefore,
                    round($quantityBefore + (float) $quantity, 2),
                    'harvest_recorded',
                    'Stock added by harvest #' . $harvestId . '.',
                    (string) $harvestId
                );
                log_activity('harvest_created', 'harvest', $harvestId, 'Harvest recorded.');
                $successMessage = 'Harvest recorded and inventory updated.';
            } else {
                $statement = $db->prepare(
                    'SELECT h.id, h.quantity, p.vegetable_id
                     FROM harvests AS h
                     JOIN plantings AS p ON p.id = h.planting_id
                     WHERE h.id = ? AND h.archived_at IS NULL
                     FOR UPDATE'
                );
                $statement->execute([$harvestId]);
                $harvest = $statement->fetch();
                if (!$harvest) {
                    throw new RuntimeException('That harvest is no longer available.');
                }

                $stockId = (int) $harvest['vegetable_id'];
                $stockCheck = $db->prepare('SELECT quantity FROM inventory WHERE vegetable_id = ? FOR UPDATE');
                $stockCheck->execute([$stockId]);
                $stock = $stockCheck->fetch();
                $quantityBefore = $stock ? (float) $stock['quantity'] : 0.0;

                if ($action === 'update') {
                    $difference = round((float) $quantity - (float) $harvest['quantity'], 2);
                    fifo_adjust_harvest_lot(
                        $db,
                        $stockId,
                        $harvestId,
                        $difference,
                        $harvestedOn
                    );
                    if ($difference > 0) {
                        $changeStock = $db->prepare(
                            'INSERT INTO inventory (vegetable_id, quantity)
                             VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
                        );
                        $changeStock->execute([$stockId, $difference]);
                    } elseif ($difference < 0) {
                        $amountToRemove = abs($difference);
                        $changeStock = $db->prepare(
                            'UPDATE inventory
                             SET quantity = quantity - ?
                             WHERE vegetable_id = ? AND quantity >= ?'
                        );
                        $changeStock->execute([$amountToRemove, $stockId, $amountToRemove]);
                        if ($changeStock->rowCount() !== 1) {
                            throw new RuntimeException('Stock is too low to reduce this harvest quantity.');
                        }
                    }

                    if ($difference !== 0.0) {
                        record_inventory_movement(
                            $db,
                            $stockId,
                            $quantityBefore,
                            round($quantityBefore + $difference, 2),
                            'harvest_corrected',
                            'Stock changed after harvest #' . $harvestId . ' was corrected.',
                            (string) $harvestId
                        );
                    }

                    $update = $db->prepare(
                        'UPDATE harvests SET quantity = ?, harvested_on = ?, quality_notes = ? WHERE id = ?'
                    );
                    $update->execute([$quantity, $harvestedOn, $notes ?: null, $harvestId]);
                    log_activity('harvest_updated', 'harvest', $harvestId, 'Harvest details updated.');
                    $successMessage = 'Harvest updated and inventory adjusted.';
                } else {
                    if (!$stock || (float) $stock['quantity'] < (float) $harvest['quantity']) {
                        throw new RuntimeException(
                            'Cannot archive this harvest because some of its stock has already been sold or removed.'
                        );
                    }
                    fifo_remove_available_stock(
                        $db,
                        $stockId,
                        (float) $harvest['quantity'],
                        $harvestId,
                        true
                    );

                    $removeStock = $db->prepare(
                        'UPDATE inventory
                         SET quantity = quantity - ?
                         WHERE vegetable_id = ? AND quantity >= ?'
                    );
                    $removeStock->execute([
                        $harvest['quantity'],
                        $stockId,
                        $harvest['quantity'],
                    ]);
                    if ($removeStock->rowCount() !== 1) {
                        throw new RuntimeException('Stock changed. Reload the page and try again.');
                    }

                    record_inventory_movement(
                        $db,
                        $stockId,
                        $quantityBefore,
                        round($quantityBefore - (float) $harvest['quantity'], 2),
                        'harvest_archived',
                        'Stock removed after harvest #' . $harvestId . ' was archived.',
                        (string) $harvestId
                    );

                    $archive = $db->prepare('UPDATE harvests SET archived_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $archive->execute([$harvestId]);
                    log_activity('harvest_archived', 'harvest', $harvestId, 'Harvest archived from active inventory.');
                    $successMessage = 'Harvest archived and inventory adjusted.';
                }
            }

            // Record the change and its inventory adjustment as one transaction.
            $db->commit();
            flash('success', $successMessage);
            redirect(app_url('modules/harvests.php'));
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                $error = $exception->getMessage();
            } else {
                throw $exception;
            }
        }
    } else {
        $error = 'Choose a valid harvest action.';
    }
}

$editingHarvest = null;
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($editId && $editId > 0) {
    $statement = $db->prepare(
        'SELECT h.*, p.vegetable_id, v.name AS vegetable
         FROM harvests AS h
         JOIN plantings AS p ON p.id = h.planting_id
         JOIN vegetables AS v ON v.id = p.vegetable_id
         WHERE h.id = ? AND h.archived_at IS NULL'
    );
    $statement->execute([$editId]);
    $editingHarvest = $statement->fetch() ?: null;
}

$plantings = $db->query(
    "SELECT p.id, p.planted_on, v.name
     FROM plantings AS p
     JOIN vegetables AS v ON v.id = p.vegetable_id
     WHERE p.status = 'growing'
     ORDER BY p.planted_on"
)->fetchAll();
$harvests = $db->query(
    'SELECT h.*, v.name AS vegetable, v.unit, p.id AS planting_number
     FROM harvests AS h
     JOIN plantings AS p ON p.id = h.planting_id
     JOIN vegetables AS v ON v.id = p.vegetable_id
     ORDER BY h.harvested_on DESC, h.id DESC'
)->fetchAll();

$pageTitle = 'Harvests';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Farm operations</p>
        <h1>Harvests</h1>
        <p>Record harvest quantities; stock is updated automatically.</p>
    </div>
</section>

<section class="panel section">
    <h2><?= $editingHarvest ? 'Edit harvest #' . (int) $editingHarvest['id'] : 'Record a harvest' ?></h2>

    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$plantings && !$editingHarvest): ?>
        <p class="muted">There are no growing plantings available to harvest.</p>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <?php if ($editingHarvest): ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="harvest_id" value="<?= (int) $editingHarvest['id'] ?>">
                <p>
                    Editing harvest #<?= (int) $editingHarvest['id'] ?> —
                    <?= h($editingHarvest['vegetable']) ?>,
                    planting #<?= (int) $editingHarvest['planting_id'] ?>.
                    <a href="<?= h(app_url('modules/harvests.php')) ?>">Cancel editing</a>
                </p>
            <?php else: ?>
                <input type="hidden" name="action" value="create">
            <?php endif; ?>
            <div class="form-grid">
                <?php if (!$editingHarvest): ?>
                    <div class="field">
                        <label for="planting_id">Planting</label>
                        <select id="planting_id" name="planting_id" required>
                            <option value="">Choose planting</option>
                            <?php foreach ($plantings as $planting): ?>
                                <option value="<?= (int) $planting['id'] ?>">
                                    <?= h($planting['name']) ?> · #<?= (int) $planting['id'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="quantity">Quantity harvested</label>
                    <input
                        id="quantity"
                        type="number"
                        min="0.01"
                        step="0.01"
                        name="quantity"
                        value="<?= h($editingHarvest['quantity'] ?? '') ?>"
                        required
                    >
                </div>
                <div class="field">
                    <label for="harvested_on">Harvest date</label>
                    <input
                        id="harvested_on"
                        type="date"
                        name="harvested_on"
                        value="<?= h($editingHarvest['harvested_on'] ?? date('Y-m-d')) ?>"
                        required
                    >
                </div>
            </div>

            <div class="field">
                <label for="quality_notes">Quality notes</label>
                <textarea id="quality_notes" name="quality_notes" maxlength="4000"><?= h($editingHarvest['quality_notes'] ?? '') ?></textarea>
            </div>

            <button type="submit"><?= $editingHarvest ? 'Save changes' : 'Save harvest' ?></button>
            <?php if (!$editingHarvest): ?>
                <button class="button secondary" type="reset">Clear</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>

<section class="panel section">
    <h2>Harvest records</h2>

    <?php if (!$harvests): ?>
        <p class="empty">No harvests recorded.</p>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Harvested</th>
                        <th>Vegetable</th>
                        <th>Planting</th>
                        <th>Quantity</th>
                        <th>Notes</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($harvests as $harvest): ?>
                        <tr>
                            <td><?= h(date('M j, Y', strtotime($harvest['harvested_on']))) ?></td>
                            <td><?= h($harvest['vegetable']) ?></td>
                            <td>#<?= (int) $harvest['planting_number'] ?></td>
                            <td><?= number_format((float) $harvest['quantity'], 2) ?> <?= h($harvest['unit']) ?></td>
                            <td><?= h($harvest['quality_notes'] ?? '—') ?></td>
                            <td>
                                <span class="status-badge <?= $harvest['archived_at'] ? 'status-archived' : 'status-active' ?>">
                                    <?= $harvest['archived_at'] ? 'Archived' : 'Active' ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!$harvest['archived_at']): ?>
                                    <div class="farm-record-actions">
                                        <a class="button secondary" href="<?= h(app_url('modules/harvests.php?edit=' . (int) $harvest['id'])) ?>">Edit</a>
                                        <form method="post" data-confirm="Archive this harvest? Inventory will be reduced by its quantity.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="harvest_id" value="<?= (int) $harvest['id'] ?>">
                                            <button class="button danger" type="submit">Archive</button>
                                        </form>
                                    </div>
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
