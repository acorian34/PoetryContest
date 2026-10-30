<?php
// An entrant's own profile: edit details, change password, or delete the account.
require __DIR__ . '/lib.php';
$user = require_user();
$pdo = db();

$errors = [];
$notice = $_SESSION['flash_profile'] ?? null;
unset($_SESSION['flash_profile']);
$v = [
    'first_name' => $user['first_name'], 'last_name' => $user['last_name'],
    'email' => $user['email'], 'phone' => $user['phone'], 'username' => $user['username'],
];
$section = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $section = $action;
    usleep(200000);

    if ($action === 'details') {
        foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
        $v['email'] = strtolower($v['email']);
        if ($v['first_name'] === '' || mb_strlen($v['first_name']) > 80) $errors[] = 'Please enter a first name.';
        if ($v['last_name'] === '' || mb_strlen($v['last_name']) > 80) $errors[] = 'Please enter a last name.';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) $errors[] = 'Please enter a valid email address.';
        if (strlen(preg_replace('/\D/', '', $v['phone'])) < 10 || strlen($v['phone']) > 40) $errors[] = 'Please enter a phone number with at least 10 digits.';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $v['username'])) $errors[] = 'Username must be 3-40 characters: letters, numbers, dot, dash or underscore.';
        if (!$errors) {
            try {
                $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, username = ? WHERE id = ?')
                    ->execute([$v['first_name'], $v['last_name'], $v['email'], $v['phone'], $v['username'], $user['id']]);
                $_SESSION['flash_profile'] = 'Your details were saved.';
                header('Location: profile.php');
                exit;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = stripos($e->getMessage(), 'username') !== false
                        ? 'That username is already taken. Please choose another.'
                        : 'Another account already uses that email address.';
                } else {
                    error_log($e->getMessage());
                    $errors[] = 'Something went wrong saving your details. Please try again.';
                }
            }
        }
    } elseif ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');
        if (!password_verify($current, $user['password_hash'])) $errors[] = 'Your current password is not correct.';
        if (strlen($new) < 8) $errors[] = 'The new password must be at least 8 characters.';
        elseif (strlen($new) > 72) $errors[] = 'The new password must be 72 characters or fewer.';
        elseif ($new !== $new2) $errors[] = 'The two new passwords do not match.';
        if (!$errors) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            $_SESSION['flash_profile'] = 'Your password was changed.';
            header('Location: profile.php');
            exit;
        }
    } elseif ($action === 'delete') {
        if (!password_verify((string)($_POST['delete_password'] ?? ''), $user['password_hash'])) $errors[] = 'Your password is not correct, so the account was not deleted.';
        if (empty($_POST['confirm_delete'])) $errors[] = 'Please tick the box to confirm you want to delete your account.';
        if (!$errors) {
            $st = $pdo->prepare('SELECT file_stored FROM entries WHERE person_id = ?');
            $st->execute([$user['id']]);
            $files = $st->fetchAll(PDO::FETCH_COLUMN);
            // Deleting the user also removes their entries and any scores on them (foreign keys cascade).
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'contestant'")->execute([$user['id']]);
            foreach ($files as $stored) {
                $path = DATA_DIR . '/files/' . basename($stored);
                if (is_file($path)) unlink($path);
            }
            session_destroy();
            header('Location: index.php');
            exit;
        }
    }
}

$st = $pdo->prepare('SELECT COUNT(*) FROM entries WHERE person_id = ?');
$st->execute([$user['id']]);
$entryCount = (int)$st->fetchColumn();

page_header('My profile', user_nav());
?>
<p><a href="dashboard.php">&larr; Dashboard</a></p>
<h1>My profile</h1>
<?php if ($notice): ?><div class="box ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<h2>Your details</h2>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="details">
  <div class="row">
    <label>First name <input name="first_name" required maxlength="80" value="<?= h($v['first_name']) ?>"></label>
    <label>Last name <input name="last_name" required maxlength="80" value="<?= h($v['last_name']) ?>"></label>
  </div>
  <div class="row">
    <label>Email <input type="email" name="email" required value="<?= h($v['email']) ?>"></label>
    <label>Phone <input type="tel" name="phone" required value="<?= h($v['phone']) ?>"></label>
  </div>
  <label>Username <input name="username" required maxlength="40" autocomplete="username" value="<?= h($v['username']) ?>"></label>
  <button type="submit">Save details</button>
</form>

<h2>Change password</h2>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="password">
  <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
  <div class="row">
    <label>New password (8+ characters) <input type="password" name="new_password" required autocomplete="new-password"></label>
    <label>Confirm new password <input type="password" name="new_password2" required autocomplete="new-password"></label>
  </div>
  <button type="submit">Change password</button>
</form>

<h2>Delete my account</h2>
<form method="post" novalidate onsubmit="return confirm('Permanently delete your account and all of your entries?');">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <p>This permanently deletes your account, your contact details
    <?= $entryCount ? 'and your <strong>' . $entryCount . ' submitted ' . ($entryCount === 1 ? 'entry' : 'entries') . '</strong> (including the uploaded files and any judges\' scores)' : '' ?>.
    It cannot be undone, and deleted entries will no longer be judged.</p>
  <label>Your password <input type="password" name="delete_password" required autocomplete="current-password"></label>
  <label class="check"><input type="checkbox" name="confirm_delete" value="1" required> I understand this cannot be undone.</label>
  <button type="submit" class="danger">Delete my account</button>
</form>
<?php page_footer();
