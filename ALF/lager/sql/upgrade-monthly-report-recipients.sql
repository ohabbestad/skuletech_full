ALTER TABLE lager_email_log
  MODIFY event_type ENUM('low_stock','test','monthly_report') NOT NULL;

CREATE TABLE IF NOT EXISTS lager_monthly_report_recipients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_name VARCHAR(160) NOT NULL DEFAULT '',
  recipient_email VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_sent_month CHAR(7) NOT NULL DEFAULT '',
  last_sent_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_lager_monthly_report_email (recipient_email),
  KEY idx_lager_monthly_report_active (active, last_sent_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lager_monthly_report_recipient_departments (
  recipient_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (recipient_id, department_id),
  KEY idx_lager_monthly_report_department (department_id),
  CONSTRAINT fk_lager_monthly_report_recipient
    FOREIGN KEY (recipient_id) REFERENCES lager_monthly_report_recipients(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT fk_lager_monthly_report_department
    FOREIGN KEY (department_id) REFERENCES lager_departments(id)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
