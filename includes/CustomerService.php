<?php
/**
 * Customer Profile & Business Logic Manager Service
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/EmailService.php';

class CustomerService {

    /**
     * Generate UUID v4 for saas_id
     */
    public static function generateSaasId(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Calculate new enddate given current YYYYMMDD, increment count, and type ('d' or 'm')
     */
    public static function calculateNextEndDate(string $currentEndDate, int $billcycle, string $billcycleType): string {
        if ($billcycle <= 0) return $currentEndDate;

        $dateTime = DateTime::createFromFormat('Ymd', $currentEndDate, new DateTimeZone('UTC'));
        if (!$dateTime) {
            $dateTime = new DateTime('now', new DateTimeZone('UTC'));
        }

        if (strtolower($billcycleType) === 'd') {
            $dateTime->modify("+{$billcycle} days");
        } else {
            $dateTime->modify("+{$billcycle} months");
        }

        return $dateTime->format('Ymd');
    }

    /**
     * Create Customer Profile from Smart Screens v2 Callback
     */
    public static function createCustomerFromCallback(array $data): array {
        $pdo = Database::getConnection();

        $saasId     = self::generateSaasId();
        $orderId    = $data['orderid'] ?? ('SS-' . date('YmdHis') . '-' . rand(100, 999));
        $startDate  = get_gmt_today_date(); // YYYYMMDD
        
        // Fetch plan defaults if planid is provided
        $planId          = $data['planid'] ?? null;
        $billcycle       = (int)($data['billcycle'] ?? 1);
        $billcycleType   = $data['billcycle_type'] ?? 'm';
        $recurringfee    = (float)($data['recurringfee'] ?? $data['amount'] ?? 0.00);
        $balance         = !empty($data['balance']) ? (float)$data['balance'] : null;

        if ($planId) {
            $stmt = $pdo->prepare("SELECT * FROM payment_plans WHERE planid = ?");
            $stmt->execute([$planId]);
            $plan = $stmt->fetch();
            if ($plan) {
                $billcycle     = (int)$plan['billcycle'];
                $billcycleType = $plan['billcycle_type'];
                $recurringfee  = (float)$plan['recurringfee'];
                $balance       = !empty($plan['balance']) ? (float)$plan['balance'] : null;
            }
        }

        // Calculate initial enddate (next payment date)
        $endDate = self::calculateNextEndDate($startDate, $billcycle, $billcycleType);

        // Password hash if provided
        $passwordHash = null;
        if (!empty($data['password'])) {
            $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        $stmt = $pdo->prepare("
            INSERT INTO customer_profiles (
                saas_id, orderid, username, password, card_name, phone, email, accttype,
                card_number, card_exp, routingnum, accountnum, startdate, enddate,
                billcycle, billcycle_type, currency, recurringfee, balance, status, planid,
                acct_code, acct_code2
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, 'active', ?,
                ?, ?
            )
        ");

        $stmt->execute([
            $saasId,
            $orderId,
            $data['username'] ?? null,
            $passwordHash,
            $data['card_name'] ?? 'Card Holder',
            $data['phone'] ?? null,
            $data['email'] ?? '',
            $data['accttype'] ?? 'credit',
            mask_card_number($data['card_number'] ?? '0000000000000000'),
            $data['card_exp'] ?? '1228',
            mask_account_number($data['routingnum'] ?? null),
            mask_account_number($data['accountnum'] ?? null),
            $startDate,
            $endDate,
            $billcycle,
            $billcycleType,
            $data['currency'] ?? 'USD',
            $recurringfee,
            $balance,
            $planId,
            $data['acct_code'] ?? null,
            $data['acct_code2'] ?? null
        ]);

        $gmtNow = get_gmt_now_formatted();

        // Record Initial Billing History
        $stmtBill = $pdo->prepare("
            INSERT INTO billing_history (saas_id, datetime, orderID, description, result, amount)
            VALUES (?, ?, ?, ?, 'success', ?)
        ");
        $stmtBill->execute([
            $saasId,
            $gmtNow,
            $orderId,
            'Initial Smart Screens v2 Payment & Profile Setup',
            (float)($data['amount'] ?? $recurringfee)
        ]);

        // Record Service History
        $stmtSvc = $pdo->prepare("
            INSERT INTO service_history (saas_id, datetime, action, reason, ipaddress, actor_username)
            VALUES (?, ?, 'profile_created', 'Customer registered via Smart Screens v2', ?, 'SYSTEM')
        ");
        $stmtSvc->execute([
            $saasId,
            $gmtNow,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        return self::getCustomerBySaasId($saasId);
    }

    /**
     * Get Customer Profile by SaaS ID
     */
    public static function getCustomerBySaasId(string $saasId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE saas_id = ?");
        $stmt->execute([$saasId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Query profiles eligible for recurring billing run
     * 3-day lookahead window: enddate <= today + 2 days
     * Run 1 (2:30 AM GMT): All active due profiles
     * Run 2 (2:30 PM GMT): Sweeps for active profiles due today whose last_attempt date != today's GMT date
     */
    public static function getCustomersForRecurringBilling(string $runType = 'run1'): array {
        $pdo = Database::getConnection();
        $todayStr = get_gmt_today_date(); // YYYYMMDD

        if ($runType === 'run2') {
            // Missed payments sweep: due today or past due, but last_attempt is not today
            $stmt = $pdo->prepare("
                SELECT * FROM customer_profiles 
                WHERE status = 'active' 
                  AND billcycle > 0 
                  AND enddate <= :today 
                  AND (last_attempt IS NULL OR LEFT(last_attempt, 8) != :today)
            ");
            $stmt->execute(['today' => $todayStr]);
        } else {
            // Run 1: 3-day lookahead window (due today or within 2-day lookahead)
            // Lookahead limit = Today + 2 days
            $dt = new DateTime('now', new DateTimeZone('UTC'));
            $dt->modify('+2 days');
            $lookaheadLimit = $dt->format('Ymd');

            $stmt = $pdo->prepare("
                SELECT * FROM customer_profiles 
                WHERE status = 'active' 
                  AND billcycle > 0 
                  AND enddate <= :lookaheadLimit
                  AND (last_attempt IS NULL OR LEFT(last_attempt, 8) != :today)
            ");
            $stmt->execute([
                'lookaheadLimit' => $lookaheadLimit,
                'today'          => $todayStr
            ]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Record outcome of recurring billing attempt
     */
    public static function recordRecurringResult(array $profile, array $result, string $actorUsername = 'cron_sendbill'): void {
        $pdo = Database::getConnection();
        $gmtNow = get_gmt_now_formatted(); // YYYYMMDDhhmmss
        $saasId = $profile['saas_id'];
        $isSuccess = ($result['result'] === 'success');

        if ($isSuccess) {
            // Advance enddate by billcycle & billcycle_type
            $newEndDate = self::calculateNextEndDate($profile['enddate'], (int)$profile['billcycle'], $profile['billcycle_type']);

            $stmt = $pdo->prepare("
                UPDATE customer_profiles 
                SET last_attempt = ?, 
                    last_billed = ?, 
                    enddate = ? 
                WHERE saas_id = ?
            ");
            $stmt->execute([$gmtNow, $gmtNow, $newEndDate, $saasId]);

            // Log Billing History
            $stmtBill = $pdo->prepare("
                INSERT INTO billing_history (saas_id, datetime, orderID, description, result, amount)
                VALUES (?, ?, ?, 'Recurring Billing Charge', 'success', ?)
            ");
            $stmtBill->execute([$saasId, $gmtNow, $result['orderID'], (float)$profile['recurringfee']]);

            // Log Service History
            $stmtSvc = $pdo->prepare("
                INSERT INTO service_history (saas_id, datetime, action, reason, actor_username)
                VALUES (?, ?, 'recurring_success', ?, ?)
            ");
            $stmtSvc->execute([$saasId, $gmtNow, "Recurring charge of {$profile['recurringfee']} succeeded. New enddate: {$newEndDate}", $actorUsername]);

        } else {
            // Failed attempt: update last_attempt date, keep enddate so it retries next day
            $stmt = $pdo->prepare("
                UPDATE customer_profiles 
                SET last_attempt = ? 
                WHERE saas_id = ?
            ");
            $stmt->execute([$gmtNow, $saasId]);

            // Log Billing History
            $stmtBill = $pdo->prepare("
                INSERT INTO billing_history (saas_id, datetime, orderID, description, result, amount)
                VALUES (?, ?, ?, ?, 'hard_fail', ?)
            ");
            $stmtBill->execute([$saasId, $gmtNow, $result['orderID'] ?? ('FAIL-' . $gmtNow), 'Recurring Billing Failed: ' . ($result['reason'] ?? 'Declined'), (float)$profile['recurringfee']]);

            // Log Service History
            $stmtSvc = $pdo->prepare("
                INSERT INTO service_history (saas_id, datetime, action, reason, actor_username)
                VALUES (?, ?, 'recurring_failed', ?, ?)
            ");
            $reason = "Recurring payment attempt failed: " . ($result['reason'] ?? 'Declined');
            $stmtSvc->execute([$saasId, $gmtNow, $reason, $actorUsername]);

            // Check failure count over the 3-day window
            $stmtCount = $pdo->prepare("
                SELECT COUNT(*) as fail_count 
                FROM billing_history 
                WHERE saas_id = ? 
                  AND result != 'success' 
                  AND datetime >= ?
            ");
            // Lookback 3 days
            $threeDaysAgo = date('Ymd000000', strtotime('-3 days'));
            $stmtCount->execute([$saasId, $threeDaysAgo]);
            $failCount = (int)($stmtCount->fetch()['fail_count'] ?? 1);

            if ($failCount >= 3) {
                // Exhausted 3-day retry window -> send email notification
                EmailService::sendRecurringFailureNotice($profile, $failCount, $result['reason'] ?? 'Declined');
            }
        }
    }

    /**
     * Disable Recurring Billing for Customer & Cancel Member Profile at Gateway
     */
    public static function disableRecurring(string $saasId, string $actorUsername = 'SYSTEM'): bool {
        $pdo = Database::getConnection();
        $profile = self::getCustomerBySaasId($saasId);

        $stmt = $pdo->prepare("UPDATE customer_profiles SET billcycle = 0, status = 'cancelled' WHERE saas_id = ?");
        $success = $stmt->execute([$saasId]);

        if ($success) {
            if ($profile && !empty($profile['username'])) {
                require_once __DIR__ . '/PnpApiService.php';
                PnpApiService::cancelMember($profile['username']);
            }

            $gmtNow = get_gmt_now_formatted();
            $stmtSvc = $pdo->prepare("
                INSERT INTO service_history (saas_id, datetime, action, reason, actor_username)
                VALUES (?, ?, 'recurring_disabled', 'Recurring billing disabled (billcycle set to 0, status set to cancelled, cancel_member called)', ?)
            ");
            $stmtSvc->execute([$saasId, $gmtNow, $actorUsername]);
        }
        return $success;
    }

    /**
     * Delete Customer Profile completely
     */
    public static function deleteCustomer(string $saasId, string $actorUsername = 'SYSTEM'): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM customer_profiles WHERE saas_id = ?");
        return $stmt->execute([$saasId]);
    }

    /**
     * Fetch Service History for SaaS ID
     */
    public static function getServiceHistory(string $saasId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM service_history WHERE saas_id = ? ORDER BY id DESC");
        $stmt->execute([$saasId]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch Billing History for SaaS ID
     */
    public static function getBillingHistory(string $saasId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM billing_history WHERE saas_id = ? ORDER BY id DESC");
        $stmt->execute([$saasId]);
        return $stmt->fetchAll();
    }

    /**
     * Get Dashboard Metrics & Analytics Tallies
     */
    public static function getDashboardMetrics(): array {
        $pdo = Database::getConnection();

        $totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customer_profiles")->fetchColumn();
        $activeCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customer_profiles WHERE status = 'active'")->fetchColumn();
        $cancelledCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customer_profiles WHERE status = 'cancelled'")->fetchColumn();

        $totalRevenue = (float)$pdo->query("SELECT SUM(amount) FROM billing_history WHERE result = 'success'")->fetchColumn();
        $successCharges = (int)$pdo->query("SELECT COUNT(*) FROM billing_history WHERE result = 'success'")->fetchColumn();
        $failedCharges = (int)$pdo->query("SELECT COUNT(*) FROM billing_history WHERE result != 'success'")->fetchColumn();

        $mrr = (float)$pdo->query("SELECT SUM(recurringfee) FROM customer_profiles WHERE status = 'active' AND billcycle > 0")->fetchColumn();

        $totalBillingAttempts = $successCharges + $failedCharges;
        $successRate = $totalBillingAttempts > 0 ? round(($successCharges / $totalBillingAttempts) * 100, 1) : 100.0;

        return [
            'total_customers'     => $totalCustomers,
            'active_customers'    => $activeCustomers,
            'cancelled_customers' => $cancelledCustomers,
            'mrr'                 => $mrr,
            'total_revenue'       => $totalRevenue,
            'success_rate'        => $successRate,
            'success_charges'     => $successCharges,
            'failed_charges'      => $failedCharges
        ];
    }

    /**
     * Resync all customer records with Plug'n Pay gateway via list_members Remote API
     */
    public static function syncMembersFromGateway(string $actorUsername = 'SYSTEM'): array {
        require_once __DIR__ . '/PnpApiService.php';
        $res = PnpApiService::listMembers('all', 'omit', null);

        if (!$res['success'] || empty($res['records'])) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => $res['error_message'] ?? 'No member records retrieved from Plug\'n Pay.'
            ];
        }

        $pdo = Database::getConnection();
        $updatedCount = 0;
        $gmtNow = get_gmt_now_formatted();

        foreach ($res['records'] as $record) {
            $username = trim($record['username'] ?? '');
            if (empty($username)) continue;

            // Find customer by username
            $stmt = $pdo->prepare("SELECT * FROM customer_profiles WHERE username = ?");
            $stmt->execute([$username]);
            $customer = $stmt->fetch();

            if ($customer) {
                $updates = [];
                $params = [];

                if (!empty($record['enddate']) && strlen($record['enddate']) === 8) {
                    $updates[] = "enddate = ?";
                    $params[] = $record['enddate'];
                }
                if (!empty($record['status'])) {
                    $statusVal = strtolower($record['status']);
                    $updates[] = "status = ?";
                    $params[] = ($statusVal === 'active' || $statusVal === 'cancelled' || $statusVal === 'expired') ? $statusVal : $customer['status'];
                }
                if (!empty($record['card_name']) || !empty($record['name'])) {
                    $nameVal = trim($record['card_name'] ?? $record['name'] ?? '');
                    if (!empty($nameVal)) {
                        $updates[] = "card_name = ?";
                        $params[] = $nameVal;
                    }
                }

                if (!empty($updates)) {
                    $params[] = $customer['saas_id'];
                    $sql = "UPDATE customer_profiles SET " . implode(', ', $updates) . " WHERE saas_id = ?";
                    $stmtUpd = $pdo->prepare($sql);
                    $stmtUpd->execute($params);

                    // Service history log
                    $stmtSvc = $pdo->prepare("
                        INSERT INTO service_history (saas_id, datetime, action, reason, actor_username)
                        VALUES (?, ?, 'gateway_resync', 'Customer record resynced via list_members Remote API', ?)
                    ");
                    $stmtSvc->execute([$customer['saas_id'], $gmtNow, $actorUsername]);

                    $updatedCount++;
                }
            }
        }

        return [
            'success' => true,
            'count'   => $updatedCount,
            'total'   => count($res['records']),
            'message' => "Resynced $updatedCount customer profile(s) out of " . count($res['records']) . " record(s) from Plug'n Pay gateway."
        ];
    }
}
