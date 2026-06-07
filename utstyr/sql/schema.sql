CREATE TABLE IF NOT EXISTS utstyr_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  role ENUM('tilsett','admin') NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_utstyr_role (role),
  UNIQUE KEY uniq_utstyr_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utstyr_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_utstyr_category_name (name),
  KEY idx_utstyr_category_sort (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utstyr_locations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_utstyr_location_name (name),
  KEY idx_utstyr_location_sort (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utstyr_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  loan_mode ENUM('unique','quantity') NOT NULL DEFAULT 'unique',
  total_quantity INT UNSIGNED NOT NULL DEFAULT 1,
  default_loan_days INT UNSIGNED NOT NULL DEFAULT 7,
  qr_token VARCHAR(140) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_utstyr_item_name (name),
  UNIQUE KEY uniq_utstyr_item_qr_token (qr_token),
  KEY idx_utstyr_item_category (category_id, name),
  KEY idx_utstyr_item_location (location_id, name),
  CONSTRAINT fk_utstyr_items_category
    FOREIGN KEY (category_id) REFERENCES utstyr_categories(id)
    ON UPDATE CASCADE,
  CONSTRAINT fk_utstyr_items_location
    FOREIGN KEY (location_id) REFERENCES utstyr_locations(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utstyr_loans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  borrower_name VARCHAR(120) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  expected_return_date DATE NOT NULL,
  borrowed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  returned_at TIMESTAMP NULL DEFAULT NULL,
  returned_by_name VARCHAR(120) NULL DEFAULT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  return_note VARCHAR(255) NOT NULL DEFAULT '',
  created_by_role ENUM('tilsett','admin') NOT NULL DEFAULT 'tilsett',
  returned_by_role ENUM('tilsett','admin') NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_utstyr_loans_item_returned (item_id, returned_at),
  KEY idx_utstyr_loans_expected_return (expected_return_date, returned_at),
  KEY idx_utstyr_loans_borrower (borrower_name),
  CONSTRAINT fk_utstyr_loans_item
    FOREIGN KEY (item_id) REFERENCES utstyr_items(id)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO utstyr_categories (name, sort_order, active)
VALUES
  ('Naturfag', 10, 1),
  ('Koding og teknologi', 20, 1),
  ('Kunst og handverk', 30, 1),
  ('Musikk', 40, 1),
  ('Uteskule', 50, 1),
  ('Anna', 90, 1)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), active = 1;

INSERT INTO utstyr_locations (name, sort_order, active)
VALUES
  ('Bibliotek', 10, 1),
  ('Naturfagrom', 20, 1),
  ('Makerspace', 30, 1),
  ('Lager', 40, 1),
  ('Anna', 90, 1)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), active = 1;
