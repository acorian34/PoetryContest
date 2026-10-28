CREATE TABLE people (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(40)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name    VARCHAR(80)  NOT NULL,
  last_name     VARCHAR(80)  NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(40)  NOT NULL,
  category      VARCHAR(10)  NOT NULL,   -- default category, updated each time they submit
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_people_username (username),
  UNIQUE KEY uq_people_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Site settings (admin submissions on/off toggle). A missing 'entries_open' row means the ENTRIES_OPEN default in config.php.
-- Existing databases: no migration needed, the table is created automatically the first time the admin toggle is used.
-- Or run this statement once by hand.
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(40)  NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id    INT UNSIGNED NOT NULL,
  contest_year SMALLINT UNSIGNED NOT NULL,
  title        VARCHAR(150) NOT NULL,
  category     VARCHAR(10)  NOT NULL,
  code         VARCHAR(16)  NOT NULL,
  file_stored  VARCHAR(64)  NOT NULL,
  file_ext     VARCHAR(10)  NOT NULL,
  file_orig    VARCHAR(255) NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entries_year_code (contest_year, code),
  KEY idx_entries_person_year (person_id, contest_year),
  CONSTRAINT fk_entries_person FOREIGN KEY (person_id)
    REFERENCES people (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
