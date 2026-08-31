<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/bjcp_styles.php';

require_login();
$user = current_user();
$db = get_db();

$batchId  = sanitize_int($_GET['batch_id'] ?? 0);
$recipeId = sanitize_int($_GET['recipe_id'] ?? 0);

$labelData = [
    'title'       => 'Craft Brew Reserve',
    'style'       => 'Homebrew',
    'brewer'      => $user['username'] . "'s Brewhouse",
    'abv'         => '5.5',
    'ibu'         => '35',
    'srm'         => '6',
    'brew_date'   => date('Y-m-d'),
    'bottle_date' => date('Y-m-d'),
    'notes'       => 'Handcrafted in small batches with premium malt and fresh hops.',
    'qr_url'      => ''
];

$serverHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isHttps    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$baseUrl    = ($isHttps ? 'https://' : 'http://') . $serverHost;

if ($batchId > 0) {
    $stmt = $db->prepare("
        SELECT b.*, c.name as category_name, r.name as recipe_name
        FROM batches b
        JOIN categories c ON b.category_id = c.id
        LEFT JOIN recipes r ON b.recipe_id = r.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$batchId, $user['id']]);
    $b = $stmt->fetch();
    
    if ($b) {
        $labelData['title']       = $b['batch_name'];
        $labelData['style']       = $b['batch_style'] ?: $b['category_name'];
        $labelData['abv']         = $b['calculated_abv'] ? number_format((float)$b['calculated_abv'], 1) : '';
        $labelData['brew_date']   = $b['date_start'] ?: date('Y-m-d');
        $labelData['bottle_date'] = $b['date_bottle'] ?: date('Y-m-d');
        $labelData['notes']       = !empty($b['reflections']) ? substr(strip_tags($b['reflections']), 0, 140) : 'Freshly brewed and bottle conditioned craft beer.';
        $labelData['qr_url']      = $baseUrl . dirname($_SERVER['PHP_SELF']) . '/batch_detail.php?id=' . $b['id'];
    }
} elseif ($recipeId > 0) {
    $stmt = $db->prepare("
        SELECT r.*, c.name as category_name
        FROM recipes r
        JOIN categories c ON r.category_id = c.id
        WHERE r.id = ? AND (r.user_id = ? OR r.is_public = 1)
    ");
    $stmt->execute([$recipeId, $user['id']]);
    $r = $stmt->fetch();
    
    if ($r) {
        $labelData['title']       = $r['name'];
        $labelData['style']       = $r['style'] ?: $r['category_name'];
        $labelData['abv']         = $r['target_abv'] ? number_format((float)$r['target_abv'], 1) : '';
        $labelData['notes']       = !empty($r['instructions']) ? substr(strip_tags($r['instructions']), 0, 140) : 'Master craft formulation.';
        $labelData['qr_url']      = $baseUrl . dirname($_SERVER['PHP_SELF']) . '/recipe_detail.php?id=' . $r['id'];
    }
}

// Check style for default SRM / IBU if available
$bjcp = find_bjcp_style($labelData['style']);
if ($bjcp) {
    if (empty($labelData['srm'])) $labelData['srm'] = round(($bjcp['srm_min'] + $bjcp['srm_max']) / 2);
    if (empty($labelData['ibu']) && $bjcp['ibu_max'] > 0) $labelData['ibu'] = round(($bjcp['ibu_min'] + $bjcp['ibu_max']) / 2);
}

$pageTitle = "Printable Bottle & Keg Label Generator - " . APP_NAME;
$activePage = 'batches';
require_once __DIR__ . '/includes/header.php';
?>

<div class="no-print" style="margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>🏷️ Bottle & Keg Label Generator</h1>
            <p style="color: var(--text-muted);">Customize and print high-resolution bottle labels and keg collar tags with SRM color bands and scannable QR codes.</p>
        </div>
        <button class="btn btn-primary" onclick="window.print()" style="font-size: 1rem; padding: 0.6rem 1.5rem;">
            🖨️ Print Labels Now
        </button>
    </div>
</div>

<!-- Customizer Controls Toolbar (Hidden in Print) -->
<div class="card no-print" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;">
    <h3 style="margin-top: 0; margin-bottom: 1rem;">🎨 Label Customizer Settings</h3>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">Label Format / Preset</label>
            <select id="layoutPreset" class="form-control" onchange="updateLabelLayout()">
                <option value="bottle-12oz">Standard 12oz Bottle (6 per page)</option>
                <option value="bottle-22oz">22oz Bomber / Large Bottle (4 per page)</option>
                <option value="keg-collar">Cornelius Keg Collar / Handle Tag (3 per page)</option>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">Batch / Beverage Name</label>
            <input type="text" id="inputTitle" class="form-control" value="<?= e($labelData['title']) ?>" oninput="syncLabels()">
        </div>

        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">Style / Subtitle</label>
            <input type="text" id="inputStyle" class="form-control" value="<?= e($labelData['style']) ?>" oninput="syncLabels()">
        </div>

        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">Brewer / Brewery</label>
            <input type="text" id="inputBrewer" class="form-control" value="<?= e($labelData['brewer']) ?>" oninput="syncLabels()">
        </div>

        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">ABV %</label>
            <input type="text" id="inputAbv" class="form-control" value="<?= e($labelData['abv']) ?>" oninput="syncLabels()">
        </div>

        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">IBU (Bitterness)</label>
            <input type="text" id="inputIbu" class="form-control" value="<?= e($labelData['ibu']) ?>" oninput="syncLabels()">
        </div>

        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">SRM Color (1 - 40)</label>
            <input type="number" id="inputSrm" class="form-control" min="1" max="40" value="<?= e($labelData['srm'] ?: '6') ?>" oninput="syncLabels()">
        </div>

        <div>
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">Bottled / Kegged Date</label>
            <input type="date" id="inputDate" class="form-control" value="<?= e($labelData['bottle_date']) ?>" oninput="syncLabels()">
        </div>

        <div style="grid-column: 1 / -1;">
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">Tasting Notes / Description / Slogan</label>
            <input type="text" id="inputNotes" class="form-control" value="<?= e($labelData['notes']) ?>" oninput="syncLabels()">
        </div>

        <div style="grid-column: 1 / -1;">
            <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">QR Code Destination URL</label>
            <input type="text" id="inputQrUrl" class="form-control" value="<?= e($labelData['qr_url']) ?>" oninput="generateQRCodes()">
        </div>
    </div>
</div>

<!-- PRINT SHEET CONTAINER -->
<div id="printSheetContainer" class="print-sheet-container">
    <div id="labelsGrid" class="labels-grid grid-bottle-12oz">
        <!-- 6 sample label instances will be cloned/rendered dynamically -->
    </div>
</div>

<!-- Lightweight Standalone Zero-Dependency Offline QR Code Library -->
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>

<script>
const SRM_COLORS = {
    1: '#FFE699', 2: '#FFD878', 3: '#FFCA5A', 4: '#FFBF42', 5: '#FBB123',
    6: '#F8A600', 7: '#F39C00', 8: '#EA8F00', 9: '#E58500', 10: '#DE7C00',
    11: '#D77200', 12: '#CF6900', 13: '#C96200', 14: '#C35900', 15: '#BB5100',
    16: '#B54C00', 17: '#B04500', 18: '#A63E00', 19: '#A13700', 20: '#9B3200',
    22: '#8B2500', 24: '#7C1E00', 26: '#6D1900', 28: '#5D1400', 30: '#4E1100',
    35: '#350A00', 40: '#1E0500'
};

function getSrmHex(srm) {
    srm = parseInt(srm) || 6;
    let closest = 1;
    let minDiff = 999;
    for (let key in SRM_COLORS) {
        let diff = Math.abs(srm - parseInt(key));
        if (diff < minDiff) {
            minDiff = diff;
            closest = key;
        }
    }
    return SRM_COLORS[closest] || '#F8A600';
}

function createQrSvg(url) {
    if (!url || typeof qrcode === 'undefined') return '';
    try {
        const qr = qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        return qr.createSvgTag({ scalable: true });
    } catch (e) {
        return '';
    }
}

function renderSingleLabel(preset) {
    const title = document.getElementById('inputTitle').value || 'Craft Beer';
    const style = document.getElementById('inputStyle').value || 'Homebrew';
    const brewer = document.getElementById('inputBrewer').value || 'Craft Brewer';
    const abv = document.getElementById('inputAbv').value;
    const ibu = document.getElementById('inputIbu').value;
    const srm = document.getElementById('inputSrm').value || 6;
    const date = document.getElementById('inputDate').value;
    const notes = document.getElementById('inputNotes').value;
    const qrUrl = document.getElementById('inputQrUrl').value;
    const srmHex = getSrmHex(srm);
    const qrSvg = createQrSvg(qrUrl);

    if (preset === 'keg-collar') {
        return `
            <div class="keg-collar-card">
                <div class="cut-circle-marker"></div>
                <div class="keg-collar-header" style="border-bottom: 4px solid ${srmHex};">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: #64748b;">${brewer}</div>
                    <div style="font-size: 1.35rem; font-weight: 800; color: #0f172a; line-height: 1.1; margin: 4px 0;">${title}</div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: #b45309;">${style}</div>
                </div>
                <div class="keg-collar-body">
                    <div class="specs-row">
                        ${abv ? `<div><strong>ABV:</strong> ${abv}%</div>` : ''}
                        ${ibu ? `<div><strong>IBU:</strong> ${ibu}</div>` : ''}
                        <div><strong>SRM:</strong> <span style="display:inline-block; width:12px; height:12px; background:${srmHex}; border-radius:2px; vertical-align:middle;"></span> ${srm}</div>
                        ${date ? `<div><strong>Kegged:</strong> ${date}</div>` : ''}
                    </div>
                    ${notes ? `<div class="label-notes" style="font-size: 0.75rem; margin-top: 6px; color: #475569;">${notes}</div>` : ''}
                </div>
                <div class="keg-collar-footer">
                    <div class="qr-box">${qrSvg}</div>
                    <div style="font-size: 0.65rem; color: #94a3b8; text-align: right;">CRAFTBREW CELLAR RESERVE</div>
                </div>
            </div>
        `;
    }

    return `
        <div class="bottle-label-card ${preset}">
            <div class="srm-accent-bar" style="background: ${srmHex};"></div>
            <div class="label-inner">
                <div class="label-header">
                    <span class="label-brewer">${brewer}</span>
                    <h2 class="label-title">${title}</h2>
                    <span class="label-style">${style}</span>
                </div>
                
                <div class="label-content">
                    <div class="label-specs">
                        ${abv ? `<div class="spec-badge"><strong>${abv}%</strong> ABV</div>` : ''}
                        ${ibu ? `<div class="spec-badge"><strong>${ibu}</strong> IBU</div>` : ''}
                        <div class="spec-badge" title="SRM Color"><span class="srm-dot" style="background: ${srmHex};"></span> SRM ${srm}</div>
                        ${date ? `<div class="spec-date">Bottled: ${date}</div>` : ''}
                    </div>
                    
                    ${notes ? `<p class="label-notes">${notes}</p>` : ''}
                </div>

                <div class="label-footer">
                    <div class="label-qr">${qrSvg}</div>
                    <div class="label-brand-tag">HANDCRAFTED BATCH</div>
                </div>
            </div>
        </div>
    `;
}

function updateLabelLayout() {
    const preset = document.getElementById('layoutPreset').value;
    const grid = document.getElementById('labelsGrid');
    
    grid.className = 'labels-grid grid-' + preset;
    
    let count = 6;
    if (preset === 'bottle-22oz') count = 4;
    if (preset === 'keg-collar') count = 3;

    let html = '';
    const labelHtml = renderSingleLabel(preset);
    for (let i = 0; i < count; i++) {
        html += labelHtml;
    }
    grid.innerHTML = html;
}

function syncLabels() {
    const preset = document.getElementById('layoutPreset').value;
    updateLabelLayout();
}

function generateQRCodes() {
    syncLabels();
}

document.addEventListener('DOMContentLoaded', () => {
    updateLabelLayout();
});
</script>

<style>
/* Base Screen & Print Layout */
.print-sheet-container {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    margin: 0 auto;
    max-width: 900px;
}

.labels-grid {
    display: grid;
    gap: 15px;
}

.grid-bottle-12oz {
    grid-template-columns: repeat(2, 1fr);
}

.grid-bottle-22oz {
    grid-template-columns: repeat(2, 1fr);
}

.grid-keg-collar {
    grid-template-columns: repeat(1, 1fr);
    max-width: 600px;
    margin: 0 auto;
}

/* 12oz & 22oz Bottle Label Card */
.bottle-label-card {
    position: relative;
    border: 2px dashed #cbd5e1;
    background: #ffffff;
    color: #0f172a;
    border-radius: 8px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 220px;
    box-sizing: border-box;
}

.bottle-22oz {
    min-height: 280px;
}

.srm-accent-bar {
    height: 8px;
    width: 100%;
}

.label-inner {
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    flex: 1;
}

.label-header {
    text-align: center;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 8px;
    margin-bottom: 8px;
}

.label-brewer {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #64748b;
    font-weight: 700;
}

.label-title {
    margin: 3px 0;
    font-size: 1.25rem;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.1;
}

.label-style {
    font-size: 0.85rem;
    font-weight: 600;
    color: #d97706;
}

.label-content {
    flex: 1;
}

.label-specs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
    font-size: 0.75rem;
}

.spec-badge {
    background: #f1f5f9;
    padding: 3px 6px;
    border-radius: 4px;
    border: 1px solid #e2e8f0;
}

.spec-date {
    color: #64748b;
    font-size: 0.7rem;
    margin-left: auto;
}

.srm-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 1px solid rgba(0,0,0,0.2);
    vertical-align: middle;
}

.label-notes {
    font-size: 0.75rem;
    color: #475569;
    line-height: 1.35;
    margin: 4px 0;
    font-style: italic;
}

.label-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-top: 8px;
    border-top: 1px dashed #e2e8f0;
    padding-top: 6px;
}

.label-qr svg {
    width: 45px;
    height: 45px;
    display: block;
}

.label-brand-tag {
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: #94a3b8;
}

/* Keg Collar Card Styling */
.keg-collar-card {
    position: relative;
    border: 2px dashed #94a3b8;
    background: #ffffff;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 15px;
    color: #0f172a;
}

.cut-circle-marker {
    position: absolute;
    top: 12px;
    right: 16px;
    width: 24px;
    height: 24px;
    border: 2px dotted #cbd5e1;
    border-radius: 50%;
    background: #f8fafc;
}

.keg-collar-header {
    padding-bottom: 8px;
    margin-bottom: 10px;
}

.specs-row {
    display: flex;
    gap: 15px;
    font-size: 0.8rem;
    background: #f8fafc;
    padding: 6px 10px;
    border-radius: 6px;
}

.keg-collar-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
}

.keg-collar-footer .qr-box svg {
    width: 40px;
    height: 40px;
}

/* Print Specific Rules */
@media print {
    body {
        background: #ffffff !important;
        color: #000000 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    .navbar, .footer, .no-print {
        display: none !important;
    }

    .container {
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .print-sheet-container {
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
    }

    .bottle-label-card, .keg-collar-card {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
