<?php
/**
 * Public Order Form & Smart Screens v2 iframe Interface
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/PnpApiService.php';

$pdo = Database::getConnection();
$plans = $pdo->query("SELECT * FROM payment_plans ORDER BY recurringfee ASC")->fetchAll();

$selectedPlanId = $_GET['planid'] ?? ($plans[0]['planid'] ?? '');
$selectedPlan = null;
foreach ($plans as $p) {
    if ($p['planid'] === $selectedPlanId) {
        $selectedPlan = $p;
        break;
    }
}
if (!$selectedPlan && !empty($plans)) {
    $selectedPlan = $plans[0];
}

$iframeUrl = $selectedPlan ? PnpApiService::getSmartScreensIframeUrl($selectedPlan) : '';
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

        .plan-item {
            background: rgba(15, 23, 42, 0.6);
            border: 2px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .plan-item:hover {
            border-color: rgba(99, 102, 241, 0.5);
            transform: translateY(-2px);
        }

        .plan-item.active {
            border-color: var(--accent);
            box-shadow: 0 0 20px var(--accent-glow);
            background: rgba(99, 102, 241, 0.1);
        }

        .plan-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .plan-name {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .plan-price {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1.3rem;
            color: #c084fc;
        }

        .plan-desc {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .iframe-container {
            width: 100%;
            height: 520px;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: #ffffff;
        }

        iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .mock-box {
            padding: 20px;
            background: rgba(15, 23, 42, 0.9);
            color: #f8fafc;
            border-radius: 12px;
            font-size: 0.9rem;
        }

        .mock-btn {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 24px;
            background: var(--accent);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .mock-btn:hover { background: var(--accent-hover); }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>Select Your Plan & Subscribe</h1>
        <p>Powered by Smart Screens v2 Payment Checkout</p>
    </header>

    <div class="checkout-grid">
        <!-- Offered Payment Plans Column -->
        <div class="plans-card">
            <h2 class="section-title">1. Offered Payment Plans</h2>
            <?php foreach ($plans as $plan): ?>
                <div class="plan-item <?= ($selectedPlan && $selectedPlan['planid'] === $plan['planid']) ? 'active' : '' ?>" 
                     onclick="window.location.href='?planid=<?= urlencode($plan['planid']) ?>'">
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
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Smart Screens v2 Iframe Payment Container -->
        <div class="iframe-card">
            <h2 class="section-title">2. Secure Card Payment (Smart Screens v2)</h2>
            <div class="iframe-container">
                <?php if (PNP_MOCK_MODE): ?>
                    <!-- Interactive Mock Form for Smart Screens v2 Testing -->
                    <div class="mock-box">
                        <h3 style="color: #a5b4fc; margin-bottom: 10px;">Smart Screens v2 Simulation Mode</h3>
                        <p style="margin-bottom: 10px;">Selected Plan: <strong><?= htmlspecialchars($selectedPlan['description'] ?? 'Plan') ?></strong> ($<?= number_format((float)($selectedPlan['recurringfee'] ?? 0), 2) ?>)</p>
                        
                        <form action="/callback.php" method="POST" style="display: grid; gap: 12px; margin-top: 15px;">
                            <input type="hidden" name="planid" value="<?= htmlspecialchars($selectedPlan['planid'] ?? '') ?>">
                            <input type="hidden" name="amount" value="<?= htmlspecialchars($selectedPlan['recurringfee'] ?? '29.99') ?>">
                            <input type="hidden" name="currency" value="<?= htmlspecialchars($selectedPlan['currency'] ?? 'USD') ?>">
                            <input type="hidden" name="billcycle" value="<?= htmlspecialchars($selectedPlan['billcycle'] ?? '1') ?>">
                            <input type="hidden" name="billcycle_type" value="<?= htmlspecialchars($selectedPlan['billcycle_type'] ?? 'm') ?>">
                            
                            <div>
                                <label style="display:block; font-size:0.8rem; margin-bottom:4px;">Cardholder Name</label>
                                <input type="text" name="card_name" value="Jane Doe" required style="width:100%; padding:8px; border-radius:6px; border:1px solid #334155; background:#0f172a; color:#fff;">
                            </div>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                <div>
                                    <label style="display:block; font-size:0.8rem; margin-bottom:4px;">Email</label>
                                    <input type="email" name="email" value="jane.doe@example.com" required style="width:100%; padding:8px; border-radius:6px; border:1px solid #334155; background:#0f172a; color:#fff;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:0.8rem; margin-bottom:4px;">Phone</label>
                                    <input type="text" name="phone" value="555-123-4567" style="width:100%; padding:8px; border-radius:6px; border:1px solid #334155; background:#0f172a; color:#fff;">
                                </div>
                            </div>

                            <?php if (($selectedPlan['collect_unpw'] ?? 'N') === 'Y'): ?>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:rgba(99,102,241,0.1); padding:10px; border-radius:8px;">
                                <div>
                                    <label style="display:block; font-size:0.8rem; margin-bottom:4px;">Account Username</label>
                                    <input type="text" name="username" value="janedoe" style="width:100%; padding:8px; border-radius:6px; border:1px solid #334155; background:#0f172a; color:#fff;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:0.8rem; margin-bottom:4px;">Account Password</label>
                                    <input type="password" name="password" value="Secret123!" style="width:100%; padding:8px; border-radius:6px; border:1px solid #334155; background:#0f172a; color:#fff;">
                                </div>
                            </div>
                            <?php endif; ?>

                            <div style="display:grid; grid-template-columns:2fr 1fr; gap:10px;">
                                <div>
                                    <label style="display:block; font-size:0.8rem; margin-bottom:4px;">Credit Card Number</label>
                                    <input type="text" name="card_number" value="4111 2222 3333 4444" required style="width:100%; padding:8px; border-radius:6px; border:1px solid #334155; background:#0f172a; color:#fff;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:0.8rem; margin-bottom:4px;">Exp (MMYY)</label>
                                    <input type="text" name="card_exp" value="1228" required style="width:100%; padding:8px; border-radius:6px; border:1px solid #334155; background:#0f172a; color:#fff;">
                                </div>
                            </div>

                            <button type="submit" class="mock-btn" style="border:none; cursor:pointer;">Simulate Smart Screens v2 Completion</button>
                        </form>
                    </div>
                <?php else: ?>
                    <iframe src="<?= htmlspecialchars($iframeUrl) ?>" title="Smart Screens v2 Payment Form"></iframe>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>
