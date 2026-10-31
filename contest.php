<?php
// One contest, as an entrant sees it: details, rules, the entrant's own entries, and the submission form.
require __DIR__ . '/lib.php';
$user = require_user();
$pdo = db();

$contestId = (int)($_GET['id'] ?? $_POST['contest_id'] ?? 0);
$contest = find_contest($contestId);

$errors = [];
$success = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$title = '';
$categoryId = 0;

if ($contest && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim((string)($_POST['title'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);

    if (!$contest['accepting']) $errors[] = 'This contest is not accepting entries right now (its status is not Open, or today is outside its start and end dates).';
    if (empty($_POST['agree_rules'])) $errors[] = 'You must agree to the contest rules to submit a poem.';
    if ($title === '' || mb_strlen($title) > 150) $errors[] = 'Please enter a title for your poem.';

    $category = null;
    foreach (contest_categories($contestId) as $cc) if ((int)$cc['id'] === $categoryId) $category = $cc;
    if (!$category) $errors[] = 'Please select a category.';

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
            $cur = $pdo->prepare('SELECT COUNT(*) FROM entries WHERE person_id = ? AND contest_id = ? AND removed_at IS NULL');
            $cur->execute([$user['id'], $contestId]);

            if ((int)$cur->fetchColumn() >= (int)$contest['max_entries']) {
                $errors[] = 'You have already submitted the maximum of ' . (int)$contest['max_entries'] . ' poem(s) for this contest.';
                $pdo->rollBack();
            } else {
                $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], DATA_DIR . '/files/' . $stored)) {
                    throw new RuntimeException('Could not save the uploaded file.');
                }
                $code = make_code($pdo, $contest["id_code"], $contestId);
                $pdo->prepare('INSERT INTO entries (person_id, contest_id, title, category_id, code, file_stored, file_ext, file_orig, rules_agreed_at)
                               VALUES (?,?,?,?,?,?,?,?,NOW())')
                    ->execute([$user['id'], $contestId, $title, $categoryId, $code, $stored, $ext, basename($f['name'])]);
                $pdo->commit();
                $_SESSION['flash'] = $title;
                header('Location: contest.php?id=' . $contestId);
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

$mine = [];
$cats = [];
if ($contest) {
    $st = $pdo->prepare('SELECT e.title, cc.name AS category_name, e.file_orig, e.created_at, e.removed_at
                         FROM entries e JOIN contest_categories cc ON cc.id = e.category_id
                         WHERE e.person_id = ? AND e.contest_id = ? ORDER BY e.id');
    $st->execute([$user['id'], $contestId]);
    $mine = $st->fetchAll();
    $cats = contest_categories($contestId);
}
$active = count(array_filter($mine, fn($m) => $m['removed_at'] === null));
$remaining = $contest ? (int)$contest['max_entries'] - $active : 0;

page_header($contest ? $contest['name'] : 'Contest', user_nav());
?>
<?php if (!$contest): ?>
  <h1>Contest not found</h1>
  <p><a href="dashboard.php">&larr; Back to your dashboard</a></p>
<?php else: ?>
<p><a href="dashboard.php">&larr; Dashboard</a></p>
<h1><?= h($contest['name']) ?></h1>
<p class="note"><?= h(status_label($contest)) ?> &middot; <?= h(fmt_date($contest['start_date'])) ?> to <?= h(fmt_date($contest['end_date'])) ?>
  &middot; up to <?= (int)$contest['max_entries'] ?> poem(s) per person</p>
<?php if (trim((string)$contest['description']) !== ''): ?><p><?= nl2br(h($contest['description'])) ?></p><?php endif; ?>
<p><strong>Categories:</strong> <?= h(implode(', ', array_column($cats, 'name'))) ?></p>
<details><summary>Contest rules</summary><div class="rules"><?= nl2br(h($contest['rules'])) ?></div></details>

<?php if ($success !== null): ?>
  <div class="box ok"><strong>Thank you!</strong> Your poem &ldquo;<?= h($success) ?>&rdquo; was received.</div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<h2>Your entries in this contest (<?= $active ?> of <?= (int)$contest['max_entries'] ?>)</h2>
<?php if (!$mine): ?>
  <p class="note">You have not submitted a poem to this contest yet.</p>
<?php else: ?>
  <div class="tablewrap"><table>
    <tr><th>Title</th><th>Category</th><th>File</th><th>Submitted</th></tr>
    <?php foreach ($mine as $m): ?>
      <tr><td><?= h($m['title']) ?><?= $m['removed_at'] ? ' <span class="note">(removed by the organisers)</span>' : '' ?></td><td><?= h($m['category_name']) ?></td><td><?= h($m['file_orig']) ?></td><td><?= h($m['created_at']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>

<?php if ($contest['accepting'] && $remaining > 0): ?>
  <h2>Submit a poem</h2>
  <form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="contest_id" value="<?= $contestId ?>">
    <label>Category
      <select name="category_id" required>
        <?php foreach ($cats as $cc): ?>
          <option value="<?= (int)$cc['id'] ?>" <?= $categoryId === (int)$cc['id'] ? 'selected' : '' ?>><?= h($cc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="agree_rules" value="1" required<?= !empty($_POST['agree_rules']) ? ' checked' : '' ?>>
      I have read and agree to the rules of this contest (see &ldquo;Contest rules&rdquo; above).</label>
    <label>Poem title <input name="title" required maxlength="150" value="<?= h($title) ?>"></label>
    <label>Poem document (<?= h(implode(', ', ALLOWED_EXT)) ?>; max <?= MAX_FILE_BYTES / 1048576 ?> MB)
      <input type="file" name="poem" required accept=".<?= h(implode(',.', ALLOWED_EXT)) ?>">
    </label>
    <p class="note">Please do <strong>not</strong> put your name inside the poem document &mdash; entries are judged anonymously.</p>
    <button type="submit">Submit poem</button>
  </form>
<?php elseif ($remaining <= 0): ?>
  <p class="note">You have used all of your submissions for this contest. Good luck!</p>
<?php else: ?>
  <div class="box err">This contest is not accepting entries right now.</div>
<?php endif; ?>
<?php endif; ?>
<?php page_footer();
