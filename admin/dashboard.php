<?php
/**
 * Admin Dashboard Page
 */

$pageTitle = 'Dashboard & Analytics';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/CustomerService.php';

$metrics = CustomerService::getDashboardMetrics();
?>

<style>
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 35px;
    }

    .metric-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        backdrop-filter: blur(12px);
        border-radius: 16px;
        padding: 24px;
        position: relative;
        overflow: hidden;
    }

    .metric-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(to right, var(--accent), #c084fc);
    }

    .metric-label {
        font-size: 0.85rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
    }

    .metric-value {
        font-family: 'Outfit', sans-serif;
        font-size: 2.1rem;
        font-weight: 700;
        color: var(--text-main);
    }

    .charts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 25px;
        margin-bottom: 35px;
    }

    .chart-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 16px;
        padding: 24px;
    }

    .chart-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .chart-container {
        position: relative;
        height: 260px;
        width: 100%;
    }
</style>

<div style="margin-bottom: 30px;">
    <h1 style="font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700;">Executive Dashboard</h1>
    <p style="color: var(--text-muted);">Real-time metrics, recurring billing success rates, and customer retention analytics.</p>
</div>

<!-- Metrics Tallies -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-label">Monthly Recurring Revenue</div>
        <div class="metric-value" style="color: #818cf8;">$<?= number_format($metrics['mrr'], 2) ?></div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Active Subscriptions</div>
        <div class="metric-value"><?= number_format($metrics['active_customers']) ?></div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Billing Success Rate</div>
        <div class="metric-value" style="color: #34d399;"><?= $metrics['success_rate'] ?>%</div>
    </div>

    <div class="metric-card">
        <div class="metric-label">Total Processed Revenue</div>
        <div class="metric-value">$<?= number_format($metrics['total_revenue'], 2) ?></div>
    </div>
</div>

<!-- Charts & Visual Analytics Section -->
<div class="charts-grid">
    <!-- Chart 1: Recurring Billing Success / Failure Breakdown -->
    <div class="chart-card">
        <div class="chart-title">
            <span>Billing Charge Status Ratio</span>
            <span style="font-size: 0.8rem; color: var(--text-muted);">Total Attempts: <?= $metrics['success_charges'] + $metrics['failed_charges'] ?></span>
        </div>
        <div class="chart-container">
            <canvas id="billingRatioChart"></canvas>
        </div>
    </div>

    <!-- Chart 2: Customer Status Retention Breakdown -->
    <div class="chart-card">
        <div class="chart-title">
            <span>Customer Retention Status</span>
            <span style="font-size: 0.8rem; color: var(--text-muted);">Total Profiles: <?= $metrics['total_customers'] ?></span>
        </div>
        <div class="chart-container">
            <canvas id="customerRetentionChart"></canvas>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    // Billing Ratio Doughnut Chart
    const ctxBilling = document.getElementById('billingRatioChart').getContext('2d');
    new Chart(ctxBilling, {
        type: 'doughnut',
        data: {
            labels: ['Successful Charges', 'Failed Charges'],
            datasets: [{
                data: [<?= (int)$metrics['success_charges'] ?>, <?= (int)$metrics['failed_charges'] ?>],
                backgroundColor: ['#10b981', '#ef4444'],
                borderColor: '#1e293b',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { color: '#94a3b8' } }
            }
        }
    });

    // Customer Retention Doughnut Chart
    const ctxRetention = document.getElementById('customerRetentionChart').getContext('2d');
    new Chart(ctxRetention, {
        type: 'pie',
        data: {
            labels: ['Active Profiles', 'Cancelled Profiles'],
            datasets: [{
                data: [<?= (int)$metrics['active_customers'] ?>, <?= (int)$metrics['cancelled_customers'] ?>],
                backgroundColor: ['#6366f1', '#f59e0b'],
                borderColor: '#1e293b',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { color: '#94a3b8' } }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
