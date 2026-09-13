<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin']);

$pageTitle = 'Categories';
$pagePath = '/categories/index.php';
$errors = [];
$editId = input_int($_GET['edit'] ?? 0);
$editingCategory = $editId > 0 ? load_category_by_id($editId) : null;

if (is_post()) {
    require_csrf($pagePath);

    $action = input_string($_POST['action'] ?? 'save', 'save');

    if ($action === 'toggle') {
        $categoryId = input_int($_POST['category_id'] ?? 0);
        $stmt = db()->prepare('UPDATE document_categories SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
        $stmt->execute(['id' => $categoryId]);
        audit_log('toggle_category', 'category', $categoryId);
        set_flash('success', 'Category status updated.');
        redirect($pagePath);
    }

    $categoryId = input_int($_POST['category_id'] ?? 0);
    $name = input_string($_POST['name'] ?? '');

    if ($name === '') {
        $errors[] = 'Category name is required.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM document_categories WHERE name = :name AND id <> :id');
        $stmt->execute([
            'name' => $name,
            'id' => $categoryId,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'Category name already exists.';
        }
    }

    if (!$errors) {
        if ($categoryId > 0) {
            $stmt = db()->prepare('UPDATE document_categories SET name = :name WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'id' => $categoryId,
            ]);
            audit_log('update_category', 'category', $categoryId);
            set_flash('success', 'Category updated.');
        } else {
            $stmt = db()->prepare(
                'INSERT INTO document_categories (name, is_active, created_at)
                 VALUES (:name, 1, NOW())'
            );
            $stmt->execute(['name' => $name]);
            $newCategoryId = (int) db()->lastInsertId();
            audit_log('create_category', 'category', $newCategoryId);
            set_flash('success', 'Category created.');
        }

        redirect($pagePath);
    }
}

$categories = db()->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM documents d WHERE d.category_id = c.id AND d.deleted_at IS NULL) AS document_count
     FROM document_categories c
     ORDER BY c.name ASC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';

$formValues = [
    'category_id' => $editingCategory['id'] ?? 0,
    'name' => old('name', $editingCategory['name'] ?? ''),
];
?>
<div class="grid page-grid">
    <div class="card">
        <div class="section-heading">
            <div>
                <h2><?= $editingCategory ? 'Edit Category' : 'Create Category' ?></h2>
                <p class="muted">Document categories help organize records, filtering, and reports.</p>
            </div>
            <?php if ($editingCategory): ?>
                <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/categories/index.php">New Category</a>
            <?php endif; ?>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="category_id" value="<?= (int) $formValues['category_id'] ?>">

            <label for="name">Category Name</label>
            <input type="text" name="name" id="name" value="<?= e($formValues['name']) ?>" required>

            <div class="actions">
                <button class="btn" type="submit"><?= $editingCategory ? 'Update Category' : 'Create Category' ?></button>
                <?php if ($editingCategory): ?>
                    <a class="btn btn-secondary" href="<?= BASE_URL ?>/categories/index.php">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="section-heading">
            <div>
                <h2>Category List</h2>
                <p class="muted">Inactive categories remain available on old records but disappear from new-document forms.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Documents</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$categories): ?>
                    <tr><td colspan="4"><div class="empty-state">No categories defined yet.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td><?= e($category['name']) ?></td>
                            <td><?= (int) $category['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                            <td><?= (int) $category['document_count'] ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/categories/index.php?edit=<?= (int) $category['id'] ?>">Edit</a>
                                    <form method="post" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                        <button class="btn btn-sm <?= (int) $category['is_active'] === 1 ? 'btn-danger' : 'btn-secondary' ?>" type="submit" data-confirm="<?= (int) $category['is_active'] === 1 ? 'Deactivate this category?' : 'Activate this category?' ?>">
                                            <?= (int) $category['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
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
