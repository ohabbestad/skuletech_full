ALTER TABLE lager_categories
  ADD COLUMN purchase_contact_name VARCHAR(160) NOT NULL DEFAULT '' AFTER active,
  ADD COLUMN purchase_contact_email VARCHAR(190) NOT NULL DEFAULT '' AFTER purchase_contact_name;

ALTER TABLE lager_items
  ADD COLUMN purchase_contact_name VARCHAR(160) NOT NULL DEFAULT '' AFTER qr_token,
  ADD COLUMN purchase_contact_email VARCHAR(190) NOT NULL DEFAULT '' AFTER purchase_contact_name,
  ADD COLUMN low_stock_notified_at TIMESTAMP NULL DEFAULT NULL AFTER purchase_contact_email,
  ADD COLUMN low_stock_notified_quantity DECIMAL(10,2) NULL DEFAULT NULL AFTER low_stock_notified_at,
  ADD KEY idx_lager_item_low_stock (active, min_quantity, low_stock_notified_at);

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
