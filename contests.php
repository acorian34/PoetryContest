<?php
require __DIR__ . '/lib.php';
require_role('admin');
$pdo = db();

$errors = [];
$notice = $_SESSION['flash_contests'] ?? null;
unset($_SESSION['flash_contests']);
$today = date('Y-m-d');
$v = ['start_date' => $today, 'end_date' => date('Y-m-d', strtotime('+3 months')), 'rules' => '', 'name' => '', 'description' => '', 'max_entries' => (string)DEFAULT_MAX_ENTRIES];

function flash_redirect(string $msg): never {
    $_SESSION['flash_contests'] = $msg;
    header('Location: contests.php');
    exit;
}

/** Validate the name / description / entry limit fields shared by the create and edit forms. */
function contest_fields(array $src, array &$errors): array {
    $f = [
        'name'        => trim((string)($src['name'] ?? '')),
        'description' => trim((string)($src['description'] ?? '')),
        'rules'       => trim((string)($src['rules'] ?? '')),
        'start_date'  => trim((string)($src['start_date'] ?? '')),
        'end_date'    => trim((string)($src['end_date'] ?? '')),
        'max_entries' => (int)($src['max_entries'] ?? 0),
    ];
    if ($f['name'] === '' || mb_strlen($f['name']) > 150) $errors[] = 'Please enter a contest name (150 characters or fewer).';
    if (mb_strlen($f['description']) > 5000) $errors[] = 'The description is too long.';
    foreach (['start_date' => 'start date', 'end_date' => 'end date'] as $k => $label) {
        $d = DateTime::createFromFormat('!Y-m-d', $f[$k]);
        if (!$d || $d->format('Y-m-d') !== $f[$k]) $errors[] = "Please enter a valid $label.";
    }
    if (!$errors && $f['end_date'] < $f['start_date']) $errors[] = 'The end date cannot be before the start date.';
    if (mb_strlen($f['rules']) > 20000) $errors[] = 'The rules text is too long.';
    if ($f['rules'] === '') $errors[] = 'Please enter the contest rules.';
    if ($f['max_entries'] < 1 || $f['max_entries'] > 20) $errors[] = 'Entries per person must be between 1 and 20.';
    return $f;
}

function duplicate_name(PDOException $e): bool { return (int)($e->errorInfo[1] ?? 0) === 1062; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    try {
        if ($action === 'create') {
            $f = contest_fields($_POST, $errors);
            $v = [...$v, ...array_map('strval', $f)];
            if (!$errors) {
                $pdo->prepare('INSERT INTO contests (name, description, rules, start_date, end_date, max_entries, is_open) VALUES (?,?,?,?,?,?,1)')
                    ->execute([$f['name'], $f['description'], $f['rules'], $f['start_date'], $f['end_date'], $f['max_entries']]);
                flash_redirect('Contest created and open for entries: ' . $f['name']);
            }
        } elseif ($action === 'update') {
            $f = contest_fields($_POST, $errors);
            if (!$errors) {
                $pdo->prepare('UPDATE contests SET name = ?, description = ?, rules = ?, start_date = ?, end_date = ?, max_entries = ? WHERE id = ?')
                    ->execute([$f['name'], $f['description'], $f['rules'], $f['start_date'], $f['end_date'], $f['max_entries'], $id]);
                flash_redirect('Contest updated.');
            }
        } elseif ($action === 'open' || $action === 'close') {
            $pdo->prepare('UPDATE contests SET is_open = ? WHERE id = ?')->execute([$action === 'open' ? 1 : 0, $id]);
            flash_redirect($action === 'open' ? 'Contest is now open for entries.' : 'Contest is now closed to new entries.');
        } elseif ($action === 'delete') {
            $n = $pdo->prepare('SELECT COUNT(*) FROM entries WHERE contest_id = ?');
            $n->execute([$id]);
            if ((int)$n->fetchColumn() > 0) {
                $errors[] = 'A contest with entries cannot be deleted. Close it instead.';
            } else {
                $pdo->prepare('DELETE FROM contests WHERE id = ?')->execute([$id]);
                flash_redirect('Contest deleted.');
            }
        }
    } catch (PDOException $e) {
        if (duplicate_name($e)) {
            $errors[] = 'A contest with that name already exists.';
        } else {
            error_log($e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$rows = $pdo->query('SELECT c.*, ' . CONTEST_ACCEPTING . ' AS accepting,
        (SELECT COUNT(*) FROM entries e WHERE e.contest_id = c.id) AS entry_count,
        (SELECT COUNT(*) FROM judge_assignments a WHERE a.contest_id = c.id) AS judge_count
    FROM contests c ORDER BY c.id DESC')->fetchAll();

page_header('Contests', admin_nav());
?>
<h1>Contests (<?= count($rows) ?>)</h1>
<p class="note">Several contests can run at once. A contest accepts entries only between its start and end dates and while its open switch is on. Entrants choose which open contests to enter; the entries-per-person limit applies to each contest separately.</p>

<?php if ($notice): ?><div class="box ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<?php foreach ($rows as $c): ?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <p><strong><?= $c['accepting'] ? 'ACCEPTING ENTRIES' : ($c['is_open'] ? 'OUTSIDE ITS DATES' : 'CLOSED') ?></strong> &middot;
     <?= (int)$c['entry_count'] ?> entries &middot; <?= (int)$c['judge_count'] ?> judges
     &middot; <a href="admin.php?contest=<?= (int)$c['id'] ?>">View entries</a></p>
  <label>Name <input name="name" required maxlength="150" value="<?= h($c['name']) ?>"></label>
  <label>Description (shown to entrants) <textarea name="description" rows="2" maxlength="5000" style="width:100%"><?= h($c['description']) ?></textarea></label>
  <div class="row">
    <label>Start date <input type="date" name="start_date" required value="<?= h($c['start_date']) ?>"></label>
    <label>End date (last day entries are accepted) <input type="date" name="end_date" required value="<?= h($c['end_date']) ?>"></label>
  </div>
  <label>Contest rules (entrants must agree to these when submitting) <textarea name="rules" rows="8" maxlength="20000" required style="width:100%"><?= h($c['rules']) ?></textarea></label>
  <label>Max submissions per person <input type="number" name="max_entries" min="1" max="20" required value="<?= (int)$c['max_entries'] ?>"></label>
  <button type="submit" name="action" value="update">Save changes</button>
  <button type="submit" name="action" value="<?= $c['is_open'] ? 'close' : 'open' ?>" formnovalidate<?= $c['is_open'] ? ' class="danger"' : '' ?>>
    <?= $c['is_open'] ? 'Close to new entries' : 'Open for entries' ?></button>
  <?php if (!(int)$c['entry_count']): ?>
    <button type="submit" name="action" value="delete" class="danger" formnovalidate onclick="return confirm('Delete this contest?');">Delete</button>
  <?php endif; ?>
</form>
<br>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="note">No contests yet. Create the first one below.</p><?php endif; ?>

<h2>Create a contest</h2>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <label>Name <input name="name" required maxlength="150" value="<?= h($v['name']) ?>"></label>
  <label>Description (shown to entrants) <textarea name="description" rows="2" maxlength="5000" style="width:100%"><?= h($v['description']) ?></textarea></label>
  <div class="row">
    <label>Start date <input type="date" name="start_date" required value="<?= h($v['start_date']) ?>"></label>
    <label>End date (last day entries are accepted) <input type="date" name="end_date" required value="<?= h($v['end_date']) ?>"></label>
  </div>
  <label>Contest rules (entrants must agree to these when submitting) <textarea name="rules" rows="8" maxlength="20000" required style="width:100%"><?= h($v['rules']) ?></textarea></label>
  <label>Max submissions per person <input type="number" name="max_entries" min="1" max="20" required value="<?= h($v['max_entries']) ?>"></label>
  <button type="submit">Create contest</button>
</form>
<?php page_footer();
