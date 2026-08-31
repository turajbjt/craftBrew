<?php
/**
 * CraftBrew - BJCP Style Guidelines & SRM Color Helper
 * Official BJCP (Beer Judge Certification Program) 2015/2021 Reference Dataset
 */

function get_bjcp_styles() {
    return [
        'American IPA' => [
            'category' => 'IPA',
            'og_min' => 1.056, 'og_max' => 1.070,
            'fg_min' => 1.008, 'fg_max' => 1.014,
            'abv_min' => 5.5,  'abv_max' => 7.5,
            'ibu_min' => 40,   'ibu_max' => 70,
            'srm_min' => 6,    'srm_max' => 14,
            'description' => 'A decidedly hoppy and bitter, moderately strong American pale ale, showcasing modern American or New World hop varieties.'
        ],
        'Hazy / New England IPA (NEIPA)' => [
            'category' => 'IPA',
            'og_min' => 1.060, 'og_max' => 1.085,
            'fg_min' => 1.010, 'fg_max' => 1.020,
            'abv_min' => 6.0,  'abv_max' => 9.0,
            'ibu_min' => 25,   'ibu_max' => 60,
            'srm_min' => 3,    'srm_max' => 7,
            'description' => 'An American IPA with intense fruit flavors and aromas, a soft body, smooth mouthfeel, and often opaque with substantial haze.'
        ],
        'Double IPA / Imperial IPA' => [
            'category' => 'IPA',
            'og_min' => 1.065, 'og_max' => 1.085,
            'fg_min' => 1.008, 'fg_max' => 1.018,
            'abv_min' => 7.5,  'abv_max' => 10.0,
            'ibu_min' => 60,   'ibu_max' => 100,
            'srm_min' => 6,    'srm_max' => 14,
            'description' => 'An intensely hoppy, fairly strong pale ale without the big, rich, complex maltiness of an American barleywine.'
        ],
        'American Pale Ale' => [
            'category' => 'Pale American Ale',
            'og_min' => 1.045, 'og_max' => 1.060,
            'fg_min' => 1.010, 'fg_max' => 1.015,
            'abv_min' => 4.5,  'abv_max' => 6.2,
            'ibu_min' => 30,   'ibu_max' => 50,
            'srm_min' => 5,    'srm_max' => 10,
            'description' => 'An average-strength, hop-forward pale American craft beer with sufficient supporting malt to make the beer balanced.'
        ],
        'American Amber Ale' => [
            'category' => 'Amber and Brown American Ale',
            'og_min' => 1.045, 'og_max' => 1.060,
            'fg_min' => 1.010, 'fg_max' => 1.015,
            'abv_min' => 4.5,  'abv_max' => 6.2,
            'ibu_min' => 25,   'ibu_max' => 40,
            'srm_min' => 10,   'srm_max' => 17,
            'description' => 'An amber, hoppy, moderate-strength American craft beer with a caramel malty flavor.'
        ],
        'American Brown Ale' => [
            'category' => 'Amber and Brown American Ale',
            'og_min' => 1.045, 'og_max' => 1.060,
            'fg_min' => 1.010, 'fg_max' => 1.016,
            'abv_min' => 4.3,  'abv_max' => 6.2,
            'ibu_min' => 20,   'ibu_max' => 30,
            'srm_min' => 18,   'srm_max' => 35,
            'description' => 'A malty but hoppy beer with caramel, chocolate, and toasty malt flavors, complemented by American hop character.'
        ],
        'American Stout' => [
            'category' => 'American Porter and Stout',
            'og_min' => 1.050, 'og_max' => 1.075,
            'fg_min' => 1.010, 'fg_max' => 1.022,
            'abv_min' => 5.0,  'abv_max' => 7.0,
            'ibu_min' => 35,   'ibu_max' => 75,
            'srm_min' => 30,   'srm_max' => 40,
            'description' => 'A fairly strong, highly roasted, bitter, hoppy dark American beer.'
        ],
        'Imperial Stout' => [
            'category' => 'American Porter and Stout',
            'og_min' => 1.075, 'og_max' => 1.115,
            'fg_min' => 1.018, 'fg_max' => 1.030,
            'abv_min' => 8.0,  'abv_max' => 12.0,
            'ibu_min' => 50,   'ibu_max' => 90,
            'srm_min' => 30,   'srm_max' => 40,
            'description' => 'An intensely-flavored, big, dark ale with a wide range of flavor balances and regional interpretations.'
        ],
        'Irish Dry Stout' => [
            'category' => 'Irish Beer',
            'og_min' => 1.036, 'og_max' => 1.044,
            'fg_min' => 1.007, 'fg_max' => 1.011,
            'abv_min' => 4.0,  'abv_max' => 4.5,
            'ibu_min' => 25,   'ibu_max' => 45,
            'srm_min' => 25,   'srm_max' => 40,
            'description' => 'A black beer with a pronounced roasted flavor, often similar to coffee, with a creamy mouthfeel.'
        ],
        'English Porter' => [
            'category' => 'English Porter',
            'og_min' => 1.040, 'og_max' => 1.052,
            'fg_min' => 1.008, 'fg_max' => 1.014,
            'abv_min' => 4.0,  'abv_max' => 5.4,
            'ibu_min' => 18,   'ibu_max' => 35,
            'srm_min' => 20,   'srm_max' => 30,
            'description' => 'A moderate-strength dark English ale with a restrained, roasty, caramel, and toffee character.'
        ],
        'German Pils' => [
            'category' => 'Pale Bitter European Beer',
            'og_min' => 1.044, 'og_max' => 1.050,
            'fg_min' => 1.008, 'fg_max' => 1.013,
            'abv_min' => 4.4,  'abv_max' => 5.2,
            'ibu_min' => 22,   'ibu_max' => 40,
            'srm_min' => 2,    'srm_max' => 5,
            'description' => 'A light-bodied, highly-attenuated, gold-colored, bottom-fermented bitter German beer showcasing floral noble hop aroma.'
        ],
        'Czech Premium Pale Lager (Bohemian Pilsner)' => [
            'category' => 'Czech Lager',
            'og_min' => 1.044, 'og_max' => 1.060,
            'fg_min' => 1.013, 'fg_max' => 1.017,
            'abv_min' => 4.2,  'abv_max' => 5.8,
            'ibu_min' => 30,   'ibu_max' => 45,
            'srm_min' => 3.5,  'srm_max' => 6,
            'description' => 'Rich, complex, rounded yet crisp and refreshing pale Czech lager with prominent Saaz hop character.'
        ],
        'Märzen / Oktoberfest' => [
            'category' => 'Amber Malty European Lager',
            'og_min' => 1.054, 'og_max' => 1.060,
            'fg_min' => 1.010, 'fg_max' => 1.014,
            'abv_min' => 5.8,  'abv_max' => 6.3,
            'ibu_min' => 18,   'ibu_max' => 24,
            'srm_min' => 8,    'srm_max' => 17,
            'description' => 'An elegant, malty German amber lager with a clean, rich, toasty, and bready malt flavor.'
        ],
        'Saison' => [
            'category' => 'Belgian Ale',
            'og_min' => 1.048, 'og_max' => 1.065,
            'fg_min' => 1.002, 'fg_max' => 1.008,
            'abv_min' => 5.0,  'abv_max' => 7.0,
            'ibu_min' => 20,   'ibu_max' => 35,
            'srm_min' => 5,    'srm_max' => 14,
            'description' => 'A refreshing, highly-attenuated, dry, fruity and spicy Belgian ale with high carbonation.'
        ],
        'Belgian Tripel' => [
            'category' => 'Strong Belgian Ale',
            'og_min' => 1.075, 'og_max' => 1.085,
            'fg_min' => 1.008, 'fg_max' => 1.014,
            'abv_min' => 7.5,  'abv_max' => 9.5,
            'ibu_min' => 20,   'ibu_max' => 40,
            'srm_min' => 4.5,  'srm_max' => 7,
            'description' => 'A pale, spicy, fruity, and dry strong Belgian ale with a pleasant rounded malt flavor and firm bitterness.'
        ],
        'Belgian Dubbel' => [
            'category' => 'Strong Belgian Ale',
            'og_min' => 1.062, 'og_max' => 1.075,
            'fg_min' => 1.008, 'fg_max' => 1.018,
            'abv_min' => 6.0,  'abv_max' => 7.6,
            'ibu_min' => 15,   'ibu_max' => 25,
            'srm_min' => 10,   'srm_max' => 17,
            'description' => 'A deep reddish-copper, moderately strong, malty, complex Belgian ale with rich dark fruit and caramel notes.'
        ],
        'Witbier' => [
            'category' => 'Belgian Ale',
            'og_min' => 1.044, 'og_max' => 1.052,
            'fg_min' => 1.008, 'fg_max' => 1.012,
            'abv_min' => 4.5,  'abv_max' => 5.5,
            'ibu_min' => 8,    'ibu_max' => 20,
            'srm_min' => 2,    'srm_max' => 4,
            'description' => 'A refreshing, elegant, wheat-based ale spiced with coriander and dried orange peel.'
        ],
        'Weissbier / Hefeweizen' => [
            'category' => 'German Wheat Beer',
            'og_min' => 1.044, 'og_max' => 1.052,
            'fg_min' => 1.010, 'fg_max' => 1.014,
            'abv_min' => 4.3,  'abv_max' => 5.6,
            'ibu_min' => 8,    'ibu_max' => 15,
            'srm_min' => 2,    'srm_max' => 6,
            'description' => 'A pale, spicy, fruity, refreshing German wheat beer with high carbonation and distinctive banana and clove yeast character.'
        ],
        'Berliner Weisse' => [
            'category' => 'European Sour Ale',
            'og_min' => 1.028, 'og_max' => 1.032,
            'fg_min' => 1.003, 'fg_max' => 1.006,
            'abv_min' => 2.8,  'abv_max' => 3.8,
            'ibu_min' => 3,    'ibu_max' => 8,
            'srm_min' => 2,    'srm_max' => 3,
            'description' => 'A very pale, refreshing, low-alcohol German wheat beer with a clean lactic sourness and high carbonation.'
        ],
        'American Barleywine' => [
            'category' => 'Strong American Ale',
            'og_min' => 1.080, 'og_max' => 1.120,
            'fg_min' => 1.016, 'fg_max' => 1.030,
            'abv_min' => 8.0,  'abv_max' => 12.0,
            'ibu_min' => 50,   'ibu_max' => 100,
            'srm_min' => 10,   'srm_max' => 19,
            'description' => 'A very strong, rich, and full-bodied American ale with a significant malty presence and intense American hop bitterness and flavor.'
        ],
        'Sweet Cider' => [
            'category' => 'Cider',
            'og_min' => 1.045, 'og_max' => 1.065,
            'fg_min' => 1.012, 'fg_max' => 1.020,
            'abv_min' => 4.5,  'abv_max' => 6.5,
            'ibu_min' => 0,    'ibu_max' => 0,
            'srm_min' => 2,    'srm_max' => 6,
            'description' => 'Apple fermented cider with perceptible residual sweetness and fresh apple character.'
        ],
        'Dry Cider' => [
            'category' => 'Cider',
            'og_min' => 1.045, 'og_max' => 1.060,
            'fg_min' => 0.995, 'fg_max' => 1.002,
            'abv_min' => 5.0,  'abv_max' => 7.5,
            'ibu_min' => 0,    'ibu_max' => 0,
            'srm_min' => 2,    'srm_max' => 5,
            'description' => 'Clean, crisp, fully fermented dry apple cider with crisp acidity and no residual sugar.'
        ],
        'Semi-Sweet Traditional Mead' => [
            'category' => 'Mead',
            'og_min' => 1.080, 'og_max' => 1.120,
            'fg_min' => 1.010, 'fg_max' => 1.025,
            'abv_min' => 10.0, 'abv_max' => 14.0,
            'ibu_min' => 0,    'ibu_max' => 0,
            'srm_min' => 1,    'srm_max' => 6,
            'description' => 'Honey fermented wine balancing pleasant floral honey sweetness with balanced acidity and alcohol warmth.'
        ]
    ];
}

/**
 * Match style string against BJCP database (case-insensitive fuzzy match)
 */
function find_bjcp_style($styleName) {
    if (empty($styleName)) return null;
    $styles = get_bjcp_styles();
    
    // Direct match
    if (isset($styles[$styleName])) {
        return ['name' => $styleName] + $styles[$styleName];
    }
    
    $cleanQuery = strtolower(trim($styleName));
    
    // Case-insensitive exact
    foreach ($styles as $name => $data) {
        if (strtolower($name) === $cleanQuery) {
            return ['name' => $name] + $data;
        }
    }
    
    // Substring match
    foreach ($styles as $name => $data) {
        if (stripos($name, $cleanQuery) !== false || stripos($cleanQuery, $name) !== false) {
            return ['name' => $name] + $data;
        }
    }
    
    return null;
}

/**
 * Convert SRM color value into a realistic Hex Color for UI previews
 */
function srm_to_hex_color($srm) {
    $srm = max(1, (float)$srm);
    $srmTable = [
        1  => '#FFE699',
        2  => '#FFD878',
        3  => '#FFCA5A',
        4  => '#FFBF42',
        5  => '#FBB123',
        6  => '#F8A600',
        7  => '#F39C00',
        8  => '#EA8F00',
        9  => '#E58500',
        10 => '#DE7C00',
        11 => '#D77200',
        12 => '#CF6900',
        13 => '#C96200',
        14 => '#C35900',
        15 => '#BB5100',
        16 => '#B54C00',
        17 => '#B04500',
        18 => '#A63E00',
        19 => '#A13700',
        20 => '#9B3200',
        22 => '#8B2500',
        24 => '#7C1E00',
        26 => '#6D1900',
        28 => '#5D1400',
        30 => '#4E1100',
        35 => '#350A00',
        40 => '#1E0500'
    ];
    
    $closestSrm = 1;
    $minDiff = 999;
    foreach ($srmTable as $key => $hex) {
        $diff = abs($srm - $key);
        if ($diff < $minDiff) {
            $minDiff = $diff;
            $closestSrm = $key;
        }
    }
    return $srmTable[$closestSrm] ?? '#FBB123';
}

/**
 * Render an interactive HTML BJCP target gauge comparison component
 */
function render_bjcp_target_gauge($metricLabel, $actualValue, $minVal, $maxVal, $unit = '', $decimals = 3) {
    $actual = ($actualValue !== null && $actualValue !== '') ? (float)$actualValue : null;
    $min = (float)$minVal;
    $max = (float)$maxVal;
    
    $statusClass = 'badge-secondary';
    $statusText  = 'Not Set';
    $markerPct   = 50;
    
    if ($actual !== null && $actual > 0) {
        $span = max(0.001, $max - $min);
        // Extend bounds by 25% on each side for visualization
        $viewMin = $min - ($span * 0.25);
        $viewMax = $max + ($span * 0.25);
        $viewSpan = max(0.001, $viewMax - $viewMin);
        
        $markerPct = round((($actual - $viewMin) / $viewSpan) * 100);
        $markerPct = max(2, min(98, $markerPct));
        
        $rangeStartPct = round((($min - $viewMin) / $viewSpan) * 100);
        $rangeWidthPct = round(($span / $viewSpan) * 100);
        
        if ($actual >= $min && $actual <= $max) {
            $statusClass = 'badge-success';
            $statusText = '✓ In Style';
        } elseif ($actual < $min) {
            $statusClass = 'badge-warning';
            $statusText = '▼ Low (' . number_format($actual, $decimals) . ')';
        } else {
            $statusClass = 'badge-danger';
            $statusText = '▲ High (' . number_format($actual, $decimals) . ')';
        }
    } else {
        $rangeStartPct = 25;
        $rangeWidthPct = 50;
    }
    
    ob_start();
    ?>
    <div class="bjcp-gauge-item" style="margin-bottom: 0.75rem; padding: 0.6rem 0.8rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 6px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem; font-size: 0.85rem;">
            <strong><?= htmlspecialchars($metricLabel) ?></strong>
            <div>
                <span style="color: var(--text-muted); margin-right: 0.5rem; font-size: 0.8rem;">Target: <?= number_format($min, $decimals) ?> – <?= number_format($max, $decimals) ?> <?= htmlspecialchars($unit) ?></span>
                <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
            </div>
        </div>
        
        <div style="position: relative; height: 12px; background: #e2e8f0; border-radius: 6px; overflow: visible;">
            <!-- Target In-Style Green Zone -->
            <div style="position: absolute; left: <?= $rangeStartPct ?>%; width: <?= $rangeWidthPct ?>%; height: 100%; background: #10b981; opacity: 0.75; border-radius: 4px;" title="Target BJCP Range"></div>
            
            <!-- Actual Value Marker Pin -->
            <?php if ($actual !== null && $actual > 0): ?>
                <div style="position: absolute; left: <?= $markerPct ?>%; top: -3px; width: 6px; height: 18px; background: #0f172a; border: 1px solid #fff; border-radius: 3px; transform: translateX(-50%); box-shadow: 0 1px 4px rgba(0,0,0,0.3);" title="Your Value: <?= number_format($actual, $decimals) ?>"></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Get BJCP style data or null if not found
 */
function get_bjcp_style_data($styleName) {
    return find_bjcp_style($styleName);
}

/**
 * Generate starter formulation recipe payload for a given BJCP style
 */
function generate_recipe_starter_data($styleName) {
    $bjcp = find_bjcp_style($styleName);
    if (!$bjcp) return null;

    $targetOg  = round(($bjcp['og_min'] + $bjcp['og_max']) / 2, 3);
    $targetFg  = round(($bjcp['fg_min'] + $bjcp['fg_max']) / 2, 3);
    $targetAbv = round(($bjcp['abv_min'] + $bjcp['abv_max']) / 2, 1);
    $targetPreOg = null;

    $categoryName = $bjcp['category'];
    if (stripos($bjcp['name'], 'Cider') !== false) {
        $categoryName = 'Cider';
        $targetPreOg = 1.045;
        $ingredients = [
            ['name' => 'Fresh Pressed Apple Cider / Juice', 'ingredient_type' => 'Fermentable', 'amount' => 5.0, 'unit' => 'Gal', 'stage_addition' => 'Primary', 'notes' => 'Preservative-free juice (no potassium sorbate)'],
            ['name' => 'Corn Sugar / Dextrose (Optional for ABV boost)', 'ingredient_type' => 'Fermentable', 'amount' => 1.0, 'unit' => 'lbs', 'stage_addition' => 'Primary', 'notes' => 'Dissolved in warm cider if higher ABV desired'],
            ['name' => 'Yeast Nutrient (DAP / Fermaid-O)', 'ingredient_type' => 'Additive', 'amount' => 1.0, 'unit' => 'tsp', 'stage_addition' => 'Primary', 'notes' => 'Add at pitch time'],
            ['name' => 'Cider / Wine Yeast (SafCider or EC-1118)', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Rehydrated at 90-95°F']
        ];
        $steps = [
            ['step_number' => 1, 'phase' => 'Preparation', 'title' => 'Sanitize Fermenter & Equipment', 'duration' => '15 mins', 'target_temp' => '', 'instructions' => 'Clean and sanitize carboy, funnel, airlock, and hydrometer with Star San.'],
            ['step_number' => 2, 'phase' => 'Pitching', 'title' => 'Aerate Juice & Pitch Yeast', 'duration' => '10 mins', 'target_temp' => '65°F - 68°F', 'instructions' => 'Aerate juice thoroughly, record Starting OG hydrometer reading, and pitch yeast with nutrient.'],
            ['step_number' => 3, 'phase' => 'Primary', 'title' => 'Primary Fermentation', 'duration' => '14 days', 'target_temp' => '65°F', 'instructions' => 'Ferment in dark temperature-controlled area until specific gravity drops to target FG.'],
            ['step_number' => 4, 'phase' => 'Bottling', 'title' => 'Bottling & Carbonation', 'duration' => '60 mins', 'target_temp' => '70°F', 'instructions' => 'Prime with corn sugar for bottle carbonation (or stabilize with sorbate/sulfite if backsweetening).']
        ];
    } elseif (stripos($bjcp['name'], 'Mead') !== false) {
        $categoryName = 'Mead';
        $ingredients = [
            ['name' => 'Raw Clover / Wildflower Honey', 'ingredient_type' => 'Fermentable', 'amount' => 12.0, 'unit' => 'lbs', 'stage_addition' => 'Primary', 'notes' => 'Pure unpasteurized honey'],
            ['name' => 'Spring / Filtered Water', 'ingredient_type' => 'Water', 'amount' => 4.0, 'unit' => 'Gal', 'stage_addition' => 'Primary', 'notes' => 'Dechlorinated spring water'],
            ['name' => 'Fermaid-O Yeast Nutrient', 'ingredient_type' => 'Additive', 'amount' => 5.0, 'unit' => 'g', 'stage_addition' => 'Primary', 'notes' => 'TOSNA staggered addition'],
            ['name' => 'Wine / Mead Yeast (Lalvin D-47 or 71B)', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Rehydrated with Go-Ferm']
        ];
        $steps = [
            ['step_number' => 1, 'phase' => 'Preparation', 'title' => 'Mix Must & Dissolve Honey', 'duration' => '30 mins', 'target_temp' => '70°F', 'instructions' => 'Warm water to 95°F to dissolve honey into must, aerate vigorously, and record Starting OG.'],
            ['step_number' => 2, 'phase' => 'Primary', 'title' => 'Pitch Yeast & Staggered Nutrients', 'duration' => '7 days', 'target_temp' => '64°F - 68°F', 'instructions' => 'Add nutrients at 24h, 48h, 72h, and 1/3 sugar break.'],
            ['step_number' => 3, 'phase' => 'Secondary', 'title' => 'Secondary Racking & Bulk Aging', 'duration' => '60 days', 'target_temp' => '60°F - 65°F', 'instructions' => 'Rack off yeast lees once clear and allow to age and mellow.']
        ];
    } elseif (stripos($bjcp['name'], 'Stout') !== false || stripos($bjcp['name'], 'Porter') !== false) {
        $categoryName = 'Beer';
        $ingredients = [
            ['name' => 'Pale 2-Row / Maris Otter Malt', 'ingredient_type' => 'Fermentable', 'amount' => 9.0, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Base malt (75%)'],
            ['name' => 'Roasted Barley (300L)', 'ingredient_type' => 'Fermentable', 'amount' => 1.0, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Provides dry coffee roast character'],
            ['name' => 'Chocolate Malt (350L)', 'ingredient_type' => 'Fermentable', 'amount' => 0.75, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Adds dark chocolate notes'],
            ['name' => 'Flaked Barley / Oats', 'ingredient_type' => 'Fermentable', 'amount' => 0.75, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Enhances body and creamy head retention'],
            ['name' => 'East Kent Goldings / Fuggle Hops', 'ingredient_type' => 'Hop', 'amount' => 1.5, 'unit' => 'oz', 'stage_addition' => 'Boil', 'notes' => '60 min bittering addition (~35 IBU)'],
            ['name' => 'English Ale Yeast (WLP004 / S-04)', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Pitch at 66°F']
        ];
        $steps = [
            ['step_number' => 1, 'phase' => 'Mash/Steep', 'title' => 'Mash-In at 154°F', 'duration' => '60 mins', 'target_temp' => '154°F', 'instructions' => 'Strike grains with 3.75 gal of water at 165°F to achieve 154°F mash rest.'],
            ['step_number' => 2, 'phase' => 'Boil', 'title' => '60-Minute Boil & Bittering Addition', 'duration' => '60 mins', 'target_temp' => '212°F', 'instructions' => 'Bring wort to rolling boil and add 1.5 oz hops at 60 mins.'],
            ['step_number' => 3, 'phase' => 'Pitching', 'title' => 'Chill Wort & Inoculate Yeast', 'duration' => '20 mins', 'target_temp' => '66°F', 'instructions' => 'Rapidly chill to 66°F, aerate with oxygen/shaking, and pitch English ale yeast.'],
            ['step_number' => 4, 'phase' => 'Primary', 'title' => 'Primary Fermentation', 'duration' => '10 days', 'target_temp' => '66°F - 68°F', 'instructions' => 'Ferment until specific gravity reaches final gravity.']
        ];
    } else {
        $categoryName = 'Beer';
        $ingredients = [
            ['name' => 'American 2-Row Pale Malt', 'ingredient_type' => 'Fermentable', 'amount' => 10.5, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Base grain (85%)'],
            ['name' => 'Crystal / Caramel 40L Malt', 'ingredient_type' => 'Fermentable', 'amount' => 0.75, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Adds subtle sweetness and amber hue'],
            ['name' => 'Munich / Vienna Malt', 'ingredient_type' => 'Fermentable', 'amount' => 0.75, 'unit' => 'lbs', 'stage_addition' => 'Mash', 'notes' => 'Adds malt depth and complexity'],
            ['name' => 'Cascade / Centennial Hops (Bittering)', 'ingredient_type' => 'Hop', 'amount' => 1.0, 'unit' => 'oz', 'stage_addition' => 'Boil', 'notes' => '60 min addition'],
            ['name' => 'Citra / Mosaic / Simcoe Hops (Aroma)', 'ingredient_type' => 'Hop', 'amount' => 1.5, 'unit' => 'oz', 'stage_addition' => 'Whirlpool', 'notes' => 'Flameout / Whirlpool at 180°F'],
            ['name' => 'American Ale Yeast (US-05 / WLP001)', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkg', 'stage_addition' => 'Primary', 'notes' => 'Pitch at 67°F']
        ];
        $steps = [
            ['step_number' => 1, 'phase' => 'Mash/Steep', 'title' => 'Single Infusion Mash at 152°F', 'duration' => '60 mins', 'target_temp' => '152°F', 'instructions' => 'Strike grains with 4.0 gal of strike water at 163°F to achieve 152°F rest.'],
            ['step_number' => 2, 'phase' => 'Boil', 'title' => '60-Minute Boil & Hop Schedule', 'duration' => '60 mins', 'target_temp' => '212°F', 'instructions' => 'Add 1.0 oz bittering hops at 60 mins, 1.5 oz aroma hops at flameout/whirlpool.'],
            ['step_number' => 3, 'phase' => 'Pitching', 'title' => 'Chill Wort & Pitch Yeast', 'duration' => '20 mins', 'target_temp' => '67°F', 'instructions' => 'Chill to 67°F, oxygenate wort, and pitch American ale yeast.'],
            ['step_number' => 4, 'phase' => 'Primary', 'title' => 'Primary Fermentation & Dry Hop', 'duration' => '10 days', 'target_temp' => '67°F - 69°F', 'instructions' => 'Add dry hops on day 7 for 3-4 days before packaging.']
        ];
    }

    $supplies = [
        ['item_name' => 'Mash Tun / BIAB Brew Bag', 'category' => 'Equipment', 'quantity' => '1 unit', 'is_required' => 1, 'notes' => 'For grain mashing'],
        ['item_name' => 'Boil Kettle & Immersion Chiller', 'category' => 'Equipment', 'quantity' => '1 unit', 'is_required' => 1, 'notes' => 'For boiling & rapid wort chilling'],
        ['item_name' => 'Star San Sanitizer', 'category' => 'Supply', 'quantity' => '1 oz', 'is_required' => 1, 'notes' => 'No-rinse sanitizer for all cold-side gear']
    ];

    return [
        'name'            => 'My ' . $bjcp['name'],
        'style'           => $bjcp['name'],
        'category'        => $categoryName,
        'batch_size_gal'  => 5.0,
        'target_pre_og'   => $targetPreOg,
        'target_og'       => $targetOg,
        'target_fg'       => $targetFg,
        'target_abv'      => $targetAbv,
        'ingredients'     => $ingredients,
        'supplies'        => $supplies,
        'steps'           => $steps
    ];
}

