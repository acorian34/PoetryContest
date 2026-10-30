<?php
require __DIR__ . '/lib.php';
require_role('admin');
$pdo = db();

$errors = [];
$notice = $_SESSION['flash_judges'] ?? null;
unset($_SESSION['flash_judges']);
$v = ['first_name' => '', 'last_name' => '', 'email' => '', 'username' => ''];

function flash_redirect(string $msg): never {
    $_SESSION['flash_judges'] = $msg;
    header('Location: judges.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
        $v['email'] = strtolower($v['email']);
        $password = (string)($_POST['password'] ?? '');

        if ($v['first_name'] === '' || mb_strlen($v['first_name']) > 80) $errors[] = 'Please enter a first name.';
        if ($v['last_name'] === '' || mb_strlen($v['last_name']) > 80) $errors[] = 'Please enter a last name.';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) $errors[] = 'Please enter a valid email address.';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $v['username'])) $errors[] = 'Username must be 3-40 characters: letters, numbers, dot, dash or underscore.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        elseif (strlen($password) > 72) $errors[] = 'Password must be 72 characters or fewer.';

        if (!$errors) {
            try {
                $pdo->prepare("INSERT INTO users (username, password_hash, first_name, last_name, email, phone, role)
                               VALUES (?,?,?,?,?,'','judge')")
                    ->execute([$v['username'], password_hash($password, PASSWORD_DEFAULT), $v['first_name'], $v['last_name'], $v['email']]);
                $newId = (int)$pdo->lastInsertId();
                $cid = (int)($_POST['contest_id'] ?? 0);
                if ($cid && find_contest($cid)) {
                    $pdo->prepare('INSERT INTO judge_assignments (person_id, contest_id) VALUES (?,?) ON DUPLICATE KEY UPDATE removed_at = NULL')->execute([$newId, $cid]);
                }
                flash_redirect('Judge account created for ' . $v['first_name'] . ' ' . $v['last_name'] . '.');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = stripos($e->getMessage(), 'username') !== false
                        ? 'That username is already taken.'
                        : 'An account with that email address already exists. Use "Make an existing account a judge" below.';
                } else {
                    error_log($e->getMessage());
                    $errors[] = 'Something went wrong creating the judge. Please try again.';
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
        } elseif ($u['role'] !== 'contestant') {
            $errors[] = 'That account is already a ' . $u['role'] . '.';
        } else {
            $pdo->prepare("UPDATE users SET role = 'judge' WHERE id = ? AND role = 'contestant'")->execute([$u['id']]);
            flash_redirect($u['first_name'] . ' ' . $u['last_name'] . ' is now a judge and no longer has entrant access. Assign them to a contest below.');
        }
    } elseif ($action === 'demote') {
        $pdo->prepare("UPDATE users SET role = 'contestant' WHERE id = ? AND role = 'judge'")->execute([(int)($_POST['id'] ?? 0)]);
        $pdo->prepare('UPDATE judge_assignments SET removed_at = NOW() WHERE person_id = ? AND removed_at IS NULL')->execute([(int)($_POST['id'] ?? 0)]);
        flash_redirect('Judge access removed. The account is now an ordinary entrant account.');
    } elseif ($action === 'assign' || $action === 'unassign') {
        $cid = (int)($_POST['contest_id'] ?? 0);
        $contest = find_contest($cid);
        $id = (int)($_POST['id'] ?? 0);
        $isJudge = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'judge'");
        $isJudge->execute([$id]);
        if (!$contest) {
            $errors[] = 'Please choose a contest.';
        } elseif (!$isJudge->fetch()) {
            $errors[] = 'Judge not found.';
        } elseif ($action === 'assign') {
            $pdo->prepare('INSERT INTO judge_assignments (person_id, contest_id) VALUES (?,?) ON DUPLICATE KEY UPDATE removed_at = NULL')->execute([$id, $cid]);
            flash_redirect('Judge assigned to ' . $contest['name'] . '.');
        } else {
            $pdo->prepare('UPDATE judge_assignments SET removed_at = NOW() WHERE person_id = ? AND contest_id = ? AND removed_at IS NULL')->execute([$id, $cid]);
            flash_redirect('Judge removed from ' . $contest['name'] . '.');
        }
    } elseif ($action === 'reset') {
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 72) {
            $errors[] = 'New password must be 8-72 characters.';
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'judge'")
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int)($_POST['id'] ?? 0)]);
            flash_redirect('Password updated.');
        }
    }
}

$judges = $pdo->query("SELECT id, username, first_name, last_name, email, created_at FROM users
                       WHERE role = 'judge' ORDER BY last_name, first_name")->fetchAll();
$contests = all_contests();
$assigned = [];  // judge id => [contest id => contest name]
foreach ($pdo->query('SELECT a.person_id, c.id, c.name FROM judge_assignments a JOIN contests c ON c.id = a.contest_id WHERE a.removed_at IS NULL ORDER BY c.id DESC') as $a) {
    $assigned[$a['person_id']][(int)$a['id']] = $a['name'];
}

page_header('Judges', admin_nav());
?>
<h1>Judges (<?= count($judges) ?>)</h1>
<p class="note">Each judge signs in at the Judge login with their own username (or email) and password. Judges see only anonymous entry codes and poems, and only for the contests they are assigned to. Create contests on the Contests page first.</p>

<?php if ($notice): ?><div class="box ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="tablewrap">
<table>
  <tr><th>Name</th><th>Username</th><th>Email</th><th>Contests</th><th>Added</th><th>New password</th><th></th></tr>
  <?php foreach ($judges as $j): ?>
  <tr>
    <td><?= h($j['first_name'] . ' ' . $j['last_name']) ?></td>
    <td><?= h($j['username']) ?></td>
    <td><?= h($j['email']) ?></td>
    <td>
      <?php foreach ($assigned[$j['id']] ?? [] as $cid => $cname): ?>
      <form method="post" class="yearform" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="unassign">
        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
        <input type="hidden" name="contest_id" value="<?= $cid ?>">
        <?= h($cname) ?> <button type="submit" class="danger" title="Remove from this contest">x</button>
      </form>
      <?php endforeach; ?>
      <?php if (empty($assigned[$j['id']])): ?><span class="note">none</span><?php endif; ?>
      <form method="post" class="yearform">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
        <select name="contest_id" required>
          <option value="">Assign to a contest...</option>
          <?php foreach ($contests as $c): if (isset($assigned[$j['id']][(int)$c['id']])) continue; ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Assign</button>
      </form>
    </td>
    <td><?= h($j['created_at']) ?></td>
    <td>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
        <input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="8+ characters">
        <button type="submit">Reset</button>
      </form>
    </td>
    <td>
      <form method="post" onsubmit="return confirm('Remove judge access for this account?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="demote">
        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
        <button type="submit" class="danger">Remove judge</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$judges): ?><tr><td colspan="7">No judges yet. Add the first one below.</td></tr><?php endif; ?>
</table>
</div>

<h2>Add a judge</h2>
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
  <label>Initial password (8+ characters; share it with the judge) <input type="password" name="password" required autocomplete="new-password"></label>
  <label>Assign to contest (optional)
    <select name="contest_id"><option value="">None yet</option>
      <?php foreach ($contests as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Create judge</button>
</form>

<h2>Make an existing account a judge</h2>
<p class="note">For someone who already registered as an entrant. The account loses entrant access while it is a judge; its past entries are kept.</p>
<form method="post" onsubmit="return confirm('Make this account a judge?');">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="promote">
  <label>Username or email <input name="login" required></label>
  <button type="submit">Make judge</button>
</form>
<?php page_footer();
