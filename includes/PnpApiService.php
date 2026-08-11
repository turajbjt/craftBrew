<?php
/**
 * Plug'n Pay (PnP) API Service Driver
 */

require_once __DIR__ . '/../config.php';

class PnpApiService {

    /**
     * Generate Smart Screens v2 hosted payment URL or parameters
     */
    public static function getSmartScreensIframeUrl(array $planDetails, string $customOrderId = '', array $customerData = []): string {
        if (empty($customOrderId)) {
            $customOrderId = 'SS-' . date('YmdHis') . '-' . rand(1000, 9999);
        }

        $initialAmount = number_format((float)($planDetails['initial_amount'] ?? 0), 2, '.', '');
        $recurringAmount = number_format((float)($planDetails['recurringfee'] ?? 0), 2, '.', '');

        $params = [
            'pt_gateway_account'                => PNP_PUBLISHER_NAME,
            'pt_order_classifier'               => $customOrderId,
            'pr_plan_id'                        => $planDetails['planid'] ?? '',
            'pr_recurring_amount'               => $recurringAmount,
            'pt_transaction_amount'             => $initialAmount,
            'pt_item_cost_1'                    => $initialAmount,
            'pt_item_description_1'             => $planDetails['description'] ?? 'Subscription',
            'pt_item_identifier_1'              => $planDetails['purchaseid'] ?? ($planDetails['planid'] ?? 'SUB-PLAN'),
            'pt_item_quantity_1'                => '1',
            'pt_item_is_taxable_1'              => 'no',
            'pd_collect_credentials'            => 'yes',
            'pd_display_items'                  => 'yes',
            'pb_cards_allowed'                  => 'visa,mastercard,amex,discover',
            'pt_customer_username'              => $customerData['username'] ?? '',
            'pb_customer_password'              => $customerData['password'] ?? '',
            'pb_customer_password_confirmation' => $customerData['password'] ?? '',
            'pt_account_code_1'                 => $planDetails['purchaseid'] ?? '',
            'callback_url'                      => APP_URL . '/callback.php',
            'return_url'                        => APP_URL . '/callback.php?status=success',
        ];

        return PNP_SMART_SCREENS_URL . '?' . http_build_query($params);
    }

    /**
     * Perform Single Card-on-File Recurring Charge via Remote API (bill_member mode)
     */
    public static function processBillMember(array $profile, float $amount): array {
        if (PNP_MOCK_MODE) {
            // Mock API response
            $isSuccess = true;
            $newOrderId = 'BILLMEM-' . date('YmdHis') . '-' . rand(100, 999);
            return [
                'success'        => $isSuccess,
                'orderID'        => $newOrderId,
                'result'         => $isSuccess ? 'success' : 'hard_fail',
                'raw_response'   => 'FinalStatus=success&Msoft=APPROVED&OrderNumber=' . $newOrderId,
                'error_message'  => $isSuccess ? '' : 'Card Declined (Mock)',
                'amount'         => $amount
            ];
        }

        $payload = [
            'publisher-name'     => PNP_PUBLISHER_NAME,
            'publisher-password' => PNP_API_KEY,
            'mode'               => 'bill_member',
            'username'           => !empty($profile['username']) ? $profile['username'] : $profile['saas_id'],
            'card-amount'        => number_format($amount, 2, '.', ''),
            'currency'           => $profile['currency'] ?? 'USD',
            'transflags'         => 'cit,recurring',
        ];

        return self::executeHttpCall(PNP_AUTHPREV_URL, $payload);
    }

    /**
     * Perform Single Authprev / COF Payment (Alias to processBillMember)
     */
    public static function processSingleAuthprev(array $profile, float $amount): array {
        return self::processBillMember($profile, $amount);
    }

    /**
     * Build and Submit Batch Upload File for Recurring Billing Engine (sendbill)
     */
    public static function processBatchAuthprev(array $profiles): array {
        $batchId = 'BATCH-' . date('YmdHis');
        $batchLines = [];

        foreach ($profiles as $profile) {
            // Format line according to PnP Batch specification: prevorderid, amount, COF flags
            $amount = number_format((float)$profile['recurringfee'], 2, '.', '');
            $batchLines[] = sprintf("%s|%s|authprev|%s|COF=R", $profile['saas_id'], $profile['orderid'], $amount);
        }

        if (PNP_MOCK_MODE) {
            // Simulate processing results
            $results = [];
            foreach ($profiles as $index => $profile) {
                // Simulate 90% success rate for realistic recurring testing
                $simulatedSuccess = ($index % 10 !== 7); 
                $txOrderId = 'REC-' . date('YmdHis') . '-' . sprintf('%03d', $index + 1);
                $results[$profile['saas_id']] = [
                    'saas_id'       => $profile['saas_id'],
                    'orderID'       => $txOrderId,
                    'result'        => $simulatedSuccess ? 'success' : 'soft_fail',
                    'reason'        => $simulatedSuccess ? 'Approved' : 'Insufficent Funds (Mock Failure)',
                    'amount'        => (float)$profile['recurringfee'],
                    'datetime'      => get_gmt_now_formatted()
                ];
            }
            return [
                'batch_id' => $batchId,
                'status'   => 'completed',
                'results'  => $results
            ];
        }

        // Live API batch upload logic
        $batchContent = implode("\n", $batchLines);
        $payload = [
            'publisher-name'     => PNP_PUBLISHER_NAME,
            'publisher-password' => PNP_API_KEY,
            'batch_data'         => $batchContent
        ];

        $uploadResponse = self::executeHttpCall(PNP_BATCH_UPLOAD_URL, $payload);
        
        // Parse batch retrieval results (or polling)
        return [
            'batch_id' => $batchId,
            'status'   => $uploadResponse['success'] ? 'completed' : 'failed',
            'results'  => []
        ];
    }

    /**
     * Query Transaction Status (query_trans mode) by Order ID
     * Specification: https://docs.plugnpay.com/docs/integration-specifications-documents/remote-api-integration-specification/section-2.-remote-transaction-administration/transaction-administration---query-transaction/
     */
    public static function queryTransaction(string $orderId, string $startDate = '', string $endDate = ''): array {
        if (empty($startDate)) {
            $startDate = date('Ymd', strtotime('-30 days'));
        }
        if (empty($endDate)) {
            $endDate = date('Ymd');
        }

        if (PNP_MOCK_MODE) {
            return [
                'success'      => true,
                'orderID'      => $orderId,
                'status'       => 'success',
                'amount'       => 29.99,
                'currency'     => 'USD',
                'trans_date'   => date('YmdHis'),
                'auth_code'    => 'MOCK' . rand(1000, 9999),
                'response_text'=> 'Transaction Approved (Mock Query)',
                'raw_response' => "FinalStatus=success&success=yes&orderID=$orderId&card-amount=29.99"
            ];
        }

        $payload = [
            'publisher-name'     => PNP_PUBLISHER_NAME,
            'publisher-password' => PNP_API_KEY,
            'mode'               => 'query_trans',
            'orderID'            => $orderId,
            'startdate'          => $startDate,
            'enddate'            => $endDate,
        ];

        $res = self::executeHttpCall(PNP_QUERY_TRANS_URL, $payload);
        
        // Parse individual transaction records returned in a00001, a00002...
        if (!empty($res['parsed'])) {
            $records = [];
            foreach ($res['parsed'] as $key => $val) {
                if (preg_match('/^a\d{5}$/', $key)) {
                    parse_str($val, $subParsed);
                    $records[] = $subParsed;
                }
            }
            if (!empty($records)) {
                $res['records'] = $records;
                if (isset($records[0]['card-amount'])) {
                    $res['amount'] = (float)$records[0]['card-amount'];
                }
                if (isset($records[0]['auth-code'])) {
                    $res['auth_code'] = $records[0]['auth-code'];
                }
            }
        }

        return $res;
    }

    /**
     * List Members Remote API (list_members mode)
     * Specification: https://docs.plugnpay.com/docs/integration-specifications-documents/remote-api-integration-specification/section-3.-remote-membership-administration/membership-management---list-members/
     * Note: crypt field defaults to 'omit' to prevent generating hashes.
     */
    public static function listMembers(string $status = 'active', string $crypt = 'omit', ?int $expcc = null): array {
        if (PNP_MOCK_MODE) {
            return [
                'success'      => true,
                'status'       => 'success',
                'TranCount'    => 2,
                'records'      => [
                    [
                        'username'   => 'janedoe',
                        'enddate'    => date('Ymd', strtotime('+30 days')),
                        'purchaseid' => 'GROUP-PRO'
                    ],
                    [
                        'username'   => 'johnsmith',
                        'enddate'    => date('Ymd', strtotime('+15 days')),
                        'purchaseid' => 'GROUP-BASIC'
                    ]
                ],
                'raw_response' => 'FinalStatus=success&TranCount=2&a00000=username%3Djanedoe%26enddate%3D20260910&a00001=username%3Djohnsmith%26enddate%3D20260826'
            ];
        }

        $payload = [
            'publisher-name'     => PNP_PUBLISHER_NAME,
            'publisher-password' => PNP_API_KEY,
            'mode'               => 'list_members',
            'status'             => $status,
            'crypt'              => $crypt, // Set to 'omit' per spec requirement to skip password generation
        ];

        if ($expcc !== null && in_array($expcc, [1, 2, 3], true)) {
            $payload['expcc'] = (string)$expcc;
        }

        $res = self::executeHttpCall(PNP_AUTHPREV_URL, $payload);

        if (!empty($res['parsed'])) {
            $records = [];
            foreach ($res['parsed'] as $key => $val) {
                if (preg_match('/^a\d{5}$/', $key)) {
                    parse_str($val, $subParsed);
                    $records[] = $subParsed;
                }
            }
            $res['records'] = $records;
            $res['TranCount'] = (int)($res['parsed']['TranCount'] ?? count($records));
        }

        return $res;
    }

    /**
     * Helper to execute HTTP POST requests via cURL
     */
    private static function executeHttpCall(string $url, array $payload): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success'       => false,
                'error_message' => 'cURL Error: ' . $error,
                'result'        => 'error',
            ];
        }

        parse_str($response, $parsed);
        $status = strtolower($parsed['FinalStatus'] ?? $parsed['status'] ?? '');
        $isSuccess = ($status === 'success' || $status === 'success-fraud-pending');

        return [
            'success'       => $isSuccess,
            'orderID'       => $parsed['OrderNumber'] ?? $payload['orderid'] ?? '',
            'result'        => $isSuccess ? 'success' : 'hard_fail',
            'raw_response'  => $response,
            'parsed'        => $parsed,
            'error_message' => $parsed['MErr'] ?? $parsed['errmsg'] ?? ''
        ];
    }
}
