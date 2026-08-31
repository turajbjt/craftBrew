<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Register - " . APP_NAME;
$activePage = 'register';
$error = '';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$regMode          = get_site_setting('registration_mode', 'open');
$minPassLength    = (int)get_site_setting('password_min_length', 8);
$requireComplex   = (bool)get_site_setting('password_require_complex', 0);
$requireAlphaNum  = (bool)get_site_setting('username_require_alphanumeric', 0);

if ($regMode === 'closed') {
    $error = "Public registration is currently disabled by the site administrator.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $regMode !== 'closed') {
    require_csrf_token();
    $botErr = '';

    if (!verify_bot_trap($botErr)) {
        $error = $botErr;
    } else {
        $username = sanitize_text($_POST['username'] ?? '', 50);
        $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $valError = '';
        $userValError = '';
        if (empty($username) || !$email || empty($password)) {
            $error = "Please provide a valid username, email address, and password.";
    } elseif (!validate_username($username, $userValError)) {
        $error = $userValError;
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (!validate_password_strength($password, $valError)) {
        $error = $valError;
    } else {
        try {
            $db = get_db();

            $stmtUser = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmtUser->execute([$username]);
            if ($stmtUser->fetch()) {
                $error = "This username is already taken. Please choose another username.";
            } else {
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = "This email address is already registered.";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $apiToken = generate_api_token();
                    $status = ($regMode === 'invite') ? 'suspended' : 'active';

                    $insertStmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, status, api_token) VALUES (?, ?, ?, 'brewer', ?, ?)");
                    $insertStmt->execute([$username, $email, $hash, $status, $apiToken]);

                    if ($regMode === 'invite') {
                        header('Location: login.php?msg=pending_approval');
                    } else {
                        header('Location: login.php?msg=registered');
                    }
                    exit;
                }
            }
        } catch (Exception $e) {
            $error = "Registration failed. Please try again.";
        }
    }
}
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Real-time Requirement List Styles */
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

<div style="max-width: 500px; margin: 2.5rem auto;">
    <div class="card">
        <h2 class="card-title" style="text-align: center; margin-bottom: 1.5rem;">✨ Join CraftBrew Community</h2>

        <?php if ($regMode === 'closed'): ?>
            <div style="background: #fef3c7; color: #92400e; padding: 1.5rem; border-radius: 8px; text-align: center; border: 1px solid #fde68a;">
                <h3 style="margin-bottom: 0.5rem;">Registration Closed</h3>
                <p>Public registration is currently closed. If you already have an account, please <a href="login.php" style="color: var(--primary-color);">log in here</a>.</p>
            </div>
        <?php else: ?>

            <?php if (!empty($error)): ?>
                <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($regMode === 'invite'): ?>
                <div style="background: #eff6ff; color: #1e40af; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #bfdbfe; font-size: 0.85rem;">
                    <strong>Note:</strong> Admin approval is required. New registrations will be reviewed before account activation.
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" id="registerForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <?= render_bot_trap() ?>
                
                <!-- Username Field -->
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required placeholder="craftbrewer" autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <ul class="req-list" id="usernameReqs" aria-live="polite">
                        <li class="req-item" id="u-len"><span class="req-icon">○</span> 3 to 30 characters</li>
                        <li class="req-item" id="u-chars"><span class="req-icon">○</span> Letters, numbers, hyphens, and underscores only</li>
                        <?php if ($requireAlphaNum): ?>
                            <li class="req-item" id="u-alphanum"><span class="req-icon">○</span> Must contain both letters and numbers</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Email Field -->
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" required placeholder="brewer@example.com" autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <ul class="req-list" id="emailReqs" aria-live="polite">
                        <li class="req-item" id="e-valid"><span class="req-icon">○</span> Valid email format (e.g. name@domain.com)</li>
                    </ul>
                </div>

                <!-- Password Field -->
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                    
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

                <!-- Confirm Password Field -->
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required placeholder="••••••••" autocomplete="new-password">
                    <ul class="req-list" id="confirmReqs" aria-live="polite">
                        <li class="req-item" id="c-match"><span class="req-icon">○</span> Passwords must match</li>
                    </ul>
                </div>

                <button type="submit" id="submitBtn" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">Create Free Account</button>
            </form>

            <p style="text-align: center; margin-top: 1.5rem; color: var(--text-muted);">
                Already have an account? <a href="login.php" style="color: var(--primary-color);">Log in here</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const minPassLength = <?= (int)$minPassLength ?>;
    const requireComplex = <?= $requireComplex ? 'true' : 'false' ?>;
    const requireAlphaNum = <?= $requireAlphaNum ? 'true' : 'false' ?>;

    const usernameInput = document.getElementById('username');
    const emailInput    = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const confirmInput  = document.getElementById('confirm_password');
    const strengthBar   = document.getElementById('strengthBar');
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

    // 1. Username Real-Time Validation
    function validateUsername() {
        const val = usernameInput.value.trim();
        const started = val.length > 0;

        const lenValid = val.length >= 3 && val.length <= 30;
        const charsValid = /^[a-zA-Z0-9_\-]+$/.test(val);
        const alphaNumValid = (!requireAlphaNum) || (/[a-zA-Z]/.test(val) && /[0-9]/.test(val));

        setReqStatus('u-len', lenValid, started);
        setReqStatus('u-chars', charsValid, started);
        if (requireAlphaNum) {
            setReqStatus('u-alphanum', alphaNumValid, started);
        }

        return lenValid && charsValid && alphaNumValid;
    }

    // 2. Email Real-Time Validation
    function validateEmail() {
        const val = emailInput.value.trim();
        const started = val.length > 0;
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const isValid = emailPattern.test(val);

        setReqStatus('e-valid', isValid, started);
        return isValid;
    }

    // 3. Password Real-Time Validation & Strength
    function validatePassword() {
        const val = passwordInput.value;
        const started = val.length > 0;

        const lenValid = val.length >= minPassLength;
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

        const isComplexMet = !requireComplex || (upperValid && lowerValid && numValid && symValid);
        return lenValid && isComplexMet;
    }

    // 4. Confirm Password Match
    function validateConfirm() {
        const passVal = passwordInput.value;
        const confVal = confirmInput.value;
        const started = confVal.length > 0;
        const isMatch = (confVal.length > 0 && passVal === confVal);

        setReqStatus('c-match', isMatch, started);
        return isMatch;
    }

    if (usernameInput) usernameInput.addEventListener('input', validateUsername);
    if (emailInput)    emailInput.addEventListener('input', validateEmail);
    if (passwordInput) {
        passwordInput.addEventListener('input', () => {
            validatePassword();
            if (confirmInput.value.length > 0) validateConfirm();
        });
    }
    if (confirmInput)  confirmInput.addEventListener('input', validateConfirm);

    // Initial check if values were prefilled
    if (usernameInput && usernameInput.value) validateUsername();
    if (emailInput && emailInput.value) validateEmail();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
