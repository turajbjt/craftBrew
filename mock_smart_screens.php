<?php
/**
 * Mock Plug'n Pay Smart Screens v2 Endpoint (mock_smart_screens.php)
 * Receives HTTP POST parameters from index.php form submission
 * and renders the payment form inside the iframe.
 */

require_once __DIR__ . '/config.php';

// Accept form parameters exclusively via HTTP POST request
$postData = $_POST;

$gatewayAccount   = $postData['pt_gateway_account'] ?? PNP_PUBLISHER_NAME;
$orderClassifier  = $postData['pt_order_classifier'] ?? ('SS-' . date('YmdHis'));
$planId           = $postData['pr_plan_id'] ?? '';
$recurringAmount  = $postData['pr_recurring_amount'] ?? '0.00';
$itemDescription  = $postData['pt_item_description_1'] ?? 'Subscription Plan';
$collectUnpw      = ($postData['pd_collect_credentials'] ?? 'yes') === 'yes';
$callbackUrl      = $postData['pb_success_url'] ?? $postData['callback_url'] ?? (APP_URL . '/callback.php');
$transitionType   = strtolower($postData['pb_transition_type'] ?? 'get');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Smart Screens v2 Hosted Checkout (Mock)</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0f172a;
            color: #f8fafc;
            padding: 20px;
        }
        .mock-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 12px;
            margin-bottom: 15px;
        }
        .mock-badge {
            background: rgba(99, 102, 241, 0.2);
            color: #a5b4fc;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .plan-summary {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        .form-grid {
            display: grid;
            gap: 12px;
        }
        label {
            display: block;
            font-size: 0.8rem;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        input {
            width: 100%;
            padding: 9px 12px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #1e293b;
            color: #fff;
            font-family: inherit;
            font-size: 0.9rem;
        }
        input:focus {
            outline: none;
            border-color: #6366f1;
        }
        .btn-submit {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            font-size: 0.95rem;
        }
        .btn-submit:hover {
            opacity: 0.95;
        }
        .post-debug {
            margin-top: 15px;
            padding: 8px;
            background: rgba(0,0,0,0.3);
            border-radius: 6px;
            font-size: 0.75rem;
            color: #64748b;
        }
    </style>
</head>
<body>

<div class="mock-header">
    <span class="mock-badge">Plug'n Pay Smart Screens v2</span>
    <h3 style="margin-top: 6px; font-size: 1.1rem;">Secure Payment Form</h3>
</div>

<div class="plan-summary">
    <div><strong>Item:</strong> <?= htmlspecialchars($itemDescription) ?></div>
    <div><strong>Plan ID:</strong> <code><?= htmlspecialchars($planId) ?></code></div>
    <div><strong>Amount:</strong> $<span style="color: #6ee7b7; font-weight: 700;"><?= htmlspecialchars($recurringAmount) ?></span></div>
    <div><strong>Order Classifier:</strong> <small style="color:#94a3b8;"><?= htmlspecialchars($orderClassifier) ?></small></div>
</div>

<form action="<?= htmlspecialchars($callbackUrl) ?>" method="POST" target="_parent">
    <input type="hidden" name="orderid" value="<?= htmlspecialchars($orderClassifier) ?>">
    <input type="hidden" name="planid" value="<?= htmlspecialchars($planId) ?>">
    <input type="hidden" name="amount" value="<?= htmlspecialchars($recurringAmount) ?>">

    <div class="form-grid">
        <div>
            <label>Cardholder Name*</label>
            <input type="text" name="card_name" value="Jane Doe" required>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
                <label>Email Address*</label>
                <input type="email" name="email" value="jane.doe@example.com" required>
            </div>
            <div>
                <label>Phone Number</label>
                <input type="text" name="phone" value="555-123-4567">
            </div>
        </div>

        <?php if ($collectUnpw): ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: rgba(99,102,241,0.1); padding: 10px; border-radius: 8px; border: 1px solid rgba(99,102,241,0.2);">
            <div>
                <label>Account Username</label>
                <input type="text" name="username" value="janedoe">
            </div>
            <div>
                <label>Account Password</label>
                <input type="password" name="password" value="Secret123!">
            </div>
        </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px;">
            <div>
                <label>Credit Card Number*</label>
                <input type="text" name="card_number" value="4111 2222 3333 4444" required>
            </div>
            <div>
                <label>Expiration (MMYY)*</label>
                <input type="text" name="card_exp" value="1228" required>
            </div>
        </div>

        <button type="submit" class="btn-submit">🔒 Complete $<?= htmlspecialchars($recurringAmount) ?> Subscription</button>
    </div>
</form>

<div class="post-debug">
    HTTP POST payload received via form submission (<?= count($_POST) ?> fields).
</div>

</body>
</html>
