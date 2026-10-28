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

/** Whether new registrations and submissions are accepted. The admin page toggle is stored in the `settings` table;
 *  until it has been used (or if the table is missing) the ENTRIES_OPEN constant in config.php applies. */
function entries_open(): bool {
    static $open = null;
    if ($open === null) {
        $open = ENTRIES_OPEN;
        try {
            $v = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'entries_open'")->fetchColumn();
            if ($v !== false) $open = $v === '1';
        } catch (Throwable $e) { /* settings table not created yet: keep the config default */ }
    }
    return $open;
}

function set_entries_open(bool $open): void {
    db()->exec('CREATE TABLE IF NOT EXISTS settings (
        setting_key   VARCHAR(40)  NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('entries_open', ?)")
        ->execute([$open ? '1' : '0']);
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** The logged-in entrant (a row from `people`), or null. */
function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $st = db()->prepare('SELECT * FROM people WHERE id = ?');
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

/** Contest year chosen on the admin/judge pages (?year=2026); defaults to the current contest year. */
function selected_year(): int {
    $y = (int)($_GET['year'] ?? 0);
    return ($y >= 2000 && $y <= 2200) ? $y : CONTEST_YEAR;
}

/** Small dropdown to switch between contest years that have entries. */
function year_picker(int $selected): string {
    $years = db()->query('SELECT DISTINCT contest_year FROM entries')->fetchAll(PDO::FETCH_COLUMN);
    $years[] = CONTEST_YEAR;
    $years[] = $selected;
    $years = array_unique(array_map('intval', $years));
    rsort($years);
    $html = '<form method="get" class="yearform"><label>Contest year <select name="year" onchange="this.form.submit()">';
    foreach ($years as $y) {
        $html .= '<option value="' . $y . '"' . ($y === $selected ? ' selected' : '') . '>' . $y . '</option>';
    }
    return $html . '</select></label></form>';
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

/** Random, unique judging code like HS-482. Numbers are random, so they reveal nothing about entry order. */
function make_code(PDO $pdo, string $category, int $year): string {
    $prefix = CATEGORIES[$category][1];
    $exists = $pdo->prepare('SELECT 1 FROM entries WHERE contest_year = ? AND code = ?');
    for ($digits = 3; $digits <= 6; $digits++) {
        $min = (int)pow(10, $digits - 1);
        $max = (int)pow(10, $digits) - 1;
        for ($i = 0; $i < 50; $i++) {
            $code = $prefix . '-' . random_int($min, $max);
            $exists->execute([$year, $code]);
            if (!$exists->fetch()) return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique code.');
}

/** Send the visitor to the login page unless logged in with the needed role (admin can see judge pages too). */
function require_role(string $role): void {
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
<link rel="stylesheet" href="style.css">
</head><body>
<header><div class="wrap"><a href="index.php" class="brand"><?= h(CONTEST_NAME) ?></a><?= $extraNav ?></div></header>
<main class="wrap">
<?php }

function page_footer(): void { ?>
</main></body></html>
<?php }
