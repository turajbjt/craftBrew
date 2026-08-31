<?php
/**
 * RESTful API Brewing Calculators & Scaling Endpoint:
 * - POST /api/v1/index.php?route=calculators/abv
 * - POST /api/v1/index.php?route=calculators/scale
 * - POST /api/v1/index.php?route=calculators/hydrometer-temp
 * - POST /api/v1/index.php?route=calculators/priming-sugar
 */

header('Content-Type: application/json; charset=utf-8');

$subAction = $subRoute ?? ($_GET['action'] ?? '');
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

switch ($subAction) {
    // 1. ABV & Attenuation Calculator
    case 'abv':
        $og = sanitize_float($input['og'] ?? ($_GET['og'] ?? 0));
        $fg = sanitize_float($input['fg'] ?? ($_GET['fg'] ?? 0));

        if ($og <= 0 || $fg <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid numeric og (e.g. 1.055) and fg (e.g. 1.010) are required']);
            exit;
        }

        $standardAbv = round(($og - $fg) * 131.25, 2);
        $advancedAbv = round((76.08 * ($og - $fg) / (1.775 - $og)) * ($fg / 0.794), 2);
        $attenuation = round((($og - $fg) / ($og - 1.000)) * 100, 1);
        $caloriesPer12oz = round((6.9 * $standardAbv + 4.0 * (($fg - 1.000) * 1000 - ($og - 1.000) * 1000 * 0.18)) * 3.55);

        echo json_encode([
            'status' => 'success',
            'og'     => $og,
            'fg'     => $fg,
            'abv_standard_percent'    => max(0, $standardAbv),
            'abv_advanced_percent'    => max(0, $advancedAbv),
            'apparent_attenuation_pct'=> max(0, $attenuation),
            'calories_per_12oz'       => max(0, $caloriesPer12oz)
        ]);
        break;

    // 2. Recipe Auto-Scaler Engine
    case 'scale':
        $srcVolume     = max(0.1, sanitize_float($input['source_volume'] ?? 5.0));
        $targetVolume  = max(0.1, sanitize_float($input['target_volume'] ?? 5.0));
        $srcEfficiency = max(10, min(100, sanitize_float($input['source_efficiency'] ?? 75.0)));
        $targetEff     = max(10, min(100, sanitize_float($input['target_efficiency'] ?? 75.0)));

        $volRatio = $targetVolume / $srcVolume;
        $effRatio = $srcEfficiency / $targetEff;

        $ingredients = is_array($input['ingredients'] ?? null) ? $input['ingredients'] : [];
        $scaledIngredients = [];
        $totalGrainLbs = 0;

        foreach ($ingredients as $ing) {
            $name = sanitize_text($ing['name'] ?? '', 100);
            $type = sanitize_text($ing['ingredient_type'] ?? 'Fermentable', 50);
            $origAmt = (float)($ing['amount'] ?? 0);
            $unit = sanitize_text($ing['unit'] ?? 'lbs', 20);

            // Scale fermentables with efficiency; others scale with volume
            $scaleFactor = ($type === 'Fermentable') ? ($volRatio * $effRatio) : $volRatio;
            $scaledAmt = round($origAmt * $scaleFactor, 3);

            if ($type === 'Fermentable' && (strtolower($unit) === 'lbs' || strtolower($unit) === 'lb')) {
                $totalGrainLbs += $scaledAmt;
            }

            $scaledIngredients[] = [
                'name'            => $name,
                'ingredient_type' => $type,
                'original_amount' => $origAmt,
                'scaled_amount'   => $scaledAmt,
                'unit'            => $unit,
                'stage_addition'  => sanitize_text($ing['stage_addition'] ?? 'Primary', 50),
                'notes'           => sanitize_text($ing['notes'] ?? '', 255)
            ];
        }

        // Water Requirement Estimates (Mash/Sparge)
        $strikeRatio = 1.35; // qts/lb
        $strikeWaterGal = round(($totalGrainLbs * $strikeRatio) / 4, 2);
        $grainLossGal = round($totalGrainLbs * 0.125, 2);
        $boilLossGal = round($targetVolume * 0.10, 2);
        $spargeWaterGal = max(0, round(($targetVolume + $grainLossGal + $boilLossGal) - $strikeWaterGal, 2));

        echo json_encode([
            'status'             => 'success',
            'source_volume_gal'  => $srcVolume,
            'target_volume_gal'  => $targetVolume,
            'volume_scaling_factor' => round($volRatio, 4),
            'efficiency_scaling_factor' => round($effRatio, 4),
            'scaled_ingredients' => $scaledIngredients,
            'water_requirements' => [
                'total_grain_lbs'  => round($totalGrainLbs, 2),
                'strike_water_gal' => $strikeWaterGal,
                'grain_absorption_loss_gal' => $grainLossGal,
                'estimated_sparge_water_gal' => $spargeWaterGal,
                'total_brewing_water_gal'   => round($strikeWaterGal + $spargeWaterGal, 2)
            ]
        ]);
        break;

    // 3. Hydrometer Temperature Correction
    case 'hydrometer-temp':
        $measuredFg = sanitize_float($input['measured_gravity'] ?? ($_GET['measured_gravity'] ?? 1.050));
        $sampleTemp = sanitize_float($input['sample_temp_f'] ?? ($_GET['sample_temp_f'] ?? 70.0));
        $calibTemp  = sanitize_float($input['calibration_temp_f'] ?? ($_GET['calibration_temp_f'] ?? 60.0));

        // Standard ASBC formula for temp correction in Fahrenheit
        $corrected = $measuredFg * ((1.00130346 - 0.000134722124 * $sampleTemp + 0.00000204052596 * pow($sampleTemp, 2) - 0.00000000232820948 * pow($sampleTemp, 3)) /
                                    (1.00130346 - 0.000134722124 * $calibTemp + 0.00000204052596 * pow($calibTemp, 2) - 0.00000000232820948 * pow($calibTemp, 3)));

        echo json_encode([
            'status'            => 'success',
            'measured_gravity'  => $measuredFg,
            'sample_temp_f'     => $sampleTemp,
            'calibration_temp_f'=> $calibTemp,
            'corrected_gravity' => round($corrected, 4),
            'gravity_delta'     => round($corrected - $measuredFg, 4)
        ]);
        break;

    // 4. Priming Sugar / Carbonation Calculator
    case 'priming-sugar':
        $volBeerGal = max(0.1, sanitize_float($input['batch_size_gal'] ?? 5.0));
        $targetCO2  = max(1.0, sanitize_float($input['target_co2_volumes'] ?? 2.4));
        $beerTempF  = sanitize_float($input['beer_temp_f'] ?? 68.0);

        // Dissolved CO2 in beer from fermentation temp
        $dissolvedCO2 = max(0.5, 3.0378 - (0.050062 * $beerTempF) + (0.00026555 * pow($beerTempF, 2)));
        $neededCO2 = max(0, $targetCO2 - $dissolvedCO2);

        // Grams per gallon factors for priming agents
        $cornSugarGrams = round($neededCO2 * 15.195 * $volBeerGal, 1);
        $tableSugarGrams = round($neededCO2 * 13.8 * $volBeerGal, 1);
        $dmeGrams = round($neededCO2 * 20.26 * $volBeerGal, 1);
        $honeyGrams = round($neededCO2 * 19.0 * $volBeerGal, 1);

        echo json_encode([
            'status'             => 'success',
            'batch_size_gal'     => $volBeerGal,
            'target_co2_volumes' => $targetCO2,
            'beer_temp_f'        => $beerTempF,
            'residual_co2_vols'  => round($dissolvedCO2, 2),
            'priming_sugar_dosages' => [
                'corn_sugar_dextrose' => ['grams' => $cornSugarGrams, 'ounces' => round($cornSugarGrams * 0.035274, 2)],
                'table_sugar_sucrose' => ['grams' => $tableSugarGrams, 'ounces' => round($tableSugarGrams * 0.035274, 2)],
                'dry_malt_extract'    => ['grams' => $dmeGrams, 'ounces' => round($dmeGrams * 0.035274, 2)],
                'raw_honey'           => ['grams' => $honeyGrams, 'ounces' => round($honeyGrams * 0.035274, 2)]
            ]
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid calculator action. Available actions: abv, scale, hydrometer-temp, priming-sugar'
        ]);
        break;
}
