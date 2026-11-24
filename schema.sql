-- CCDI Student Evaluation Portal - MySQL schema
-- Run automatically by setup.php, or import manually into an empty database.

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role          ENUM('student','admin') NOT NULL DEFAULT 'student',
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL,
  sid           VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  course        VARCHAR(10) NULL,
  block         VARCHAR(10) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_sid (sid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS professors (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name  VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS allocations (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  professor_id INT UNSIGNED NOT NULL,
  course       VARCHAR(10) NOT NULL,
  block        VARCHAR(10) NOT NULL,
  subject      VARCHAR(120) NOT NULL,
  term         VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_alloc (professor_id, course, block, subject, term),
  KEY idx_alloc_lookup (course, block, term),
  CONSTRAINT fk_alloc_prof FOREIGN KEY (professor_id) REFERENCES professors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id    INT UNSIGNED NOT NULL,
  allocation_id INT UNSIGNED NOT NULL,
  teaching      TINYINT UNSIGNED NOT NULL,
  mastery       TINYINT UNSIGNED NOT NULL,
  punctuality   TINYINT UNSIGNED NOT NULL,
  comment       TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eval (student_id, allocation_id),
  CONSTRAINT fk_eval_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_eval_alloc FOREIGN KEY (allocation_id) REFERENCES allocations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  to_email   VARCHAR(150) NOT NULL,
  subject    VARCHAR(200) NOT NULL,
  body       TEXT NOT NULL,
  term       VARCHAR(60) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mail_student (student_id, term),
  CONSTRAINT fk_mail_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (k, v) VALUES ('active_term', '1st Semester, AY 2026-2027');
