<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'staff');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';
    if ($action === 'toggle') {
        $vegetableId = (int) ($_POST['id'] ?? 0);
        if ($vegetableId < 1) {
            flash('error', 'Choose a valid vegetable.');
            redirect(app_url('modules/vegetables.php'));
        }
        $statement = $db->prepare('UPDATE vegetables SET active = 1 - active WHERE id = ?');
        $statement->execute([$vegetableId]);
        if ($statement->rowCount() !== 1) {
            flash('error', 'That vegetable could not be found.');
            redirect(app_url('modules/vegetables.php'));
        }
        log_activity('availability_changed', 'vegetable', $vegetableId, 'Vegetable availability changed.');
        flash('success', 'Vegetable availability updated.');
        redirect(app_url('modules/vegetables.php'));
    }
    if (!in_array($action, ['create', 'update'], true)) {
        flash('error', 'Choose a valid vegetable action.');
        redirect(app_url('modules/vegetables.php'));
    }

    $vegetableId = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $variety = trim($_POST['variety'] ?? '');
    $unit = trim($_POST['unit'] ?? 'kg');
    $rawPrice = $_POST['price'] ?? '';
    $priceInput = is_string($rawPrice) ? trim($rawPrice) : '';
    $price = $priceInput !== ''
        ? filter_var($priceInput, FILTER_VALIDATE_FLOAT)
        : false;
    $priceHasValidFormat = preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $priceInput) === 1;
    $description = trim($_POST['description'] ?? '');

    if (($action === 'update' && $vegetableId < 1)
        || $name === ''
        || strlen($name) > 120
        || !in_array($category, vegetable_categories(), true)
        || $unit === ''
        || strlen($unit) > 30
        || $price === false
        || !$priceHasValidFormat
        || $price < 0) {
        $error = 'Choose a category, enter a vegetable name and unit, and use a price with up to two decimal places.';
    } else {
        if ($action === 'update') {
            $existing = $db->prepare('SELECT id FROM vegetables WHERE id = ?');
            $existing->execute([$vegetableId]);
            if (!$existing->fetch()) {
                $error = 'That vegetable could not be found.';
            } else {
                $statement = $db->prepare(
                    'UPDATE vegetables
                     SET name = ?, category = ?, variety = ?, description = ?, unit = ?, price = ?
                     WHERE id = ?'
                );
                $statement->execute([
                    $name,
                    $category,
                    $variety ?: null,
                    $description ?: null,
                    $unit,
                    $price,
                    $vegetableId,
                ]);
                log_activity('vegetable_updated', 'vegetable', $vegetableId, 'Vegetable details updated.');
                flash('success', 'Vegetable updated.');
                redirect(app_url('modules/vegetables.php'));
            }
        } else {
            $statement = $db->prepare(
                'INSERT INTO vegetables (name, category, variety, description, unit, price)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $name,
                $category,
                $variety ?: null,
                $description ?: null,
                $unit,
                $price,
            ]);
            $vegetableId = (int) $db->lastInsertId();
            log_activity('vegetable_created', 'vegetable', $vegetableId, 'Vegetable added to catalog.');
            flash('success', 'Vegetable added.');
        }
        redirect(app_url('modules/vegetables.php'));
    }
}

$search = trim($_GET['q'] ?? '');
$editingVegetable = null;
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($editId && $editId > 0) {
    $statement = $db->prepare('SELECT * FROM vegetables WHERE id = ?');
    $statement->execute([$editId]);
    $editingVegetable = $statement->fetch() ?: null;
}

$vegetableSql = 'SELECT v.*, COALESCE(i.quantity, 0) AS stock
                 FROM vegetables AS v
                 LEFT JOIN inventory AS i ON i.vegetable_id = v.id';
if ($search !== '') {
    $statement = $db->prepare(
        $vegetableSql . ' WHERE v.name LIKE ? OR v.category LIKE ? OR v.variety LIKE ? ORDER BY v.name'
    );
    $like = '%' . $search . '%';
    $statement->execute([$like, $like, $like]);
    $vegetables = $statement->fetchAll();
} else {
    $vegetables = $db->query($vegetableSql . ' ORDER BY v.name')->fetchAll();
}

$pageTitle = 'Vegetables';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Vegetables</h1>
</div>

<section class="panel section">
    <h2><?= $editingVegetable ? 'Edit vegetable' : 'Add vegetable' ?></h2>

    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($editingVegetable): ?>
        <p><a href="<?= h(app_url('modules/vegetables.php')) ?>">Cancel editing</a></p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <?php if ($editingVegetable): ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $editingVegetable['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" maxlength="120" required value="<?= h($editingVegetable['name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <?php foreach (vegetable_categories() as $categoryOption): ?>
                        <option
                            value="<?= h($categoryOption) ?>"
                            <?= ($editingVegetable['category'] ?? 'Other') === $categoryOption ? 'selected' : '' ?>
                        >
                            <?= h($categoryOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="variety">Variety</label>
                <input id="variety" name="variety" maxlength="120" value="<?= h($editingVegetable['variety'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="unit">Unit (e.g. kg, bunch)</label>
                <input id="unit" name="unit" maxlength="30" value="<?= h($editingVegetable['unit'] ?? 'kg') ?>" required>
            </div>
            <div class="field">
                <label for="price">Price per unit</label>
                <input
                    id="price"
                    type="number"
                    min="0"
                    max="99999999.99"
                    step="0.01"
                    name="price"
                    value="<?= h($editingVegetable['price'] ?? '') ?>"
                    required>
            </div>
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description"><?= h($editingVegetable['description'] ?? '') ?></textarea>
        </div>

        <button type="submit"><?= $editingVegetable ? 'Save changes' : 'Add vegetable' ?></button>
    </form>
</section>

<section class="panel section">
    <h2>Vegetable list</h2>
    <form method="get" class="inline-form section">
        <div class="field">
            <label for="q">Search vegetables</label>
            <input id="q" name="q" value="<?= h($search) ?>" placeholder="Name, category, or variety">
        </div>
        <button type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="button secondary" href="<?= h(app_url('modules/vegetables.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if (!$vegetables): ?>
        <p class="empty">No vegetables have been added.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Variety</th>
                        <th>Unit price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Edit</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vegetables as $vegetable): ?>
                        <tr>
                            <td><?= h($vegetable['name']) ?></td>
                            <td><?= h($vegetable['category']) ?></td>
                            <td><?= h($vegetable['variety'] ?? '—') ?></td>
                            <td>
                                <?= money($vegetable['price']) ?>
                                / <?= h($vegetable['unit']) ?>
                            </td>
                            <td><?= number_format((float) $vegetable['stock'], 2) ?></td>
                            <td>
                                <span class="status-badge <?= $vegetable['active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $vegetable['active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><a href="<?= h(app_url('modules/vegetables.php?edit=' . (int) $vegetable['id'])) ?>">Edit</a></td>
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