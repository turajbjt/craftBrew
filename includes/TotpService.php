<?php
/**
 * CraftBrew TOTP & 2FA Service
 * Pure-PHP RFC 6238 Time-Based One-Time Password Implementation
 * Compatible with Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden, etc.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_check.php';

class TotpService {

    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically secure 16-character Base32 Secret Key
     */
    public static function generateSecret(int $length = 16): string {
        $secret = '';
        $validChars = self::$base32Chars;
        $maxIndex = strlen($validChars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $secret .= $validChars[random_int(0, $maxIndex)];
        }
        return $secret;
    }

    /**
     * Calculate 6-digit TOTP Code for a given timestamp or current 30s slice
     */
    public static function getCode(string $secret, ?int $timeSlice = null): string {
        if ($timeSlice === null) {
            $timeSlice = (int)floor(time() / 30);
        }

        $secretBinary = self::base32Decode($secret);
        // Pack time into 8-byte big-endian binary string
        $timeBinary = pack('N*', 0) . pack('N*', $timeSlice);
        $hmac = hash_hmac('sha1', $timeBinary, $secretBinary, true);

        // Dynamic truncation
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $hashPart = substr($hmac, $offset, 4);
        $value = unpack('N', $hashPart)[1] & 0x7FFFFFFF;

        $modulo = $value % 1000000;
        return str_pad((string)$modulo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify user submitted 6-digit TOTP code with clock-drift tolerance
     */
    public static function verifyCode(string $secret, string $code, int $discrepancy = 1): bool {
        $code = trim($code);
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $currentTimeSlice = (int)floor(time() / 30);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate Single-Use Emergency Recovery Backup Codes (e.g. 8 codes formatted XXXX-XXXX)
     */
    public static function generateBackupCodes(int $count = 8): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $part1 = strtoupper(bin2hex(random_bytes(2)));
            $part2 = strtoupper(bin2hex(random_bytes(2)));
            $codes[] = "{$part1}-{$part2}";
        }
        return $codes;
    }

    /**
     * Verify and consume a single-use emergency backup code
     */
    public static function verifyAndConsumeBackupCode(int $userId, string $inputCode): bool {
        $inputCode = strtoupper(trim(str_replace(' ', '', $inputCode)));
        if (empty($inputCode)) return false;

        $db = get_db();
        $stmt = $db->prepare("SELECT two_factor_backup_codes FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $json = $stmt->fetchColumn();

        if (empty($json)) return false;
        $codes = json_decode($json, true);
        if (!is_array($codes)) return false;

        $foundIdx = null;
        foreach ($codes as $idx => $code) {
            if (hash_equals(strtoupper(trim($code)), $inputCode)) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx !== null) {
            unset($codes[$foundIdx]);
            $updatedJson = json_encode(array_values($codes));
            $up = $db->prepare("UPDATE users SET two_factor_backup_codes = ? WHERE id = ?");
            $up->execute([$updatedJson, $userId]);
            return true;
        }

        return false;
    }

    /**
     * Generate standard otpauth:// URL for authenticator apps
     */
    public static function getOtpAuthUri(string $username, string $secret, string $issuer = APP_NAME): string {
        $label = rawurlencode($issuer . ':' . $username);
        $issuerParam = rawurlencode($issuer);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuerParam}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Base32 binary decoder
     */
    private static function base32Decode(string $base32): string {
        $base32 = strtoupper(trim($base32));
        $chars = self::$base32Chars;
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0; $i < strlen($base32); $i++) {
            $char = $base32[$i];
            if ($char === '=' || $char === ' ') continue;
            $val = strpos($chars, $char);
            if ($val === false) continue;

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
