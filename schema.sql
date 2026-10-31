-- Fresh install (MySQL 8). Existing databases: run migrate.sql instead.

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(40)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name    VARCHAR(80)  NOT NULL,
  last_name     VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(40)  NOT NULL,
  role          ENUM('contestant','judge','admin') NOT NULL DEFAULT 'contestant',
  deleted_at    TIMESTAMP NULL,   -- set when the user deletes their account; the row and their entries are kept
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contests are created by the admin (contests.php). Several can be open at the same time.
CREATE TABLE contests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_code      VARCHAR(10)  NOT NULL,                  -- short contest ID, e.g. SPR26; entry IDs are this plus a random 4-digit number
  name         VARCHAR(150) NOT NULL,
  description  TEXT         NULL,
  rules        TEXT         NULL,                  -- contest rules; entrants must agree to them when submitting
  start_date   DATE         NOT NULL,              -- entries accepted from this date (inclusive)
  end_date     DATE         NOT NULL,              -- through this date (inclusive)
  max_entries  TINYINT UNSIGNED NOT NULL DEFAULT 2,   -- poems allowed per person in this contest
  status       ENUM('open','closed','cancelled','delayed','postponed') NOT NULL DEFAULT 'open',   -- only "open" contests accept entries, and only between the dates
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contests_idcode (id_code),
  UNIQUE KEY uq_contests_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each contest has its own list of categories (set by the admin). code_prefix starts the judging codes, e.g. HS-482.
CREATE TABLE contest_categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contest_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  code_prefix VARCHAR(6)   NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  removed_at  TIMESTAMP NULL,   -- flagged instead of deleted
  UNIQUE KEY uq_cat_name (contest_id, name),
  UNIQUE KEY uq_cat_prefix (contest_id, code_prefix),
  CONSTRAINT fk_cat_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id    INT UNSIGNED NOT NULL,
  contest_id   INT UNSIGNED NOT NULL,
  title        VARCHAR(150) NOT NULL,
  category_id  INT UNSIGNED NOT NULL,
  code         VARCHAR(16)  NOT NULL,   -- entry ID shown to judges, e.g. SPR26-4821 (contest ID code + random 4 digits)
  file_stored  VARCHAR(64)  NOT NULL,
  file_ext     VARCHAR(10)  NOT NULL,
  file_orig    VARCHAR(255) NOT NULL,
  rules_agreed_at TIMESTAMP NULL,                  -- when the entrant ticked the rules checkbox
  removed_at   TIMESTAMP NULL,                  -- set when an admin removes the entry; the row and file are kept
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entries_contest_code (contest_id, code),
  KEY idx_entries_person_contest (person_id, contest_id),
  KEY idx_entries_category (category_id),
  CONSTRAINT fk_entries_person FOREIGN KEY (person_id)
    REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_entries_contest FOREIGN KEY (contest_id)
    REFERENCES contests (id) ON DELETE RESTRICT,
  CONSTRAINT fk_entries_category FOREIGN KEY (category_id)
    REFERENCES contest_categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which judge is assigned to which contest. A judge can be assigned to several contests.
CREATE TABLE judge_assignments (
  person_id   INT UNSIGNED NOT NULL,
  contest_id  INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at  TIMESTAMP NULL,   -- set when the judge is unassigned; the row is kept
  PRIMARY KEY (person_id, contest_id),
  KEY idx_assign_contest (contest_id),
  CONSTRAINT fk_assign_person  FOREIGN KEY (person_id)  REFERENCES users (id)   ON DELETE CASCADE,
  CONSTRAINT fk_assign_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One score per judge per entry (1 to SCORE_MAX from config.php), with an optional comment.
CREATE TABLE scores (
  entry_id   INT UNSIGNED NOT NULL,
  judge_id   INT UNSIGNED NOT NULL,
  score      TINYINT UNSIGNED NOT NULL,
  comments   VARCHAR(2000) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (entry_id, judge_id),
  KEY idx_scores_judge (judge_id),
  CONSTRAINT fk_scores_entry FOREIGN KEY (entry_id) REFERENCES entries (id) ON DELETE CASCADE,
  CONSTRAINT fk_scores_judge FOREIGN KEY (judge_id) REFERENCES users (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
