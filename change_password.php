<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$pageTitle = "Change Password - " . APP_NAME;
$activePage = 'profile';
$message = '';
$error = '';

$minPassLength  = (int)get_site_setting('password_min_length', 8);
$requireComplex = (bool)get_site_setting('password_require_complex', 0);
$isForced       = (!empty($user['must_change_password']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $currentPass = $_POST['current_password'] ?? '';
    $newPass     = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    // Fetch user's current password hash
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $currentHash = $stmt->fetchColumn();

    if (!$isForced && !password_verify($currentPass, $currentHash)) {
        $error = "Incorrect current password.";
    } elseif ($newPass !== $confirmPass) {
        $error = "New password and confirmation password do not match.";
    } else {
        $valError = '';
        if (!validate_password_strength($newPass, $valError)) {
            $error = $valError;
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $upStmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?");
            $upStmt->execute([$newHash, $user['id']]);

            // Regenerate session identifier upon credential change (OWASP A07)
            session_regenerate_id(true);

            // Clear session must_change flag
            $_SESSION['must_change_password'] = 0;

            header('Location: index.php?msg=password_updated');
            exit;
        }
    }
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<style>
.req-list {
    list-style: none;
    padding: 0;
    margin: 0.4rem 0 0.75rem 0;
    font-size: 0.8rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}
.req-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: var(--text-muted);
    transition: color 0.2s ease;
}
.req-item .req-icon {
    font-size: 0.85rem;
    display: inline-block;
    width: 14px;
    text-align: center;
}
.req-item.valid {
    color: #16a34a;
    font-weight: 600;
}
.req-item.invalid {
    color: #dc2626;
}
.strength-bar-wrap {
    height: 4px;
    background: #e2e8f0;
    border-radius: 2px;
    overflow: hidden;
    margin-top: 0.4rem;
}
.strength-bar {
    height: 100%;
    width: 0%;
    transition: width 0.3s ease, background-color 0.3s ease;
}
</style>

<div style="max-width: 500px; margin: 3rem auto;">
    <div class="card">
        <h2 class="card-title" style="text-align: center; margin-bottom: 0.5rem;">🔑 Change Your Password</h2>
        
        <?php if ($isForced): ?>
            <div style="background: #fef3c7; color: #92400e; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #fde68a; font-size: 0.9rem;">
                <strong>Security Requirement:</strong> You signed in with a temporary or expired password. Please set a new permanent password to continue.
            </div>
        <?php else: ?>
            <p style="color: var(--text-muted); text-align: center; font-size: 0.9rem; margin-bottom: 1.5rem;">
                Choose a strong new password for your account.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="change_password.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            
            <?php if (!$isForced): ?>
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label" for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required placeholder="••••••••" autocomplete="current-password">
                </div>
            <?php endif; ?>

            <!-- New Password Field -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label" for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                
                <!-- Strength meter -->
                <div class="strength-bar-wrap">
                    <div class="strength-bar" id="strengthBar"></div>
                </div>
                <small id="strengthLabel" style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.25rem;"></small>

                <ul class="req-list" id="passwordReqs" aria-live="polite">
                    <li class="req-item" id="p-len"><span class="req-icon">○</span> At least <?= $minPassLength ?> characters</li>
                    <?php if ($requireComplex): ?>
                        <li class="req-item" id="p-upper"><span class="req-icon">○</span> At least one uppercase letter (A-Z)</li>
                        <li class="req-item" id="p-lower"><span class="req-icon">○</span> At least one lowercase letter (a-z)</li>
                        <li class="req-item" id="p-num"><span class="req-icon">○</span> At least one number (0-9)</li>
                        <li class="req-item" id="p-sym"><span class="req-icon">○</span> At least one special symbol (!@#$%^&amp;*)</li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Confirm New Password Field -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label" for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                <ul class="req-list" id="confirmReqs" aria-live="polite">
                    <li class="req-item" id="c-match"><span class="req-icon">○</span> Passwords must match</li>
                </ul>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">Update Password</button>
        </form>

        <?php if (!$isForced): ?>
            <p style="text-align: center; margin-top: 1.5rem;">
                <a href="profile.php" style="color: var(--text-muted);">&laquo; Cancel and Return to Profile</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const minPassLength = <?= (int)$minPassLength ?>;
    const requireComplex = <?= $requireComplex ? 'true' : 'false' ?>;

    const newPassInput = document.getElementById('new_password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthBar  = document.getElementById('strengthBar');
    const strengthLabel = document.getElementById('strengthLabel');

    function setReqStatus(id, isValid, isStarted) {
        const el = document.getElementById(id);
        if (!el) return;
        const icon = el.querySelector('.req-icon');

        if (!isStarted) {
            el.className = 'req-item';
            if (icon) icon.textContent = '○';
        } else if (isValid) {
            el.className = 'req-item valid';
            if (icon) icon.textContent = '✓';
        } else {
            el.className = 'req-item invalid';
            if (icon) icon.textContent = '✕';
        }
    }

    function validatePassword() {
        const val = newPassInput.value;
        const started = val.length > 0;

        const lenValid   = val.length >= minPassLength;
        const upperValid = /[A-Z]/.test(val);
        const lowerValid = /[a-z]/.test(val);
        const numValid   = /[0-9]/.test(val);
        const symValid   = /[\W_]/.test(val);

        setReqStatus('p-len', lenValid, started);
        if (requireComplex) {
            setReqStatus('p-upper', upperValid, started);
            setReqStatus('p-lower', lowerValid, started);
            setReqStatus('p-num', numValid, started);
            setReqStatus('p-sym', symValid, started);
        }

        // Strength Calculation
        let score = 0;
        if (val.length >= minPassLength) score++;
        if (val.length >= 12) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[\W_]/.test(val)) score++;

        if (!started) {
            strengthBar.style.width = '0%';
            strengthBar.style.backgroundColor = 'transparent';
            strengthLabel.textContent = '';
        } else if (score <= 2) {
            strengthBar.style.width = '33%';
            strengthBar.style.backgroundColor = '#ef4444';
            strengthLabel.textContent = 'Strength: Weak';
            strengthLabel.style.color = '#ef4444';
        } else if (score <= 4) {
            strengthBar.style.width = '66%';
            strengthBar.style.backgroundColor = '#f59e0b';
            strengthLabel.textContent = 'Strength: Moderate';
            strengthLabel.style.color = '#f59e0b';
        } else {
            strengthBar.style.width = '100%';
            strengthBar.style.backgroundColor = '#10b981';
            strengthLabel.textContent = 'Strength: Strong';
            strengthLabel.style.color = '#10b981';
        }
    }

    function validateConfirm() {
        const passVal = newPassInput.value;
        const confVal = confirmInput.value;
        const started = confVal.length > 0;
        const isMatch = (confVal.length > 0 && passVal === confVal);

        setReqStatus('c-match', isMatch, started);
    }

    if (newPassInput) {
        newPassInput.addEventListener('input', () => {
            validatePassword();
            if (confirmInput.value.length > 0) validateConfirm();
        });
    }
    if (confirmInput) confirmInput.addEventListener('input', validateConfirm);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
