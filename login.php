<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Login - " . APP_NAME;
$activePage = 'login';
$error = '';

if (current_user()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    
    $username = sanitize_text($_POST['username'] ?? '', 100);
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT id, username, email, password_hash, role, api_token FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Regenerate session ID to prevent Session Fixation
                session_regenerate_id(true);

                if (empty($user['api_token'])) {
                    $token = generate_api_token();
                    $updateStmt = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
                    $updateStmt->execute([$token, $user['id']]);
                    $user['api_token'] = $token;
                }

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['api_token'] = $user['api_token'];

                header('Location: index.php');
                exit;
            } else {
                $error = "Invalid username/email or password.";
            }
        } catch (Exception $e) {
            $error = "An authentication error occurred. Please try again.";
        }
    }
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 450px; margin: 3rem auto;">
    <div class="card">
        <h2 class="card-title" style="text-align: center; margin-bottom: 1.5rem;">🍺 Brewer Login</h2>

        <?php if (!empty($_GET['msg']) && $_GET['msg'] === 'registered'): ?>
            <div style="background: #dcfce7; color: #166534; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                Account registered successfully! You can now log in.
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            
            <div class="form-group">
                <label class="form-label" for="username">Username or Email</label>
                <input type="text" id="username" name="username" class="form-control" required placeholder="brewer">
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Log In</button>
        </form>

        <p style="text-align: center; margin-top: 1.5rem; color: var(--text-muted);">
            Don't have an account? <a href="register.php" style="color: var(--primary-color);">Register here</a>
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
