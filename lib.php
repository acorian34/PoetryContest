<?php
require __DIR__ . '/config.php';

session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR . '/files')) mkdir(DATA_DIR . '/files', 0755, true);
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** SQL condition for "accepting entries now": the admin open switch is on and today is within the start and end dates (inclusive). */
const CONTEST_ACCEPTING = "(status = 'open' AND CURDATE() BETWEEN start_date AND end_date)";

/** All contests, newest first, or only the ones accepting entries. */
function all_contests(bool $openOnly = false): array {
    $sql = 'SELECT *, ' . CONTEST_ACCEPTING . ' AS accepting FROM contests' . ($openOnly ? ' WHERE ' . CONTEST_ACCEPTING : '') . ' ORDER BY id DESC';
    return db()->query($sql)->fetchAll();
}

function find_contest(int $id): ?array {
    $st = db()->prepare('SELECT *, ' . CONTEST_ACCEPTING . ' AS accepting FROM contests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** The contest chosen with ?contest=ID, limited to the given list (so a judge cannot pick one they are not assigned to).
 *  Falls back to the first contest in the list, or null when the list is empty. */
function selected_contest(array $list): ?array {
    $id = (int)($_GET['contest'] ?? 0);
    foreach ($list as $c) if ((int)$c['id'] === $id) return $c;
    return $list[0] ?? null;
}

/** Small dropdown to switch between contests. */
function contest_picker(array $list, ?int $selectedId): string {
    $html = '<form method="get" class="yearform"><label>Contest <select name="contest" onchange="this.form.submit()">';
    foreach ($list as $c) {
        $html .= '<option value="' . (int)$c['id'] . '"' . ((int)$c['id'] === $selectedId ? ' selected' : '') . '>' . h($c['name']) . '</option>';
    }
    return $html . '</select></label></form>';
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** The logged-in entrant (a row from `users` with role contestant), or null. */
function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $st = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'contestant' AND deleted_at IS NULL");
            $st->execute([(int)$_SESSION['user_id']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

/**
 * Check an uploaded file is really a PDF, Word (.doc/.docx) or OpenDocument (.odt) file, not just named like one.
 * Returns an error message, or null if the file is acceptable.
 */
function check_upload(string $tmpPath, string $ext): ?string {
    $msg = 'Only ' . strtoupper(implode(', ', ALLOWED_EXT)) . ' files are accepted, and the file must be a valid one.';
    if (!in_array($ext, ALLOWED_EXT, true)) return $msg;
    $head = (string)file_get_contents($tmpPath, false, null, 0, 1024);

    switch ($ext) {
        case 'pdf':
            return strpos($head, '%PDF-') !== false ? null : $msg;
        case 'doc': // legacy Word = OLE2 compound file
            return strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0 ? null : $msg;
        case 'docx':
        case 'odt':
            if (strncmp($head, "PK\x03\x04", 4) !== 0) return $msg; // both are ZIP packages
            if (!class_exists('ZipArchive')) return null;           // zip extension missing: header check only
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) !== true) return $msg;
            $ok = $ext === 'docx'
                ? $zip->locateName('word/document.xml') !== false
                : trim((string)$zip->getFromName('mimetype')) === 'application/vnd.oasis.opendocument.text';
            $zip->close();
            return $ok ? null : $msg;
    }
    return $msg;
}


/** Send the visitor to the login page unless an entrant is logged in. */
function require_user(): array {
    $user = current_user();
    if (!$user) { header('Location: login.php'); exit; }
    return $user;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, refresh, and try again.');
    }
}

/** A contest's categories in display order. */
function contest_categories(int $contestId): array {
    $st = db()->prepare('SELECT * FROM contest_categories WHERE contest_id = ? AND removed_at IS NULL ORDER BY sort_order, id');
    $st->execute([$contestId]);
    return $st->fetchAll();
}

/** Short judging-code prefix for a category name (initials of its words, or its first letters), unique among $used. */
function category_prefix(string $name, array $used): string {
    $ascii = strtoupper((string)preg_replace('/[^A-Za-z0-9 ]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name));
    $words = preg_split('/\s+/', trim($ascii), -1, PREG_SPLIT_NO_EMPTY);
    $base = '';
    foreach ($words as $w) $base .= $w[0];
    if (strlen($base) < 2) $base = substr(str_replace(' ', '', $ascii), 0, 3);
    $base = substr($base !== '' ? $base : 'X', 0, 4);
    $prefix = $base;
    for ($i = 2; in_array($prefix, $used, true); $i++) $prefix = $base . $i;
    return $prefix;
}

/** Random, unique judging code like HS-482. Numbers are random, so they reveal nothing about entry order. */
function make_code(PDO $pdo, string $prefix, int $contestId): string {
    $exists = $pdo->prepare('SELECT 1 FROM entries WHERE contest_id = ? AND code = ?');
    for ($digits = 3; $digits <= 6; $digits++) {
        $min = (int)pow(10, $digits - 1);
        $max = (int)pow(10, $digits) - 1;
        for ($i = 0; $i < 50; $i++) {
            $code = $prefix . '-' . random_int($min, $max);
            $exists->execute([$contestId, $code]);
            if (!$exists->fetch()) return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique code.');
}

/** The logged-in staff member (a row from `users` whose role is judge or admin), or null.
 *  Checked against the database on every request, so demoting a judge takes effect immediately. */
function current_judge(): ?array {
    static $judge = false;
    if ($judge === false) {
        $judge = null;
        if (!empty($_SESSION['judge_id'])) {
            $st = db()->prepare("SELECT * FROM users WHERE id = ? AND role IN ('judge','admin')");
            $st->execute([(int)$_SESSION['judge_id']]);
            $judge = $st->fetch() ?: null;
        }
    }
    return $judge;
}

/** Contests the logged-in judge is assigned to (newest first), or null when not restricted (admins). */
function judge_contests(): ?array {
    $j = current_judge();
    if (!$j) return [];
    if ($j['role'] === 'admin') return null;
    $st = db()->prepare('SELECT c.* FROM contests c JOIN judge_assignments a ON a.contest_id = c.id
                         WHERE a.person_id = ? AND a.removed_at IS NULL ORDER BY c.id DESC');
    $st->execute([$j['id']]);
    return $st->fetchAll();
}

/** Whether the logged-in judge may view and score entries of this contest. */
function judge_can_access(int $contestId): bool {
    $list = judge_contests();
    if ($list === null) return true;
    foreach ($list as $c) if ((int)$c['id'] === $contestId) return true;
    return false;
}

/** Send the visitor to the login page unless logged in with the needed role (admin can see judge pages too).
 *  Judges and admins are database users; the role is re-read on every request so changes take effect immediately. */
function require_role(string $role): void {
    if (!empty($_SESSION['judge_id'])) {
        $j = current_judge();
        if ($j) {
            $_SESSION['role'] = $j['role'];
        } else {
            unset($_SESSION['role'], $_SESSION['judge_id']); // account removed or demoted
        }
    }
    $have = $_SESSION['role'] ?? '';
    if ($have !== $role && $have !== 'admin') {
        header('Location: login.php?as=' . $role);
        exit;
    }
}

function page_header(string $title, string $extraNav = ''): void { ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - <?= h(CONTEST_NAME) ?></title>
<link rel="icon" type="image/png" href="images/logo.png">
<link rel="stylesheet" href="style.css">
</head><body>
<header><div class="wrap"><a href="index.php" class="brand"><img src="images/logo.png" alt="" class="logo"><?= h(CONTEST_NAME) ?></a><?= $extraNav ?></div></header>
<main class="wrap">
<?php }

function page_footer(): void { ?>
</main></body></html>
<?php }

/** Navigation links shared by the administrator pages. */
function admin_nav(): string {
    return '<nav><a href="admin.php">Entries</a> <a href="contests.php">Contests</a> <a href="judges.php">Judges</a> '
         . '<a href="admins.php">Admins</a> <a href="report.php">Report</a> <a href="login.php?logout=1">Log out</a></nav>';
}

/** Status label for a contest row (needs the `accepting` column): the admin's status flag, plus whether the dates allow entries. */
function status_label(array $c): string {
    if ($c['accepting']) return 'Open';
    if ($c['status'] !== 'open') return ucfirst($c['status']);   // Closed, Cancelled, Delayed, Postponed
    return $c['start_date'] > date('Y-m-d') ? 'Open (not started yet)' : 'Closed (ended)';
}

/** Navigation for logged-in entrants. */
function user_nav(): string {
    return '<nav><a href="dashboard.php">Dashboard</a> <a href="history.php">My history</a> '
         . '<a href="profile.php">My profile</a> <a href="login.php?logout=1">Log out</a></nav>';
}

function fmt_date(string $d): string { return date('M j, Y', strtotime($d)); }

/** Contest status flags. Only "open" contests accept entries, and only between their start and end dates. */
const CONTEST_STATUSES = ['open', 'closed', 'cancelled', 'delayed', 'postponed'];
