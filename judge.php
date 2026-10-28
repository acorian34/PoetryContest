<?php
require __DIR__ . '/lib.php';
require_role('judge');

$year = selected_year();
$st = db()->prepare('SELECT id, code, category, title FROM entries WHERE contest_year = ? ORDER BY category, code');
$st->execute([$year]);
$rows = $st->fetchAll();
$byCat = [];
foreach ($rows as $r) $byCat[$r['category']][] = $r;

page_header('Judging', '<nav><a href="login.php?logout=1">Log out</a></nav>');
?>
<h1><?= $year ?> entries for judging</h1>
<?= year_picker($year) ?>
<p class="note">Entries are identified only by code. Use the code when recording scores.</p>
<?php foreach (CATEGORIES as $key => [$label]): ?>
  <h2><?= h($label) ?> (<?= count($byCat[$key] ?? []) ?>)</h2>
  <?php if (empty($byCat[$key])): ?>
    <p class="note">No entries.</p>
  <?php else: ?>
  <div class="tablewrap">
  <table>
    <tr><th>Code</th><th>Title</th><th>Poem</th></tr>
    <?php foreach ($byCat[$key] as $r): ?>
    <tr>
      <td><strong><?= h($r['code']) ?></strong></td>
      <td><?= h($r['title']) ?></td>
      <td><a href="download.php?id=<?= (int)$r['id'] ?>">Download</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
<?php endforeach; ?>
<?php page_footer();
