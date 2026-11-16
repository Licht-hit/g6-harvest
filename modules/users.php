<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Cache-Control: private, no-store');

$roles = ['admin', 'staff', 'farmer', 'buyer'];
$roleLabels = [
    'admin' => 'Administrator',
    'staff' => 'Staff',
    'farmer' => 'Farmer',
    'buyer' => 'Buyer',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $nextRole = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';

    if ($action !== 'change_role' || $targetId === false || $targetId < 1 || !in_array($nextRole, $roles, true)) {
        flash('error', 'Choose a valid user and role.');
        redirect(app_url('modules/users.php'));
    }

    if ($targetId === (int) current_user()['id']) {
        flash('error', 'You cannot change your own role.');
        redirect(app_url('modules/users.php'));
    }

    $db->beginTransaction();
    try {
        $adminLock = $db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id FOR UPDATE");
        $administrators = $adminLock->fetchAll();

        $userStatement = $db->prepare('SELECT id, name, role, email_verified_at FROM users WHERE id = ? FOR UPDATE');
        $userStatement->execute([$targetId]);
        $targetUser = $userStatement->fetch();
        if (!$targetUser) {
            throw new RuntimeException('That user could not be found.');
        }
        if ($targetUser['role'] === $nextRole) {
            throw new RuntimeException('That account already has this role.');
        }
        if ($nextRole !== 'buyer' && !$targetUser['email_verified_at']) {
            throw new RuntimeException('Verify the account email before granting an operations role.');
        }
        if ($targetUser['role'] === 'admin' && $nextRole !== 'admin' && count($administrators) <= 1) {
            throw new RuntimeException('You cannot remove the role from the last administrator.');
        }

        $update = $db->prepare('UPDATE users SET role = ? WHERE id = ?');
        $update->execute([$nextRole, $targetId]);
        log_activity(
            'user_role_changed',
            'user',
            $targetId,
            'Role changed from ' . $roleLabels[$targetUser['role']] . ' to ' . $roleLabels[$nextRole] . '.'
        );
        $db->commit();
        flash('success', 'Role updated for ' . $targetUser['name'] . '.');
    } catch (RuntimeException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    redirect(app_url('modules/users.php'));
}

$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$roleFilter = is_string($_GET['role'] ?? null) ? trim($_GET['role']) : '';
$pageInput = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $pageInput && $pageInput > 0 ? $pageInput : 1;
$pageSize = 25;
$filterError = !in_array($roleFilter, array_merge([''], $roles), true)
    ? 'Choose a valid role filter.'
    : '';
$conditions = [];
$parameters = [];
if ($search !== '') {
    $conditions[] = '(name LIKE ? OR email LIKE ?)';
    $searchTerm = '%' . $search . '%';
    array_push($parameters, $searchTerm, $searchTerm);
}
if ($roleFilter !== '' && $filterError === '') {
    $conditions[] = 'role = ?';
    $parameters[] = $roleFilter;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$users = [];
$matchingUsers = 0;
$pageCount = 1;
$summary = $db->query(
    "SELECT
        COUNT(*) AS total,
        SUM(role = 'admin') AS administrators,
        SUM(role = 'staff') AS staff,
        SUM(role = 'farmer') AS farmers,
        SUM(role = 'buyer') AS buyers,
        SUM(email_verified_at IS NOT NULL) AS verified
     FROM users"
)->fetch();

if ($filterError === '') {
    $countStatement = $db->prepare('SELECT COUNT(*) FROM users ' . $whereSql);
    $countStatement->execute($parameters);
    $matchingUsers = (int) $countStatement->fetchColumn();
    $pageCount = max(1, (int) ceil($matchingUsers / $pageSize));
    $page = min($page, $pageCount);
    $offset = ($page - 1) * $pageSize;

    $statement = $db->prepare(
        'SELECT id, name, email, role, email_verified_at, created_at
         FROM users ' . $whereSql . '
         ORDER BY name, id
         LIMIT ? OFFSET ?'
    );
    $parameters[] = $pageSize;
    $parameters[] = $offset;
    $statement->execute($parameters);
    $users = $statement->fetchAll();
}

$firstRecord = $matchingUsers > 0 ? (($page - 1) * $pageSize) + 1 : 0;
$lastRecord = min($page * $pageSize, $matchingUsers);
$pageTitle = 'Users and roles';
require __DIR__ . '/../includes/header.php';
?>
<section class="module-heading">
    <div>
        <p class="module-eyebrow">Administrator</p>
        <h1>Users and roles</h1>
        <p>Review accounts and control access to farm operations.</p>
    </div>
</section>

<section class="module-stat-grid" aria-label="Account summary">
    <?php foreach ([
        ['All accounts', 'total'],
        ['Administrators', 'administrators'],
        ['Staff', 'staff'],
        ['Farmers', 'farmers'],
        ['Buyers', 'buyers'],
        ['Verified emails', 'verified'],
    ] as [$label, $key]): ?>
        <article class="module-stat-card">
            <span><?= h($label) ?></span>
            <strong><?= number_format((int) ($summary[$key] ?? 0)) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel section module-filter-panel">
    <form method="get" class="module-filter-form">
        <div class="field module-search-field">
            <label for="user-search">Search accounts</label>
            <input id="user-search" name="search" value="<?= h($search) ?>" maxlength="100" placeholder="Name or email">
        </div>
        <div class="field">
            <label for="user-role-filter">Role</label>
            <select id="user-role-filter" name="role">
                <option value="">All roles</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= h($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>>
                        <?= h($roleLabels[$role]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="module-filter-actions">
            <button type="submit">Filter</button>
            <a class="button secondary" href="<?= h(app_url('modules/users.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<section class="panel section module-table-panel">
    <div class="module-table-heading">
        <div>
            <h2>Accounts</h2>
            <p class="muted">Changing a role updates the account's permissions immediately. You cannot change your own role or remove the last administrator.</p>
        </div>
    </div>
    <?php if ($filterError !== ''): ?>
        <div class="notice error" role="alert"><?= h($filterError) ?></div>
    <?php elseif (!$users): ?>
        <p class="empty">No accounts match these filters.</p>
    <?php else: ?>
        <div class="table-wrap module-table-wrap">
            <table class="module-table">
                <thead>
                    <tr><th>Name</th><th>Email</th><th>Role</th><th>Email status</th><th>Created</th><th>Update role</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $account): ?>
                        <?php $isCurrentUser = (int) $account['id'] === (int) current_user()['id']; ?>
                        <tr>
                            <td>
                                <strong><?= h($account['name']) ?></strong>
                                <?php if ($isCurrentUser): ?><small class="table-secondary">You</small><?php endif; ?>
                            </td>
                            <td><?= h($account['email']) ?></td>
                            <td><?= h($roleLabels[$account['role']] ?? ucfirst($account['role'])) ?></td>
                            <td>
                                <span class="status-badge <?= $account['email_verified_at'] ? 'status-active' : 'status-pending' ?>">
                                    <?= $account['email_verified_at'] ? 'Verified' : 'Unverified' ?>
                                </span>
                            </td>
                            <td><?= h(date('M j, Y', strtotime($account['created_at']))) ?></td>
                            <td>
                                <?php if ($isCurrentUser): ?>
                                    —
                                <?php elseif (!$account['email_verified_at'] && $account['role'] === 'buyer'): ?>
                                    <span class="muted">Verify email first</span>
                                <?php else: ?>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="change_role">
                                        <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                        <label class="sr-only" for="user-role-<?= (int) $account['id'] ?>">
                                            Change role for <?= h($account['name']) ?>
                                        </label>
                                        <select id="user-role-<?= (int) $account['id'] ?>" name="role">
                                            <?php foreach ($roles as $role): ?>
                                                <option
                                                    value="<?= h($role) ?>"
                                                    <?= $account['role'] === $role ? 'selected' : '' ?>
                                                    <?= !$account['email_verified_at'] && $role !== 'buyer' ? 'disabled' : '' ?>
                                                >
                                                    <?= h($roleLabels[$role]) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="button secondary" type="submit">Save role</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="app-pagination">
            <span class="muted">Showing <?= $firstRecord ?>–<?= $lastRecord ?> of <?= number_format($matchingUsers) ?> accounts</span>
            <div class="app-actions">
                <?php if ($page > 1): ?>
                    <a class="button secondary" href="<?= h(app_url('modules/users.php?' . http_build_query([
                        'search' => $search,
                        'role' => $roleFilter,
                        'page' => $page - 1,
                    ]))) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $pageCount): ?>
                    <a class="button secondary" href="<?= h(app_url('modules/users.php?' . http_build_query([
                        'search' => $search,
                        'role' => $roleFilter,
                        'page' => $page + 1,
                    ]))) ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
