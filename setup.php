<?php
// One-time page that creates the first administrator account. It is locked as soon as any admin exists.
require __DIR__ . '/lib.php';
$pdo = db();

$locked = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
$errors = [];
$v = ['first_name' => '', 'last_name' => '', 'email' => '', 'username' => ''];

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    usleep(300000);
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $v['email'] = strtolower($v['email']);
    $password = (string)($_POST['password'] ?? '');

    if (!hash_equals(SETUP_KEY, (string)($_POST['setup_key'] ?? ''))) $errors[] = 'The setup key is not correct. It is SETUP_KEY in config.php.';
    if ($v['first_name'] === '' || mb_strlen($v['first_name']) > 80) $errors[] = 'Please enter a first name.';
    if ($v['last_name'] === '' || mb_strlen($v['last_name']) > 80) $errors[] = 'Please enter a last name.';
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) $errors[] = 'Please enter a valid email address.';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $v['username'])) $errors[] = 'Username must be 3-40 characters: letters, numbers, dot, dash or underscore.';
    if (strlen($password) < 8 || strlen($password) > 72) $errors[] = 'Password must be 8-72 characters.';

    if (!$errors) {
        try {
            $pdo->prepare("INSERT INTO users (username, password_hash, first_name, last_name, email, phone, category, role)
                           VALUES (?,?,?,?,?,'','','admin')")
                ->execute([$v['username'], password_hash($password, PASSWORD_DEFAULT), $v['first_name'], $v['last_name'], $v['email']]);
            header('Location: login.php?as=admin');
            exit;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $errors[] = 'That username or email address is already registered. Use a different one, or promote that account in the database: UPDATE users SET role = \'admin\' WHERE username = \'...\';';
            } else {
                error_log($e->getMessage());
                $errors[] = 'Something went wrong creating the account. Please try again.';
            }
        }
    }
}

page_header('Setup');
?>
<h1>Create the first administrator</h1>
<?php if ($locked): ?>
  <div class="box ok">Setup is already complete. <a href="login.php?as=admin">Administrator login</a>. Existing administrators can add more on the Admins page.</div>
<?php else: ?>
  <?php if ($errors): ?>
    <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
  <?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <label>Setup key (SETUP_KEY from config.php) <input type="password" name="setup_key" required autocomplete="off"></label>
    <div class="row">
      <label>First name <input name="first_name" required maxlength="80" value="<?= h($v['first_name']) ?>"></label>
      <label>Last name <input name="last_name" required maxlength="80" value="<?= h($v['last_name']) ?>"></label>
    </div>
    <div class="row">
      <label>Email <input type="email" name="email" required value="<?= h($v['email']) ?>"></label>
      <label>Username <input name="username" required maxlength="40" value="<?= h($v['username']) ?>"></label>
    </div>
    <label>Password (8+ characters) <input type="password" name="password" required autocomplete="new-password"></label>
    <button type="submit">Create administrator</button>
  </form>
<?php endif; ?>
<?php page_footer();
