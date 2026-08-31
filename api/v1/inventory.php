<?php
/**
 * RESTful API Cellar Inventory Endpoint:
 * - GET    /api/v1/index.php?route=inventory            (List cellar items with category/search filters)
 * - GET    /api/v1/index.php?route=inventory&id={id}    (Inventory item detail)
 * - POST   /api/v1/index.php?route=inventory            (Add inventory item)
 * - PUT    /api/v1/index.php?route=inventory&id={id}    (Update stock / delta adjust quantity)
 * - DELETE /api/v1/index.php?route=inventory&id={id}    (Delete inventory item)
 */

$user = authenticate_api_request();
$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Read JSON input body
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$allowedCategories = ['Fermentable', 'Hop', 'Yeast', 'Additive', 'Supply', 'Equipment', 'Other'];

if ($method === 'GET') {
    if ($id > 0) {
        $stmt = $db->prepare("SELECT * FROM inventory WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user['id']]);
        $item = $stmt->fetch();

        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Inventory item not found or access denied']);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'item'   => $item
        ]);
    } else {
        $catFilter    = sanitize_text($_GET['category'] ?? '', 50);
        $searchQuery  = sanitize_text($_GET['search'] ?? '', 50);
        $limit        = min(max(1, (int)($_GET['limit'] ?? 100)), 300);
        $offset       = max(0, (int)($_GET['offset'] ?? 0));

        $sql = "SELECT * FROM inventory WHERE user_id = ?";
        $params = [$user['id']];

        if (!empty($catFilter)) {
            $sql .= " AND category = ?";
            $params[] = $catFilter;
        }
        if (!empty($searchQuery)) {
            $sql .= " AND (item_name LIKE ? OR notes LIKE ?)";
            $params[] = "%{$searchQuery}%";
            $params[] = "%{$searchQuery}%";
        }

        $sql .= " ORDER BY category ASC, item_name ASC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        // Categorized aggregation counts
        $catSummary = $db->query("
            SELECT category, COUNT(*) as item_count, SUM(quantity) as total_quantity
            FROM inventory
            WHERE user_id = {$user['id']}
            GROUP BY category
            ORDER BY category ASC
        ")->fetchAll();

        echo json_encode([
            'status'     => 'success',
            'count'      => count($items),
            'limit'      => $limit,
            'offset'     => $offset,
            'categories' => $catSummary,
            'inventory'  => $items
        ]);
    }

} elseif ($method === 'POST') {
    $itemName = sanitize_text($input['item_name'] ?? '', 100);
    $category = validate_enum($input['category'] ?? 'Fermentable', $allowedCategories, 'Fermentable');
    $quantity = max(0, sanitize_float($input['quantity'] ?? 0));
    $unit     = sanitize_text($input['unit'] ?? 'lbs', 20);
    $notes    = sanitize_text($input['notes'] ?? '', 500);

    if (empty($itemName)) {
        http_response_code(400);
        echo json_encode(['error' => 'item_name is required']);
        exit;
    }

    $ins = $db->prepare("
        INSERT INTO inventory (user_id, item_name, category, quantity, unit, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([$user['id'], $itemName, $category, $quantity, $unit, $notes]);
    $newId = (int)$db->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'status'       => 'success',
        'message'      => 'Inventory item added to cellar stock',
        'inventory_id' => $newId
    ]);

} elseif ($method === 'PUT' || $method === 'PATCH') {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid inventory id is required in query parameter (?id={id})']);
        exit;
    }

    $chk = $db->prepare("SELECT * FROM inventory WHERE id = ? AND user_id = ?");
    $chk->execute([$id, $user['id']]);
    $existing = $chk->fetch();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Inventory item not found or access denied']);
        exit;
    }

    $itemName = isset($input['item_name']) ? sanitize_text($input['item_name'], 100) : $existing['item_name'];
    $category = isset($input['category']) ? validate_enum($input['category'], $allowedCategories, $existing['category']) : $existing['category'];
    $unit     = isset($input['unit']) ? sanitize_text($input['unit'], 20) : $existing['unit'];
    $notes    = isset($input['notes']) ? sanitize_text($input['notes'], 500) : $existing['notes'];

    // Support both direct quantity assignment and relative adjustments (e.g. adjust_quantity = -2.5)
    $quantity = (float)$existing['quantity'];
    if (isset($input['quantity'])) {
        $quantity = max(0, sanitize_float($input['quantity']));
    } elseif (isset($input['adjust_quantity'])) {
        $delta = sanitize_float($input['adjust_quantity']);
        $quantity = max(0, $quantity + $delta);
    }

    $up = $db->prepare("
        UPDATE inventory SET
            item_name = ?, category = ?, quantity = ?, unit = ?, notes = ?
        WHERE id = ? AND user_id = ?
    ");
    $up->execute([$itemName, $category, $quantity, $unit, $notes, $id, $user['id']]);

    echo json_encode([
        'status'         => 'success',
        'message'        => 'Inventory item updated successfully',
        'inventory_id'   => $id,
        'quantity'       => $quantity,
        'unit'           => $unit
    ]);

} elseif ($method === 'DELETE') {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid inventory id is required in query parameter (?id={id})']);
        exit;
    }

    $chk = $db->prepare("SELECT id FROM inventory WHERE id = ? AND user_id = ?");
    $chk->execute([$id, $user['id']]);
    if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Inventory item not found or access denied']);
        exit;
    }

    $del = $db->prepare("DELETE FROM inventory WHERE id = ? AND user_id = ?");
    $del->execute([$id, $user['id']]);

    echo json_encode([
        'status'               => 'success',
        'message'              => 'Inventory item removed from cellar stock',
        'deleted_inventory_id' => $id
    ]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}
