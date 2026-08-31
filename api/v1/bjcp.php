<?php
/**
 * RESTful API BJCP Style Guidelines & Starter Generator Endpoint:
 * - GET /api/v1/index.php?route=bjcp                    (List all BJCP styles with target bounds & SRM hex codes)
 * - GET /api/v1/index.php?route=bjcp&style={style_name} (Get single style spec & pre-formulated template)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/bjcp_styles.php';

$requestedStyle = trim($_GET['style'] ?? '');

if (!empty($requestedStyle)) {
    $styleData = get_bjcp_style_data($requestedStyle);
    if (!$styleData) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'message' => "BJCP Style '{$requestedStyle}' not found"
        ]);
        exit;
    }

    $starterTemplate = generate_recipe_starter_data($requestedStyle);

    echo json_encode([
        'status'           => 'success',
        'style'            => $requestedStyle,
        'guidelines'       => $styleData,
        'srm_color_hex'    => srm_to_hex_color(round(($styleData['srm_min'] + $styleData['srm_max']) / 2)),
        'starter_template' => $starterTemplate
    ]);
} else {
    $categoryFilter = trim($_GET['category'] ?? '');
    $allStyles = get_bjcp_styles();

    $stylesList = [];
    foreach ($allStyles as $name => $data) {
        if (!empty($categoryFilter) && strcasecmp($data['category'], $categoryFilter) !== 0) {
            continue;
        }
        $midSrm = round(($data['srm_min'] + $data['srm_max']) / 2);
        $stylesList[] = array_merge([
            'name'          => $name,
            'srm_color_hex' => srm_to_hex_color($midSrm)
        ], $data);
    }

    echo json_encode([
        'status'     => 'success',
        'count'      => count($stylesList),
        'categories' => ['Beer', 'Cider', 'Mead', 'Wine'],
        'styles'     => $stylesList
    ]);
}
