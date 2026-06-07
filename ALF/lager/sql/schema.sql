CREATE TABLE IF NOT EXISTS lager_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  role ENUM('driftsleiar','laerar') NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_lager_role (role),
  UNIQUE KEY uniq_lager_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  purchase_contact_name VARCHAR(160) NOT NULL DEFAULT '',
  purchase_contact_email VARCHAR(190) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_lager_category_name (name),
  KEY idx_lager_category_sort (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_lager_department_name (name),
  KEY idx_lager_department_sort (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  unit VARCHAR(40) NOT NULL DEFAULT 'stk',
  stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
  min_quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
  shelf_label VARCHAR(80) NOT NULL DEFAULT '',
  qr_token VARCHAR(140) NOT NULL,
  purchase_contact_name VARCHAR(160) NOT NULL DEFAULT '',
  purchase_contact_email VARCHAR(190) NOT NULL DEFAULT '',
  low_stock_notified_at TIMESTAMP NULL DEFAULT NULL,
  low_stock_notified_quantity DECIMAL(10,2) NULL DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_lager_item_name (name),
  UNIQUE KEY uniq_lager_item_qr_token (qr_token),
  KEY idx_lager_item_category (category_id, name),
  KEY idx_lager_item_low_stock (active, min_quantity, low_stock_notified_at),
  CONSTRAINT fk_lager_items_category
    FOREIGN KEY (category_id) REFERENCES lager_categories(id)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_movements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NULL,
  movement_type ENUM('in','out','count_adjustment') NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  stock_after DECIMAL(10,2) NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_by_role ENUM('public','driftsleiar','laerar') NOT NULL DEFAULT 'public',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lager_movements_item_time (item_id, created_at),
  KEY idx_lager_movements_department_time (department_id, created_at),
  KEY idx_lager_movements_type_time (movement_type, created_at),
  CONSTRAINT fk_lager_movements_item
    FOREIGN KEY (item_id) REFERENCES lager_items(id)
    ON UPDATE CASCADE,
  CONSTRAINT fk_lager_movements_department
    FOREIGN KEY (department_id) REFERENCES lager_departments(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_counts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  count_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_role ENUM('driftsleiar','laerar') NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_lager_counts_date (count_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_count_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  count_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  expected_quantity DECIMAL(10,2) NOT NULL,
  counted_quantity DECIMAL(10,2) NOT NULL,
  difference_quantity DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_lager_count_item (count_id, item_id),
  KEY idx_lager_count_lines_item (item_id),
  CONSTRAINT fk_lager_count_lines_count
    FOREIGN KEY (count_id) REFERENCES lager_counts(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_lager_count_lines_item
    FOREIGN KEY (item_id) REFERENCES lager_items(id)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_email_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  event_type ENUM('low_stock','test') NOT NULL,
  status ENUM('sent','failed','skipped') NOT NULL,
  recipient_name VARCHAR(160) NOT NULL DEFAULT '',
  recipient_email VARCHAR(190) NOT NULL DEFAULT '',
  subject VARCHAR(255) NOT NULL DEFAULT '',
  message TEXT NULL,
  error_message VARCHAR(255) NOT NULL DEFAULT '',
  created_by_role ENUM('system','public','driftsleiar','laerar') NOT NULL DEFAULT 'system',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lager_email_log_time (created_at),
  KEY idx_lager_email_log_item_time (item_id, created_at),
  KEY idx_lager_email_log_category_time (category_id, created_at),
  CONSTRAINT fk_lager_email_log_item
    FOREIGN KEY (item_id) REFERENCES lager_items(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT fk_lager_email_log_category
    FOREIGN KEY (category_id) REFERENCES lager_categories(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lager_categories (name, sort_order, active)
VALUES
  ('Tørrvarer', 10, 1),
  ('Krydder', 20, 1),
  ('Vaskemiddel', 30, 1),
  ('Matoppbevaring', 40, 1),
  ('Anna', 50, 1)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), active = 1;

INSERT INTO lager_departments (name, sort_order, active)
VALUES
  ('Arbeidslivsfag', 10, 1),
  ('Mat og helse', 20, 1),
  ('Spesped', 30, 1),
  ('Anna', 90, 1)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), active = 1;

INSERT INTO lager_items (category_id, name, unit, stock_quantity, min_quantity, shelf_label, qr_token, active)
VALUES
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Kveitemjøl', 'stk', 0, 0, '', 'kveitemjol', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Grov sammale kveitemjøl', 'stk', 0, 0, '', 'grov-sammale-kveitemjol', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Glutenfritt mjøl', 'stk', 0, 0, '', 'glutenfritt-mjol', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Havregryn', 'stk', 0, 0, '', 'havregryn', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Maizena', 'stk', 0, 0, '', 'maizena', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Potetmjøl', 'stk', 0, 0, '', 'potetmjol', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Sukker', 'stk', 0, 0, '', 'sukker', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Melis', 'stk', 0, 0, '', 'melis', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Bakepulver', 'stk', 0, 0, '', 'bakepulver', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Vaniljesukker', 'stk', 0, 0, '', 'vaniljesukker', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Rapsolje', 'stk', 0, 0, '', 'rapsolje', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Olivenolje', 'stk', 0, 0, '', 'olivenolje', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Eddik', 'stk', 0, 0, '', 'eddik', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Kvitvinseddik', 'stk', 0, 0, '', 'kvitvinseddik', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Fullkorn fusilli', 'stk', 0, 0, '', 'fullkorn-fusilli', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Tørrvarer'), 'Jasminris', 'stk', 0, 0, '', 'jasminris', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Salt', 'stk', 0, 0, '', 'salt', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Flaksalt', 'stk', 0, 0, '', 'flaksalt', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Grovsalt', 'stk', 0, 0, '', 'grovsalt', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Finmalt pepper', 'stk', 0, 0, '', 'finmalt-pepper', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Kanel', 'stk', 0, 0, '', 'kanel', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Kardemomme', 'stk', 0, 0, '', 'kardemomme', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Kvitlaukspulver', 'stk', 0, 0, '', 'kvitlaukspulver', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Paprikapulver', 'stk', 0, 0, '', 'paprikapulver', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Spisskummen', 'stk', 0, 0, '', 'spisskummen', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Karri', 'stk', 0, 0, '', 'karri', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Oregano', 'stk', 0, 0, '', 'oregano', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Rosmarin', 'stk', 0, 0, '', 'rosmarin', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Timian', 'stk', 0, 0, '', 'timian', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Garam masala', 'stk', 0, 0, '', 'garam-masala', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Krydder'), 'Valmuefrø', 'stk', 0, 0, '', 'valmuefro', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Vaskemiddel'), 'Oppvaskmiddel', 'stk', 0, 0, '', 'oppvaskmiddel', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Vaskemiddel'), 'Ovnspray', 'stk', 0, 0, '', 'ovnspray', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Matoppbevaring'), 'Bakepapir', 'stk', 0, 0, '', 'bakepapir', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Matoppbevaring'), 'Aluminiumsfolie', 'stk', 0, 0, '', 'aluminiumsfolie', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Matoppbevaring'), 'Brødposer', 'stk', 0, 0, '', 'brodposer', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Matoppbevaring'), 'Plastfolie', 'stk', 0, 0, '', 'plastfolie', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Matoppbevaring'), 'Bioposer', 'stk', 0, 0, '', 'bioposer', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Anna'), 'Oppvaskkostar', 'stk', 0, 0, '', 'oppvaskkostar', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Anna'), 'Vaskefiller', 'stk', 0, 0, '', 'vaskefiller', 1),
  ((SELECT id FROM lager_categories WHERE name = 'Anna'), 'Tørkefiller', 'stk', 0, 0, '', 'torkefiller', 1)
ON DUPLICATE KEY UPDATE
  category_id = VALUES(category_id),
  qr_token = VALUES(qr_token),
  active = 1;
