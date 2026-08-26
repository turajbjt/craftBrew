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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    
    $username = sanitize_text($_POST['username'] ?? '', 50);
    $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($username) || !$email || empty($password)) {
        $error = "Please provide a valid username, email address, and password.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        try {
            $db = get_db();
            init_schema();

            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = "Username or email is already registered.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $apiToken = generate_api_token();
                $insertStmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, api_token) VALUES (?, ?, ?, 'brewer', ?)");
                $insertStmt->execute([$username, $email, $hash, $apiToken]);

                header('Location: login.php?msg=registered');
                exit;
            }
        } catch (Exception $e) {
            $error = "Registration failed. Please try again.";
        }
    }
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 480px; margin: 2.5rem auto;">
    <div class="card">
        <h2 class="card-title" style="text-align: center; margin-bottom: 1.5rem;">✨ Join CraftBrew Community</h2>

        <?php if (!empty($error)): ?>
            <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" required placeholder="masterbrewer">
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" required placeholder="brewer@example.com">
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required placeholder="At least 8 characters">
            </div>

            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required placeholder="Re-enter password">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Register Brewer Account</button>
        </form>

        <p style="text-align: center; margin-top: 1.5rem; color: var(--text-muted);">
            Already registered? <a href="login.php" style="color: var(--primary-color);">Log in here</a>
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
