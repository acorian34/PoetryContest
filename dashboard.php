<?php
require __DIR__ . '/lib.php';
$user = require_user();
$pdo = db();

$flash = $_SESSION['flash_dashboard'] ?? null;
unset($_SESSION['flash_dashboard']);

// Contests this person has entered that have not ended yet (past contests are on the history page)
$st = $pdo->prepare('SELECT c.*, ' . CONTEST_ACCEPTING . ' AS accepting, COUNT(e.id) AS used
                     FROM contests c JOIN entries e ON e.contest_id = c.id
                     WHERE e.person_id = ? AND c.end_date >= CURDATE()
                     GROUP BY c.id ORDER BY c.start_date DESC, c.id DESC');
$st->execute([$user['id']]);
$entered = $st->fetchAll();

$usedByContest = [];
$st = $pdo->prepare('SELECT contest_id, COUNT(*) FROM entries WHERE person_id = ? GROUP BY contest_id');
$st->execute([$user['id']]);
foreach ($st->fetchAll(PDO::FETCH_NUM) as [$cid, $n]) $usedByContest[(int)$cid] = (int)$n;

$open = all_contests(true);
usort($open, fn($a, $b) => [$b['start_date'], $b['id']] <=> [$a['start_date'], $a['id']]);

page_header('Dashboard', user_nav());
?>
<h1>Welcome, <?= h($user['first_name']) ?></h1>
<?php if ($flash): ?><div class="box ok"><?= h($flash) ?></div><?php endif; ?>
<p>
  <a class="btn alt" href="history.php">My past contests and entries</a>
  <a class="btn alt" href="profile.php">Edit or delete my profile</a>
</p>

<h2>Contests you have entered</h2>
<?php if (!$entered): ?>
  <p class="note">You have not entered a current contest yet. Pick one from the open contests below.</p>
<?php else: ?>
  <div class="tablewrap"><table>
    <tr><th>Contest</th><th>Start date</th><th>Close date</th><th>Status</th><th>Your entries</th><th></th></tr>
    <?php foreach ($entered as $c): ?>
    <tr>
      <td><a href="contest.php?id=<?= (int)$c['id'] ?>"><strong><?= h($c['name']) ?></strong></a></td>
      <td><?= h(fmt_date($c['start_date'])) ?></td>
      <td><?= h(fmt_date($c['end_date'])) ?></td>
      <td><?= h(status_label($c)) ?></td>
      <td><?= (int)$c['used'] ?> of <?= (int)$c['max_entries'] ?></td>
      <td><a href="contest.php?id=<?= (int)$c['id'] ?>">View</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>

<h2>Contests open for entries</h2>
<?php if (!$open): ?>
  <div class="box err">There are no contests open for entries right now.</div>
<?php else: ?>
  <ul class="contestlist">
  <?php foreach ($open as $c): $n = $usedByContest[(int)$c['id']] ?? 0; ?>
    <li>
      <strong><a href="contest.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></strong>
      <span class="note">&mdash; <?= h(fmt_date($c['start_date'])) ?> to <?= h(fmt_date($c['end_date'])) ?>
        &middot; <?= $n ?> of <?= (int)$c['max_entries'] ?> entries used</span>
      <?php if (trim((string)$c['description']) !== ''): ?><br><span class="note"><?= nl2br(h($c['description'])) ?></span><?php endif; ?>
      <br><a href="contest.php?id=<?= (int)$c['id'] ?>"><?= $n >= (int)$c['max_entries'] ? 'View details and rules' : 'View details, rules and enter' ?></a>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php page_footer();
