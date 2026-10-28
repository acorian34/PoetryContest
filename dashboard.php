<?php
require __DIR__ . '/lib.php';
$user = require_user();
$pdo = db();

$errors = [];
$success = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$title = '';

// This person's entries per contest (limit and category rule apply per contest)
$openContests = all_contests(true);
$postedContest = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim((string)($_POST['title'] ?? ''));
    $category = (string)($_POST['category'] ?? '');
    $postedContest = (int)($_POST['contest_id'] ?? 0);
    $contest = find_contest($postedContest);

    if (!$contest) $errors[] = 'Please choose a contest.';
    elseif (!$contest['accepting']) $errors[] = 'That contest is not accepting entries right now (it is closed or outside its start and end dates).';
    if (empty($_POST['agree_rules'])) $errors[] = 'You must agree to the contest rules to submit a poem.';
    if ($title === '' || mb_strlen($title) > 150) $errors[] = 'Please enter a title for your poem.';
    if (!isset(CATEGORIES[$category])) $errors[] = 'Please select a category.';

    $f = $_FILES['poem'] ?? null;
    $ext = '';
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please attach your poem as a document.';
    } elseif ($f['error'] !== UPLOAD_ERR_OK) {
        $errors[] = ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
            ? 'That file is too large.' : 'The file upload failed. Please try again.';
    } else {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($problem = check_upload($f['tmp_name'], $ext)) $errors[] = $problem;
        if ($f['size'] > MAX_FILE_BYTES) $errors[] = 'File must be under ' . (MAX_FILE_BYTES / 1048576) . ' MB.';
    }

    if (!$errors) {
        $stored = null;
        try {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$user['id']]); // serialize this person's uploads
            $cur = $pdo->prepare('SELECT category FROM entries WHERE person_id = ? AND contest_id = ?');
            $cur->execute([$user['id'], $postedContest]);
            $existing = $cur->fetchAll(PDO::FETCH_COLUMN);

            if (count($existing) >= (int)$contest['max_entries']) {
                $errors[] = 'You have already submitted the maximum of ' . (int)$contest['max_entries'] . ' poem(s) for ' . $contest['name'] . '.';
                $pdo->rollBack();
            } elseif ($existing && $existing[0] !== $category) {
                $errors[] = 'All of your poems in this contest must be in the same category (' . CATEGORIES[$existing[0]][0] . ').';
                $pdo->rollBack();
            } else {
                $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], DATA_DIR . '/files/' . $stored)) {
                    throw new RuntimeException('Could not save the uploaded file.');
                }
                $code = make_code($pdo, $category, $postedContest);
                $pdo->prepare('INSERT INTO entries (person_id, contest_id, title, category, code, file_stored, file_ext, file_orig, rules_agreed_at)
                               VALUES (?,?,?,?,?,?,?,?,NOW())')
                    ->execute([$user['id'], $postedContest, $title, $category, $code, $stored, $ext, basename($f['name'])]);
                $pdo->prepare('UPDATE users SET category = ? WHERE id = ?')->execute([$category, $user['id']]); // default for the next contest
                $pdo->commit();
                $_SESSION['flash'] = $title;
                header('Location: dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($stored && is_file(DATA_DIR . '/files/' . $stored)) unlink(DATA_DIR . '/files/' . $stored);
            error_log($e->getMessage());
            $errors[] = 'Something went wrong saving your entry. Please try again.';
        }
    }
}

$st = $pdo->prepare('SELECT e.title, e.category, e.file_orig, e.created_at, e.contest_id, c.name AS contest_name
                     FROM entries e JOIN contests c ON c.id = e.contest_id
                     WHERE e.person_id = ? ORDER BY e.contest_id DESC, e.id');
$st->execute([$user['id']]);
$mine = $st->fetchAll();

// Per open contest: how many poems used, and the category they are locked to
$used = [];
$locked = [];
foreach ($mine as $m) {
    $used[$m['contest_id']] = ($used[$m['contest_id']] ?? 0) + 1;
    $locked[$m['contest_id']] = $m['category'];
}
$enterable = array_values(array_filter($openContests, fn($c) => ($used[$c['id']] ?? 0) < (int)$c['max_entries']));

page_header('My entries', '<nav><a href="login.php?logout=1">Log out</a></nav>');
?>
<h1>Welcome, <?= h($user['first_name']) ?></h1>

<?php if ($success !== null): ?>
  <div class="box ok"><strong>Thank you!</strong> Your poem &ldquo;<?= h($success) ?>&rdquo; was received.</div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<h2>Your poems</h2>
<?php if (!$mine): ?>
  <p class="note">You have not submitted a poem yet.</p>
<?php else: ?>
  <div class="tablewrap"><table>
    <tr><th>Contest</th><th>Title</th><th>Category</th><th>File</th><th>Submitted</th></tr>
    <?php foreach ($mine as $m): ?>
      <tr><td><?= h($m['contest_name']) ?></td><td><?= h($m['title']) ?></td>
          <td><?= h(CATEGORIES[$m['category']][0]) ?></td><td><?= h($m['file_orig']) ?></td><td><?= h($m['created_at']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>

<h2>Open contests</h2>
<?php if (!$openContests): ?>
  <div class="box err">There are no contests open for entries right now.</div>
<?php else: ?>
  <ul>
  <?php foreach ($openContests as $c): $n = $used[$c['id']] ?? 0; ?>
    <li><strong><?= h($c['name']) ?></strong> (<?= h(date('M j, Y', strtotime($c['start_date']))) ?> to <?= h(date('M j, Y', strtotime($c['end_date']))) ?>) &mdash; <?= $n ?> of <?= (int)$c['max_entries'] ?> poems used<?php if (isset($locked[$c['id']])): ?>
      (category: <?= h(CATEGORIES[$locked[$c['id']]][0]) ?>)<?php endif; ?>
      <?php if (trim((string)$c['description']) !== ''): ?><br><span class="note"><?= nl2br(h($c['description'])) ?></span><?php endif; ?>
      <details><summary>Contest rules</summary><div class="rules"><?= nl2br(h($c['rules'])) ?></div></details></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($enterable): ?>
  <h2>Submit a poem</h2>
  <form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <label>Contest
      <select name="contest_id" required>
        <?php foreach ($enterable as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $postedContest === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Category
      <select name="category" required>
        <?php foreach (CATEGORIES as $key => [$label]): ?>
          <option value="<?= h($key) ?>" <?= $user['category'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <p class="note">Within one contest, all of your poems must be in the same category.</p>
    <label class="check"><input type="checkbox" name="agree_rules" value="1" required<?= !empty($_POST['agree_rules']) ? ' checked' : '' ?>>
      I have read and agree to the rules of the selected contest (see &ldquo;Contest rules&rdquo; above).</label>
    <label>Poem title <input name="title" required maxlength="150" value="<?= h($title) ?>"></label>
    <label>Poem document (<?= h(implode(', ', ALLOWED_EXT)) ?>; max <?= MAX_FILE_BYTES / 1048576 ?> MB)
      <input type="file" name="poem" required accept=".<?= h(implode(',.', ALLOWED_EXT)) ?>">
    </label>
    <p class="note">Please do <strong>not</strong> put your name inside the poem document &mdash; entries are judged anonymously.</p>
    <button type="submit">Submit poem</button>
  </form>
<?php elseif ($openContests): ?>
  <p class="note">You have used all of your submissions in the open contests. Good luck!</p>
<?php endif; ?>
<?php page_footer();
