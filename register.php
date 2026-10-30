<?php
require __DIR__ . '/lib.php';

if (current_user()) { header('Location: dashboard.php'); exit; }

$errors = [];
$v = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $v['email'] = strtolower($v['email']);
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($v['first_name'] === '' || mb_strlen($v['first_name']) > 80) $errors[] = 'Please enter a first name.';
    if ($v['last_name'] === '' || mb_strlen($v['last_name']) > 80) $errors[] = 'Please enter a last name.';
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) $errors[] = 'Please enter a valid email address.';
    if (strlen(preg_replace('/\D/', '', $v['phone'])) < 10 || strlen($v['phone']) > 40) $errors[] = 'Please enter a phone number with at least 10 digits.';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $v['username'])) $errors[] = 'Username must be 3-40 characters: letters, numbers, dot, dash or underscore.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    elseif (strlen($password) > 72) $errors[] = 'Password must be 72 characters or fewer.';
    elseif ($password !== $password2) $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        try {
            $pdo = db();
            $pdo->prepare('INSERT INTO users (username, password_hash, first_name, last_name, email, phone)
                           VALUES (?,?,?,?,?,?)')
                ->execute([$v['username'], password_hash($password, PASSWORD_DEFAULT), $v['first_name'],
                           $v['last_name'], $v['email'], $v['phone']]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$pdo->lastInsertId();
            header('Location: dashboard.php');
            exit;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) { // duplicate key
                $errors[] = stripos($e->getMessage(), 'username') !== false
                    ? 'That username is already taken. Please choose another.'
                    : 'An account with that email address already exists. Try logging in instead.';
            } else {
                error_log($e->getMessage());
                $errors[] = 'Something went wrong creating your account. Please try again.';
            }
        }
    }
}

page_header('Register');
?>
<div class="hero">
  <img src="images/logo.png" alt="<?= h(CONTEST_NAME) ?> emblem" class="biglogo">
  <div>
    <h1>Create your account</h1>
    <p class="lead">Already registered? <a href="login.php">Log in</a>.</p>
  </div>
</div>

<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="row">
    <label>First name <input name="first_name" required maxlength="80" value="<?= h($v['first_name']) ?>"></label>
    <label>Last name <input name="last_name" required maxlength="80" value="<?= h($v['last_name']) ?>"></label>
  </div>
  <div class="row">
    <label>Email <input type="email" name="email" required value="<?= h($v['email']) ?>"></label>
    <label>Phone <input type="tel" name="phone" required value="<?= h($v['phone']) ?>"></label>
  </div>
  <label>Username <input name="username" required maxlength="40" autocomplete="username" value="<?= h($v['username']) ?>"></label>
  <div class="row">
    <label>Password (8+ characters) <input type="password" name="password" required autocomplete="new-password"></label>
    <label>Confirm password <input type="password" name="password2" required autocomplete="new-password"></label>
  </div>
  <button type="submit">Create account</button>
</form>
<?php page_footer();
