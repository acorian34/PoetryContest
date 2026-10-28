<?php
require __DIR__ . '/lib.php';
$user = require_user();
$pdo = db();

$errors = [];
$success = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$title = '';

// This person's entries for the current contest year (limit and category rule apply per year)
$thisYear = $pdo->prepare('SELECT category FROM entries WHERE person_id = ? AND contest_year = ?');
$thisYear->execute([$user['id'], CONTEST_YEAR]);
$yearCategories = $thisYear->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim((string)($_POST['title'] ?? ''));
    $category = (string)($_POST['category'] ?? '');

    if (!entries_open()) $errors[] = 'Contest submissions are currently closed.';
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
            $pdo->prepare('SELECT id FROM people WHERE id = ? FOR UPDATE')->execute([$user['id']]); // serialize this person's uploads
            $cur = $pdo->prepare('SELECT category FROM entries WHERE person_id = ? AND contest_year = ?');
            $cur->execute([$user['id'], CONTEST_YEAR]);
            $existing = $cur->fetchAll(PDO::FETCH_COLUMN);

            if (count($existing) >= MAX_ENTRIES) {
                $errors[] = 'You have already submitted the maximum of ' . MAX_ENTRIES . ' poems for ' . CONTEST_YEAR . '.';
                $pdo->rollBack();
            } elseif ($existing && $existing[0] !== $category) {
                $errors[] = 'Both of your poems must be in the same category (' . CATEGORIES[$existing[0]][0] . ').';
                $pdo->rollBack();
            } else {
                $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], DATA_DIR . '/files/' . $stored)) {
                    throw new RuntimeException('Could not save the uploaded file.');
                }
                $code = make_code($pdo, $category, CONTEST_YEAR);
                $pdo->prepare('INSERT INTO entries (person_id, contest_year, title, category, code, file_stored, file_ext, file_orig)
                               VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$user['id'], CONTEST_YEAR, $title, $category, $code, $stored, $ext, basename($f['name'])]);
                $pdo->prepare('UPDATE people SET category = ? WHERE id = ?')->execute([$category, $user['id']]); // next year's default
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

$st = $pdo->prepare('SELECT contest_year, title, category, file_orig, created_at FROM entries
                     WHERE person_id = ? ORDER BY contest_year DESC, id');
$st->execute([$user['id']]);
$mine = $st->fetchAll();
$usedThisYear = count(array_filter($mine, fn($m) => (int)$m['contest_year'] === CONTEST_YEAR));
$lockedCategory = $yearCategories[0] ?? null; // set once they have a poem this year
$remaining = MAX_ENTRIES - $usedThisYear;

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
    <tr><th>Year</th><th>Title</th><th>Category</th><th>File</th><th>Submitted</th></tr>
    <?php foreach ($mine as $m): ?>
      <tr><td><?= (int)$m['contest_year'] ?></td><td><?= h($m['title']) ?></td>
          <td><?= h(CATEGORIES[$m['category']][0]) ?></td><td><?= h($m['file_orig']) ?></td><td><?= h($m['created_at']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>

<?php if ($remaining > 0 && entries_open()): ?>
  <h2>Submit a poem for <?= CONTEST_YEAR ?> (<?= $usedThisYear ?> of <?= MAX_ENTRIES ?> used)</h2>
  <form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <?php if ($lockedCategory): ?>
      <p>Category: <strong><?= h(CATEGORIES[$lockedCategory][0]) ?></strong></p>
      <input type="hidden" name="category" value="<?= h($lockedCategory) ?>">
    <?php else: ?>
      <label>Category
        <select name="category" required>
          <?php foreach (CATEGORIES as $key => [$label]): ?>
            <option value="<?= h($key) ?>" <?= $user['category'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label>Poem title <input name="title" required maxlength="150" value="<?= h($title) ?>"></label>
    <label>Poem document (<?= h(implode(', ', ALLOWED_EXT)) ?>; max <?= MAX_FILE_BYTES / 1048576 ?> MB)
      <input type="file" name="poem" required accept=".<?= h(implode(',.', ALLOWED_EXT)) ?>">
    </label>
    <p class="note">Please do <strong>not</strong> put your name inside the poem document &mdash; entries are judged anonymously.</p>
    <button type="submit">Submit poem</button>
  </form>
<?php elseif ($remaining <= 0): ?>
  <p class="note">You have used all of your <?= CONTEST_YEAR ?> submissions. Good luck!</p>
<?php else: ?>
  <div class="box err">Contest submissions are currently closed.</div>
<?php endif; ?>
<?php page_footer();
