<?php
/**
 * Email Service Helper
 */

require_once __DIR__ . '/../config.php';

class EmailService {

    /**
     * Send email notification for failed 3-day recurring billing attempt
     */
    public static function sendRecurringFailureNotice(array $customer, int $failedDaysCount, string $lastReason): bool {
        $subject = sprintf("[%s] Alert: Recurring Billing Failure for SaaS ID %s", APP_NAME, $customer['saas_id']);
        $body = "RECURRING BILLING FAILURE NOTICE\n";
        $body .= "----------------------------------------\n";
        $body .= "SaaS ID:        " . $customer['saas_id'] . "\n";
        $body .= "Customer Name:  " . $customer['card_name'] . "\n";
        $body .= "Email:          " . $customer['email'] . "\n";
        $body .= "Order ID:       " . $customer['orderid'] . "\n";
        $body .= "Recurring Fee:  $" . number_format((float)$customer['recurringfee'], 2) . "\n";
        $body .= "Failed Days:    " . $failedDaysCount . " consecutive days\n";
        $body .= "Last Reason:    " . $lastReason . "\n";
        $body .= "Last Attempt:   " . ($customer['last_attempt'] ?? 'N/A') . " GMT\n";
        $body .= "----------------------------------------\n";
        $body .= "Action Required: Please log into the portal to review customer status or contact customer.\n";

        return self::sendMail($customer['email'] . ', ' . ALERT_EMAIL_TO, $subject, $body);
    }

    /**
     * Send customer credentials email
     */
    public static function sendCredentialsEmail(string $recipientEmail, string $username, string $password): bool {
        $subject = sprintf("[%s] Your Account Credentials", APP_NAME);
        $body = "Hello,\n\nHere are your account access credentials:\n\n";
        $body .= "Username: " . $username . "\n";
        $body .= "Password: " . $password . "\n\n";
        $body .= "Thank you,\nCustomer Service";

        return self::sendMail($recipientEmail, $subject, $body);
    }

    /**
     * Internal mail dispatcher wrapper using standard PHP mail()
     */
    public static function sendMail(string $to, string $subject, string $body): bool {
        if (!SEND_EMAIL_NOTIFICATIONS) {
            error_log("[EmailService] Email notifications disabled. Message to $to suppressed.");
            return true;
        }

        $headers = "From: " . ALERT_EMAIL_FROM . "\r\n" .
                   "Reply-To: " . ALERT_EMAIL_FROM . "\r\n" .
                   "X-Mailer: PHP/" . phpversion();

        $success = @mail($to, $subject, $body, $headers);
        error_log(sprintf("[EmailService] Dispatching mail to '%s' - Subject: '%s' - Status: %s", $to, $subject, $success ? 'SUCCESS' : 'FAILED'));
        return $success;
    }
}
