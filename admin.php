<?php
require __DIR__ . '/lib.php';
require_role('admin');
$pdo = db();
$contests = all_contests();
$contest = selected_contest($contests);
$cid = $contest ? (int)$contest['id'] : 0;

// Remove or restore an entry. Nothing is deleted: the entry, its file and its scores stay in the database, flagged as removed.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entry_id'], $_POST['entry_action'])) {
    csrf_check();
    $flag = $_POST['entry_action'] === 'restore' ? 'NULL' : 'NOW()';
    $pdo->prepare("UPDATE entries SET removed_at = $flag WHERE id = ?")->execute([(int)$_POST['entry_id']]);
    header('Location: admin.php?contest=' . (int)($_POST['contest'] ?? 0));
    exit;
}

$rows = [];
$removed = [];
if ($contest) {
    $st = $pdo->prepare('SELECT e.id, e.code, e.title, cc.name AS category_name, cc.id AS category_id, e.file_orig, e.created_at,
                                p.first_name, p.last_name, p.email, p.phone, p.deleted_at,
                                (SELECT AVG(s.score) FROM scores s WHERE s.entry_id = e.id) AS avg_score,
                                (SELECT COUNT(*) FROM scores s WHERE s.entry_id = e.id) AS score_count
                         FROM entries e JOIN users p ON p.id = e.person_id
                         JOIN contest_categories cc ON cc.id = e.category_id
                         WHERE e.contest_id = ? AND e.removed_at IS NULL
                         ORDER BY cc.sort_order, cc.id, e.code');
    $st->execute([$cid]);
    $rows = $st->fetchAll();

    $st = $pdo->prepare('SELECT e.id, e.code, e.title, cc.name AS category_name, e.removed_at, p.first_name, p.last_name
                         FROM entries e JOIN users p ON p.id = e.person_id JOIN contest_categories cc ON cc.id = e.category_id
                         WHERE e.contest_id = ? AND e.removed_at IS NOT NULL ORDER BY e.removed_at DESC');
    $st->execute([$cid]);
    $removed = $st->fetchAll();

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
    <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?><?= $r['deleted_at'] ? ' <span class="note">(account closed)</span>' : '' ?></td>
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
      <form method="post" onsubmit="return confirm('Remove this entry from the contest? It stays on record and can be restored.');">
        <?= csrf_field() ?>
        <input type="hidden" name="entry_action" value="remove">
        <input type="hidden" name="entry_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="contest" value="<?= $cid ?>">
        <button type="submit" class="danger">Remove</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="11">No entries in this contest.</td></tr><?php endif; ?>
</table>
</div>
<?php if ($removed): ?>
<h2>Removed entries (<?= count($removed) ?>)</h2>
<p class="note">These stay on record (and can be checked against new submissions) but are hidden from judges and reports.</p>
<div class="tablewrap">
<table>
  <tr><th>Code</th><th>Category</th><th>Title</th><th>Author</th><th>Removed</th><th>File</th><th></th></tr>
  <?php foreach ($removed as $r): ?>
  <tr>
    <td><strong><?= h($r['code']) ?></strong></td>
    <td><?= h($r['category_name']) ?></td>
    <td><?= h($r['title']) ?></td>
    <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
    <td><?= h($r['removed_at']) ?></td>
    <td><a href="download.php?id=<?= (int)$r['id'] ?>">Download</a></td>
    <td>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="entry_action" value="restore">
        <input type="hidden" name="entry_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="contest" value="<?= $cid ?>">
        <button type="submit">Restore</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php page_footer();
