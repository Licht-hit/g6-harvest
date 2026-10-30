<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'toggle') {
        $statement = $pdo->prepare('UPDATE vegetables SET active = 1 - active WHERE id = ?');
        $statement->execute([(int) ($_POST['id'] ?? 0)]);
        flash('success', 'Vegetable availability updated.');
        redirect(app_url('modules/vegetables.php'));
    }
    $name = trim($_POST['name'] ?? '');
    $variety = trim($_POST['variety'] ?? '');
    $unit = trim($_POST['unit'] ?? 'kg');
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);

    if ($name === '' || $unit === '' || $price === false || $price < 0) {
        $error = 'Enter a vegetable name, unit, and a non-negative price.';
    } else {
        $statement = $pdo->prepare(
            'INSERT INTO vegetables (name, variety, description, unit, price)
             VALUES (?, ?, ?, ?, ?)'
        );
        $description = trim($_POST['description'] ?? '');
        $statement->execute([
            $name,
            $variety ?: null,
            $description ?: null,
            $unit,
            $price,
        ]);
        flash('success', 'Vegetable added.');
        redirect(app_url('modules/vegetables.php'));
    }
}

$vegetables = $pdo->query(
    'SELECT v.*, COALESCE(i.quantity, 0) AS stock
     FROM vegetables AS v
     LEFT JOIN inventory AS i ON i.vegetable_id = v.id
     ORDER BY v.name'
)->fetchAll();

$pageTitle = 'Vegetables';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Vegetables</h1>
</div>

<section class="panel section">
    <h2>Add vegetable</h2>

    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <div class="form-grid">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" required>
            </div>
            <div class="field">
                <label for="variety">Variety</label>
                <input id="variety" name="variety">
            </div>
            <div class="field">
                <label for="unit">Unit (e.g. kg, bunch)</label>
                <input id="unit" name="unit" value="kg" required>
            </div>
            <div class="field">
                <label for="price">Price per unit</label>
                <input
                    id="price"
                    type="number"
                    min="0"
                    step="0.01"
                    name="price"
                    required>
            </div>
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description"></textarea>
        </div>

        <button type="submit">Add vegetable</button>
    </form>
</section>

<section class="panel section">
    <h2>Vegetable list</h2>

    <?php if (!$vegetables): ?>
        <p class="empty">No vegetables have been added.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Variety</th>
                        <th>Unit price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vegetables as $vegetable): ?>
                        <tr>
                            <td><?= h($vegetable['name']) ?></td>
                            <td><?= h($vegetable['variety'] ?? '—') ?></td>
                            <td>
                                <?= money($vegetable['price']) ?>
                                / <?= h($vegetable['unit']) ?>
                            </td>
                            <td><?= number_format((float) $vegetable['stock'], 2) ?></td>
                            <td><?= $vegetable['active'] ? 'Active' : 'Inactive' ?></td>
                            <td>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $vegetable['id'] ?>">
                                    <button class="button secondary" type="submit">
                                        <?= $vegetable['active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>