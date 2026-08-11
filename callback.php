<?php
/**
 * Smart Screens v2 Callback Ingestion Endpoint
 */

require_once __DIR__ . '/includes/CustomerService.php';

$inputData = $_POST;
if (empty($inputData)) {
    $inputData = $_GET;
}

$errorMsg = null;
$createdCustomer = null;

if (!empty($inputData)) {
    try {
        $createdCustomer = CustomerService::createCustomerFromCallback($inputData);
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmation - SaaS Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: rgba(30, 41, 59, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            max-width: 550px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        .icon-success {
            width: 70px;
            height: 70px;
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 20px;
        }
        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .meta-table {
            width: 100%;
            margin: 25px 0;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.95rem;
        }
        .meta-table td {
            padding: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .meta-table td:first-child {
            color: #94a3b8;
            font-weight: 500;
        }
        .saas-badge {
            background: rgba(99, 102, 241, 0.2);
            color: #a5b4fc;
            padding: 4px 10px;
            border-radius: 6px;
            font-family: monospace;
            font-weight: 600;
        }
        .btn {
            display: inline-block;
            padding: 12px 28px;
            background: #6366f1;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .btn:hover { background: #4f46e5; }
    </style>
</head>
<body>

<div class="card">
    <?php if ($createdCustomer): ?>
        <div class="icon-success">✓</div>
        <h1>Subscription Confirmed!</h1>
        <p style="color: #94a3b8;">Your customer profile and payment record have been generated.</p>

        <table class="meta-table">
            <tr>
                <td>SaaS Internal ID</td>
                <td><span class="saas-badge"><?= htmlspecialchars($createdCustomer['saas_id']) ?></span></td>
            </tr>
            <tr>
                <td>Order ID</td>
                <td><?= htmlspecialchars($createdCustomer['orderid']) ?></td>
            </tr>
            <tr>
                <td>Customer Name</td>
                <td><?= htmlspecialchars($createdCustomer['card_name']) ?></td>
            </tr>
            <tr>
                <td>Payment Method</td>
                <td><?= htmlspecialchars(strtoupper($createdCustomer['accttype'])) ?> (<?= htmlspecialchars($createdCustomer['card_number']) ?>)</td>
            </tr>
            <tr>
                <td>Start Date</td>
                <td><?= htmlspecialchars($createdCustomer['startdate']) ?></td>
            </tr>
            <tr>
                <td>Next Scheduled Billing</td>
                <td><?= htmlspecialchars($createdCustomer['enddate']) ?></td>
            </tr>
            <tr>
                <td>Recurring Fee</td>
                <td>$<?= number_format((float)$createdCustomer['recurringfee'], 2) ?> / cycle</td>
            </tr>
        </table>

        <a href="/" class="btn">Return to Order Form</a>
    <?php else: ?>
        <h1 style="color: #ef4444;">Callback Processing Error</h1>
        <p style="color: #94a3b8; margin: 15px 0;"><?= htmlspecialchars($errorMsg ?? 'No valid callback payload received.') ?></p>
        <a href="/" class="btn">Back to Home</a>
    <?php endif; ?>
</div>

</body>
</html>
