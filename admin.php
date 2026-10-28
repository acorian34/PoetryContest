<?php
require __DIR__ . '/lib.php';
require_role('admin');
$pdo = db();
$year = selected_year();

// Open or close submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_open'])) {
    csrf_check();
    set_entries_open($_POST['set_open'] === '1');
    header('Location: admin.php?year=' . (int)($_POST['year'] ?? CONTEST_YEAR));
    exit;
}

// Delete an entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $st = $pdo->prepare('SELECT file_stored FROM entries WHERE id = ?');
    $st->execute([(int)$_POST['delete_id']]);
    if ($row = $st->fetch()) {
        $pdo->prepare('DELETE FROM entries WHERE id = ?')->execute([(int)$_POST['delete_id']]);
        $file = DATA_DIR . '/files/' . basename($row['file_stored']);
        if (is_file($file)) unlink($file);
        // The entrant's account is kept; only the entry and its file are removed.
    }
    header('Location: admin.php?year=' . (int)($_POST['year'] ?? CONTEST_YEAR));
    exit;
}

$st = $pdo->prepare('SELECT e.id, e.code, e.title, e.category, e.file_orig, e.created_at,
                            p.first_name, p.last_name, p.email, p.phone
                     FROM entries e JOIN people p ON p.id = e.person_id
                     WHERE e.contest_year = ?
                     ORDER BY e.category, e.code');
$st->execute([$year]);
$rows = $st->fetchAll();

// CSV export (for the selected year)
if (isset($_GET['csv'])) {
    $safe = fn($s) => preg_match('/^[=+\-@\t\r]/', (string)$s) ? "'" . $s : $s; // block spreadsheet formula injection
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="poetry-entries-' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    fputcsv($out, ['Year', 'Code', 'Category', 'Title', 'First name', 'Last name', 'Email', 'Phone', 'Submitted']);
    foreach ($rows as $r) {
        fputcsv($out, [$year, $r['code'], CATEGORIES[$r['category']][0], $safe($r['title']), $safe($r['first_name']),
                       $safe($r['last_name']), $r['email'], $safe($r['phone']), $r['created_at']]);
    }
    exit;
}

$counts = [];
foreach ($rows as $r) $counts[$r['category']] = ($counts[$r['category']] ?? 0) + 1;

page_header('Admin', '<nav><a href="judge.php?year=' . $year . '">Judge view</a> <a href="admin.php?csv=1&amp;year=' . $year . '">Download CSV</a> <a href="login.php?logout=1">Log out</a></nav>');
?>
<h1><?= $year ?> entries (<?= count($rows) ?>)</h1>
<?php $open = entries_open(); ?>
<form method="post" class="yearform">
  <?= csrf_field() ?>
  <input type="hidden" name="year" value="<?= $year ?>">
  <input type="hidden" name="set_open" value="<?= $open ? '0' : '1' ?>">
  Submissions are currently <strong><?= $open ? 'ON (open)' : 'OFF (closed)' ?></strong>.
  <button type="submit"<?= $open ? ' class="danger"' : '' ?>><?= $open ? 'Turn submissions off' : 'Turn submissions on' ?></button>
</form>
<?= year_picker($year) ?>
<p class="note">
<?php foreach (CATEGORIES as $key => [$label]): ?>
  <?= h($label) ?>: <strong><?= $counts[$key] ?? 0 ?></strong> &nbsp;
<?php endforeach; ?>
</p>
<div class="tablewrap">
<table>
  <tr><th>Code</th><th>Category</th><th>Title</th><th>Author</th><th>Email</th><th>Phone</th><th>Submitted</th><th>File</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><strong><?= h($r['code']) ?></strong></td>
    <td><?= h(CATEGORIES[$r['category']][0]) ?></td>
    <td><?= h($r['title']) ?></td>
    <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
    <td><?= h($r['email']) ?></td>
    <td><?= h($r['phone']) ?></td>
    <td><?= h($r['created_at']) ?></td>
    <td><a href="download.php?id=<?= (int)$r['id'] ?>">Download</a></td>
    <td>
      <form method="post" onsubmit="return confirm('Delete this entry permanently?');">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <button type="submit" class="danger">Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9">No entries for <?= $year ?>.</td></tr><?php endif; ?>
</table>
</div>
<?php page_footer();
