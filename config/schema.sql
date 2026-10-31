-- AI-detect experiment schema (MariaDB/MySQL)
-- Research DB: sessions, trials, images   |  Prize DB: prize_contacts (isolated credentials)

CREATE DATABASE IF NOT EXISTS ai_detect_research CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ai_detect_prize CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -------- research --------
USE ai_detect_research;

CREATE TABLE IF NOT EXISTS images (
  image_id     VARCHAR(64) PRIMARY KEY,
  file         VARCHAR(160) NOT NULL,
  source       ENUM('real','gan','sd15') NOT NULL,
  psi          VARCHAR(4) NOT NULL DEFAULT '',
  gender       ENUM('Male','Female') NOT NULL,
  gender_conf  DECIMAL(4,3) NOT NULL DEFAULT 0,
  phash        CHAR(16) NOT NULL DEFAULT '',
  role         ENUM('pool','practice') NOT NULL DEFAULT 'pool',
  last_used_at DATETIME NULL,
  INDEX idx_cell (source, gender, role, last_used_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  session_id     CHAR(24) PRIMARY KEY,
  lang           ENUM('th','en') NOT NULL,
  birth_year     SMALLINT NOT NULL,
  cohort         ENUM('genx','millennial','genz','other') NOT NULL,
  gender         VARCHAR(16) NOT NULL DEFAULT '',
  device         VARCHAR(250) NOT NULL DEFAULT '',
  screen         VARCHAR(40)  NOT NULL DEFAULT '',
  plan           LONGTEXT NOT NULL,             -- JSON trial plan (server-generated)
  alias          VARCHAR(24) NOT NULL,          -- detective codename, unique per session
  familiarity_1  TINYINT NULL,                  -- v2 survey: yes/no items 1/0
  familiarity_2  TINYINT NULL,                  -- freq follow-up 0-4
  familiarity_3  TINYINT NULL,
  familiarity_4  TINYINT NULL,
  familiarity_5  TINYINT NULL,
  familiarity_6  TINYINT NULL,
  familiarity_7  TINYINT NULL,
  familiarity_8  TINYINT NULL,                  -- self-rated familiarity 1-5
  consent        TINYINT NULL,                  -- NULL until answered (post-game/quit)
  completed      TINYINT NOT NULL DEFAULT 0,
  repeat_tag     VARCHAR(64) NOT NULL DEFAULT '',  -- browser cookie id; one-play lock when TEST_MODE off
  created_at     DATETIME NOT NULL,
  finished_at    DATETIME NULL,
  UNIQUE KEY uq_alias (alias),
  INDEX idx_repeat (repeat_tag)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trials (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id    CHAR(24) NOT NULL,
  round         ENUM('practice','1','2') NOT NULL,
  trial_index   INT NOT NULL,
  image_id      VARCHAR(64) NOT NULL,
  pair_image_id VARCHAR(64) NULL,               -- other image of the 2AFC pair (round 1)
  image_type    ENUM('real','gan','diffusion') NOT NULL,
  response      TINYINT NOT NULL,               -- 1 = AI chosen / yes, 0 = real chosen / no
  correct       TINYINT NOT NULL,
  rt_ms         INT NOT NULL,
  created_at    DATETIME NOT NULL,
  UNIQUE KEY uq_trial (session_id, round, trial_index),
  CONSTRAINT fk_trials_session FOREIGN KEY (session_id) REFERENCES sessions(session_id)
) ENGINE=InnoDB;

-- -------- prize (separate DB, separate credentials) --------
USE ai_detect_prize;

CREATE TABLE IF NOT EXISTS prize_contacts (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alias      VARCHAR(24) NOT NULL,
  contact    VARCHAR(250) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_alias (alias)
) ENGINE=InnoDB;

-- -------- credential isolation (dev passwords; override in deployment) --------
CREATE USER IF NOT EXISTS 'research_app'@'localhost' IDENTIFIED BY 'research_dev_pw';
CREATE USER IF NOT EXISTS 'prize_app'@'localhost'    IDENTIFIED BY 'prize_dev_pw';
GRANT ALL PRIVILEGES ON ai_detect_research.* TO 'research_app'@'localhost';
GRANT ALL PRIVILEGES ON ai_detect_prize.*    TO 'prize_app'@'localhost';
-- research_app must NOT see ai_detect_prize and vice versa (grants above are the only ones)
FLUSH PRIVILEGES;
