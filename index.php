<?php
require __DIR__ . '/lib.php';

if (current_user()) { header('Location: dashboard.php'); exit; }

page_header('Welcome');
?>
<h1><?= h(CONTEST_NAME) ?></h1>
<p class="lead">Register for a free account, then enter one or more of the contests below by submitting your poems as documents.
Poems are judged anonymously in four categories:</p>
<ul>
  <?php foreach (CATEGORIES as [$label]): ?><li><?= h($label) ?></li><?php endforeach; ?>
</ul>

<?php $open = all_contests(true); ?>
<?php if (!$open): ?>
  <div class="box err">No contests are open for entries right now.</div>
<?php else: ?>
  <h2>Open contests</h2>
  <ul>
  <?php foreach ($open as $c): ?>
    <li><strong><?= h($c['name']) ?></strong> (<?= h(date('M j, Y', strtotime($c['start_date']))) ?> to <?= h(date('M j, Y', strtotime($c['end_date']))) ?>, up to <?= (int)$c['max_entries'] ?> poems per person)<?php if (trim((string)$c['description']) !== ''): ?><br><span class="note"><?= nl2br(h($c['description'])) ?></span><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
<p>
  <a class="btn" href="register.php">Register</a>
  <a class="btn alt" href="login.php">Log in</a>
</p>
<p class="note"><a href="login.php?as=judge">Judge login</a> &middot; <a href="login.php?as=admin">Admin login</a></p>
<?php page_footer();
