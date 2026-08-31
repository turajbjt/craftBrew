<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/bjcp_styles.php';

require_login();

// Calculations logic
$calcType   = $_POST['calc_type'] ?? 'abv';
$abvResult  = null;
$attenuationResult = null;
$tempCorrResult = null;
$sugarResult = null;
$sugarBoostResult = null;
$ibuResult          = null;
$srmResult          = null;
$strikeWaterResult  = null;
$yeastPitchResult   = null;
$waterChemResult    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($calcType === 'abv') {
        $og = (float)($_POST['og'] ?? 0);
        $fg = (float)($_POST['fg'] ?? 0);
        $formula = $_POST['formula'] ?? 'standard';
        if ($og > 1.0 && $fg > 0 && $og > $fg) {
            $abvResult = calculate_abv($og, $fg, $formula);
            $attenuationResult = round((($og - $fg) / ($og - 1.0)) * 100, 1);
        }
    } elseif ($calcType === 'temp_corr') {
        $measuredSg = (float)($_POST['measured_sg'] ?? 0);
        $sampleTemp = (float)($_POST['sample_temp'] ?? 60);
        $calibTemp  = (float)($_POST['calib_temp'] ?? 60);
        
        if ($measuredSg > 0) {
            if (abs($sampleTemp - $calibTemp) < 0.001) {
                $tempCorrResult = $measuredSg;
            } else {
                $t = $sampleTemp;
                $c = $calibTemp;
                $num = 1.00130346 - (0.000134722124 * $t) + (0.00000204052596 * pow($t, 2)) - (0.00000000232820948 * pow($t, 3));
                $den = 1.00130346 - (0.000134722124 * $c) + (0.00000204052596 * pow($c, 2)) - (0.00000000232820948 * pow($c, 3));
                $tempCorrResult = round($measuredSg * ($num / $den), 3);
            }
        }
    } elseif ($calcType === 'priming') {
        $volumeGal = (float)($_POST['volume_gal'] ?? 5);
        $co2Vol    = (float)($_POST['co2_vol'] ?? 2.4);
        $beerTemp  = (float)($_POST['beer_temp'] ?? 65);

        if ($volumeGal > 0 && $co2Vol > 0) {
            $volumeLiters = $volumeGal * 3.78541;
            $dissolvedCo2 = 3.0378 - (0.050062 * $beerTemp) + (0.00026555 * pow($beerTemp, 2));
            $neededCo2 = max(0, $co2Vol - $dissolvedCo2);
            $cornSugarGrams = round($neededCo2 * $volumeLiters * 4.4127, 1);
            $cornSugarOz    = round($cornSugarGrams / 28.3495, 2);
            $tableSugarGrams = round($cornSugarGrams * 0.91, 1);

            $sugarResult = [
                'corn_grams'  => $cornSugarGrams,
                'corn_oz'     => $cornSugarOz,
                'table_grams' => $tableSugarGrams,
                'dissolved'   => round($dissolvedCo2, 2)
            ];
        }
    } elseif ($calcType === 'sugar_boost') {
        $currentOg = (float)($_POST['current_og'] ?? 1.040);
        $targetOg  = (float)($_POST['target_og'] ?? 1.065);
        $batchGal  = (float)($_POST['batch_gal'] ?? 5.0);

        if ($targetOg > $currentOg && $batchGal > 0 && $currentOg >= 1.0) {
            $deltaPoints = ($targetOg - $currentOg) * 1000;
            $totalPointGallons = $deltaPoints * $batchGal;

            $sucroseLbs  = $totalPointGallons / 46.0;
            $dextroseLbs = $totalPointGallons / 42.0;
            $honeyLbs    = $totalPointGallons / 35.0;
            $dmeLbs      = $totalPointGallons / 44.0;
            $lmeLbs      = $totalPointGallons / 36.0;

            $sugarBoostResult = [
                'delta_pts' => round($deltaPoints, 1),
                'total_pt_gal' => round($totalPointGallons, 1),
                'sucrose' => [
                    'lbs' => round($sucroseLbs, 2),
                    'oz' => round($sucroseLbs * 16, 1),
                    'grams' => round($sucroseLbs * 453.592, 0),
                ],
                'dextrose' => [
                    'lbs' => round($dextroseLbs, 2),
                    'oz' => round($dextroseLbs * 16, 1),
                    'grams' => round($dextroseLbs * 453.592, 0),
                ],
                'honey' => [
                    'lbs' => round($honeyLbs, 2),
                    'oz' => round($honeyLbs * 16, 1),
                    'grams' => round($honeyLbs * 453.592, 0),
                ],
                'dme' => [
                    'lbs' => round($dmeLbs, 2),
                    'oz' => round($dmeLbs * 16, 1),
                    'grams' => round($dmeLbs * 453.592, 0),
                ],
                'lme' => [
                    'lbs' => round($lmeLbs, 2),
                    'oz' => round($lmeLbs * 16, 1),
                    'grams' => round($lmeLbs * 453.592, 0),
                ],
            ];
        }
    } elseif ($calcType === 'ibu_srm') {
        $hopOz      = (float)($_POST['hop_oz'] ?? 1.0);
        $alphaAcid  = (float)($_POST['alpha_acid'] ?? 5.5);
        $boilMins   = (float)($_POST['boil_mins'] ?? 60);
        $batchGal   = (float)($_POST['batch_gal'] ?? 5.0);
        $boilSg     = (float)($_POST['boil_sg'] ?? 1.050);
        $grainLbs   = (float)($_POST['grain_lbs'] ?? 10.0);
        $grainL     = (float)($_POST['grain_l'] ?? 10.0);

        if ($batchGal > 0) {
            $bignessFactor = 1.65 * pow(0.000125, $boilSg - 1.0);
            $timeFactor    = (1 - exp(-0.04 * $boilMins)) / 4.15;
            $utilization   = $bignessFactor * $timeFactor;
            $ibuVal        = round(($utilization * ($hopOz * ($alphaAcid / 100.0) * 7490)) / $batchGal, 1);

            $mcu           = ($grainLbs * $grainL) / $batchGal;
            $srmVal        = round(1.4922 * pow(max(0.1, $mcu), 0.6859), 1);

            $ibuResult = $ibuVal;
            $srmResult = [
                'srm' => $srmVal,
                'mcu' => round($mcu, 1),
            ];
        }
    } elseif ($calcType === 'strike_water') {
        $targetMashTemp = (float)($_POST['target_mash_temp'] ?? 152);
        $grainTemp      = (float)($_POST['grain_temp'] ?? 68);
        $waterRatio     = (float)($_POST['water_ratio'] ?? 1.25);
        $grainLbs       = (float)($_POST['grain_lbs'] ?? 10.0);
        $boilMins       = (float)($_POST['boil_mins'] ?? 60);
        $boiloffRate    = (float)($_POST['boiloff_rate'] ?? 1.0);

        if ($waterRatio > 0 && $grainLbs > 0) {
            $strikeTemp = (0.2 / $waterRatio) * ($targetMashTemp - $grainTemp) + $targetMashTemp;
            $mashVolGal = ($waterRatio * $grainLbs) / 4.0;
            $grainAbsorbGal = $grainLbs * 0.125;
            $boiloffGal = ($boilMins / 60.0) * $boiloffRate;
            $totalWaterNeeded = $mashVolGal + $grainAbsorbGal + $boiloffGal + 0.5;
            $spargeVolGal = max(0, $totalWaterNeeded - $mashVolGal);

            $strikeWaterResult = [
                'strike_temp'   => round($strikeTemp, 1),
                'mash_vol_gal'  => round($mashVolGal, 2),
                'sparge_vol_gal'=> round($spargeVolGal, 2),
                'total_water'   => round($totalWaterNeeded, 2),
                'grain_absorb'  => round($grainAbsorbGal, 2),
            ];
        }
    } elseif ($calcType === 'yeast_pitch') {
        $targetOg       = (float)($_POST['target_og'] ?? 1.055);
        $batchGal       = (float)($_POST['batch_gal'] ?? 5.0);
        $pitchRateType  = $_POST['pitch_rate_type'] ?? 'ale';
        $daysOld        = (int)($_POST['days_old'] ?? 30);

        if ($targetOg > 1.0 && $batchGal > 0) {
            $plato = round(($targetOg - 1.0) * 259, 1);
            $volMl = $batchGal * 3785.41;
            $rateFactor = ($pitchRateType === 'lager') ? 1.5 : 0.75;
            $cellsNeeded = round(($rateFactor * $volMl * $plato) / 1000000000, 1);

            $viability = max(0, round(100 - ($daysOld * 0.75), 0));
            $viableCellsPerPack = round(100 * ($viability / 100.0), 1);
            $packsNeeded = round($cellsNeeded / max(1, $viableCellsPerPack), 1);
            $starterVolL = round($cellsNeeded / 200.0, 2);

            $yeastPitchResult = [
                'plato'        => $plato,
                'cells_needed' => $cellsNeeded,
                'viability'    => $viability,
                'packs_needed' => $packsNeeded,
                'starter_l'    => $starterVolL,
            ];
        }
    } elseif ($calcType === 'water_chem') {
        $batchGal      = (float)($_POST['batch_gal'] ?? 5.0);
        $targetProfile = $_POST['target_profile'] ?? 'hoppy';

        if ($batchGal > 0) {
            $gypsumGrams  = ($targetProfile === 'hoppy') ? round(1.2 * $batchGal, 1) : round(0.5 * $batchGal, 1);
            $calciumGrams = ($targetProfile === 'malty') ? round(1.2 * $batchGal, 1) : round(0.6 * $batchGal, 1);
            $epsomGrams   = round(0.4 * $batchGal, 1);
            $lacticMl     = round(0.5 * $batchGal, 1);

            $waterChemResult = [
                'gypsum'  => $gypsumGrams,
                'calcium' => $calciumGrams,
                'epsom'   => $epsomGrams,
                'lactic'  => $lacticMl,
                'profile' => ucfirst($targetProfile),
            ];
        }
    }
}

$pageTitle = "Brewing Calculators - " . APP_NAME;
$activePage = 'calculators';
require_once __DIR__ . '/includes/header.php';
?>

<h1>🧮 Craft Brewing Calculators</h1>
<p style="color: var(--text-muted); margin-bottom: 2rem;">Accurately measure ABV, hydrometer temperature corrections, and priming sugar for bottling.</p>

<div class="card-grid">
    <!-- ABV & Attenuation Calculator -->
    <div class="card" id="calc-abv">
        <h3 class="card-title">🍺 ABV & Attenuation</h3>
        <p class="card-subtitle">Calculate Alcohol By Volume from Original & Final Gravity.</p>
        <form method="POST" action="calculators.php#calc-abv">
            <input type="hidden" name="calc_type" value="abv">
            <div class="form-group">
                <label class="form-label">Original Gravity (OG)</label>
                <input type="number" step="0.001" name="og" class="form-control" value="<?= htmlspecialchars($_POST['og'] ?? '1.050') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Final Gravity (FG)</label>
                <input type="number" step="0.001" name="fg" class="form-control" value="<?= htmlspecialchars($_POST['fg'] ?? '1.010') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Calculation Formula</label>
                <select name="formula" class="form-control">
                    <option value="standard" <?= (($_POST['formula'] ?? '') === 'standard') ? 'selected' : '' ?>>Standard Formula ((OG - FG) × 131.25)</option>
                    <option value="alternate" <?= (($_POST['formula'] ?? '') === 'alternate') ? 'selected' : '' ?>>Alternate High-Gravity (Hall / Cereal Killer)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Calculate ABV</button>
        </form>
        <?php if ($abvResult !== null): ?>
            <div style="margin-top: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary-color);">ABV: <?= $abvResult ?>%</div>
                <div style="color: var(--text-muted);">Apparent Attenuation: <strong><?= $attenuationResult ?>%</strong></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Hydrometer Temp Correction -->
    <div class="card" id="calc-temp-corr">
        <h3 class="card-title">🌡️ Hydrometer Temp Correction</h3>
        <p class="card-subtitle">Correct gravity reading for sample temperature.</p>
        <form method="POST" action="calculators.php#calc-temp-corr">
            <input type="hidden" name="calc_type" value="temp_corr">
            <div class="form-group">
                <label class="form-label">Measured Gravity</label>
                <input type="number" step="0.001" name="measured_sg" class="form-control" value="<?= htmlspecialchars($_POST['measured_sg'] ?? '1.050') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Sample Temp (°F)</label>
                <input type="number" step="0.1" name="sample_temp" class="form-control" value="<?= htmlspecialchars($_POST['sample_temp'] ?? '78') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Hydrometer Calibration Temp (°F)</label>
                <input type="number" step="0.1" name="calib_temp" class="form-control" value="<?= htmlspecialchars($_POST['calib_temp'] ?? '60') ?>" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Correct Gravity</button>
        </form>
        <?php if ($tempCorrResult !== null): ?>
            <div style="margin-top: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 1.6rem; font-weight: 800; color: #10b981;">Corrected SG: <?= sprintf('%.3f', $tempCorrResult) ?></div>
                <div style="color: var(--text-muted); font-size: 0.85rem;">Adjusted for <?= htmlspecialchars($_POST['sample_temp']) ?>°F vs <?= htmlspecialchars($_POST['calib_temp']) ?>°F calibration.</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Priming Sugar Calculator -->
    <div class="card" id="calc-priming">
        <h3 class="card-title">🍾 Priming Sugar Calculator</h3>
        <p class="card-subtitle">Calculate sugar needed for bottle carbonation.</p>
        <form method="POST" action="calculators.php#calc-priming">
            <input type="hidden" name="calc_type" value="priming">
            <div class="form-group">
                <label class="form-label">Batch Volume (Gallons)</label>
                <input type="number" step="0.1" name="volume_gal" class="form-control" value="<?= htmlspecialchars($_POST['volume_gal'] ?? '5.0') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Target CO₂ Volumes</label>
                <input type="number" step="0.1" name="co2_vol" class="form-control" value="<?= htmlspecialchars($_POST['co2_vol'] ?? '2.4') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Beer/Cider Temp (°F)</label>
                <input type="number" step="0.1" name="beer_temp" class="form-control" value="<?= htmlspecialchars($_POST['beer_temp'] ?? '68') ?>" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Calculate Sugar</button>
        </form>
        <?php if ($sugarResult !== null): ?>
            <div style="margin-top: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 1.1rem; font-weight: 700; color: #3b82f6;">Corn Sugar (Dextrose): <?= $sugarResult['corn_grams'] ?>g (<?= $sugarResult['corn_oz'] ?> oz)</div>
                <div style="font-size: 0.95rem; color: var(--text-dark); margin-top: 0.25rem;">Table Sugar (Sucrose): <strong><?= $sugarResult['table_grams'] ?>g</strong></div>
                <div style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem;">Residual CO₂ in beer: ~<?= $sugarResult['dissolved'] ?> vols</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Gravity Boost / Mash Sugar Addition Calculator -->
    <div class="card" id="calc-sugar-boost">
        <h3 class="card-title">🍯 Gravity Boost / Mash Sugar Addition</h3>
        <p class="card-subtitle">Calculate sugar or extract needed to raise batch to target OG.</p>
        <form method="POST" action="calculators.php#calc-sugar-boost">
            <input type="hidden" name="calc_type" value="sugar_boost">
            <div class="form-group">
                <label class="form-label">Batch Volume (Gallons)</label>
                <input type="number" step="0.1" name="batch_gal" class="form-control" value="<?= htmlspecialchars($_POST['batch_gal'] ?? '5.0') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Current Gravity (OG / SG)</label>
                <input type="number" step="0.001" name="current_og" class="form-control" value="<?= htmlspecialchars($_POST['current_og'] ?? '1.040') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Target Gravity (OG)</label>
                <input type="number" step="0.001" name="target_og" class="form-control" value="<?= htmlspecialchars($_POST['target_og'] ?? '1.065') ?>" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Calculate Addition</button>
        </form>
        <?php if ($sugarBoostResult !== null): ?>
            <div style="margin-top: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                    Target boost: <strong>+<?= $sugarBoostResult['delta_pts'] ?> pts</strong> (<?= $sugarBoostResult['total_pt_gal'] ?> pt-gal)
                </div>
                <div style="font-size: 0.95rem; margin-bottom: 0.25rem; color: var(--text-dark);">
                    🍬 <strong>Table Sugar (Sucrose, 46 ppg):</strong> <?= $sugarBoostResult['sucrose']['lbs'] ?> lbs (<?= $sugarBoostResult['sucrose']['oz'] ?> oz / <?= $sugarBoostResult['sucrose']['grams'] ?>g)
                </div>
                <div style="font-size: 0.95rem; margin-bottom: 0.25rem; color: var(--text-dark);">
                    🌽 <strong>Corn Sugar (Dextrose, 42 ppg):</strong> <?= $sugarBoostResult['dextrose']['lbs'] ?> lbs (<?= $sugarBoostResult['dextrose']['oz'] ?> oz / <?= $sugarBoostResult['dextrose']['grams'] ?>g)
                </div>
                <div style="font-size: 0.95rem; margin-bottom: 0.25rem; color: var(--text-dark);">
                    🍯 <strong>Honey (35 ppg):</strong> <?= $sugarBoostResult['honey']['lbs'] ?> lbs (<?= $sugarBoostResult['honey']['oz'] ?> oz / <?= $sugarBoostResult['honey']['grams'] ?>g)
                </div>
                <div style="font-size: 0.95rem; margin-bottom: 0.25rem; color: var(--text-dark);">
                    🌾 <strong>Dry Malt Extract (DME, 44 ppg):</strong> <?= $sugarBoostResult['dme']['lbs'] ?> lbs (<?= $sugarBoostResult['dme']['oz'] ?> oz / <?= $sugarBoostResult['dme']['grams'] ?>g)
                </div>
                <div style="font-size: 0.95rem; color: var(--text-dark);">
                    🏺 <strong>Liquid Malt Extract (LME, 36 ppg):</strong> <?= $sugarBoostResult['lme']['lbs'] ?> lbs (<?= $sugarBoostResult['lme']['oz'] ?> oz / <?= $sugarBoostResult['lme']['grams'] ?>g)
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Hop Bitterness (IBU) & SRM Color Estimator -->
    <div class="card" id="calc-ibu-srm">
        <h3 class="card-title">🌿 IBU & SRM Color Estimator</h3>
        <p class="card-subtitle">Calculate Tinseth IBUs and Morey SRM color rating.</p>
        <form method="POST" action="calculators.php#calc-ibu-srm">
            <input type="hidden" name="calc_type" value="ibu_srm">
            <div class="form-group">
                <label class="form-label">Hop Weight (oz)</label>
                <input type="number" step="0.1" name="hop_oz" class="form-control" value="<?= htmlspecialchars($_POST['hop_oz'] ?? '1.5') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Alpha Acid % (AA%)</label>
                <input type="number" step="0.1" name="alpha_acid" class="form-control" value="<?= htmlspecialchars($_POST['alpha_acid'] ?? '6.5') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Boil Time (Minutes)</label>
                <input type="number" name="boil_mins" class="form-control" value="<?= htmlspecialchars($_POST['boil_mins'] ?? '60') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Batch Volume (Gallons)</label>
                <input type="number" step="0.1" name="batch_gal" class="form-control" value="<?= htmlspecialchars($_POST['batch_gal'] ?? '5.0') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Grain Weight (lbs) & Color (°L)</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="number" step="0.1" name="grain_lbs" class="form-control" placeholder="10 lbs" value="<?= htmlspecialchars($_POST['grain_lbs'] ?? '10.0') ?>">
                    <input type="number" step="0.1" name="grain_l" class="form-control" placeholder="10 °L" value="<?= htmlspecialchars($_POST['grain_l'] ?? '10.0') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Calculate IBU & SRM</button>
        </form>
        <?php if ($ibuResult !== null && $srmResult !== null): ?>
            <div style="margin-top: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 1.4rem; font-weight: 800; color: #166534;">Estimated IBUs: <?= $ibuResult ?> IBU</div>
                <div style="font-size: 1.1rem; font-weight: 700; color: #b45309; margin-top: 0.35rem;">SRM Color Rating: <?= $srmResult['srm'] ?> SRM (<?= $srmResult['mcu'] ?> MCU)</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mash Strike Water & Sparge Calculator -->
    <div class="card" id="calc-strike-water">
        <h3 class="card-title">🌾 Mash Strike Water & Sparge</h3>
        <p class="card-subtitle">Calculate strike water temp, mash volume, and sparge water.</p>
        <form method="POST" action="calculators.php#calc-strike-water">
            <input type="hidden" name="calc_type" value="strike_water">
            <div class="form-group">
                <label class="form-label">Target Mash Temp (°F)</label>
                <input type="number" step="0.1" name="target_mash_temp" class="form-control" value="<?= htmlspecialchars($_POST['target_mash_temp'] ?? '152') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Dry Grain Temp (°F)</label>
                <input type="number" step="0.1" name="grain_temp" class="form-control" value="<?= htmlspecialchars($_POST['grain_temp'] ?? '68') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Water-to-Grain Ratio (qt/lb)</label>
                <input type="number" step="0.05" name="water_ratio" class="form-control" value="<?= htmlspecialchars($_POST['water_ratio'] ?? '1.25') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Total Grain Weight (lbs)</label>
                <input type="number" step="0.1" name="grain_lbs" class="form-control" value="<?= htmlspecialchars($_POST['grain_lbs'] ?? '10.0') ?>" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Calculate Water</button>
        </form>
        <?php if ($strikeWaterResult !== null): ?>
            <div style="margin-top: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 1.3rem; font-weight: 800; color: #dc2626;">Strike Water Temp: <?= $strikeWaterResult['strike_temp'] ?>°F</div>
                <div style="font-size: 0.95rem; margin-top: 0.35rem; color: var(--text-dark);">Mash Water Volume: <strong><?= $strikeWaterResult['mash_vol_gal'] ?> gal</strong></div>
                <div style="font-size: 0.95rem; margin-top: 0.25rem; color: var(--text-dark);">Sparge Water Volume: <strong><?= $strikeWaterResult['sparge_vol_gal'] ?> gal</strong></div>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">Total Water Needed: <?= $strikeWaterResult['total_water'] ?> gal (Absorbs <?= $strikeWaterResult['grain_absorb'] ?> gal)</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Yeast Pitch Rate & Starter Calculator -->
    <div class="card" id="calc-yeast-pitch">
        <h3 class="card-title">🧪 Yeast Pitch Rate & Starter</h3>
        <p class="card-subtitle">Calculate required cell count, viability, and starter size.</p>
        <form method="POST" action="calculators.php#calc-yeast-pitch">
            <input type="hidden" name="calc_type" value="yeast_pitch">
            <div class="form-group">
                <label class="form-label">Target Original Gravity (OG)</label>
                <input type="number" step="0.001" name="target_og" class="form-control" value="<?= htmlspecialchars($_POST['target_og'] ?? '1.055') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Batch Volume (Gallons)</label>
                <input type="number" step="0.1" name="batch_gal" class="form-control" value="<?= htmlspecialchars($_POST['batch_gal'] ?? '5.0') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Beer Type & Pitch Rate</label>
                <select name="pitch_rate_type" class="form-control">
                    <option value="ale" <?= (($_POST['pitch_rate_type'] ?? '') === 'ale') ? 'selected' : '' ?>>Ale (0.75 M cells/mL/°P)</option>
                    <option value="lager" <?= (($_POST['pitch_rate_type'] ?? '') === 'lager') ? 'selected' : '' ?>>Lager (1.50 M cells/mL/°P)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Yeast Age (Days Old)</label>
                <input type="number" name="days_old" class="form-control" value="<?= htmlspecialchars($_POST['days_old'] ?? '30') ?>" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Calculate Pitch Rate</button>
        </form>
        <?php if ($yeastPitchResult !== null): ?>
            <div style="margin-top: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 1.2rem; font-weight: 800; color: #2563eb;">Cells Needed: <?= $yeastPitchResult['cells_needed'] ?> Billion Cells (<?= $yeastPitchResult['plato'] ?> °P)</div>
                <div style="font-size: 0.95rem; margin-top: 0.35rem; color: var(--text-dark);">Estimated Viability: <strong><?= $yeastPitchResult['viability'] ?>%</strong></div>
                <div style="font-size: 0.95rem; margin-top: 0.25rem; color: var(--text-dark);">Dry Yeast Packets Needed: <strong><?= $yeastPitchResult['packs_needed'] ?> packs</strong></div>
                <div style="font-size: 0.95rem; margin-top: 0.25rem; color: var(--text-dark);">Liquid Starter Volume Needed: <strong><?= $yeastPitchResult['starter_l'] ?> Liters</strong></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Water Chemistry & Salt Addition Estimator -->
    <div class="card" id="calc-water-chem">
        <h3 class="card-title">💧 Water Chemistry & Salt Additions</h3>
        <p class="card-subtitle">Estimate mineral salts & acid for target water profile.</p>
        <form method="POST" action="calculators.php#calc-water-chem">
            <input type="hidden" name="calc_type" value="water_chem">
            <div class="form-group">
                <label class="form-label">Batch Volume (Gallons)</label>
                <input type="number" step="0.1" name="batch_gal" class="form-control" value="<?= htmlspecialchars($_POST['batch_gal'] ?? '5.0') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Target Flavor Profile</label>
                <select name="target_profile" class="form-control">
                    <option value="hoppy" <?= (($_POST['target_profile'] ?? '') === 'hoppy') ? 'selected' : '' ?>>Hoppy / Bitter (High Sulfate)</option>
                    <option value="balanced" <?= (($_POST['target_profile'] ?? '') === 'balanced') ? 'selected' : '' ?>>Balanced (Equal Sulfate/Chloride)</option>
                    <option value="malty" <?= (($_POST['target_profile'] ?? '') === 'malty') ? 'selected' : '' ?>>Malty / Full Body (High Chloride)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Estimate Mineral Salts</button>
        </form>
        <?php if ($waterChemResult !== null): ?>
            <div style="margin-top: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 1.1rem; font-weight: 700; color: #0284c7; margin-bottom: 0.5rem;">Target Profile: <?= $waterChemResult['profile'] ?></div>
                <div style="font-size: 0.95rem; margin-bottom: 0.25rem;">🧪 Gypsum (CaSO₄): <strong><?= $waterChemResult['gypsum'] ?>g</strong></div>
                <div style="font-size: 0.95rem; margin-bottom: 0.25rem;">🧪 Calcium Chloride (CaCl₂): <strong><?= $waterChemResult['calcium'] ?>g</strong></div>
                <div style="font-size: 0.95rem; margin-bottom: 0.25rem;">🧪 Epsom Salt (MgSO₄): <strong><?= $waterChemResult['epsom'] ?>g</strong></div>
                <div style="font-size: 0.95rem;">🍋 Lactic Acid 88% (pH 5.3 target): <strong>~<?= $waterChemResult['lactic'] ?> mL</strong></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 🎯 BJCP Style Guidelines Explorer -->
    <div class="card" id="calc-bjcp" style="grid-column: 1 / -1; border-left: 4px solid #d97706;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
            <h3 class="card-title" style="margin: 0;">🎯 BJCP Style Guidelines Explorer</h3>
            <span class="badge badge-warning">BJCP 2021 Reference</span>
        </div>
        <p class="card-subtitle">Search and inspect official BJCP target gravity, ABV, IBU, and color ranges.</p>

        <div style="margin-bottom: 1rem;">
            <select id="bjcpSelector" class="form-control" onchange="renderBjcpExplorer()" style="font-size: 1rem; font-weight: 600;">
                <?php foreach (get_bjcp_styles() as $sName => $sData): ?>
                    <option value="<?= e($sName) ?>"><?= e($sName) ?> (<?= e($sData['category']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="bjcpExplorerDetails" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 1.25rem; border-radius: 8px;">
            <!-- Rendered by JS below -->
        </div>
    </div>
</div>

<script>
const BJCP_DATA = <?= json_encode(get_bjcp_styles(), JSON_UNESCAPED_SLASHES) ?>;

function renderBjcpExplorer() {
    const sel = document.getElementById('bjcpSelector');
    const container = document.getElementById('bjcpExplorerDetails');
    if (!sel || !container) return;

    const style = BJCP_DATA[sel.value];
    if (!style) return;

    container.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
            <h4 style="margin: 0; font-size: 1.15rem; color: #1e293b;">${sel.value}</h4>
            <span class="badge badge-primary">${style.category}</span>
        </div>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem; line-height: 1.4;">${style.description}</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
            <div style="background: #fff; border: 1px solid #cbd5e1; padding: 0.75rem; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">OG Range</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #0f172a;">${style.og_min.toFixed(3)} – ${style.og_max.toFixed(3)}</div>
            </div>
            <div style="background: #fff; border: 1px solid #cbd5e1; padding: 0.75rem; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">FG Range</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #10b981;">${style.fg_min.toFixed(3)} – ${style.fg_max.toFixed(3)}</div>
            </div>
            <div style="background: #fff; border: 1px solid #cbd5e1; padding: 0.75rem; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">ABV Range</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #3b82f6;">${style.abv_min}% – ${style.abv_max}%</div>
            </div>
            <div style="background: #fff; border: 1px solid #cbd5e1; padding: 0.75rem; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">IBU Range</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #16a34a;">${style.ibu_min} – ${style.ibu_max}</div>
            </div>
            <div style="background: #fff; border: 1px solid #cbd5e1; padding: 0.75rem; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">SRM Color</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #d97706;">${style.srm_min} – ${style.srm_max} SRM</div>
            </div>
        </div>

        <div style="margin-top: 1.25rem; text-align: right;">
            <a href="recipe_edit.php?action=new&style=${encodeURIComponent(sel.value)}" class="btn btn-primary" style="font-size: 0.9rem;">📖 Formulate Recipe for this Style &raquo;</a>
        </div>
    `;
}

document.addEventListener('DOMContentLoaded', () => {
    renderBjcpExplorer();
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (window.location.hash) {
        const el = document.querySelector(window.location.hash);
        if (el) {
            setTimeout(() => {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.style.transition = 'box-shadow 0.3s ease';
                el.style.boxShadow = '0 0 0 3px rgba(37, 99, 235, 0.4)';
                setTimeout(() => { el.style.boxShadow = ''; }, 2000);
            }, 100);
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
