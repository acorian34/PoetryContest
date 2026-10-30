<?php
// ---- EDIT THESE BEFORE GOING LIVE ----
const CONTEST_NAME    = 'New Braunfels Writers Guild Contests';   // site title; the individual contests are created on the admin Contests page
const SETUP_KEY       = 'change-me-setup';   // only used once, on setup.php, to create the first administrator account
// Judges and administrators are database users with their own logins: create the first admin on setup.php, then add judges and admins in the app.
const DEFAULT_MAX_ENTRIES = 2;                // pre-filled when creating a contest; each contest has its own limit
const SCORE_MAX       = 10;                  // judges score each entry from 1 to this number
const MAX_FILE_BYTES  = 5 * 1024 * 1024;     // 5 MB

// MySQL connection
const DB_HOST = 'localhost';
const DB_PORT = 3306;
const DB_NAME = 'poetry_contest';
const DB_USER = 'poetry_user';
const DB_PASS = 'change-me-db';


const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'odt'];   // odt = OpenDocument (LibreOffice / OpenOffice)

// Uploaded poems are stored in DATA_DIR/files (blocked from the web by data/.htaccess).
// For best security, move this folder OUTSIDE your web root and change the path here.
const DATA_DIR = __DIR__ . '/data';
