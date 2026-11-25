-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System
--  Migration 2026_11_26: fields of the forms that may stay blank, step 4 of the
--  small value procurement (the price schedule), and the Barangay Project
--  Monitoring Board
--
--  Run it ONCE on every company database, after a backup (phpMyAdmin: select
--  the database > Import; Navicat: Execute SQL File; or the mysql command).
--  It needs 2026_11_25_cities_form_values_signatories.sql (version 38) to have been run first.
--  Written for MySQL 5.5.3+ and MariaDB 5.5+. Safe to run more than once.
--  New installs do not need it (database/schema.sql includes everything).
--
--  What it changes
--    1. One column on brgy_templates: optional_fields, the fields of a template
--       that may stay blank (printed empty, not counted as blanks): the P.R. no.
--       and date of the purchase request; the DV no., date and fund of the
--       disbursement voucher; the IAR no. and date, the RIS no. and date and the
--       invoice no. and date of the inspection and acceptance report; the RIS no.
--       and date of the requisition and issue slip.
--    2. Step 4 of the small value procurement is "Prepare price schedule" (it was
--       "Prepare billing documents"): the SI, OR and BIR Form 2307 are no longer
--       papers of the step. On transactions that have not done step 4 yet, the
--       SI, OR and 2307 with nothing attached are taken off the step; a paper with
--       a signed copy attached stays.
--    3. The page Barangay Contracts > Barangay Project Monitoring Board, given to
--       the roles that open Projects & Contracts (view and print).
--
--  Nothing else is deleted, no amount changes and nothing is posted to the books.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  1. Fields of a template that may stay blank
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_templates ADD COLUMN optional_fields VARCHAR(255) NULL AFTER required_fields',
  'SELECT ''brgy_templates: optional_fields already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_templates' AND column_name = 'optional_fields');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE brgy_templates SET optional_fields = 'pr.no,pr.date' WHERE code = 'purchase_request' AND optional_fields IS NULL;
UPDATE brgy_templates SET optional_fields = 'dv.no,dv.date,dv.fund' WHERE code = 'disbursement_voucher' AND optional_fields IS NULL;
UPDATE brgy_templates SET optional_fields = 'iar.no,iar.date,ris.no,ris.date,iar.invoice_no,iar.invoice_date' WHERE code = 'inspection_acceptance_report' AND optional_fields IS NULL;
UPDATE brgy_templates SET optional_fields = 'ris.no,ris.date' WHERE code = 'requisition_issue_slip' AND optional_fields IS NULL;
-- the P.R., IAR and RIS numbers are no longer fields those forms need
UPDATE brgy_templates SET required_fields = 'entity.name' WHERE code = 'purchase_request' AND required_fields = 'entity.name,pr.no';
UPDATE brgy_templates SET required_fields = 'entity.name' WHERE code = 'inspection_acceptance_report' AND required_fields = 'entity.name,iar.no';
UPDATE brgy_templates SET required_fields = 'entity.name' WHERE code = 'requisition_issue_slip' AND required_fields = 'entity.name,ris.no';

-- ---------------------------------------------------------------------
--  2. Step 4 of the small value procurement: the price schedule
-- ---------------------------------------------------------------------
UPDATE brgy_sop_steps
   SET section_title = 'Price schedule', title = 'Prepare price schedule',
       instructions = 'Prepare the price schedule after the canvass: the items with their quantities, unit costs and unit prices (Price schedule tab). The items typed on the purchase request can be taken over. It has to agree with the company''s quotation in the canvass. When the step is done, the purchase order is recorded as the award and the item tables of the forms still in draft are filled from the price schedule.',
       papers = NULL, checks = NULL, action = 'price_schedule', role_hint = 'Documentation staff', updated_at = NOW()
 WHERE code = 'svp4' AND process = 'svp' AND action = 'billing_docs';

-- the SI, OR and 2307 with nothing attached leave step 4 of the transactions that have not done it
DELETE pp FROM brgy_project_papers pp
  JOIN brgy_projects p ON p.id = pp.project_id AND p.process = 'svp'
  LEFT JOIN brgy_project_steps ps ON ps.project_id = pp.project_id AND ps.step_code = pp.step_code
 WHERE pp.step_code = 'svp4' AND pp.paper_code IN ('si', 'or', 'form_2307') AND pp.document_id IS NULL AND pp.form_id IS NULL
   AND COALESCE(ps.status, 'pending') = 'pending';

-- ---------------------------------------------------------------------
--  3. The page Barangay Project Monitoring Board (Barangay Contracts > Transactions)
-- ---------------------------------------------------------------------
SET @bg_mod  = (SELECT id FROM modules WHERE code = 'brgy' LIMIT 1);
SET @bg_sort = (SELECT COALESCE(MAX(sort_order), 1750) FROM pages WHERE page_key = 'brgy.projects');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('brgy.board', @bg_mod, 'Barangay Project Monitoring Board', 'center', 'Transactions', @bg_sort + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'brgy.projects'
  JOIN pages dst ON dst.page_key = 'brgy.board'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  The audit trail
-- ---------------------------------------------------------------------
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'brgy', 'brgy.board', 'access_change', 'page',
        'Fields that may stay blank, step 4 price schedule, monitoring board',
        'Migration 2026_11_26: the fields of the forms that may stay blank, step 4 of the small value procurement as the price schedule, the Barangay Project Monitoring Board');
