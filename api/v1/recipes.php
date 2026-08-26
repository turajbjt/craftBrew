<?php
/**
 * API Recipes Endpoint: GET /api/v1/index.php?route=recipes
 * Includes structured ingredients, supplies, and process steps
 */

$user = authenticate_api_request();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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
        echo json_encode(['error' => 'Recipe not found']);
        exit;
    }

    $recipe['details'] = get_recipe_details($id);

    echo json_encode(['status' => 'success', 'recipe' => $recipe]);
} else {
    $stmt = $db->prepare("
        SELECT r.*, c.name as category_name, u.username
        FROM recipes r
        JOIN categories c ON r.category_id = c.id
        JOIN users u ON r.user_id = u.id
        WHERE (r.user_id = ? OR r.is_public = 1)
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $recipes = $stmt->fetchAll();

    foreach ($recipes as &$rec) {
        $rec['details'] = get_recipe_details($rec['id']);
    }

    echo json_encode(['status' => 'success', 'count' => count($recipes), 'recipes' => $recipes]);
}
