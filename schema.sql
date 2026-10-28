-- Fresh install (MySQL 8). Existing databases: run migrate.sql instead.

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(40)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name    VARCHAR(80)  NOT NULL,
  last_name     VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(40)  NOT NULL,
  category      VARCHAR(10)  NOT NULL,   -- default category, updated each time they submit (empty for judges)
  role          ENUM('contestant','judge','admin') NOT NULL DEFAULT 'contestant',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contests are created by the admin (contests.php). Several can be open at the same time.
CREATE TABLE contests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL,
  description  TEXT         NULL,
  rules        TEXT         NULL,                  -- contest rules; entrants must agree to them when submitting
  start_date   DATE         NOT NULL,              -- entries accepted from this date (inclusive)
  end_date     DATE         NOT NULL,              -- through this date (inclusive)
  max_entries  TINYINT UNSIGNED NOT NULL DEFAULT 2,   -- poems allowed per person in this contest
  is_open      TINYINT(1)   NOT NULL DEFAULT 1,       -- admin switch: 0 closes the contest regardless of dates
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contests_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id    INT UNSIGNED NOT NULL,
  contest_id   INT UNSIGNED NOT NULL,
  title        VARCHAR(150) NOT NULL,
  category     VARCHAR(10)  NOT NULL,
  code         VARCHAR(16)  NOT NULL,
  file_stored  VARCHAR(64)  NOT NULL,
  file_ext     VARCHAR(10)  NOT NULL,
  file_orig    VARCHAR(255) NOT NULL,
  rules_agreed_at TIMESTAMP NULL,                  -- when the entrant ticked the rules checkbox
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entries_contest_code (contest_id, code),
  KEY idx_entries_person_contest (person_id, contest_id),
  CONSTRAINT fk_entries_person FOREIGN KEY (person_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_entries_contest FOREIGN KEY (contest_id)
    REFERENCES contests (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which judge is assigned to which contest. A judge can be assigned to several contests.
CREATE TABLE judge_assignments (
  person_id   INT UNSIGNED NOT NULL,
  contest_id  INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
