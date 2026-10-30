<?php
require __DIR__ . '/lib.php';
require_role('admin');
$pdo = db();

$errors = [];
$notice = $_SESSION['flash_contests'] ?? null;
unset($_SESSION['flash_contests']);
$today = date('Y-m-d');
$v = ['categories' => "Poetry
Prose", 'start_date' => $today, 'end_date' => date('Y-m-d', strtotime('+3 months')), 'rules' => '', 'name' => '', 'description' => '', 'max_entries' => (string)DEFAULT_MAX_ENTRIES];

function flash_redirect(string $msg, ?int $id = null): never {
    $_SESSION['flash_contests'] = $msg;
    header('Location: contests.php' . ($id ? '?id=' . $id : ''));
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

/** One category name per line: trimmed, blank lines dropped, duplicates (ignoring case) removed. */
function parse_categories(string $text, array &$errors): array {
    $names = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (mb_strlen($line) > 60) { $errors[] = 'Category names must be 60 characters or fewer.'; continue; }
        $names[mb_strtolower($line)] ??= $line;
    }
    if (!$names) $errors[] = 'Please enter at least one category.';
    if (count($names) > 30) $errors[] = 'A contest can have at most 30 categories.';
    return array_values($names);
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
            $v['categories'] = (string)($_POST['categories'] ?? '');
            $cats = parse_categories($v['categories'], $errors);
            if (!$errors) {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO contests (name, description, rules, start_date, end_date, max_entries, is_open) VALUES (?,?,?,?,?,?,1)')
                    ->execute([$f['name'], $f['description'], $f['rules'], $f['start_date'], $f['end_date'], $f['max_entries']]);
                $newId = (int)$pdo->lastInsertId();
                $used = [];
                $ins = $pdo->prepare('INSERT INTO contest_categories (contest_id, name, code_prefix, sort_order) VALUES (?,?,?,?)');
                foreach ($cats as $i => $name) {
                    $used[] = $prefix = category_prefix($name, $used);
                    $ins->execute([$newId, $name, $prefix, $i + 1]);
                }
                $pdo->commit();
                flash_redirect('Contest created and open for entries: ' . $f['name'], $newId);
            }
        } elseif ($action === 'update') {
            $f = contest_fields($_POST, $errors);
            if (!$errors) {
                $pdo->prepare('UPDATE contests SET name = ?, description = ?, rules = ?, start_date = ?, end_date = ?, max_entries = ? WHERE id = ?')
                    ->execute([$f['name'], $f['description'], $f['rules'], $f['start_date'], $f['end_date'], $f['max_entries'], $id]);
                flash_redirect('Contest updated.', $id);
            }
        } elseif ($action === 'cat_add' || $action === 'cat_rename' || $action === 'cat_delete') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($action === 'cat_add') {
                if ($name === '' || mb_strlen($name) > 60) {
                    $errors[] = 'Please enter a category name (60 characters or fewer).';
                } else {
                    $used = $pdo->prepare('SELECT code_prefix FROM contest_categories WHERE contest_id = ?');
                    $used->execute([$id]);
                    $prefix = category_prefix($name, $used->fetchAll(PDO::FETCH_COLUMN));
                    $pdo->prepare('INSERT INTO contest_categories (contest_id, name, code_prefix, sort_order)
                                   SELECT ?, ?, ?, COALESCE(MAX(sort_order), 0) + 1 FROM contest_categories WHERE contest_id = ?')
                        ->execute([$id, $name, $prefix, $id]);
                    flash_redirect('Category added.', $id);
                }
            } elseif ($action === 'cat_rename') {
                if ($name === '' || mb_strlen($name) > 60) {
                    $errors[] = 'Please enter a category name (60 characters or fewer).';
                } else {
                    $pdo->prepare('UPDATE contest_categories SET name = ? WHERE id = ?')->execute([$name, (int)($_POST['cat_id'] ?? 0)]);
                    flash_redirect('Category renamed.', $id);
                }
            } else {
                $cid = (int)($_POST['cat_id'] ?? 0);
                $n = $pdo->prepare('SELECT COUNT(*) FROM entries WHERE category_id = ?');
                $n->execute([$cid]);
                $left = $pdo->prepare('SELECT COUNT(*) FROM contest_categories WHERE contest_id = ?');
                $left->execute([$id]);
                if ((int)$n->fetchColumn() > 0) {
                    $errors[] = 'A category with entries cannot be deleted.';
                } elseif ((int)$left->fetchColumn() <= 1) {
                    $errors[] = 'A contest needs at least one category.';
                } else {
                    $pdo->prepare('DELETE FROM contest_categories WHERE id = ? AND contest_id = ?')->execute([$cid, $id]);
                    flash_redirect('Category deleted.', $id);
                }
            }
        } elseif ($action === 'open' || $action === 'close') {
            $pdo->prepare('UPDATE contests SET is_open = ? WHERE id = ?')->execute([$action === 'open' ? 1 : 0, $id]);
            flash_redirect($action === 'open' ? 'Contest is now open for entries.' : 'Contest is now closed to new entries.', $id);
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
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (duplicate_name($e)) {
            $errors[] = str_starts_with($action, 'cat_') ? 'That category already exists in this contest.' : 'A contest with that name already exists.';
        } else {
            error_log($e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}


// Which screen: the list (default), the create form (?new=1), or one contest's details (?id=N)
$viewId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$creating = isset($_GET['new']) || (($_POST['action'] ?? '') === 'create');
$status = ' ' . CONTEST_ACCEPTING . ' AS accepting';

$detail = null;
if (!$creating && $viewId) {
    $st = $pdo->prepare('SELECT c.*,' . $status . ',
            (SELECT COUNT(*) FROM entries e WHERE e.contest_id = c.id) AS entry_count,
            (SELECT COUNT(*) FROM judge_assignments a WHERE a.contest_id = c.id) AS judge_count
        FROM contests c WHERE c.id = ?');
    $st->execute([$viewId]);
    $detail = $st->fetch() ?: null;
}

page_header('Contests', admin_nav());
?>
<?php if ($notice): ?><div class="box ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<?php if ($creating): ?>
<p><a href="contests.php">&larr; All contests</a></p>
<h1>Create a contest</h1>
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
  <label>Categories (one per line; entrants choose one for each entry) <textarea name="categories" rows="5" required style="width:100%"><?= h($v['categories']) ?></textarea></label>
  <button type="submit">Create contest</button>
</form>

<?php elseif ($detail): $c = $detail; ?>
<p><a href="contests.php">&larr; All contests</a></p>
<h1><?= h($c['name']) ?></h1>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <p><strong><?= h(status_label($c)) ?></strong> &middot;
     <?= (int)$c['entry_count'] ?> entries &middot; <?= (int)$c['judge_count'] ?> judges
     &middot; <a href="admin.php?contest=<?= (int)$c['id'] ?>">View entries</a>
     &middot; <a href="report.php?contest=<?= (int)$c['id'] ?>">Report</a></p>
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

<h2>Categories</h2>
<?php
$cats = $pdo->prepare('SELECT cc.*, (SELECT COUNT(*) FROM entries e WHERE e.category_id = cc.id) AS entry_count
                       FROM contest_categories cc WHERE cc.contest_id = ? ORDER BY cc.sort_order, cc.id');
$cats->execute([$c['id']]);
?>
<div class="tablewrap"><table style="max-width:640px">
  <?php foreach ($cats->fetchAll() as $cc): ?>
  <tr>
    <td>
      <form method="post" class="scoreform">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="cat_id" value="<?= (int)$cc['id'] ?>">
        <input name="name" required maxlength="60" value="<?= h($cc['name']) ?>">
        <button type="submit" name="action" value="cat_rename">Rename</button>
        <?php if (!(int)$cc['entry_count']): ?><button type="submit" name="action" value="cat_delete" class="danger" formnovalidate onclick="return confirm('Delete this category?');">Delete</button><?php endif; ?>
      </form>
    </td>
    <td><?= (int)$cc['entry_count'] ?> entries</td>
  </tr>
  <?php endforeach; ?>
  <tr><td colspan="2">
    <form method="post" class="scoreform">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <input name="name" required maxlength="60" placeholder="New category">
      <button type="submit" name="action" value="cat_add">Add category</button>
    </form>
  </td></tr>
</table></div>

<?php else: ?>
<?php
if ($viewId) echo '<div class="box err">That contest was not found.</div>';
// Newest first: by start date, then end date, then id for contests that start on the same day
$rows = $pdo->query('SELECT c.*,' . $status . ',
        (SELECT COUNT(*) FROM entries e WHERE e.contest_id = c.id) AS entry_count
    FROM contests c ORDER BY c.start_date DESC, c.end_date DESC, c.id DESC')->fetchAll();
?>
<h1>Contests (<?= count($rows) ?>)</h1>
<p><a class="btn" href="contests.php?new=1">Create a contest</a></p>
<p class="note">Several contests can run at once. A contest is open while its open switch is on and today is between its start and end dates.</p>
<?php if (!$rows): ?>
  <p class="note">No contests yet. Create the first one.</p>
<?php else: ?>
<div class="tablewrap">
<table>
  <tr><th>Name</th><th>Start date</th><th>Close date</th><th>Status</th><th>Entries</th><th></th></tr>
  <?php foreach ($rows as $c): ?>
  <tr>
    <td><a href="contests.php?id=<?= (int)$c['id'] ?>"><strong><?= h($c['name']) ?></strong></a></td>
    <td><?= h(date('M j, Y', strtotime($c['start_date']))) ?></td>
    <td><?= h(date('M j, Y', strtotime($c['end_date']))) ?></td>
    <td><?= h(status_label($c)) ?></td>
    <td><?= (int)$c['entry_count'] ?></td>
    <td><a href="contests.php?id=<?= (int)$c['id'] ?>">Details</a></td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php page_footer();
