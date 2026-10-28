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
    usleep(300000); // slow down guessing

    if ($as === 'admin') {
        if (hash_equals(ADMIN_PASSWORD, $pw)) {
            session_regenerate_id(true);
            $_SESSION['role'] = 'admin';
            header('Location: admin.php');
            exit;
        }
        $error = 'Incorrect password.';
    } else {
        // Entrants and judges both sign in with their own account (username or email + password).
        $login = trim((string)($_POST['login'] ?? ''));
        $st = db()->prepare('SELECT * FROM people WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, strtolower($login)]);
        $u = $st->fetch();
        if ($u && password_verify($pw, $u['password_hash'])) {
            $isStaff = in_array($u['role'], ['judge', 'admin'], true);
            if ($as === 'entrant' && !$isStaff) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$u['id'];
                header('Location: dashboard.php');
                exit;
            }
            if ($as === 'judge' && $isStaff) {
                session_regenerate_id(true);
                $_SESSION['judge_id'] = (int)$u['id'];
                $_SESSION['role'] = $u['role'];
                header('Location: ' . ($u['role'] === 'admin' ? 'admin.php' : 'judge.php'));
                exit;
            }
            $error = $as === 'entrant' ? 'This is a judge account. Please use the judge login.'
                                       : 'This account is not a judge account.';
        } else {
            $error = 'Incorrect username/email or password.';
        }
    }
}

page_header($as === 'entrant' ? 'Log in' : ucfirst($as) . ' login');
?>
<h1><?= $as === 'entrant' ? 'Log in' : ucfirst($as) . ' login' ?></h1>
<?php if ($error): ?><div class="box err"><?= h($error) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <?php if ($as !== 'admin'): ?>
    <label>Username or email <input name="login" required autofocus autocomplete="username" value="<?= h($login) ?>"></label>
  <?php endif; ?>
  <label>Password <input type="password" name="password" required <?= $as === 'admin' ? 'autofocus' : '' ?> autocomplete="current-password"></label>
  <button type="submit">Log in</button>
</form>
<?php if ($as === 'entrant'): ?>
  <p class="note">New here? <a href="register.php">Create an account</a>.</p>
<?php endif; ?>
<?php page_footer();
