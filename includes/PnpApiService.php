<?php
/**
 * Plug'n'Pay (PnP) API Service Driver
 */

require_once __DIR__ . '/../config.php';

class PnpApiService {

    /**
     * Generate Smart Screens v2 hosted payment URL or parameters
     */
    public static function getSmartScreensIframeUrl(array $planDetails, string $customOrderId = ''): string {
        if (empty($customOrderId)) {
            $customOrderId = 'SS-' . date('YmdHis') . '-' . rand(1000, 9999);
        }
        $params = [
            'publisher-name' => PNP_PUBLISHER_NAME,
            'order-id'       => $customOrderId,
            'planid'         => $planDetails['planid'] ?? '',
            'card-amount'    => number_format((float)($planDetails['initial_amount'] ?? 0), 2, '.', ''),
            'currency'       => $planDetails['currency'] ?? 'USD',
            'easycart'       => '1',
            'mode'           => 'auth',
            'recurring'      => 'init', // Initial recurring setup flag
            'callback_url'   => APP_URL . '/callback.php',
            'return_url'     => APP_URL . '/callback.php?status=success',
        ];
        return PNP_SMART_SCREENS_URL . '?' . http_build_query($params);
    }

    /**
     * Perform Single Authprev Payment against Card-on-File
     */
    public static function processSingleAuthprev(array $profile, float $amount): array {
        if (PNP_MOCK_MODE) {
            // Mock API response
            $isSuccess = true;
            $newOrderId = 'AUTHPREV-' . date('YmdHis') . '-' . rand(100, 999);
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
            'publisher-name' => PNP_PUBLISHER_NAME,
            'publisher-password' => PNP_API_KEY,
            'mode'           => 'authprev',
            'prevorderid'    => $profile['orderid'],
            'card-amount'    => number_format($amount, 2, '.', ''),
            'currency'       => $profile['currency'] ?? 'USD',
            'cof_indicator'  => 'R', // Recurring COF flag
        ];

        return self::executeHttpCall(PNP_AUTHPREV_URL, $payload);
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
     */
    public static function queryTransaction(string $orderId): array {
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
                'raw_response' => "FinalStatus=success&OrderNumber=$orderId&Amount=29.99"
            ];
        }

        $payload = [
            'publisher-name'     => PNP_PUBLISHER_NAME,
            'publisher-password' => PNP_API_KEY,
            'mode'               => 'query_trans',
            'orderid'            => $orderId,
        ];

        return self::executeHttpCall(PNP_QUERY_TRANS_URL, $payload);
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
