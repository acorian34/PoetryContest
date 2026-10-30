<?php
require __DIR__ . '/lib.php';
require_role('admin');
$pdo = db();
$contests = all_contests();
$contest = selected_contest($contests);
$cid = $contest ? (int)$contest['id'] : 0;

// Delete an entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    csrf_check();
    $st = $pdo->prepare('SELECT file_stored FROM entries WHERE id = ?');
    $st->execute([(int)$_POST['delete_id']]);
    if ($row = $st->fetch()) {
        $pdo->prepare('DELETE FROM entries WHERE id = ?')->execute([(int)$_POST['delete_id']]);
        $file = DATA_DIR . '/files/' . basename($row['file_stored']);
        if (is_file($file)) unlink($file);
        // The entrant's account is kept; only the entry, its file and its scores are removed.
    }
    header('Location: admin.php?contest=' . (int)($_POST['contest'] ?? 0));
    exit;
}

$rows = [];
if ($contest) {
    $st = $pdo->prepare('SELECT e.id, e.code, e.title, cc.name AS category_name, cc.id AS category_id, e.file_orig, e.created_at,
                                p.first_name, p.last_name, p.email, p.phone,
                                (SELECT AVG(s.score) FROM scores s WHERE s.entry_id = e.id) AS avg_score,
                                (SELECT COUNT(*) FROM scores s WHERE s.entry_id = e.id) AS score_count
                         FROM entries e JOIN users p ON p.id = e.person_id
                         JOIN contest_categories cc ON cc.id = e.category_id
                         WHERE e.contest_id = ?
                         ORDER BY cc.sort_order, cc.id, e.code');
    $st->execute([$cid]);
    $rows = $st->fetchAll();

    // Individual judge scores, grouped by entry
    $byEntry = [];
    $st = $pdo->prepare('SELECT s.entry_id, s.score, s.comments, p.first_name, p.last_name
                         FROM scores s JOIN entries e ON e.id = s.entry_id JOIN users p ON p.id = s.judge_id
                         WHERE e.contest_id = ? ORDER BY p.last_name, p.first_name');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $s) $byEntry[$s['entry_id']][] = $s;
}

$counts = [];
foreach ($rows as $r) $counts[$r['category_id']] = ($counts[$r['category_id']] ?? 0) + 1;

page_header('Admin', admin_nav());
?>
<?php if (!$contest): ?>
  <h1>Entries</h1>
  <p class="note">No contests yet. <a href="contests.php">Create the first contest</a>.</p>
<?php else: ?>
<h1><?= h($contest['name']) ?> (<?= count($rows) ?> entries)</h1>
<p><a href="judge.php?contest=<?= $cid ?>">Judge view</a> &middot; <a href="report.php?contest=<?= $cid ?>">Report and scores</a></p>
<p class="note">Submissions are <strong><?= $contest['accepting'] ? 'open' : 'closed' ?></strong> (<?= h($contest['start_date']) ?> to <?= h($contest['end_date']) ?>) &middot; <?= (int)$contest['max_entries'] ?> per person &middot; <a href="contests.php">manage contests</a></p>
<?= contest_picker($contests, $cid) ?>
<p class="note">
<?php foreach (contest_categories($cid) as $cc): ?>
  <?= h($cc['name']) ?>: <strong><?= $counts[$cc['id']] ?? 0 ?></strong> &nbsp;
<?php endforeach; ?>
</p>
<div class="tablewrap">
<table>
  <tr><th>Code</th><th>Category</th><th>Title</th><th>Author</th><th>Email</th><th>Phone</th><th>Submitted</th><th>Avg score</th><th>Judge scores</th><th>File</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><strong><?= h($r['code']) ?></strong></td>
    <td><?= h($r['category_name']) ?></td>
    <td><?= h($r['title']) ?></td>
    <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
    <td><?= h($r['email']) ?></td>
    <td><?= h($r['phone']) ?></td>
    <td><?= h($r['created_at']) ?></td>
    <td><?= $r['avg_score'] === null ? '-' : h(number_format((float)$r['avg_score'], 2)) ?></td>
    <td>
      <?php foreach ($byEntry[$r['id']] ?? [] as $s): ?>
        <div title="<?= h($s['comments']) ?>"><?= h($s['first_name'] . ' ' . $s['last_name']) ?>: <strong><?= (int)$s['score'] ?></strong></div>
      <?php endforeach; ?>
    </td>
    <td><a href="download.php?id=<?= (int)$r['id'] ?>">Download</a></td>
    <td>
      <form method="post" onsubmit="return confirm('Delete this entry permanently?');">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="contest" value="<?= $cid ?>">
        <button type="submit" class="danger">Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="11">No entries in this contest.</td></tr><?php endif; ?>
</table>
</div>
<?php endif; ?>
<?php page_footer();
