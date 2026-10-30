<?php
require __DIR__ . '/lib.php';
require_role('admin');
if (($_SESSION['role'] ?? '') !== 'admin') exit; // only administrators manage administrators
$pdo = db();
$me = current_judge();

$errors = [];
$notice = $_SESSION['flash_admins'] ?? null;
unset($_SESSION['flash_admins']);
$v = ['first_name' => '', 'last_name' => '', 'email' => '', 'username' => ''];

function flash_redirect(string $msg): never {
    $_SESSION['flash_admins'] = $msg;
    header('Location: admins.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'create') {
        foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
        $v['email'] = strtolower($v['email']);
        $password = (string)($_POST['password'] ?? '');

        if ($v['first_name'] === '' || mb_strlen($v['first_name']) > 80) $errors[] = 'Please enter a first name.';
        if ($v['last_name'] === '' || mb_strlen($v['last_name']) > 80) $errors[] = 'Please enter a last name.';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) $errors[] = 'Please enter a valid email address.';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $v['username'])) $errors[] = 'Username must be 3-40 characters: letters, numbers, dot, dash or underscore.';
        if (strlen($password) < 8 || strlen($password) > 72) $errors[] = 'Password must be 8-72 characters.';

        if (!$errors) {
            try {
                $pdo->prepare("INSERT INTO users (username, password_hash, first_name, last_name, email, phone, role)
                               VALUES (?,?,?,?,?,'','admin')")
                    ->execute([$v['username'], password_hash($password, PASSWORD_DEFAULT), $v['first_name'], $v['last_name'], $v['email']]);
                flash_redirect('Administrator account created for ' . $v['first_name'] . ' ' . $v['last_name'] . '.');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = stripos($e->getMessage(), 'username') !== false
                        ? 'That username is already taken.'
                        : 'An account with that email address already exists. Use "Make an existing account an administrator" below.';
                } else {
                    error_log($e->getMessage());
                    $errors[] = 'Something went wrong creating the administrator. Please try again.';
                }
            }
        }
    } elseif ($action === 'promote') {
        $login = trim((string)($_POST['login'] ?? ''));
        $st = $pdo->prepare('SELECT id, role, first_name, last_name FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$login, strtolower($login)]);
        $u = $st->fetch();
        if (!$u) {
            $errors[] = 'No account found with that username or email.';
        } elseif ($u['role'] === 'admin') {
            $errors[] = 'That account is already an administrator.';
        } else {
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$u['id']]);
            $pdo->prepare('DELETE FROM judge_assignments WHERE person_id = ?')->execute([$u['id']]);
            flash_redirect($u['first_name'] . ' ' . $u['last_name'] . ' is now an administrator.');
        }
    } elseif ($action === 'demote') {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($id === (int)$me['id']) {
            $errors[] = 'You cannot remove your own administrator access. Ask another administrator to do it.';
        } elseif ($count <= 1) {
            $errors[] = 'There must be at least one administrator.';
        } else {
            $pdo->prepare("UPDATE users SET role = 'contestant' WHERE id = ? AND role = 'admin'")->execute([$id]);
            flash_redirect('Administrator access removed. The account is now an ordinary entrant account.');
        }
    } elseif ($action === 'reset') {
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 72) {
            $errors[] = 'New password must be 8-72 characters.';
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'admin'")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            flash_redirect('Password updated.');
        }
    }
}

$admins = $pdo->query("SELECT id, username, first_name, last_name, email, created_at FROM users
                       WHERE role = 'admin' ORDER BY last_name, first_name")->fetchAll();

page_header('Administrators', admin_nav());
?>
<h1>Administrators (<?= count($admins) ?>)</h1>
<p class="note">Administrators sign in at the Administrator login with their own username (or email) and password. They see all entrant details and manage contests, judges and administrators.</p>

<?php if ($notice): ?><div class="box ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="tablewrap">
<table>
  <tr><th>Name</th><th>Username</th><th>Email</th><th>Added</th><th>New password</th><th></th></tr>
  <?php foreach ($admins as $a): ?>
  <tr>
    <td><?= h($a['first_name'] . ' ' . $a['last_name']) ?><?= (int)$a['id'] === (int)$me['id'] ? ' (you)' : '' ?></td>
    <td><?= h($a['username']) ?></td>
    <td><?= h($a['email']) ?></td>
    <td><?= h($a['created_at']) ?></td>
    <td>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="8+ characters">
        <button type="submit">Reset</button>
      </form>
    </td>
    <td>
      <?php if ((int)$a['id'] !== (int)$me['id']): ?>
      <form method="post" onsubmit="return confirm('Remove administrator access for this account?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="demote">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button type="submit" class="danger">Remove admin</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>

<h2>Add an administrator</h2>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <div class="row">
    <label>First name <input name="first_name" required maxlength="80" value="<?= h($v['first_name']) ?>"></label>
    <label>Last name <input name="last_name" required maxlength="80" value="<?= h($v['last_name']) ?>"></label>
  </div>
  <div class="row">
    <label>Email <input type="email" name="email" required value="<?= h($v['email']) ?>"></label>
    <label>Username <input name="username" required maxlength="40" value="<?= h($v['username']) ?>"></label>
  </div>
  <label>Initial password (8+ characters; share it with them) <input type="password" name="password" required autocomplete="new-password"></label>
  <button type="submit">Create administrator</button>
</form>

<h2>Make an existing account an administrator</h2>
<p class="note">Works for entrant or judge accounts. Judge assignments are cleared; the account loses entrant or judge access while it is an administrator.</p>
<form method="post" onsubmit="return confirm('Make this account an administrator?');">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="promote">
  <label>Username or email <input name="login" required></label>
  <button type="submit">Make administrator</button>
</form>
<?php page_footer();
