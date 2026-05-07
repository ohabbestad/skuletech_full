INSERT INTO kantine_calendar_days
  (date_id, week_no, turnus_type, status, merknad, tidleg_overstyre, sent_overstyre)
VALUES
  ('2026-05-11', 20, 'A', 'open', '', '', ''),
  ('2026-05-12', 20, 'A', 'open', '', '', ''),
  ('2026-05-13', 20, 'A', 'open', '', '', ''),
  ('2026-05-14', 20, 'A', 'open', '', '', ''),
  ('2026-05-15', 20, 'A', 'open', '', '', '')
ON DUPLICATE KEY UPDATE
  week_no = VALUES(week_no),
  turnus_type = VALUES(turnus_type),
  status = VALUES(status);

INSERT INTO kantine_settings (setting_key, setting_value)
VALUES
  ('driftsleiar_Måndag', 'Test Driftsleiar 1'),
  ('driftsleiar_Tysdag', 'Test Driftsleiar 2'),
  ('driftsleiar_Onsdag', 'Test Driftsleiar 3'),
  ('driftsleiar_Torsdag', 'Test Driftsleiar 4'),
  ('driftsleiar_Fredag', 'Test Driftsleiar 5'),
  ('turnus_A_Måndag_tidleg', 'Elev A, Elev B'),
  ('turnus_A_Måndag_seint', 'Elev C, Elev D'),
  ('turnus_A_Tysdag_tidleg', 'Elev E, Elev F'),
  ('turnus_A_Tysdag_seint', 'Elev G, Elev H'),
  ('turnus_A_Onsdag_tidleg', 'Elev A, Elev C'),
  ('turnus_A_Onsdag_seint', 'Elev B, Elev D'),
  ('turnus_A_Torsdag_tidleg', 'Elev E, Elev G'),
  ('turnus_A_Torsdag_seint', 'Elev F, Elev H'),
  ('turnus_A_Fredag_tidleg', 'Elev A, Elev E'),
  ('turnus_A_Fredag_seint', 'Elev D, Elev H')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO kantine_values (value_kind, date_id, item_key, value_text)
VALUES
  ('menus', '2026-05-11', 'dagens', 'Testmeny: grove rundstykke og suppe'),
  ('menus', '2026-05-12', 'dagens', 'Testmeny: wraps')
ON DUPLICATE KEY UPDATE value_text = VALUES(value_text);
