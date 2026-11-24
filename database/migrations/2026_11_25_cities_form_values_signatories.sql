-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System
--  Migration 2026_11_25: the City/Municipality List of the customers, the values
--  filled on a transaction's forms, and the signatures of five templates
--
--  Run it ONCE on every company database, after a backup (phpMyAdmin: select
--  the database > Import; Navicat: Execute SQL File; or the mysql command).
--  It needs 2026_11_24_estimates_sworn_statements.sql (version 37) to have been run first.
--  Written for MySQL 5.5.3+ and MariaDB 5.5+. Safe to run more than once.
--  New installs do not need it (database/schema.sql includes everything).
--
--  What it changes
--    1. The table cities: the City/Municipality List (Customers > Lists), filled
--       with the cities and municipalities of the procuring entities.
--    2. One empty column on customers: city_id, the customer's city or municipality
--       (a customer linked to a procuring entity gets the entity's city). A city or
--       municipality that customers use cannot be deleted.
--    3. The page City/Municipality List, given to the roles that open the Customer
--       Center, with the same rights.
--    4. The table brgy_form_values: what is filled into the blanks of a transaction's
--       forms (the purchase order no. and date on the purchase order ...), so the
--       transaction's next forms (the IAR) are filled with it.
--    5. The templates Transaction form, Requisition and issue slip, Disbursement
--       voucher, Statement of ongoing contracts and Statement of similar contracts:
--       their signatures are marked (class names only, the text and the look do not
--       change), so the Fill in the blanks panel lists each name and position. The
--       version before is kept (the template > Versions > Put back).
--
--  Nothing is deleted, no amount changes and nothing is posted to the books.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  1. The City/Municipality List
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cities (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  province    VARCHAR(120) NULL,
  zip_code    VARCHAR(10)  NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NULL,
  KEY ix_cities_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the cities and municipalities of the procuring entities (each once)
INSERT INTO cities (name, province)
SELECT DISTINCT TRIM(e.city_municipality), NULLIF(TRIM(e.province), '')
  FROM brgy_entities e
 WHERE TRIM(COALESCE(e.city_municipality, '')) <> ''
   AND NOT EXISTS (SELECT 1 FROM cities t WHERE t.name = TRIM(e.city_municipality) AND COALESCE(t.province, '') = COALESCE(NULLIF(TRIM(e.province), ''), ''));

-- ---------------------------------------------------------------------
--  2. The customer's city or municipality
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE customers ADD COLUMN city_id INT UNSIGNED NULL AFTER zip_code',
  'SELECT ''customers: city_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'city_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE customers ADD CONSTRAINT fk_cust_city FOREIGN KEY (city_id) REFERENCES cities(id)',
  'SELECT ''customers: fk_cust_city already exists''')
  FROM information_schema.table_constraints
 WHERE table_schema = DATABASE() AND table_name = 'customers' AND constraint_name = 'fk_cust_city');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- a customer that is a procuring entity takes the entity's city (only when it has none yet)
UPDATE customers c
  JOIN brgy_entities e ON e.customer_id = c.id
  JOIN cities t ON t.name = TRIM(e.city_municipality) AND COALESCE(t.province, '') = COALESCE(NULLIF(TRIM(e.province), ''), '')
   SET c.city_id = t.id
 WHERE c.city_id IS NULL;

-- ---------------------------------------------------------------------
--  3. The page City/Municipality List (Customers > Lists)
-- ---------------------------------------------------------------------
SET @cu_mod  = (SELECT id FROM modules WHERE code = 'cust' LIMIT 1);
SET @cu_sort = (SELECT COALESCE(MAX(sort_order), 590) FROM pages WHERE page_key = 'cust.credit_memos');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('cust.cities', @cu_mod, 'City/Municipality List', 'list', 'Lists', @cu_sort + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, src.can_create, src.can_edit, src.can_delete, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'cust.center'
  JOIN pages dst ON dst.page_key = 'cust.cities'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  4. The values filled on a transaction's forms
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brgy_form_values (
  project_id  INT UNSIGNED NOT NULL,
  ph_key      VARCHAR(60)  NOT NULL,
  value       VARCHAR(500) NOT NULL,
  form_id     INT UNSIGNED NULL,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NULL,
  PRIMARY KEY (project_id, ph_key),
  CONSTRAINT fk_bfv_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  5. The signatures of five templates, marked for the Fill in the blanks panel
--     (each only when it was not done yet and its text still has the signatures as supplied)
-- ---------------------------------------------------------------------

-- txn_form
SET @t = (SELECT id FROM brgy_templates WHERE code = 'txn_form' AND INSTR(body, 'sg-name') = 0 AND INSTR(body, 'PREPARED / RECEIVED BY:</td><td class="val">{{txn.prepared_by}}</td>') > 0);
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Kept before version 38' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);
UPDATE brgy_templates SET body = REPLACE(body, 'PREPARED / RECEIVED BY:</td><td class="val">{{txn.prepared_by}}</td>', 'PREPARED / RECEIVED BY:</td><td class="val"><span class="sg-name">{{txn.prepared_by}}</span></td>'),
       version_no = version_no + 1, updated_at = NOW() WHERE id = @t;
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Version 38: the signatures marked, so the Fill in the blanks panel lists their names and positions' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

-- requisition_issue_slip
SET @t = (SELECT id FROM brgy_templates WHERE code = 'requisition_issue_slip' AND INSTR(body, 'sg-name') = 0 AND INSTR(body, '<div style="text-align:center;font-weight:bold;text-decoration:underline">') > 0);
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Kept before version 38' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);
UPDATE brgy_templates SET body = REPLACE(REPLACE(REPLACE(REPLACE(body, '<div style="text-align:center;font-weight:bold;text-decoration:underline">', '<div class="sg-name" style="text-align:center;font-weight:bold;text-decoration:underline">'), '<div style="text-align:center">Requisitioner</div>', '<div class="sg-cap" style="text-align:center">Requisitioner</div>'), '<div style="text-align:center">Punong Barangay</div>', '<div class="sg-cap" style="text-align:center">Punong Barangay</div>'), '<div style="text-align:center">Barangay Treasurer</div>', '<div class="sg-cap" style="text-align:center">Barangay Treasurer</div>'),
       version_no = version_no + 1, updated_at = NOW() WHERE id = @t;
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Version 38: the signatures marked, so the Fill in the blanks panel lists their names and positions' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

-- disbursement_voucher
SET @t = (SELECT id FROM brgy_templates WHERE code = 'disbursement_voucher' AND INSTR(body, 'sg-name') = 0 AND INSTR(body, 'Printed Name: <b><u>{{') > 0);
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Kept before version 38' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);
UPDATE brgy_templates SET body = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(body, 'Printed Name: <b><u>{{', 'Printed Name: <b><u><span class="sg-name">{{'), '_upper}}</u></b><br>Position: ', '_upper}}</span></u></b><br>Position: <span class="sg-cap">'), 'Chairman, Committee on Appropriations<br><b>Date:</b>', 'Chairman, Committee on Appropriations</span><br><b>Date:</b>'), 'Barangay Treasurer<br><b>Date:</b>', 'Barangay Treasurer</span><br><b>Date:</b>'), 'Barangay Captain<br><b>Date:</b>', 'Barangay Captain</span><br><b>Date:</b>'), '<b><u>{{company.name_upper}}</u></b><br>Signature Over Printed Name</td>', '<b><u><span class="sg-name">{{company.name_upper}}</span></u></b><br><span class="sg-cap">Signature Over Printed Name</span></td>'), '</div>{{entity.bookkeeper}}<br>Barangay Bookkeeper</td>', '</div><span class="sg-name">{{entity.bookkeeper}}</span><br><span class="sg-cap">Barangay Bookkeeper</span></td>'), '</div>{{entity.city_accountant}}<br>City/Municipal Accountant</td>', '</div><span class="sg-name">{{entity.city_accountant}}</span><br><span class="sg-cap">City/Municipal Accountant</span></td>'),
       version_no = version_no + 1, updated_at = NOW() WHERE id = @t;
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Version 38: the signatures marked, so the Fill in the blanks panel lists their names and positions' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

-- statement_ongoing_contracts
SET @t = (SELECT id FROM brgy_templates WHERE code = 'statement_ongoing_contracts' AND INSTR(body, 'sg-name') = 0 AND INSTR(body, '<table class="fx-plain fx-kv" style="margin-top:22px"><tbody><tr><td class="k" style="width:110px">Submitted by</td><td>: <b><u>{{signatory.name}}</u></b></td></tr>') > 0);
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Kept before version 38' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);
UPDATE brgy_templates SET body = REPLACE(REPLACE(body, '<table class="fx-plain fx-kv" style="margin-top:22px"><tbody><tr><td class="k" style="width:110px">Submitted by</td><td>: <b><u>{{signatory.name}}</u></b></td></tr>', '<table class="fx-plain fx-kv sg-block" style="margin-top:22px"><tbody><tr><td class="k" style="width:110px">Submitted by</td><td>: <b><u><span class="sg-name">{{signatory.name}}</span></u></b></td></tr>'), '<td class="k">Designation</td><td>: <u>{{signatory.position}}</u></td>', '<td class="k">Designation</td><td>: <u><span class="sg-cap">{{signatory.position}}</span></u></td>'),
       version_no = version_no + 1, updated_at = NOW() WHERE id = @t;
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Version 38: the signatures marked, so the Fill in the blanks panel lists their names and positions' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

-- statement_similar_contracts
SET @t = (SELECT id FROM brgy_templates WHERE code = 'statement_similar_contracts' AND INSTR(body, 'sg-name') = 0 AND INSTR(body, '<table class="fx-plain fx-kv" style="margin-top:22px"><tbody><tr><td class="k" style="width:110px">Submitted by</td><td>: <b><u>{{signatory.name}}</u></b></td></tr>') > 0);
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Kept before version 38' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);
UPDATE brgy_templates SET body = REPLACE(REPLACE(body, '<table class="fx-plain fx-kv" style="margin-top:22px"><tbody><tr><td class="k" style="width:110px">Submitted by</td><td>: <b><u>{{signatory.name}}</u></b></td></tr>', '<table class="fx-plain fx-kv sg-block" style="margin-top:22px"><tbody><tr><td class="k" style="width:110px">Submitted by</td><td>: <b><u><span class="sg-name">{{signatory.name}}</span></u></b></td></tr>'), '<td class="k">Designation</td><td>: <u>{{signatory.position}}</u></td>', '<td class="k">Designation</td><td>: <u><span class="sg-cap">{{signatory.position}}</span></u></td>'),
       version_no = version_no + 1, updated_at = NOW() WHERE id = @t;
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Version 38: the signatures marked, so the Fill in the blanks panel lists their names and positions' FROM brgy_templates t
 WHERE t.id = @t AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

-- ---------------------------------------------------------------------
--  The audit trail
-- ---------------------------------------------------------------------
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'City/Municipality List, form values, signatures',
        'Migration 2026_11_25: the City/Municipality List and the customer''s city, the values filled on a transaction''s forms, the signatures of five templates marked');
