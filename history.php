<?php
// Every contest this person has entered (current and past) with their entries.
require __DIR__ . '/lib.php';
$user = require_user();
$pdo = db();

$st = $pdo->prepare('SELECT c.*, ' . CONTEST_ACCEPTING . ' AS accepting
                     FROM contests c
                     WHERE c.id IN (SELECT contest_id FROM entries WHERE person_id = ?)
                     ORDER BY c.start_date DESC, c.id DESC');
$st->execute([$user['id']]);
$contests = $st->fetchAll();

$byContest = [];
$st = $pdo->prepare('SELECT e.contest_id, e.title, cc.name AS category_name, e.file_orig, e.created_at, e.removed_at
                     FROM entries e JOIN contest_categories cc ON cc.id = e.category_id
                     WHERE e.person_id = ? ORDER BY e.id');
$st->execute([$user['id']]);
foreach ($st->fetchAll() as $e) $byContest[$e['contest_id']][] = $e;

page_header('My history', user_nav());
?>
<p><a href="dashboard.php">&larr; Dashboard</a></p>
<h1>My contests and entries</h1>
<?php if (!$contests): ?>
  <p class="note">You have not entered any contests yet.</p>
<?php endif; ?>
<?php foreach ($contests as $c): ?>
  <h2><a href="contest.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></h2>
  <p class="note"><?= h(fmt_date($c['start_date'])) ?> to <?= h(fmt_date($c['end_date'])) ?> &middot; <?= h(status_label($c)) ?></p>
  <div class="tablewrap"><table>
    <tr><th>Title</th><th>Category</th><th>File</th><th>Submitted</th></tr>
    <?php foreach ($byContest[$c['id']] ?? [] as $e): ?>
      <tr><td><?= h($e['title']) ?><?= $e['removed_at'] ? ' <span class="note">(removed by the organisers)</span>' : '' ?></td><td><?= h($e['category_name']) ?></td><td><?= h($e['file_orig']) ?></td><td><?= h($e['created_at']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endforeach; ?>
<?php page_footer();
