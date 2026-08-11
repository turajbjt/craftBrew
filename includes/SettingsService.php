<?php
/**
 * Dynamic System Settings Service Helper
 * Manages database-backed configuration overrides for Gateway, Email, and Application settings.
 */

require_once __DIR__ . '/../db.php';

class SettingsService {
    private static ?array $cachedSettings = null;

    /**
     * Get all key-value settings from database table
     */
    public static function getAll(): array {
        if (self::$cachedSettings !== null) {
            return self::$cachedSettings;
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
            $rows = $stmt->fetchAll();
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            self::$cachedSettings = $settings;
            return self::$cachedSettings;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Get a specific setting value by key with optional fallback
     */
    public static function get(string $key, mixed $default = null): mixed {
        $all = self::getAll();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Save/update a setting value in database
     */
    public static function set(string $key, string $value): bool {
        try {
            $pdo = Database::getConnection();
            $engine = strtolower(defined('DB_ENGINE') ? DB_ENGINE : 'sqlite');

            if ($engine === 'sqlite') {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
                $success = $stmt->execute([$key, $value]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $success = $stmt->execute([$key, $value]);
            }

            if ($success) {
                if (self::$cachedSettings === null) {
                    self::$cachedSettings = [];
                }
                self::$cachedSettings[$key] = $value;
            }
            return $success;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Save multiple settings from key-value array
     */
    public static function saveMultiple(array $settingsMap): bool {
        $allSuccess = true;
        foreach ($settingsMap as $key => $val) {
            if (!self::set($key, (string)$val)) {
                $allSuccess = false;
            }
        }
        return $allSuccess;
    }

    /**
     * Bind dynamic settings into system constants if not already defined
     */
    public static function initConstants(): void {
        $dbSettings = self::getAll();

        $defaultMap = [
            'PNP_PUBLISHER_NAME'      => ['key' => 'pnp_publisher_name',      'default' => getenv('PNP_PUBLISHER_NAME') ?: 'demo_publisher'],
            'PNP_API_KEY'             => ['key' => 'pnp_api_key',             'default' => getenv('PNP_API_KEY') ?: 'demo_api_key_12345'],
            'PNP_AUTHPREV_URL'        => ['key' => 'pnp_authprev_url',        'default' => getenv('PNP_AUTHPREV_URL') ?: 'https://pay1.plugnpay.com/payment/pnpremote.cgi'],
            'PNP_BATCH_UPLOAD_URL'    => ['key' => 'pnp_batch_upload_url',    'default' => getenv('PNP_BATCH_UPLOAD_URL') ?: 'https://pay1.plugnpay.com/payment/batchupload.cgi'],
            'PNP_QUERY_TRANS_URL'     => ['key' => 'pnp_query_trans_url',     'default' => getenv('PNP_QUERY_TRANS_URL') ?: 'https://pay1.plugnpay.com/payment/querytrans.cgi'],
            'PNP_SMART_SCREENS_URL'   => ['key' => 'pnp_smart_screens_url',   'default' => getenv('PNP_SMART_SCREENS_URL') ?: 'https://pay1.plugnpay.com/smartscreens/v2/index.cgi'],
            'ALERT_EMAIL_FROM'        => ['key' => 'alert_email_from',        'default' => getenv('ALERT_EMAIL_FROM') ?: 'billing-alerts@example.com'],
            'ALERT_EMAIL_TO'          => ['key' => 'alert_email_to',          'default' => getenv('ALERT_EMAIL_TO') ?: 'merchant-admin@example.com'],
            'APP_NAME'                => ['key' => 'app_name',                'default' => 'SaaS Recurring Billing & Management Portal'],
            'APP_URL'                 => ['key' => 'app_url',                 'default' => getenv('APP_URL') ?: 'http://localhost:8080'],
        ];

        foreach ($defaultMap as $constName => $info) {
            if (!defined($constName)) {
                $val = array_key_exists($info['key'], $dbSettings) ? $dbSettings[$info['key']] : $info['default'];
                define($constName, $val);
            }
        }

        if (!defined('PNP_MOCK_MODE')) {
            $mockVal = array_key_exists('pnp_mock_mode', $dbSettings) 
                ? filter_var($dbSettings['pnp_mock_mode'], FILTER_VALIDATE_BOOLEAN)
                : (getenv('PNP_MOCK_MODE') !== false ? filter_var(getenv('PNP_MOCK_MODE'), FILTER_VALIDATE_BOOLEAN) : true);
            define('PNP_MOCK_MODE', $mockVal);
        }

        if (!defined('SEND_EMAIL_NOTIFICATIONS')) {
            $emailNotifVal = array_key_exists('send_email_notifications', $dbSettings) 
                ? filter_var($dbSettings['send_email_notifications'], FILTER_VALIDATE_BOOLEAN)
                : true;
            define('SEND_EMAIL_NOTIFICATIONS', $emailNotifVal);
        }
    }
}
