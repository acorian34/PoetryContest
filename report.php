<?php
// Contest report for administrators: every entry with the entrant's name, email, phone and score.
// Scores count as final once every judge assigned to the contest has scored every entry.
require __DIR__ . '/lib.php';
require_role('admin');
if (($_SESSION['role'] ?? '') !== 'admin') exit;
$pdo = db();

$contests = all_contests();
$contest = selected_contest($contests);
$cid = $contest ? (int)$contest['id'] : 0;

$judges = [];   // assigned judges, by id
$entries = [];
$scores = [];   // entry id => judge id => score
$expected = 0;
$given = 0;
$complete = false;

if ($contest) {
    $st = $pdo->prepare("SELECT u.id, u.first_name, u.last_name FROM judge_assignments a JOIN users u ON u.id = a.person_id
                         WHERE a.contest_id = ? AND a.removed_at IS NULL AND u.role = 'judge' ORDER BY u.last_name, u.first_name");
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $j) $judges[(int)$j['id']] = $j;

    $st = $pdo->prepare('SELECT e.id, e.code, e.title, cc.name AS category_name, cc.id AS category_id, e.created_at, u.first_name, u.last_name, u.email, u.phone, u.deleted_at
                         FROM entries e JOIN users u ON u.id = e.person_id
                         JOIN contest_categories cc ON cc.id = e.category_id
                         WHERE e.contest_id = ? AND e.removed_at IS NULL ORDER BY cc.sort_order, cc.id, e.code');
    $st->execute([$cid]);
    $entries = $st->fetchAll();

    $st = $pdo->prepare('SELECT s.entry_id, s.judge_id, s.score FROM scores s JOIN entries e ON e.id = s.entry_id WHERE e.contest_id = ? AND e.removed_at IS NULL');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $s) {
        if (isset($judges[(int)$s['judge_id']])) $scores[(int)$s['entry_id']][(int)$s['judge_id']] = (int)$s['score'];
    }

    $expected = count($entries) * count($judges);
    foreach ($scores as $byJudge) $given += count($byJudge);
    $complete = $expected > 0 && $given === $expected;

    // Average and rank within category (ties share a rank)
    foreach ($entries as &$e) {
        $s = $scores[(int)$e['id']] ?? [];
        $e['avg'] = $s ? array_sum($s) / count($s) : null;
        $e['rank'] = null;
    }
    unset($e);
    $byCat = [];
    foreach ($entries as $i => $e) if ($e['avg'] !== null) $byCat[$e['category_id']][] = $i;
    foreach ($byCat as $idx) {
        usort($idx, fn($a, $b) => $entries[$b]['avg'] <=> $entries[$a]['avg']);
        $rank = 0; $prev = null;
        foreach ($idx as $pos => $i) {
            if ($prev === null || abs($entries[$i]['avg'] - $prev) > 1e-9) $rank = $pos + 1;
            $entries[$i]['rank'] = $rank;
            $prev = $entries[$i]['avg'];
        }
    }
}

// CSV download: final once judging is complete; ?interim=1 downloads the scores so far
if ($contest && isset($_GET['csv']) && ($complete || isset($_GET['interim']))) {
    $safe = fn($s) => preg_match('/^[=+\-@\t\r]/', (string)$s) ? "'" . $s : $s; // block spreadsheet formula injection
    $status = $complete ? 'Final' : 'Interim';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="poetry-report-contest-' . $cid . '-' . strtolower($status) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    $head = ['Contest', 'Status', 'Code', 'Category', 'Rank in category', 'Title', 'First name', 'Last name', 'Email', 'Phone', 'Submitted', 'Account closed', 'Average score', 'Judges scored'];
    foreach ($judges as $j) $head[] = 'Score: ' . $j['first_name'] . ' ' . $j['last_name'];
    fputcsv($out, array_map($safe, $head), ',', '"', '');
    foreach ($entries as $e) {
        $s = $scores[(int)$e['id']] ?? [];
        $row = [$safe($contest['name']), $status, $e['code'], $e['category_name'], $e['rank'] ?? '', $safe($e['title']),
                $safe($e['first_name']), $safe($e['last_name']), $e['email'], $safe($e['phone']), $e['created_at'],
                $e['deleted_at'] ? 'Yes' : '', $e['avg'] === null ? '' : round($e['avg'], 2), count($s) . ' of ' . count($judges)];
        foreach ($judges as $jid => $_) $row[] = $s[$jid] ?? '';
        fputcsv($out, $row, ',', '"', '');
    }
    exit;
}

page_header('Report', admin_nav());
?>
<h1>Contest report</h1>
<?php if (!$contest): ?>
  <p class="note">No contests yet. <a href="contests.php">Create the first contest</a>.</p>
<?php else: ?>
<?= contest_picker($contests, $cid) ?>
<h2><?= h($contest['name']) ?></h2>

<?php if (!$entries): ?>
  <div class="box err">This contest has no entries.</div>
<?php elseif (!$judges): ?>
  <div class="box err">No judges are assigned to this contest, so there are no scores. <a href="judges.php">Assign judges</a>.</div>
<?php elseif ($complete): ?>
  <div class="box ok"><strong>Judging is complete.</strong> All <?= count($judges) ?> judge(s) have scored all <?= count($entries) ?> entries.</div>
<?php else: ?>
  <div class="box err"><strong>Judging is not complete.</strong> <?= $given ?> of <?= $expected ?> scores have been entered
    (<?= count($judges) ?> judge(s) &times; <?= count($entries) ?> entries). Scores below are interim.</div>
<?php endif; ?>

<?php if ($entries): ?>
<p>
  <?php if ($complete): ?>
    <a class="btn" href="report.php?contest=<?= $cid ?>&amp;csv=1">Download final report (CSV)</a>
  <?php else: ?>
    <a class="btn alt" href="report.php?contest=<?= $cid ?>&amp;csv=1&amp;interim=1">Download interim report (CSV)</a>
  <?php endif; ?>
</p>
<div class="tablewrap">
<table>
  <tr><th>Rank</th><th>Code</th><th>Category</th><th>Title</th><th>Name</th><th>Email</th><th>Phone</th>
    <?php foreach ($judges as $j): ?><th><?= h($j['first_name'] . ' ' . $j['last_name']) ?></th><?php endforeach; ?>
    <th>Average</th></tr>
  <?php foreach ($entries as $e): $s = $scores[(int)$e['id']] ?? []; ?>
  <tr>
    <td><?= $e['rank'] ?? '-' ?></td>
    <td><strong><?= h($e['code']) ?></strong></td>
    <td><?= h($e['category_name']) ?></td>
    <td><?= h($e['title']) ?></td>
    <td><?= h($e['first_name'] . ' ' . $e['last_name']) ?><?= $e['deleted_at'] ? ' <span class="note">(account closed)</span>' : '' ?></td>
    <td><?= h($e['email']) ?></td>
    <td><?= h($e['phone']) ?></td>
    <?php foreach ($judges as $jid => $_): ?><td><?= isset($s[$jid]) ? (int)$s[$jid] : '-' ?></td><?php endforeach; ?>
    <td><strong><?= $e['avg'] === null ? '-' : h(number_format($e['avg'], 2)) ?></strong></td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<p class="note">Rank is within each category, by average score; tied entries share a rank.</p>
<?php endif; ?>
<?php endif; ?>
<?php page_footer();
