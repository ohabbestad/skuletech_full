-- KantineVeke 2: import this WHOLE file through phpMyAdmin (UTF-8).
-- Requires MySQL 5.7.8+ / MariaDB 10.2.3+, InnoDB and JSON functions.
-- Stop application writes and take a database backup BEFORE importing.
-- No stored procedures, SSH, database names or passwords are needed.
-- Do not import schema.sql into an existing installation.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len = 16777216;

-- DDL commits implicitly. These empty tables are safe to create again.
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

START TRANSACTION;
INSERT IGNORE INTO kantine_revision (id, revision) VALUES (1, 0);
SELECT revision INTO @kv2_revision FROM kantine_revision WHERE id = 1 FOR UPDATE;
SET @kv2_done = EXISTS(SELECT 1 FROM kantine_schema_versions WHERE version >= 2);
SET @kv2_clean = NOT EXISTS(SELECT 1 FROM kantine_task_templates)
  AND NOT EXISTS(SELECT 1 FROM kantine_day_state)
  AND NOT EXISTS(SELECT 1 FROM kantine_values WHERE value_kind = 'tasks' AND LEFT(item_key, 7) = 'legacy_');

-- Build ordered JSON without JSON_ARRAYAGG / window functions (MySQL 5.7).
SELECT CONCAT('[', COALESCE(GROUP_CONCAT(
  JSON_OBJECT('id', CONCAT('legacy_', id), 'label', label, 'shift', shift_name, 'weekday', 0)
  ORDER BY shift_name, sort_order, id SEPARATOR ','), ''), ']'), COUNT(*)
INTO @kv2_template, @kv2_task_count FROM kantine_task_list;
SELECT CONCAT('[', COALESCE(GROUP_CONCAT(
  JSON_OBJECT('id', CONCAT('legacy_', id), 'label', label, 'shift', shift_name)
  ORDER BY shift_name, sort_order, id SEPARATOR ','), ''), ']')
INTO @kv2_tasks FROM kantine_task_list;
SET @kv2_json_ok = JSON_VALID(@kv2_template) AND JSON_VALID(@kv2_tasks);
SET @kv2_json_ok = @kv2_json_ok AND JSON_LENGTH(@kv2_template) = @kv2_task_count
  AND JSON_LENGTH(@kv2_tasks) = @kv2_task_count;

-- Temporary tables are connection-local and contain only expected new rows.
DROP TEMPORARY TABLE IF EXISTS kv2_expected_days;
CREATE TEMPORARY TABLE kv2_expected_days (
  date_id DATE NOT NULL PRIMARY KEY,
  staffing_json TEXT NULL,
  tasks_json MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO kv2_expected_days (date_id, staffing_json, tasks_json)
SELECT dates.date_id,
  CASE WHEN OCTET_LENGTH(COALESCE(c.tidleg_overstyre, '')) > 0
         OR OCTET_LENGTH(COALESCE(c.sent_overstyre, '')) > 0
  THEN JSON_OBJECT(
    'leader', COALESCE(leader.setting_value, ''),
    'early', JSON_EXTRACT(CONCAT('[', REPLACE(JSON_QUOTE(
      CASE WHEN OCTET_LENGTH(COALESCE(c.tidleg_overstyre, '')) > 0
           THEN c.tidleg_overstyre ELSE COALESCE(early_staff.setting_value, '') END
    ), ',', '","'), ']'), '$'),
    'late', JSON_EXTRACT(CONCAT('[', REPLACE(JSON_QUOTE(
      CASE WHEN OCTET_LENGTH(COALESCE(c.sent_overstyre, '')) > 0
           THEN c.sent_overstyre ELSE COALESCE(late_staff.setting_value, '') END
    ), ',', '","'), ']'), '$')
  ) ELSE NULL END,
  @kv2_tasks
FROM (
  SELECT date_id FROM kantine_calendar_days
  UNION SELECT date_id FROM kantine_values WHERE value_kind = 'tasks'
) AS dates
LEFT JOIN kantine_calendar_days c ON c.date_id = dates.date_id
LEFT JOIN kantine_settings leader ON BINARY leader.setting_key = BINARY CONCAT(
  'driftsleiar_', ELT(WEEKDAY(c.date_id) + 1, 'Måndag', 'Tysdag', 'Onsdag', 'Torsdag', 'Fredag', 'Laurdag', 'Sundag'))
LEFT JOIN kantine_settings early_staff ON c.turnus_type IN ('A','B','C') AND BINARY early_staff.setting_key = BINARY CONCAT(
  'turnus_', c.turnus_type, '_', ELT(WEEKDAY(c.date_id) + 1, 'Måndag', 'Tysdag', 'Onsdag', 'Torsdag', 'Fredag', 'Laurdag', 'Sundag'), '_tidleg')
LEFT JOIN kantine_settings late_staff ON c.turnus_type IN ('A','B','C') AND BINARY late_staff.setting_key = BINARY CONCAT(
  'turnus_', c.turnus_type, '_', ELT(WEEKDAY(c.date_id) + 1, 'Måndag', 'Tysdag', 'Onsdag', 'Torsdag', 'Fredag', 'Laurdag', 'Sundag'), '_seint')
WHERE NOT @kv2_done AND @kv2_clean AND @kv2_json_ok;

-- Match zero-based legacy indexes by order, not by the database's task ID.
DROP TEMPORARY TABLE IF EXISTS kv2_expected_checks;
CREATE TEMPORARY TABLE kv2_expected_checks (
  date_id DATE NOT NULL,
  item_key VARCHAR(180) NOT NULL,
  value_bool TINYINT(1) NOT NULL,
  PRIMARY KEY (date_id, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO kv2_expected_checks (date_id, item_key, value_bool)
SELECT v.date_id, CONCAT('legacy_', task.id), (COALESCE(v.value_bool, 0) <> 0)
FROM kantine_values v
JOIN kantine_task_list task ON BINARY v.item_key = BINARY CONCAT(task.shift_name, '_', (
  SELECT COUNT(*) FROM kantine_task_list previous
  WHERE previous.shift_name = task.shift_name
    AND (previous.sort_order < task.sort_order OR (previous.sort_order = task.sort_order AND previous.id < task.id))
))
WHERE v.value_kind = 'tasks' AND NOT @kv2_done AND @kv2_clean AND @kv2_json_ok;

SET @kv2_can_apply = NOT @kv2_done AND @kv2_clean AND @kv2_json_ok
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('kantine_values', 'kantine_schema_versions', 'kantine_revision', 'kantine_task_templates', 'kantine_day_state')
    AND ENGINE = 'InnoDB') = 5
  AND NOT EXISTS (
    SELECT 1 FROM kv2_expected_days WHERE staffing_json IS NOT NULL AND (
      JSON_LENGTH(JSON_EXTRACT(staffing_json, '$.early')) > 100 OR
      JSON_LENGTH(JSON_EXTRACT(staffing_json, '$.late')) > 100
    )
  );
INSERT INTO kantine_task_templates (effective_date, tasks_json)
SELECT '1000-01-01', @kv2_template WHERE @kv2_can_apply;
INSERT INTO kantine_day_state (date_id, staffing_json, tasks_custom, tasks_json)
SELECT date_id, staffing_json, 0, tasks_json FROM kv2_expected_days WHERE @kv2_can_apply;
INSERT INTO kantine_values (value_kind, date_id, item_key, value_text, value_bool)
SELECT 'tasks', date_id, item_key, NULL, value_bool FROM kv2_expected_checks WHERE @kv2_can_apply;

-- Verify all written rows BEFORE marking the migration complete.
SELECT COUNT(*) INTO @kv2_expected_day_count FROM kv2_expected_days;
SET @kv2_verified = @kv2_can_apply
  AND EXISTS (SELECT 1 FROM kantine_task_templates WHERE effective_date = '1000-01-01' AND BINARY tasks_json = BINARY @kv2_template)
  AND (SELECT COUNT(*) FROM kantine_day_state) = @kv2_expected_day_count
  AND NOT EXISTS (
    SELECT 1 FROM kv2_expected_days expected LEFT JOIN kantine_day_state actual ON actual.date_id = expected.date_id
    WHERE actual.date_id IS NULL OR NOT (BINARY actual.staffing_json <=> BINARY expected.staffing_json)
      OR BINARY actual.tasks_json <> BINARY expected.tasks_json OR actual.tasks_custom <> 0
  )
  AND NOT EXISTS (
    SELECT 1 FROM kv2_expected_checks expected LEFT JOIN kantine_values actual
      ON actual.value_kind = 'tasks' AND actual.date_id = expected.date_id AND actual.item_key = expected.item_key
    WHERE actual.date_id IS NULL OR actual.value_bool <> expected.value_bool OR actual.value_bool IS NULL OR actual.value_text IS NOT NULL
  );
INSERT INTO kantine_schema_versions (version) SELECT 2 WHERE @kv2_verified;
UPDATE kantine_revision SET revision = revision + 1 WHERE id = 1 AND @kv2_verified;
SET @kv2_verified = @kv2_verified
  AND EXISTS (SELECT 1 FROM kantine_schema_versions WHERE version = 2)
  AND EXISTS (SELECT 1 FROM kantine_revision WHERE id = 1 AND revision = @kv2_revision + 1);

-- PREPARE is used only for this fixed COMMIT/ROLLBACK choice, never user input.
SET @kv2_finish = IF(@kv2_verified, 'COMMIT', 'ROLLBACK');
PREPARE kv2_finish_statement FROM @kv2_finish;
EXECUTE kv2_finish_statement;
DEALLOCATE PREPARE kv2_finish_statement;
SELECT CASE
  WHEN @kv2_done THEN 'ALLEREIE OPPGRADERT - ingen data endra'
  WHEN @kv2_verified THEN 'OPPGRADERING FULLFORT'
  ELSE 'STOPP - oppgraderinga vart ikkje fullfort. Behald vedlikehald og meld fra.'
END AS resultat,
(SELECT COUNT(*) FROM kantine_day_state) AS dagslister,
(SELECT COUNT(*) FROM kantine_schema_versions WHERE version = 2) AS versjon_2;
DROP TEMPORARY TABLE IF EXISTS kv2_expected_checks;
DROP TEMPORARY TABLE IF EXISTS kv2_expected_days;
