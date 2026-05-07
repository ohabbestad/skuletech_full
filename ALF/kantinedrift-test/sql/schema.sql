CREATE TABLE IF NOT EXISTS kantine_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  role ENUM('tilsett','driftsleiar','laerar') NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_role (role),
  UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kantine_calendar_days (
  date_id DATE NOT NULL,
  week_no INT NOT NULL,
  turnus_type VARCHAR(20) NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  merknad VARCHAR(255) NOT NULL DEFAULT '',
  tidleg_overstyre TEXT NULL,
  sent_overstyre TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (date_id),
  KEY idx_week_no (week_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kantine_settings (
  setting_key VARCHAR(160) NOT NULL,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kantine_task_list (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  shift_name ENUM('early','late') NOT NULL,
  sort_order INT NOT NULL,
  label VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_shift_order (shift_name, sort_order),
  KEY idx_shift_order (shift_name, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kantine_values (
  value_kind ENUM('attendance','tasks','menus') NOT NULL,
  date_id DATE NOT NULL,
  item_key VARCHAR(180) NOT NULL,
  value_text TEXT NULL,
  value_bool TINYINT(1) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (value_kind, date_id, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kantine_substitutes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  date_id DATE NOT NULL,
  shift_name ENUM('tidleg','seint') NOT NULL,
  student_name VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_date_shift (date_id, shift_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO kantine_task_list (shift_name, sort_order, label)
VALUES
  ('early', 10, 'Finn fram varer og utstyr'),
  ('early', 20, 'Gjer klar serveringsområdet'),
  ('early', 30, 'Sjekk at menyen stemmer'),
  ('late', 10, 'Rydd serveringsområdet'),
  ('late', 20, 'Vask benkar og utstyr'),
  ('late', 30, 'Sett på plass varer')
ON DUPLICATE KEY UPDATE label = VALUES(label);
