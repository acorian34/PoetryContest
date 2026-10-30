<?php
require __DIR__ . '/lib.php';

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

$as = $_GET['as'] ?? 'entrant';
if (!in_array($as, ['admin', 'judge'], true)) $as = 'entrant';
if ($as === 'entrant' && current_user()) { header('Location: dashboard.php'); exit; }

$error = '';
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw = (string)($_POST['password'] ?? '');
    $login = trim((string)($_POST['login'] ?? ''));
    usleep(300000); // slow down guessing

    // Everyone (entrant, judge, admin) is a row in `users` and signs in with username or email + password.
    $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $st->execute([$login, strtolower($login)]);
    $u = $st->fetch();
    if ($u && $u['deleted_at'] === null && password_verify($pw, $u['password_hash'])) {
        $role = $u['role'];
        if ($as === 'entrant' && $role === 'contestant') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$u['id'];
            header('Location: dashboard.php');
            exit;
        }
        if (($as === 'judge' && in_array($role, ['judge', 'admin'], true)) || ($as === 'admin' && $role === 'admin')) {
            session_regenerate_id(true);
            $_SESSION['judge_id'] = (int)$u['id'];   // staff session; the role is re-read from the database on every request
            $_SESSION['role'] = $role;
            header('Location: ' . ($role === 'admin' ? 'admin.php' : 'judge.php'));
            exit;
        }
        $error = match ($as) {
            'entrant' => 'This is a ' . $role . ' account. Please use the ' . $role . ' login.',
            'admin'   => 'This account is not an administrator account.',
            default   => 'This account is not a judge account.',
        };
    } else {
        $error = 'Incorrect username/email or password.';
    }
}

page_header($as === 'entrant' ? 'Log in' : ucfirst($as) . ' login');
?>
<h1><?= $as === 'entrant' ? 'Log in' : ucfirst($as) . ' login' ?></h1>
<?php if ($error): ?><div class="box err"><?= h($error) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <label>Username or email <input name="login" required autofocus autocomplete="username" value="<?= h($login) ?>"></label>
  <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
  <button type="submit">Log in</button>
</form>
<?php if ($as === 'entrant'): ?>
  <p class="note">New here? <a href="register.php">Create an account</a>.</p>
<?php endif; ?>
<?php page_footer();
