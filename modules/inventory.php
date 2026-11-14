<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/fifo.php';
require_role('admin', 'staff', 'farmer');

$isAdmin = user_has_role(current_user(), 'admin');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $vegetableIdInput = $_POST['vegetable_id'] ?? null;
    $vegetableId = is_string($vegetableIdInput) || is_int($vegetableIdInput)
        ? filter_var($vegetableIdInput, FILTER_VALIDATE_INT)
        : false;
    $quantityInput = $_POST['quantity'] ?? null;
    $quantity = is_string($quantityInput) || is_int($quantityInput) || is_float($quantityInput)
        ? filter_var($quantityInput, FILTER_VALIDATE_FLOAT)
        : false;
    $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';

    if (
        $vegetableId === false
        || $vegetableId < 1
        || $quantity === false
        || !is_finite((float) $quantity)
        || $quantity < 0
        || $quantity > 99999999.99
        || abs((float) $quantity - round((float) $quantity, 2)) > 0.000000001
    ) {
        $error = 'Choose a vegetable and enter a stock quantity from 0 to 99,999,999.99 with at most two decimal places.';
    } elseif (strlen($reason) < 3 || strlen($reason) > 160) {
        $error = 'Enter a stock adjustment reason between 3 and 160 characters.';
    } else {
        try {
            $db->beginTransaction();

            $vegetableStatement = $db->prepare(
                'SELECT name, unit FROM vegetables WHERE id = ? FOR UPDATE'
            );
            $vegetableStatement->execute([$vegetableId]);
            $vegetable = $vegetableStatement->fetch();
            if (!$vegetable) {
                throw new RuntimeException('Choose a vegetable that still exists in the catalog.');
            }

            $inventoryStatement = $db->prepare(
                'SELECT quantity FROM inventory WHERE vegetable_id = ? FOR UPDATE'
            );
            $inventoryStatement->execute([$vegetableId]);
            $currentQuantity = $inventoryStatement->fetchColumn();
            $previousQuantity = $currentQuantity === false ? 0.0 : (float) $currentQuantity;

            if ($currentQuantity === false) {
                $saveStock = $db->prepare(
                    'INSERT INTO inventory (vegetable_id, quantity) VALUES (?, ?)'
                );
            } else {
                $saveStock = $db->prepare(
                    'UPDATE inventory SET quantity = ? WHERE vegetable_id = ?'
                );
            }
            $saveStock->execute(
                $currentQuantity === false
                    ? [$vegetableId, $quantity]
                    : [$quantity, $vegetableId]
            );
            fifo_reconcile_manual_adjustment(
                    $db,
                    $vegetableId,
                    $previousQuantity,
                    (float) $quantity
            );

            record_inventory_movement(
                $db,
                $vegetableId,
                $previousQuantity,
                (float) $quantity,
                'manual_adjustment',
                $reason
            );
            log_activity(
                'inventory_adjusted',
                'vegetable',
                $vegetableId,
                'Stock changed from ' . number_format($previousQuantity, 2)
                    . ' to ' . number_format((float) $quantity, 2) . ' '
                    . $vegetable['unit'] . '. Reason: ' . $reason
            );
            $db->commit();
            flash('success', 'Inventory quantity updated and adjustment recorded.');
            redirect(app_url('modules/inventory.php'));
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
    }
}

$vegetables = $db->query(
    'SELECT id, name
     FROM vegetables
     ORDER BY name'
)->fetchAll();
$inventory = $db->query(
    'SELECT
        v.id,
        v.name,
        v.unit,
        COALESCE(i.quantity, 0) AS quantity,
        i.updated_at
     FROM vegetables AS v
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     ORDER BY v.name'
)->fetchAll();
$fifoLots = $db->query(
    "SELECT
        l.id,
        l.vegetable_id,
        l.harvest_id,
        l.source_type,
        l.received_on,
        l.quantity_remaining,
        v.name AS vegetable,
        v.unit
     FROM inventory_lots AS l
     JOIN vegetables AS v ON v.id = l.vegetable_id
     WHERE l.quantity_remaining > 0
     ORDER BY v.name,
        CASE WHEN l.source_type = 'opening_balance' THEN 0 ELSE 1 END,
        l.received_on,
        l.id"
)->fetchAll();
$fifoPositionByVegetable = [];
$fifoBatchRows = [];
foreach ($fifoLots as $lot) {
    $vegetableId = (int) $lot['vegetable_id'];
    $fifoPositionByVegetable[$vegetableId] = ($fifoPositionByVegetable[$vegetableId] ?? 0) + 1;
    $source = $lot['source_type'] === 'opening_balance'
        ? 'Opening balance'
        : ($lot['source_type'] === 'harvest'
            ? 'Harvest #' . (int) $lot['harvest_id']
            : 'Manual addition');
    $received = $lot['source_type'] === 'opening_balance'
        ? 'Opening balance'
        : date('M j, Y', strtotime($lot['received_on']));
    $fifoBatchRows[] = [
        'position' => $fifoPositionByVegetable[$vegetableId],
        'vegetable' => $lot['vegetable'],
        'source' => $source,
        'received' => $received,
        'quantity' => (float) $lot['quantity_remaining'],
        'unit' => $lot['unit'],
    ];
}

$movementTypes = [
    'manual_adjustment' => 'Manual adjustment',
    'harvest_recorded' => 'Harvest recorded',
    'harvest_corrected' => 'Harvest correction',
    'harvest_archived' => 'Harvest archived',
    'order_reserved' => 'Order stock reserved',
    'order_cancelled' => 'Cancelled order restocked',
];
$movements = [];
$matchingMovements = 0;
$movementPage = 1;
$movementPageCount = 1;
$movementFilterError = '';
$movementFrom = is_string($_GET['movement_from'] ?? null) ? trim($_GET['movement_from']) : '';
$movementTo = is_string($_GET['movement_to'] ?? null) ? trim($_GET['movement_to']) : '';
$movementVegetableId = filter_var(
    $_GET['movement_vegetable'] ?? null,
    FILTER_VALIDATE_INT
);
$movementType = is_string($_GET['movement_type'] ?? null)
    ? $_GET['movement_type']
    : '';
$requestedMovementPage = filter_var($_GET['movement_page'] ?? 1, FILTER_VALIDATE_INT);
$movementPageSize = 25;

if ($isAdmin) {
    if (
        ($movementFrom !== '' && !is_valid_date($movementFrom))
        || ($movementTo !== '' && !is_valid_date($movementTo))
    ) {
        $movementFilterError = 'Enter dates in the required year-month-day format.';
    } elseif (
        $movementFrom !== ''
        && $movementTo !== ''
        && $movementFrom > $movementTo
    ) {
        $movementFilterError = 'The start date must be on or before the end date.';
    } elseif (
        isset($_GET['movement_vegetable'])
        && $_GET['movement_vegetable'] !== ''
        && (!$movementVegetableId || $movementVegetableId < 1)
    ) {
        $movementFilterError = 'Choose a valid vegetable filter.';
    } elseif ($movementType !== '' && !isset($movementTypes[$movementType])) {
        $movementFilterError = 'Choose a valid movement type.';
    }

    if ($movementFilterError === '') {
        $movementConditions = [];
        $movementParameters = [];
        if ($movementFrom !== '') {
            $movementConditions[] = 'm.created_at >= ?';
            $movementParameters[] = $movementFrom . ' 00:00:00';
        }
        if ($movementTo !== '') {
            $movementConditions[] = 'm.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $movementParameters[] = $movementTo;
        }
        if ($movementVegetableId) {
            $movementConditions[] = 'm.vegetable_id = ?';
            $movementParameters[] = $movementVegetableId;
        }
        if ($movementType !== '') {
            $movementConditions[] = 'm.movement_type = ?';
            $movementParameters[] = $movementType;
        }
        $movementWhere = $movementConditions
            ? 'WHERE ' . implode(' AND ', $movementConditions)
            : '';

        $movementCountStatement = $db->prepare(
            'SELECT COUNT(*)
             FROM inventory_movements AS m
             ' . $movementWhere
        );
        $movementCountStatement->execute($movementParameters);
        $matchingMovements = (int) $movementCountStatement->fetchColumn();
        $movementPageCount = max(1, (int) ceil($matchingMovements / $movementPageSize));
        $movementPage = $requestedMovementPage && $requestedMovementPage > 0
            ? min($requestedMovementPage, $movementPageCount)
            : 1;
        $movementOffset = ($movementPage - 1) * $movementPageSize;
        $movementParameters[] = $movementPageSize;
        $movementParameters[] = $movementOffset;

        $movementStatement = $db->prepare(
            'SELECT
                m.id,
                m.vegetable_name,
                m.unit,
                m.movement_type,
                m.quantity_before,
                m.quantity_after,
                m.quantity_change,
                m.reason,
                m.reference_code,
                m.created_at,
                u.name AS actor
             FROM inventory_movements AS m
             LEFT JOIN users AS u ON u.id = m.actor_id
             ' . $movementWhere . '
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ? OFFSET ?'
        );
        $movementStatement->execute($movementParameters);
        $movements = $movementStatement->fetchAll();
    }
}

$pageTitle = 'Inventory';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Farm operations</p>
        <h1>Inventory</h1>
        <p>Adjust available stock. Orders use the oldest available stock batch first.</p>
    </div>
</section>

<section class="panel section">
    <h2>Set stock quantity</h2>

    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$vegetables): ?>
        <p class="muted">Add vegetables before managing inventory.</p>
    <?php else: ?>
        <form method="post" class="inline-form">
            <?= csrf_field() ?>

            <div class="field">
                <label for="vegetable_id">Vegetable</label>
                <select id="vegetable_id" name="vegetable_id" required>
                    <option value="">Choose one</option>
                    <?php foreach ($vegetables as $vegetable): ?>
                        <option
                            value="<?= (int) $vegetable['id'] ?>"
                            <?= (string) ($_POST['vegetable_id'] ?? '') === (string) $vegetable['id'] ? 'selected' : '' ?>
                        >
                            <?= h($vegetable['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="quantity">Quantity available</label>
                <input
                    id="quantity"
                    type="number"
                    min="0"
                    step="0.01"
                    name="quantity"
                    required
                    value="<?= h($_POST['quantity'] ?? '') ?>"
                >
            </div>

            <div class="field">
                <label for="reason">Reason for adjustment</label>
                <input
                    id="reason"
                    type="text"
                    name="reason"
                    minlength="3"
                    maxlength="160"
                    required
                    value="<?= h($_POST['reason'] ?? '') ?>"
                    placeholder="For example, new harvest or stock correction"
                >
            </div>

            <button type="submit">Save inventory</button>
        </form>
    <?php endif; ?>
</section>

<section class="panel section">
    <h2>Current stock</h2>

    <?php if (!$inventory): ?>
        <p class="empty">No vegetables in the catalog.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Vegetable</th>
                        <th>Available quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory as $item): ?>
                        <tr>
                            <td><?= h($item['name']) ?></td>
                            <td>
                                <?= number_format((float) $item['quantity'], 2) ?> <?= h($item['unit']) ?>
                                <small class="table-secondary">
                                    Updated <?= h($item['updated_at'] ? date('M j, g:i A', strtotime($item['updated_at'])) : '—') ?>
                                </small>
                            </td>
                            <td>
                                <?php $inStock = (float) $item['quantity'] > 5; ?>
                                <span class="status-badge status-<?= $inStock ? 'paid' : 'pending_payment' ?>">
                                    <?= $inStock ? 'In stock' : 'Low stock' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel section">
    <h2>Stock batches</h2>
    <p class="muted">Each vegetable's batches are ordered oldest first; orders draw from position 1 first.</p>
    <?php if (!$fifoBatchRows): ?>
        <p class="empty">No stock batches are available.</p>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Batch position</th>
                        <th>Vegetable</th>
                        <th>Batch source</th>
                        <th>Received</th>
                        <th>Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fifoBatchRows as $batch): ?>
                        <tr>
                            <td><?= (int) $batch['position'] ?></td>
                            <td><?= h($batch['vegetable']) ?></td>
                            <td><?= h($batch['source']) ?></td>
                            <td><?= h($batch['received']) ?></td>
                            <td><?= number_format($batch['quantity'], 2) ?> <?= h($batch['unit']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
    <section class="panel section module-filter-panel" aria-labelledby="inventory-movement-title">
        <div class="module-table-heading">
            <div>
                <h2 id="inventory-movement-title">Inventory movement history</h2>
                <p class="muted">
                    <?= number_format($matchingMovements) ?> matching
                    <?= $matchingMovements === 1 ? 'movement' : 'movements' ?>
                </p>
            </div>
            <?php if ($matchingMovements > 0): ?>
                <?php
                $firstMovement = (($movementPage - 1) * $movementPageSize) + 1;
                $lastMovement = min($movementPage * $movementPageSize, $matchingMovements);
                ?>
                <span class="module-record-range">
                    Showing <?= number_format($firstMovement) ?>–<?= number_format($lastMovement) ?>
                </span>
            <?php endif; ?>
        </div>

        <form method="get" class="module-filter-form">
            <div class="field">
                <label for="movement_from">Start date</label>
                <input
                    id="movement_from"
                    type="date"
                    name="movement_from"
                    value="<?= h($movementFrom) ?>"
                >
            </div>
            <div class="field">
                <label for="movement_to">End date</label>
                <input
                    id="movement_to"
                    type="date"
                    name="movement_to"
                    value="<?= h($movementTo) ?>"
                >
            </div>
            <div class="field">
                <label for="movement_vegetable">Vegetable</label>
                <select id="movement_vegetable" name="movement_vegetable">
                    <option value="">All vegetables</option>
                    <?php foreach ($vegetables as $vegetable): ?>
                        <option
                            value="<?= (int) $vegetable['id'] ?>"
                            <?= (string) $movementVegetableId === (string) $vegetable['id'] ? 'selected' : '' ?>
                        >
                            <?= h($vegetable['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="movement_type">Movement type</label>
                <select id="movement_type" name="movement_type">
                    <option value="">All movement types</option>
                    <?php foreach ($movementTypes as $type => $label): ?>
                        <option value="<?= h($type) ?>" <?= $movementType === $type ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="module-filter-actions">
                <button type="submit">Apply filters</button>
                <a class="button secondary" href="<?= h(app_url('modules/inventory.php#inventory-movement-title')) ?>">
                    Clear
                </a>
            </div>
        </form>

        <?php if ($movementFilterError !== ''): ?>
            <div class="notice error" role="alert"><?= h($movementFilterError) ?></div>
        <?php endif; ?>

        <?php if ($movementFilterError === '' && !$movements): ?>
            <div class="module-empty-state">
                <span class="module-empty-icon" aria-hidden="true">◷</span>
                <h3>No inventory movements found</h3>
                <p>New stock changes will appear here. Try a different date range or filter.</p>
            </div>
        <?php elseif ($movements): ?>
            <div class="table-wrap module-table-wrap">
                <table class="module-table">
                    <thead>
                        <tr>
                            <th>Date and time</th>
                            <th>Vegetable</th>
                            <th>Movement</th>
                            <th>Before</th>
                            <th>Change</th>
                            <th>After</th>
                            <th>Reason / reference</th>
                            <th>Updated by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $movement): ?>
                            <?php
                            $change = (float) $movement['quantity_change'];
                            $movementLabel = $movementTypes[$movement['movement_type']]
                                ?? ucwords(str_replace('_', ' ', $movement['movement_type']));
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h(date('M j, Y', strtotime($movement['created_at']))) ?></strong>
                                    <small class="table-secondary">
                                        <?= h(date('g:i a', strtotime($movement['created_at']))) ?>
                                    </small>
                                </td>
                                <td><?= h($movement['vegetable_name']) ?></td>
                                <td><?= h($movementLabel) ?></td>
                                <td>
                                    <?= number_format((float) $movement['quantity_before'], 2) ?>
                                    <?= h($movement['unit']) ?>
                                </td>
                                <td class="<?= $change >= 0 ? 'inventory-movement-increase' : 'inventory-movement-decrease' ?>">
                                    <?= $change > 0 ? '+' : '' ?><?= number_format($change, 2) ?>
                                    <?= h($movement['unit']) ?>
                                </td>
                                <td>
                                    <?= number_format((float) $movement['quantity_after'], 2) ?>
                                    <?= h($movement['unit']) ?>
                                </td>
                                <td>
                                    <?= h($movement['reason']) ?>
                                    <?php if ($movement['reference_code']): ?>
                                        <small class="table-secondary">
                                            Reference: <?= h($movement['reference_code']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($movement['actor'] ?? 'Account unavailable') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($movementPageCount > 1): ?>
                <?php
                $movementFilterParameters = array_filter(
                    [
                        'movement_from' => $movementFrom,
                        'movement_to' => $movementTo,
                        'movement_vegetable' => $movementVegetableId ? (string) $movementVegetableId : '',
                        'movement_type' => $movementType,
                    ],
                    static fn ($value): bool => $value !== ''
                );
                ?>
                <nav class="module-pagination" aria-label="Inventory movement pages">
                    <?php if ($movementPage > 1): ?>
                        <a href="?<?= h(http_build_query($movementFilterParameters + ['movement_page' => $movementPage - 1])) ?>#inventory-movement-title">
                            Previous
                        </a>
                    <?php else: ?>
                        <span class="is-disabled">Previous</span>
                    <?php endif; ?>
                    <span>Page <?= number_format($movementPage) ?> of <?= number_format($movementPageCount) ?></span>
                    <?php if ($movementPage < $movementPageCount): ?>
                        <a href="?<?= h(http_build_query($movementFilterParameters + ['movement_page' => $movementPage + 1])) ?>#inventory-movement-title">
                            Next
                        </a>
                    <?php else: ?>
                        <span class="is-disabled">Next</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
