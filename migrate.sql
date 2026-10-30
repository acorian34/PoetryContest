-- Migration for an EXISTING database (MySQL 8) created from the original schema.sql.
-- Back up first. Run the whole file once. Fresh installs use schema.sql instead.
-- If you already ran an earlier version of this file (it named the table "people"), run only:  RENAME TABLE people TO users;

-- 1. Everyone (entrants, judges, admins) lives in the `users` table; add roles. Every existing account becomes a contestant.
RENAME TABLE people TO users;
ALTER TABLE users
  ADD COLUMN role ENUM('contestant','judge','admin') NOT NULL DEFAULT 'contestant' AFTER category,
  ADD KEY idx_users_role (role);

-- 2. Contests, judge assignments and scores.
CREATE TABLE contests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL,
  description  TEXT         NULL,
  rules        TEXT         NULL,
  start_date   DATE         NOT NULL,
  end_date     DATE         NOT NULL,
  max_entries  TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_open      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contests_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE judge_assignments (
  person_id   INT UNSIGNED NOT NULL,
  contest_id  INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (person_id, contest_id),
  KEY idx_assign_contest (contest_id),
  CONSTRAINT fk_assign_person  FOREIGN KEY (person_id)  REFERENCES users (id)   ON DELETE CASCADE,
  CONSTRAINT fk_assign_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. One contest per year that already has entries, named "Poetry Contest <year>", closed to new entries, with dates
--    covering that calendar year and no rules text yet. Edit them on the Contests page (add the rules, dates, open switch).
--    (Rename or open them afterwards on the Contests page.)
INSERT INTO contests (name, start_date, end_date, max_entries, is_open)
  SELECT CONCAT('Poetry Contest ', contest_year), MAKEDATE(contest_year, 1), MAKEDATE(contest_year + 1, 1) - INTERVAL 1 DAY, 2, 0
  FROM (SELECT DISTINCT contest_year FROM entries) y
  ORDER BY contest_year;

-- Optional: keep the current year open for entries (use your CONTEST_YEAR). Skipped if you set this via the admin page later.
-- UPDATE contests SET is_open = 1 WHERE name = 'Poetry Contest 2026';

-- 4. Point entries at their contest instead of a year.
ALTER TABLE entries ADD COLUMN rules_agreed_at TIMESTAMP NULL;   -- NULL for entries made before rules agreement existed
ALTER TABLE entries ADD COLUMN contest_id INT UNSIGNED NULL AFTER person_id;
UPDATE entries e JOIN contests c ON c.name = CONCAT('Poetry Contest ', e.contest_year) SET e.contest_id = c.id;
ALTER TABLE entries
  MODIFY contest_id INT UNSIGNED NOT NULL,
  ADD UNIQUE KEY uq_entries_contest_code (contest_id, code),
  ADD KEY idx_entries_person_contest (person_id, contest_id),
  ADD CONSTRAINT fk_entries_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE RESTRICT;
ALTER TABLE entries
  DROP INDEX uq_entries_year_code,
  DROP INDEX idx_entries_person_year,
  DROP COLUMN contest_year;

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

-- 5. The old global "submissions on/off" setting is replaced by a per-contest open switch. Safe to drop.
DROP TABLE IF EXISTS settings;

-- 6. Categories belong to each contest. Every contest created above gets the four original categories, entries are re-pointed,
--    and the old fixed category columns are removed. (Edit the lists on the Contests page.) If you ran an earlier version of this
--    file, run only this step 6.
CREATE TABLE contest_categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contest_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  code_prefix VARCHAR(6)   NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_cat_name (contest_id, name),
  UNIQUE KEY uq_cat_prefix (contest_id, code_prefix),
  CONSTRAINT fk_cat_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO contest_categories (contest_id, name, code_prefix, sort_order)
  SELECT c.id, x.name, x.prefix, x.ord FROM contests c JOIN (
    SELECT '5th-6th Grade' AS name, 'G56' AS prefix, 1 AS ord UNION ALL SELECT '7th-8th Grade', 'G78', 2
    UNION ALL SELECT 'High School', 'HS', 3 UNION ALL SELECT 'Adult', 'AD', 4) x;

ALTER TABLE entries ADD COLUMN category_id INT UNSIGNED NULL AFTER contest_id;
UPDATE entries e JOIN contest_categories cc ON cc.contest_id = e.contest_id
  AND cc.code_prefix = CASE e.category WHEN 'g56' THEN 'G56' WHEN 'g78' THEN 'G78' WHEN 'hs' THEN 'HS' ELSE 'AD' END
  SET e.category_id = cc.id;
ALTER TABLE entries
  MODIFY category_id INT UNSIGNED NOT NULL,
  ADD KEY idx_entries_category (category_id),
  ADD CONSTRAINT fk_entries_category FOREIGN KEY (category_id) REFERENCES contest_categories (id) ON DELETE RESTRICT;
ALTER TABLE entries DROP COLUMN category;
ALTER TABLE users DROP COLUMN category;

-- Then open setup.php in the browser and enter SETUP_KEY (config.php) to create the first administrator. Admins then manage
-- contests, judges and further administrators in the app. The shared admin password no longer exists.

-- 7. Accounts are never deleted: "delete my account" sets deleted_at, and entries are protected from removal along with the user.
--    If you ran an earlier version of this file, run only this step 7.
ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL AFTER role;
ALTER TABLE entries DROP FOREIGN KEY fk_entries_person;
ALTER TABLE entries ADD CONSTRAINT fk_entries_person FOREIGN KEY (person_id) REFERENCES users (id) ON DELETE RESTRICT;

-- 8. Contests have a status instead of an on/off switch, and nothing is ever deleted: contests, entries, categories and judge
--    assignments are flagged. If you ran an earlier version of this file, run only steps 8 and 9 (after step 7).
ALTER TABLE contests
  ADD COLUMN status ENUM('open','closed','cancelled','delayed','postponed') NOT NULL DEFAULT 'open' AFTER max_entries;
UPDATE contests SET status = IF(is_open = 1, 'open', 'closed');
ALTER TABLE contests DROP COLUMN is_open;

-- 9. "Removed" flags.
ALTER TABLE contest_categories ADD COLUMN removed_at TIMESTAMP NULL;
ALTER TABLE entries ADD COLUMN removed_at TIMESTAMP NULL AFTER rules_agreed_at;
ALTER TABLE judge_assignments ADD COLUMN removed_at TIMESTAMP NULL AFTER assigned_at;
