<?php
require __DIR__ . '/lib.php';
require_role('judge');

$st = db()->prepare('SELECT code, contest_id, file_stored, file_ext FROM entries WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$e = $st->fetch();
if ($e && !judge_can_access((int)$e['contest_id'])) $e = false; // judge not assigned to this entry's contest
$path = $e ? DATA_DIR . '/files/' . basename($e['file_stored']) : '';
if (!$e || !is_file($path)) { http_response_code(404); exit('Not found.'); }

$types = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'odt'  => 'application/vnd.oasis.opendocument.text',
];
header('Content-Type: ' . ($types[$e['file_ext']] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $e['code'] . '.' . $e['file_ext'] . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
