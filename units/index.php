<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin']);

$pageTitle = 'Offices';
$pagePath = '/units/index.php';
$errors = [];
$editId = input_int($_GET['edit'] ?? 0);
$editingOffice = $editId > 0 ? load_office_by_id($editId) : null;

if (is_post()) {
    require_csrf($pagePath);

    $action = input_string($_POST['action'] ?? 'save', 'save');

    if ($action === 'toggle') {
        $officeId = input_int($_POST['office_id'] ?? 0);
        $office = load_office_by_id($officeId);

        if (!$office) {
            set_flash('error', 'Unit not found.');
            redirect($pagePath);
        }
        if (is_protected_admin_unit($office)) {
            set_flash('error', 'The Records Office - Iba Campus unit must remain active.');
            redirect($pagePath);
        }

        $stmt = db()->prepare('UPDATE offices SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
        $stmt->execute(['id' => $officeId]);
        audit_log('toggle_office', 'office', $officeId);
        set_flash('success', 'Unit status updated.');
        redirect($pagePath);
    }

    $officeId = input_int($_POST['office_id'] ?? 0);
    if ($officeId <= 0 && $editId > 0) {
        $officeId = $editId;
    }
    $name = input_string($_POST['name'] ?? '');
    $code = strtoupper(input_string($_POST['code'] ?? ''));
    $code = preg_replace('/[^A-Z0-9]+/', '', $code) ?? '';
    $trunkLine = substr(input_string($_POST['trunk_line'] ?? ''), 0, 50);
    $localNumber = substr(input_string($_POST['local_number'] ?? ''), 0, 50);
    $existingOffice = null;

    if ($name === '') {
        $errors[] = 'Unit name is required.';
    }

    if ($code === '') {
        $code = generate_unique_office_code(db(), $name);
    }

    if ($officeId > 0) {
        $existingOffice = load_office_by_id($officeId);
        if (!$existingOffice) {
            $errors[] = 'Unit not found for editing.';
        }
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM offices WHERE name = :name AND id <> :id');
        $stmt->execute([
            'name' => $name,
            'id' => $officeId,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'Unit name already exists.';
        }

        $stmt = db()->prepare('SELECT COUNT(*) FROM offices WHERE code = :code AND id <> :id');
        $stmt->execute([
            'code' => $code,
            'id' => $officeId,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'Unit code already exists.';
        }
    }

    if (!$errors) {
        if ($officeId > 0) {
            run_in_transaction(static function () use ($officeId, $existingOffice, $name, $code, $trunkLine, $localNumber): void {
                $stmt = db()->prepare(
                    'UPDATE offices
                     SET name = :name,
                         code = :code,
                         trunk_line = :trunk_line,
                         local_number = :local_number
                     WHERE id = :id'
                );
                $stmt->execute([
                    'name' => $name,
                    'code' => $code,
                    'trunk_line' => $trunkLine !== '' ? $trunkLine : null,
                    'local_number' => $localNumber !== '' ? $localNumber : null,
                    'id' => $officeId,
                ]);

                sync_legacy_office_name_references(
                    db(),
                    $officeId,
                    (string) ($existingOffice['name'] ?? ''),
                    $name
                );
            });
            audit_log('update_office', 'office', $officeId, ['code' => $code]);
            set_flash('success', 'Unit updated.');
        } else {
            $stmt = db()->prepare(
                'INSERT INTO offices (name, code, trunk_line, local_number, is_active, created_at)
                 VALUES (:name, :code, :trunk_line, :local_number, 1, NOW())'
            );
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'trunk_line' => $trunkLine !== '' ? $trunkLine : null,
                'local_number' => $localNumber !== '' ? $localNumber : null,
            ]);
            $newOfficeId = (int) db()->lastInsertId();
            audit_log('create_office', 'office', $newOfficeId, ['code' => $code]);
            set_flash('success', 'Unit created.');
        }

        redirect($pagePath);
    }
}

$offices = db()->query(
    'SELECT o.*,
            (SELECT COUNT(*) FROM users u WHERE u.office_id = o.id) AS user_count,
            (SELECT COUNT(*) FROM documents d WHERE d.current_office_id = o.id AND d.deleted_at IS NULL) AS active_document_count
     FROM offices o
     ORDER BY o.name ASC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';

$formValues = [
    'office_id' => $editingOffice['id'] ?? 0,
    'name' => old('name', $editingOffice['name'] ?? ''),
    'code' => old('code', $editingOffice['code'] ?? ''),
    'trunk_line' => old('trunk_line', $editingOffice['trunk_line'] ?? ''),
    'local_number' => old('local_number', $editingOffice['local_number'] ?? ''),
];
?>
<div class="grid page-grid">
    <div class="card">
        <div class="section-heading">
            <div>
                <h2><?= $editingOffice ? 'Edit Office' : 'Create Office' ?></h2>
                <p class="muted">Offices identify campus offices used for document location tracking, reporting, and workload monitoring.</p>
            </div>
            <?php if ($editingOffice): ?>
                <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/units/index.php">New Office</a>
            <?php endif; ?>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= BASE_URL ?>/units/index.php<?= $editingOffice ? '?edit=' . (int) $editingOffice['id'] : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="office_id" value="<?= (int) $formValues['office_id'] ?>">

            <label for="name">Office Name</label>
            <input type="text" name="name" id="name" value="<?= e($formValues['name']) ?>" required>

            <!-- <label for="code">Office Code</label>
            <input type="text" name="code" id="code" value="<?= e($formValues['code']) ?>" placeholder="Auto-generated if left blank"> -->

            <label for="trunk_line">Trunk Line</label>
            <input type="text" name="trunk_line" id="trunk_line" value="<?= e($formValues['trunk_line']) ?>" maxlength="50" placeholder="Optional">

            <label for="local_number">Local / Extension</label>
            <input type="text" name="local_number" id="local_number" value="<?= e($formValues['local_number']) ?>" maxlength="50" placeholder="Optional">

            <div class="actions">
                <button class="btn" type="submit"><?= $editingOffice ? 'Update Unit' : 'Create Unit' ?></button>
                <?php if ($editingOffice): ?>
                    <a class="btn btn-secondary" href="<?= BASE_URL ?>/units/index.php">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>Office Directory</h2>
                <p class="muted">Active document counts help spot overloaded campus offices quickly.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <!-- <th>Code</th> -->
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Users</th>
                        <th>Active Documents</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$offices): ?>
                    <tr><td colspan="7"><div class="empty-state">No offices defined yet.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($offices as $office): ?>
                        <tr>
                            <td><?= e($office['name']) ?></td>
                            <!-- <td><?= e($office['code']) ?></td> -->
                            <td><?= e(office_contact_label($office['trunk_line'] ?? null, $office['local_number'] ?? null)) ?></td>
                            <td><?= (int) $office['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                            <td><?= (int) $office['user_count'] ?></td>
                            <td><?= (int) $office['active_document_count'] ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/units/index.php?edit=<?= (int) $office['id'] ?>">Edit</a>
                                    <?php if (!is_protected_admin_unit($office)): ?>
                                        <form method="post" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="office_id" value="<?= (int) $office['id'] ?>">
                                            <button class="btn btn-sm <?= (int) $office['is_active'] === 1 ? 'btn-danger' : 'btn-secondary' ?>" type="submit" data-confirm="<?= (int) $office['is_active'] === 1 ? 'Deactivate this unit?' : 'Activate this unit?' ?>">
                                                <?= (int) $office['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">Protected</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
