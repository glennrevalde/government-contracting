-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System
--  Migration 2026_11_20: tradenames, people accountable, Report Center menu
--
--  Run it ONCE on every company database, after a backup (phpMyAdmin: select
--  the database > Import; Navicat: Execute SQL File; or the mysql command).
--  It needs 2026_11_19_accounting_updates.sql (version 32) to have been run first.
--  Written for MySQL 5.5.3+ and MariaDB 5.5+. Safe to run more than once.
--  New installs do not need it (database/schema.sql includes everything).
--
--  What it changes
--    1. The table brgy_tradenames: the tradenames of the company, one of them the
--       default. It is filled with the tradename of Contracting Settings (made the
--       default) and the tradenames already typed on transactions.
--    2. The table brgy_function_roles: the user roles allowed for each person of a
--       transaction (sales representative, preparer, checker / reviewer, approver,
--       authorized submitter, project manager, collector). The sales representative
--       gets the roles named like "Sales Representative"; the others start empty,
--       which lists every active user, as before.
--    3. The pages Tradenames (Barangay Contracts > Lists) and People Accountable
--       Set-up (Barangay Contracts > Setup), given to the roles that open Barangays &
--       Procuring Entities and Contracting Settings, with the same rights.
--    4. The menus: Report Center becomes a menu of its own, after Home, with the
--       Report Center, Memorized Reports and Saved Report Layouts pages (their rights
--       do not change); the Chart of Accounts menu is renamed Financial (the Chart of
--       Accounts list itself keeps its name).
--
--  Nothing is deleted. The columns requestor_position and inspector_position stay
--  on brgy_projects with what they hold; the transaction form no longer asks for them.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Tradenames
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brgy_tradenames (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(160) NOT NULL,
  description  VARCHAR(255) NULL,
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order   INT          NOT NULL DEFAULT 100,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by   INT UNSIGNED NULL,
  updated_at   DATETIME     NULL,
  UNIQUE KEY uq_brgy_tradenames_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the tradename of Contracting Settings, as the default (only when no default is set yet)
SET @tn_has_default = (SELECT COUNT(*) FROM brgy_tradenames WHERE is_default = 1);
INSERT IGNORE INTO brgy_tradenames (name, is_default, sort_order)
SELECT TRIM(setting_value), 1, 10
  FROM settings
 WHERE setting_key = 'brgy_tradename' AND TRIM(COALESCE(setting_value, '')) <> '' AND @tn_has_default = 0;

-- the tradenames already typed on transactions
INSERT IGNORE INTO brgy_tradenames (name, is_default, sort_order)
SELECT DISTINCT TRIM(tradename), 0, 100
  FROM brgy_projects
 WHERE tradename IS NOT NULL AND TRIM(tradename) <> '';

-- still no default: the first one becomes the default
SET @tn_has_default = (SELECT COUNT(*) FROM brgy_tradenames WHERE is_default = 1);
SET @tn_first = (SELECT MIN(id) FROM brgy_tradenames WHERE is_active = 1);
UPDATE brgy_tradenames SET is_default = 1 WHERE id = @tn_first AND @tn_has_default = 0;

-- ---------------------------------------------------------------------
--  2. The roles allowed for each person of a transaction
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brgy_function_roles (
  function_code VARCHAR(20)  NOT NULL,          -- sales_rep, preparer, checker, approver, submitter, pm, collector
  role_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (function_code, role_id),
  KEY ix_bfr_role (role_id),
  CONSTRAINT fk_bfr_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the positions acting as sales representative (only when none is set yet)
SET @fr_has_sales = (SELECT COUNT(*) FROM brgy_function_roles WHERE function_code = 'sales_rep');
INSERT IGNORE INTO brgy_function_roles (function_code, role_id)
SELECT 'sales_rep', id FROM roles WHERE name LIKE '%sales rep%' AND @fr_has_sales = 0;

-- ---------------------------------------------------------------------
--  3. The pages Tradenames and People Accountable Set-up
-- ---------------------------------------------------------------------
SET @br_mod  = (SELECT id FROM modules WHERE code = 'brgy' LIMIT 1);
SET @br_ent  = (SELECT COALESCE(MAX(sort_order), 2400) FROM pages WHERE page_key = 'brgy.entities');
SET @br_set  = (SELECT COALESCE(MAX(sort_order), 2450) FROM pages WHERE page_key = 'brgy.settings');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('brgy.tradenames',  @br_mod, 'Tradenames',                'list',  'Lists', @br_ent + 1, 1),
  ('brgy.accountable', @br_mod, 'People Accountable Set-up', 'setup', 'Setup', @br_set + 1, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, src.can_create, src.can_edit, src.can_delete, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'brgy.entities'
  JOIN pages dst ON dst.page_key = 'brgy.tradenames'
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, src.can_create, src.can_edit, src.can_delete, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'brgy.settings'
  JOIN pages dst ON dst.page_key = 'brgy.accountable'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  4. The menus: Report Center on its own, Chart of Accounts renamed Financial
-- ---------------------------------------------------------------------
INSERT IGNORE INTO modules (code, name, icon, sort_order) VALUES ('rc', 'Report Center', 'bar-chart-line', 2);

UPDATE modules SET name = 'Financial' WHERE code = 'coa';

UPDATE modules SET sort_order = CASE code
    WHEN 'home' THEN 1 WHEN 'rc' THEN 2 WHEN 'coa' THEN 3 WHEN 'fa' THEN 4 WHEN 'cust' THEN 5 WHEN 'vend' THEN 6
    WHEN 'inv' THEN 7 WHEN 'pos' THEN 8 WHEN 'bank' THEN 9 WHEN 'bir' THEN 10 WHEN 'brgy' THEN 11 WHEN 'admin' THEN 12
    WHEN 'company' THEN 13 ELSE sort_order END;

-- the standard Accountant role names the menu by its new name (only when its description was never changed)
UPDATE roles SET description = CONCAT('Financial (Chart of Accounts)', SUBSTRING(description, 18))
 WHERE name = 'Accountant' AND is_system = 1 AND description LIKE 'Chart of Accounts, Fixed Assets,%';

SET @rc_mod = (SELECT id FROM modules WHERE code = 'rc' LIMIT 1);
UPDATE pages SET module_id = @rc_mod, menu_group = 'Report Center'
 WHERE page_key IN ('home.reports', 'home.memorized', 'home.report_layouts') AND @rc_mod IS NOT NULL;

-- ---------------------------------------------------------------------
--  The audit trail
-- ---------------------------------------------------------------------
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Tradenames, people accountable, Report Center',
        'Migration 2026_11_20: tradenames, roles allowed for the people of a transaction, Report Center menu, Financial menu');
