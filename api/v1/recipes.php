<?php
/**
 * RESTful API Recipes Endpoint:
 * - GET    /api/v1/index.php?route=recipes            (List recipes with search/filters)
 * - GET    /api/v1/index.php?route=recipes&id={id}    (Recipe detail with ingredients, supplies, steps)
 * - POST   /api/v1/index.php?route=recipes            (Create recipe with structured items)
 * - PUT    /api/v1/index.php?route=recipes&id={id}    (Update recipe and structured items)
 * - DELETE /api/v1/index.php?route=recipes&id={id}    (Delete recipe)
 */

$user = authenticate_api_request();
$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Read JSON input body
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

if ($method === 'GET') {
    if ($id > 0) {
        $stmt = $db->prepare("
            SELECT r.*, c.name as category_name, u.username
            FROM recipes r
            JOIN categories c ON r.category_id = c.id
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ? AND (r.user_id = ? OR r.is_public = 1)
        ");
        $stmt->execute([$id, $user['id']]);
        $recipe = $stmt->fetch();

        if (!$recipe) {
            http_response_code(404);
            echo json_encode(['error' => 'Recipe not found or access denied']);
            exit;
        }

        $recipe['details'] = get_recipe_details($id);

        echo json_encode([
            'status' => 'success',
            'recipe' => $recipe
        ]);
    } else {
        $categoryFilter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
        $styleFilter    = sanitize_text($_GET['style'] ?? '', 100);
        $searchQuery    = sanitize_text($_GET['search'] ?? '', 50);
        $publicOnly     = isset($_GET['public_only']) ? (bool)$_GET['public_only'] : false;
        $limit          = min(max(1, (int)($_GET['limit'] ?? 50)), 200);
        $offset         = max(0, (int)($_GET['offset'] ?? 0));

        $sql = "
            SELECT r.*, c.name as category_name, u.username,
                   (SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.recipe_id = r.id) as ingredients_count,
                   (SELECT COUNT(*) FROM recipe_steps rs WHERE rs.recipe_id = r.id) as steps_count
            FROM recipes r
            JOIN categories c ON r.category_id = c.id
            JOIN users u ON r.user_id = u.id
            WHERE (r.user_id = ? OR r.is_public = 1)
        ";
        $params = [$user['id']];

        if ($publicOnly) {
            $sql = str_replace('(r.user_id = ? OR r.is_public = 1)', 'r.is_public = 1', $sql);
            $params = [];
        }

        if ($categoryFilter > 0) {
            $sql .= " AND r.category_id = ?";
            $params[] = $categoryFilter;
        }
        if (!empty($styleFilter)) {
            $sql .= " AND r.style = ?";
            $params[] = $styleFilter;
        }
        if (!empty($searchQuery)) {
            $sql .= " AND (r.name LIKE ? OR r.style LIKE ? OR r.ingredients LIKE ?)";
            $params[] = "%{$searchQuery}%";
            $params[] = "%{$searchQuery}%";
            $params[] = "%{$searchQuery}%";
        }

        $sql .= " ORDER BY r.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $recipes = $stmt->fetchAll();

        // Optional full details inclusion
        $includeDetails = !empty($_GET['include_details']);
        if ($includeDetails) {
            foreach ($recipes as &$rec) {
                $rec['details'] = get_recipe_details($rec['id']);
            }
        }

        echo json_encode([
            'status'  => 'success',
            'count'   => count($recipes),
            'limit'   => $limit,
            'offset'  => $offset,
            'recipes' => $recipes
        ]);
    }

} elseif ($method === 'POST') {
    // Create new recipe
    $name         = sanitize_text($input['name'] ?? '', 100);
    $catId        = max(1, (int)($input['category_id'] ?? 1));
    $style        = sanitize_text($input['style'] ?? '', 100);
    $batchSize    = validate_batch_size($input['batch_size_gal'] ?? 5.0);
    $targetPreOg  = validate_gravity($input['target_pre_og'] ?? null);
    $targetOg     = validate_gravity($input['target_og'] ?? null);
    $targetFg     = validate_gravity($input['target_fg'] ?? null);
    $targetAbv    = sanitize_float($input['target_abv'] ?? null);
    if ($targetAbv !== null && ($targetAbv < 0 || $targetAbv > 100)) $targetAbv = null;

    $ingredientsRaw = sanitize_text($input['ingredients_raw'] ?? ($input['ingredients'] ?? ''), 5000);
    $instructions   = sanitize_text($input['instructions'] ?? '', 5000);
    $isPublic       = !empty($input['is_public']) ? 1 : 0;

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Recipe name is required']);
        exit;
    }

    $ins = $db->prepare("
        INSERT INTO recipes (
            user_id, category_id, name, style, batch_size_gal,
            target_pre_og, target_og, target_fg, target_abv,
            ingredients, instructions, is_public
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?
        )
    ");
    $ins->execute([
        $user['id'], $catId, $name, $style, $batchSize,
        $targetPreOg, $targetOg, $targetFg, $targetAbv ? round($targetAbv, 2) : null,
        $ingredientsRaw, $instructions, $isPublic
    ]);
    $newRecipeId = (int)$db->lastInsertId();

    // Save structured child tables
    $ingredients = is_array($input['ingredients_list'] ?? null) ? $input['ingredients_list'] : (is_array($input['ingredients'] ?? null) ? $input['ingredients'] : []);
    $supplies    = is_array($input['supplies'] ?? null) ? $input['supplies'] : [];
    $steps       = is_array($input['steps'] ?? null) ? $input['steps'] : [];

    save_recipe_details($newRecipeId, $ingredients, $supplies, $steps);

    http_response_code(201);
    echo json_encode([
        'status'    => 'success',
        'message'   => 'Recipe formulated and saved successfully',
        'recipe_id' => $newRecipeId
    ]);

} elseif ($method === 'PUT' || $method === 'PATCH') {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid recipe id is required in query parameter (?id={id})']);
        exit;
    }

    // Check ownership
    $chk = $db->prepare("SELECT * FROM recipes WHERE id = ? AND user_id = ?");
    $chk->execute([$id, $user['id']]);
    $existing = $chk->fetch();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Recipe not found or access denied']);
        exit;
    }

    $name         = isset($input['name']) ? sanitize_text($input['name'], 100) : $existing['name'];
    $catId        = isset($input['category_id']) ? max(1, (int)$input['category_id']) : (int)$existing['category_id'];
    $style        = isset($input['style']) ? sanitize_text($input['style'], 100) : $existing['style'];
    $batchSize    = isset($input['batch_size_gal']) ? validate_batch_size($input['batch_size_gal']) : (float)$existing['batch_size_gal'];
    $targetPreOg  = array_key_exists('target_pre_og', $input) ? validate_gravity($input['target_pre_og']) : $existing['target_pre_og'];
    $targetOg     = array_key_exists('target_og', $input) ? validate_gravity($input['target_og']) : $existing['target_og'];
    $targetFg     = array_key_exists('target_fg', $input) ? validate_gravity($input['target_fg']) : $existing['target_fg'];
    $targetAbv    = array_key_exists('target_abv', $input) ? sanitize_float($input['target_abv']) : (float)$existing['target_abv'];
    if ($targetAbv !== null && ($targetAbv < 0 || $targetAbv > 100)) $targetAbv = null;

    $ingredientsRaw = isset($input['ingredients_raw']) ? sanitize_text($input['ingredients_raw'], 5000) : (isset($input['ingredients']) && is_string($input['ingredients']) ? sanitize_text($input['ingredients'], 5000) : $existing['ingredients']);
    $instructions   = isset($input['instructions']) ? sanitize_text($input['instructions'], 5000) : $existing['instructions'];
    $isPublic       = array_key_exists('is_public', $input) ? (!empty($input['is_public']) ? 1 : 0) : (int)$existing['is_public'];

    $up = $db->prepare("
        UPDATE recipes SET
            category_id = ?, name = ?, style = ?, batch_size_gal = ?,
            target_pre_og = ?, target_og = ?, target_fg = ?, target_abv = ?,
            ingredients = ?, instructions = ?, is_public = ?
        WHERE id = ? AND user_id = ?
    ");
    $up->execute([
        $catId, $name, $style, $batchSize,
        $targetPreOg, $targetOg, $targetFg, $targetAbv ? round($targetAbv, 2) : null,
        $ingredientsRaw, $instructions, $isPublic,
        $id, $user['id']
    ]);

    // If structured lists are provided in payload, update them
    if (isset($input['ingredients_list']) || isset($input['supplies']) || isset($input['steps'])) {
        $currDetails = get_recipe_details($id);
        $ingredients = is_array($input['ingredients_list'] ?? null) ? $input['ingredients_list'] : (is_array($input['ingredients'] ?? null) ? $input['ingredients'] : $currDetails['ingredients']);
        $supplies    = is_array($input['supplies'] ?? null) ? $input['supplies'] : $currDetails['supplies'];
        $steps       = is_array($input['steps'] ?? null) ? $input['steps'] : $currDetails['steps'];

        save_recipe_details($id, $ingredients, $supplies, $steps);
    }

    echo json_encode([
        'status'    => 'success',
        'message'   => 'Recipe updated successfully',
        'recipe_id' => $id
    ]);

} elseif ($method === 'DELETE') {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid recipe id is required in query parameter (?id={id})']);
        exit;
    }

    $chk = $db->prepare("SELECT id FROM recipes WHERE id = ? AND user_id = ?");
    $chk->execute([$id, $user['id']]);
    if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Recipe not found or access denied']);
        exit;
    }

    $del = $db->prepare("DELETE FROM recipes WHERE id = ? AND user_id = ?");
    $del->execute([$id, $user['id']]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Recipe deleted successfully',
        'deleted_recipe_id' => $id
    ]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}
