-- Run through api/migrate.php: the PHP step copies the existing data atomically.
CREATE TABLE IF NOT EXISTS kantine_schema_versions (
  version INT NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kantine_revision (
  id INT NOT NULL PRIMARY KEY,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kantine_task_templates (
  effective_date DATE NOT NULL PRIMARY KEY,
  tasks_json MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kantine_day_state (
  date_id DATE NOT NULL PRIMARY KEY,
  staffing_json TEXT NULL,
  tasks_custom TINYINT(1) NOT NULL DEFAULT 0,
  tasks_json MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
