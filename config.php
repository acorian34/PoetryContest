<?php
// ---- EDIT THESE BEFORE GOING LIVE ----
const CONTEST_NAME    = 'Annual Poetry Contest';
const CONTEST_YEAR    = 2026;                // CHANGE THIS EACH YEAR: new entries are stamped with it
const ADMIN_PASSWORD  = 'change-me-admin';   // sees everything (names, contact info, CSV export)
const JUDGE_PASSWORD  = 'change-me-judge';   // sees only anonymous entry codes + poems
const MAX_ENTRIES     = 2;                   // per person, per contest year
const MAX_FILE_BYTES  = 5 * 1024 * 1024;     // 5 MB
const ENTRIES_OPEN    = true;                // default only; admin page toggle overrides it

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
