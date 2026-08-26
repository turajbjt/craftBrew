<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();

$user = current_user();
$userId = $user['id'];
$message = '';
$error = '';

// Handle Actions: Add/Edit Item, Delete Item
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed (CSRF token error).";
    } else {
        $action = $_POST['action'] ?? 'save';
        if ($action === 'save') {
            $saved = save_inventory_item($userId, [
                'id'        => (int)($_POST['id'] ?? 0),
                'item_name' => $_POST['item_name'] ?? '',
                'category'  => $_POST['category'] ?? 'Fermentable',
                'quantity'  => (float)($_POST['quantity'] ?? 0),
                'unit'      => $_POST['unit'] ?? '',
                'notes'     => $_POST['notes'] ?? '',
            ]);
            if ($saved) {
                $message = "Inventory item saved successfully!";
            } else {
                $error = "Failed to save inventory item. Item name is required.";
            }
        } elseif ($action === 'delete') {
            $itemId = (int)($_POST['id'] ?? 0);
            if ($itemId > 0 && delete_inventory_item($userId, $itemId)) {
                $message = "Inventory item deleted!";
            } else {
                $error = "Failed to delete item.";
            }
        }
    }
}

$inventory = get_inventory($userId);
$csrfToken = generate_csrf_token();

$pageTitle = "Cellar Inventory - " . APP_NAME;
$activePage = 'inventory';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>📦 Cellar Inventory & Stock Management</h1>
        <p style="color: var(--text-muted);">Track grains, hops, yeast, additives, and brewing equipment in stock.</p>
    </div>
    <button class="btn btn-primary" onclick="openInventoryModal()">+ Add Stock Item</button>
</div>

<?php if (!empty($message)): ?>
    <div style="background: #dcfce7; color: #166534; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #bbf7d0;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Inventory Table Card -->
<div class="card">
    <div class="table-container" style="margin-bottom: 0;">
        <table>
            <thead>
                <tr>
                    <th style="width: 30%;">Item Name</th>
                    <th style="width: 20%;">Category</th>
                    <th style="width: 15%;">Quantity</th>
                    <th style="width: 15%;">Unit</th>
                    <th style="width: 20%; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventory)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                            No items in cellar inventory yet. Click <strong>+ Add Stock Item</strong> to start tracking stock!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventory as $item): 
                        $isLow = (float)$item['quantity'] <= 1.0;
                    ?>
                        <tr>
                            <td>
                                <strong><?= e($item['item_name']) ?></strong>
                                <?php if (!empty($item['notes'])): ?>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?= e($item['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-secondary"><?= e($item['category']) ?></span>
                            </td>
                            <td>
                                <strong style="color: <?= $isLow ? '#dc2626' : '#1e293b' ?>;">
                                    <?= (float)$item['quantity'] ?>
                                </strong>
                                <?php if ($isLow): ?>
                                    <span class="badge" style="background: #fee2e2; color: #991b1b; margin-left: 0.25rem;">Low</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($item['unit']) ?></td>
                            <td style="text-align: center; white-space: nowrap;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick='editInventoryItem(<?= json_encode($item) ?>)'>Edit</button>
                                <form method="POST" style="display: inline-block;" onsubmit="return confirm('Delete this inventory item?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                    <button type="submit" class="btn btn-logout btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal / Form Card for Adding/Editing Item -->
<div id="invModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 500px; padding: 1.5rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 id="invModalTitle" style="margin-bottom: 1rem;">📦 Add Stock Item</h3>
        <form method="POST" action="inventory.php" id="invForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="inv_id" value="0">

            <div class="form-group">
                <label class="form-label">Item Name</label>
                <input type="text" name="item_name" id="inv_name" class="form-control" placeholder="e.g. 2-Row Pale Malt, Citra Hops, US-05 Yeast" required>
            </div>

            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" id="inv_category" class="form-control">
                    <option value="Fermentable">Fermentable</option>
                    <option value="Hop">Hop</option>
                    <option value="Yeast">Yeast</option>
                    <option value="Additive/Finings">Additive/Finings</option>
                    <option value="Equipment/Packaging">Equipment/Packaging</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Quantity</label>
                    <input type="number" step="0.01" name="quantity" id="inv_quantity" class="form-control" placeholder="10.0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" id="inv_unit" class="form-control" placeholder="lbs / oz / pkt / gal">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Notes (Optional)</label>
                <input type="text" name="notes" id="inv_notes" class="form-control" placeholder="Alpha acid %, supplier info, storage location...">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeInventoryModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Item</button>
            </div>
        </form>
    </div>
</div>

<script>
function openInventoryModal() {
    document.getElementById('inv_id').value = '0';
    document.getElementById('invForm').reset();
    document.getElementById('invModalTitle').textContent = '📦 Add Stock Item';
    document.getElementById('invModal').style.display = 'flex';
}

function closeInventoryModal() {
    document.getElementById('invModal').style.display = 'none';
}

function editInventoryItem(item) {
    document.getElementById('inv_id').value = item.id;
    document.getElementById('inv_name').value = item.item_name;
    document.getElementById('inv_category').value = item.category;
    document.getElementById('inv_quantity').value = item.quantity;
    document.getElementById('inv_unit').value = item.unit;
    document.getElementById('inv_notes').value = item.notes || '';
    document.getElementById('invModalTitle').textContent = '✏️ Edit Stock Item';
    document.getElementById('invModal').style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
