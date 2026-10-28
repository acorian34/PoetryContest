<?php
// ---- EDIT THESE BEFORE GOING LIVE ----
const CONTEST_NAME    = 'Annual Poetry Contest';   // site title; the individual contests are created on the admin Contests page
const ADMIN_PASSWORD  = 'change-me-admin';   // sees everything (names, contact info, CSV export) and manages judges
// Judges have no password here: the admin creates them on the Judges page (judges.php), each with their own login.
const DEFAULT_MAX_ENTRIES = 2;                // pre-filled when creating a contest; each contest has its own limit
const SCORE_MAX       = 10;                  // judges score each entry from 1 to this number
const MAX_FILE_BYTES  = 5 * 1024 * 1024;     // 5 MB

// MySQL connection
const DB_HOST = 'localhost';
const DB_PORT = 3306;
const DB_NAME = 'poetry_contest';
const DB_USER = 'poetry_user';
const DB_PASS = 'change-me-db';

// Category key => [label, code prefix used in judging numbers]
const CATEGORIES = [
    'g56'   => ['5th-6th Grade', 'G56'],
    'g78'   => ['7th-8th Grade', 'G78'],
    'hs'    => ['High School',   'HS'],
    'adult' => ['Adult',         'AD'],
];

const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'odt'];   // odt = OpenDocument (LibreOffice / OpenOffice)

// Uploaded poems are stored in DATA_DIR/files (blocked from the web by data/.htaccess).
// For best security, move this folder OUTSIDE your web root and change the path here.
const DATA_DIR = __DIR__ . '/data';
