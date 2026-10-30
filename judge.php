<?php
require __DIR__ . '/lib.php';
require_role('judge');
$pdo = db();

$me = current_judge();                          // judge or admin account
$canScore = $me && $me['role'] === 'judge';       // admins can look at every contest but only assigned judges score
$contests = judge_contests() ?? all_contests(); // judges only get the contests they are assigned to
$contest = selected_contest($contests);
$cid = $contest ? (int)$contest['id'] : 0;

$errors = [];
$notice = $_SESSION['flash_score'] ?? null;
unset($_SESSION['flash_score']);

// Save a score
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $score = filter_var($_POST['score'] ?? '', FILTER_VALIDATE_INT);
    $comments = trim((string)($_POST['comments'] ?? ''));

    $st = $pdo->prepare('SELECT contest_id, code FROM entries WHERE id = ? AND removed_at IS NULL');
    $st->execute([$entryId]);
    $entry = $st->fetch();

    if (!$canScore) {
        $errors[] = 'Only judge accounts can record scores.';
    } elseif (!$entry || !judge_can_access((int)$entry['contest_id'])) {
        $errors[] = 'You are not a judge for that entry\'s contest.';
    } elseif ($score === false || $score < 1 || $score > SCORE_MAX) {
        $errors[] = 'Score must be a whole number from 1 to ' . SCORE_MAX . '.';
    } elseif (mb_strlen($comments) > 2000) {
        $errors[] = 'Comments must be 2000 characters or fewer.';
    } else {
        $pdo->prepare('INSERT INTO scores (entry_id, judge_id, score, comments) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE score = VALUES(score), comments = VALUES(comments)')
            ->execute([$entryId, $me['id'], $score, $comments]);
        $_SESSION['flash_score'] = 'Saved score for ' . $entry['code'] . '.';
        header('Location: judge.php?contest=' . (int)$entry['contest_id'] . '#e' . $entryId);
        exit;
    }
}

$byCat = [];
$mine = [];
if ($contest) {
    $st = $pdo->prepare('SELECT id, code, category_id, title FROM entries WHERE contest_id = ? AND removed_at IS NULL ORDER BY code');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) $byCat[$r['category_id']][] = $r;
    if ($canScore) {
        $st = $pdo->prepare('SELECT s.entry_id, s.score, s.comments FROM scores s JOIN entries e ON e.id = s.entry_id
                             WHERE s.judge_id = ? AND e.contest_id = ? AND e.removed_at IS NULL');
        $st->execute([$me['id'], $cid]);
        foreach ($st->fetchAll() as $s) $mine[$s['entry_id']] = $s;
    }
}
$total = array_sum(array_map('count', $byCat));

page_header('Judging', '<nav>' . ($me ? h($me['first_name'] . ' ' . $me['last_name']) . ' ' : '') . '<a href="login.php?logout=1">Log out</a></nav>');
?>
<?php if (!$contest): ?>
  <h1>Judging</h1>
  <div class="box err"><?= $canScore ? 'You have not been assigned to a contest yet. Please contact the contest administrator.' : 'There are no contests yet.' ?></div>
<?php else: ?>
<h1><?= h($contest['name']) ?>: entries for judging</h1>
<?= contest_picker($contests, $cid) ?>
<?php if ($notice): ?><div class="box ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="box err"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>
<p class="note">Entries are identified only by code. Score each poem from 1 to <?= SCORE_MAX ?> (<?= SCORE_MAX ?> is best); you can change a score later.
<?php if ($canScore): ?>You have scored <strong><?= count($mine) ?></strong> of <?= $total ?>.<?php endif; ?></p>
<?php foreach (contest_categories($cid) as $cc): $key = $cc['id']; ?>
  <h2><?= h($cc['name']) ?> (<?= count($byCat[$key] ?? []) ?>)</h2>
  <?php if (empty($byCat[$key])): ?>
    <p class="note">No entries.</p>
  <?php else: ?>
  <div class="tablewrap">
  <table>
    <tr><th>Code</th><th>Title</th><th>Poem</th><th>Your score</th></tr>
    <?php foreach ($byCat[$key] as $r): $s = $mine[$r['id']] ?? null; ?>
    <tr id="e<?= (int)$r['id'] ?>">
      <td><strong><?= h($r['code']) ?></strong></td>
      <td><?= h($r['title']) ?></td>
      <td><a href="download.php?id=<?= (int)$r['id'] ?>">Download</a></td>
      <td>
      <?php if ($canScore): ?>
        <form method="post" class="scoreform">
          <?= csrf_field() ?>
          <input type="hidden" name="entry_id" value="<?= (int)$r['id'] ?>">
          <input type="number" name="score" min="1" max="<?= SCORE_MAX ?>" required style="width:5em" value="<?= $s ? (int)$s['score'] : '' ?>">
          <input name="comments" maxlength="2000" placeholder="Comments (optional)" value="<?= h($s['comments'] ?? '') ?>">
          <button type="submit"><?= $s ? 'Update' : 'Save' ?></button>
        </form>
      <?php else: ?>-<?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
<?php page_footer();
