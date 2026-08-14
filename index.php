<?php
/**
 * Public Order Form & Smart Screens v2 iframe Interface
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/PnpApiService.php';

$pdo = Database::getConnection();
$plans = $pdo->query("SELECT * FROM payment_plans ORDER BY recurringfee ASC")->fetchAll();

// On initial page load, no plan is selected by default ($selectedPlan = null)
$selectedPlanId = $_GET['planid'] ?? $_POST['planid'] ?? null;
$selectedPlan = null;
if ($selectedPlanId) {
    foreach ($plans as $p) {
        if ($p['planid'] === $selectedPlanId) {
            $selectedPlan = $p;
            break;
        }
    }
}

// Clean Form Action Base URL (Strictly NO GET query parameters appended)
$formActionUrl = PNP_MOCK_MODE ? (APP_URL . '/mock_smart_screens.php') : PNP_SMART_SCREENS_URL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscribe & Payment Checkout - Smart Screens v2</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #311042 100%);
            --card-bg: rgba(30, 41, 59, 0.7);
            --card-border: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --accent-glow: rgba(99, 102, 241, 0.4);
            --success: #10b981;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-gradient);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
        }

        .container {
            width: 100%;
            max-width: 1100px;
        }

        header {
            text-align: center;
            margin-bottom: 35px;
        }

        header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(to right, #a5b4fc, #c084fc, #f472b6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }

        header p {
            color: var(--text-muted);
            font-size: 1.1rem;
        }

        .checkout-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 30px;
        }

        @media (max-width: 900px) {
            .checkout-grid { grid-template-columns: 1fr; }
        }

        .plans-card, .iframe-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Standard Plan Form Card Styling */
        .plan-form-card {
            background: rgba(15, 23, 42, 0.6);
            border: 2px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.25s ease;
        }

        .plan-form-card:hover {
            border-color: rgba(99, 102, 241, 0.5);
            transform: translateY(-2px);
        }

        .plan-form-card.active {
            border-color: var(--accent);
            box-shadow: 0 0 20px var(--accent-glow);
            background: rgba(99, 102, 241, 0.1);
        }

        .plan-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .plan-name {
            font-weight: 600;
            font-size: 1.15rem;
        }

        .plan-price {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1.35rem;
            color: #c084fc;
        }

        .plan-desc {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .btn-submit-plan {
            background: linear-gradient(135deg, var(--accent) 0%, #818cf8 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            width: 100%;
            transition: background 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);
        }

        .btn-submit-plan:hover {
            background: linear-gradient(135deg, var(--accent-hover) 0%, #6366f1 100%);
            box-shadow: 0 6px 18px rgba(99, 102, 241, 0.45);
        }

        /* Initial Filler Container Styling */
        .filler-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            height: 540px;
            background: rgba(15, 23, 42, 0.6);
            border: 2px dashed rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 40px 30px;
        }

        .filler-icon {
            font-size: 3.8rem;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 12px rgba(165, 180, 252, 0.4));
        }

        .filler-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 10px;
        }

        .filler-subtitle {
            color: var(--text-muted);
            font-size: 0.98rem;
            max-width: 420px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .step-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
            max-width: 400px;
            text-align: left;
        }

        .step-item {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.88rem;
            color: #cbd5e1;
        }

        .step-num {
            background: var(--accent);
            color: white;
            font-weight: 700;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        .iframe-container {
            width: 100%;
            height: 540px;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: #0f172a;
        }

        iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>Select Your Plan & Subscribe</h1>
        <p>Powered by Smart Screens v2 Payment Checkout</p>
    </header>

    <div class="checkout-grid">
        <!-- Column 1: Offered Payment Plan HTML Forms -->
        <div class="plans-card">
            <h2 class="section-title">1. Offered Payment Plans</h2>
            
            <?php foreach ($plans as $plan): ?>
                <?php $postParams = PnpApiService::getSmartScreensPostFields($plan); ?>
                
                <!-- Standard HTML Form POST sending all fields via HTTP POST body (Clean Action URL) -->
                <form action="<?= htmlspecialchars($formActionUrl) ?>" 
                      method="POST" 
                      target="pnp_checkout_iframe" 
                      class="plan-form-card <?= ($selectedPlan && $selectedPlan['planid'] === $plan['planid']) ? 'active' : '' ?>"
                      id="form-plan-<?= htmlspecialchars($plan['planid']) ?>"
                      onsubmit="handleFormSubmit(this)">

                    <!-- Smart Screens v2 Parameters Sent Exclusively via HTTP POST Body -->
                    <?php foreach ($postParams as $key => $val): ?>
                        <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($val) ?>">
                    <?php endforeach; ?>

                    <div class="plan-header">
                        <div class="plan-name"><?= htmlspecialchars($plan['description']) ?></div>
                        <div class="plan-price">$<?= number_format((float)$plan['recurringfee'], 2) ?></div>
                    </div>

                    <div class="plan-desc">
                        Cycle: Every <?= (int)$plan['billcycle'] ?> <?= $plan['billcycle_type'] === 'd' ? 'Day(s)' : 'Month(s)' ?> 
                        | Initial: $<?= number_format((float)$plan['initial_amount'], 2) ?>
                        <?php if ($plan['collect_unpw'] === 'Y'): ?>
                            <br><span style="color: #60a5fa;">🔑 Customer Account Login Creation Included</span>
                        <?php endif; ?>
                    </div>

                    <!-- HTML Form Submit Button -->
                    <button type="submit" class="btn-submit-plan">Select Plan & Proceed to Checkout 💳</button>
                </form>
            <?php endforeach; ?>
        </div>

        <!-- Column 2: Smart Screens v2 Payment Iframe Container -->
        <div class="iframe-card">
            <h2 class="section-title">2. Secure Card Payment (Smart Screens v2)</h2>

            <!-- Initial Filler View (Displayed initially before any plan form is submitted) -->
            <div id="filler-container" class="filler-box" style="<?= $selectedPlan ? 'display: none;' : '' ?>">
                <div class="filler-icon">💳</div>
                <h3 class="filler-title">Select a Plan to Begin</h3>
                <p class="filler-subtitle">
                    Choose one of our offered payment plans on the left and click "Select Plan & Proceed to Checkout" to submit your request.
                </p>
                <div class="step-list">
                    <div class="step-item">
                        <div class="step-num">1</div>
                        <div>Choose your preferred subscription tier on the left.</div>
                    </div>
                    <div class="step-item">
                        <div class="step-num">2</div>
                        <div>Click the plan button to post your selection to Smart Screens v2.</div>
                    </div>
                    <div class="step-item">
                        <div class="step-num">3</div>
                        <div>Enter your card information to complete registration.</div>
                    </div>
                </div>
            </div>

            <!-- Active Checkout View with Target Iframe (Receives HTTP Form POST Payload) -->
            <div id="checkout-view" style="<?= !$selectedPlan ? 'display: none;' : '' ?>">
                <div class="iframe-container">
                    <iframe name="pnp_checkout_iframe" id="pnp_checkout_iframe" src="about:blank" title="Smart Screens v2 Payment Form"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function handleFormSubmit(formElement) {
    // 1. Hide filler container and show active checkout view containing target iframe
    document.getElementById('filler-container').style.display = 'none';
    document.getElementById('checkout-view').style.display = 'block';

    // 2. Highlight active plan card
    document.querySelectorAll('.plan-form-card').forEach(card => card.classList.remove('active'));
    formElement.classList.add('active');

    // 3. Browser natively dispatches all hidden input tags via HTTP POST to target="pnp_checkout_iframe"
}
</script>

</body>
</html>
