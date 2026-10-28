<?php
require __DIR__ . '/lib.php';

if (current_user()) { header('Location: dashboard.php'); exit; }

page_header('Welcome');
?>
<h1><?= h(CONTEST_NAME) ?></h1>
<p class="lead">Register for a free account, then submit up to <?= MAX_ENTRIES ?> poems as documents.
Poems are judged anonymously in four categories:</p>
<ul>
  <?php foreach (CATEGORIES as [$label]): ?><li><?= h($label) ?></li><?php endforeach; ?>
</ul>

<?php if (!entries_open()): ?>
  <div class="box err">Contest submissions are currently closed.</div>
<?php endif; ?>
<p>
  <a class="btn" href="register.php">Register</a>
  <a class="btn alt" href="login.php">Log in</a>
</p>
<p class="note"><a href="login.php?as=judge">Judge login</a> &middot; <a href="login.php?as=admin">Admin login</a></p>
<?php page_footer();
