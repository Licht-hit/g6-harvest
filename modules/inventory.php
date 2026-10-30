<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $vegetableId = (int) ($_POST['vegetable_id'] ?? 0);
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);

    if ($vegetableId < 1 || $quantity === false || $quantity < 0) {
        $error = 'Choose a vegetable and enter a non-negative stock quantity.';
    } else {
        // Create the inventory row, or replace its quantity if it already exists.
        $statement = $db->prepare(
            'INSERT INTO inventory (vegetable_id, quantity)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        );
        $statement->execute([$vegetableId, $quantity]);
        log_activity('inventory_adjusted', 'vegetable', $vegetableId, 'Inventory quantity manually adjusted.');
        flash('success', 'Inventory quantity updated.');
        redirect(app_url('modules/inventory.php'));
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

$pageTitle = 'Inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading"><h1>Inventory</h1></div>

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
                        <option value="<?= (int) $vegetable['id'] ?>">
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
                >
            </div>

            <button type="submit">Update stock</button>
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
                        <th>Quantity</th>
                        <th>Unit</th>
                        <th>Last updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory as $item): ?>
                        <tr>
                            <td><?= h($item['name']) ?></td>
                            <td><?= number_format((float) $item['quantity'], 2) ?></td>
                            <td><?= h($item['unit']) ?></td>
                            <td><?= h($item['updated_at'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
