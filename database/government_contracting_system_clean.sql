-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System - clean database (setup lists and users, no transactions)
--  Generated 2026-11-23 19:54:45 +08:00 from database `government_contracting_system`
--  117 tables, 1765 rows (0 transactions)
--
--  Import:  phpMyAdmin > Import > choose this file > Go
--      or:  mysql -u root -p < government_contracting_system_clean.sql
--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.
--  Import as root (or a user with CREATE, INSERT, REFERENCES and TRIGGER privileges).
--  WARNING: importing drops and recreates every table in the database below.
--
--  Default login: admin / Admin@12345 (a new password is required at first login)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+08:00';

CREATE DATABASE IF NOT EXISTS `government_contracting_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `government_contracting_system`;

-- ---------------------------------------------------------------------
--  1. TABLES
-- ---------------------------------------------------------------------
-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System - database schema
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+ (XAMPP / WAMP / Laragon)
--  MySQL 5.5 rules followed here: only TIMESTAMP columns default to
--  CURRENT_TIMESTAMP (one per table), indexed text columns stay within
--  767 bytes (utf8mb4), and foreign key columns match exactly.
-- =====================================================================


-- ---------------------------------------------------------------------
--  SYSTEM ADMINISTRATION AND SECURITY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  setting_key   VARCHAR(60)  NOT NULL PRIMARY KEY,
  setting_value TEXT         NULL,
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(120) NULL,
  employee_no          VARCHAR(30)  NULL,
  pos_default_site_id  INT UNSIGNED NULL,                 -- the site this user's POS Register opens on
  pos_default_class_id INT UNSIGNED NULL,                 -- the class every new sale of theirs starts with
  status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  approval_status      VARCHAR(10)  NOT NULL DEFAULT 'approved',  -- approved / pending / rejected (joined with the Company Code)
  created_via          VARCHAR(12)  NOT NULL DEFAULT 'admin',     -- admin / registration / provisioning / setup / platform
  approved_by          INT UNSIGNED NULL,                         -- who approved or rejected the registration
  approved_at          DATETIME     NULL,
  rejected_reason      VARCHAR(255) NULL,
  is_platform          TINYINT(1)   NOT NULL DEFAULT 0,           -- the account a Super Admin works through inside the company
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  password_changed_at  DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_approval (approval_status),
  KEY ix_users_pos_site (pos_default_site_id),
  KEY ix_users_pos_class (pos_default_class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  description VARCHAR(255) NULL,
  is_admin    TINYINT(1)   NOT NULL DEFAULT 0,   -- full access to everything
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,   -- standard role shipped with the system
  all_sites   TINYINT(1)   NOT NULL DEFAULT 1,   -- 0 = limited to role_sites
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_roles;
CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS modules;
CREATE TABLE modules (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  icon       VARCHAR(40)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_modules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pages;
CREATE TABLE pages (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_key     VARCHAR(60)  NOT NULL,
  module_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  page_type    ENUM('center','transaction','list','report','setup','admin') NOT NULL DEFAULT 'transaction',
  menu_group   VARCHAR(40)  NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  show_in_menu TINYINT(1)   NOT NULL DEFAULT 1,
  description  VARCHAR(255) NULL,
  UNIQUE KEY uq_pages_key (page_key),
  CONSTRAINT fk_pages_module FOREIGN KEY (module_id) REFERENCES modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Page access + action permissions per role (View = allowed)
DROP TABLE IF EXISTS role_page_access;
CREATE TABLE role_page_access (
  role_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id, page_id),
  CONSTRAINT fk_rpa_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rpa_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-level overrides always take precedence over roles
DROP TABLE IF EXISTS user_page_overrides;
CREATE TABLE user_page_overrides (
  user_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, page_id),
  CONSTRAINT fk_upo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_upo_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sensitive permissions set separately from the grid
--  view_costs, view_bank_balances, credit_limit_override, coa_changes,
--  closed_period_changes (0/1); max_discount_pct, max_price_override_pct,
--  max_amount (number, blank = unlimited)
DROP TABLE IF EXISTS role_special_permissions;
CREATE TABLE role_special_permissions (
  role_id    INT UNSIGNED NOT NULL,
  perm_key   VARCHAR(40)  NOT NULL,
  perm_value VARCHAR(40)  NULL,
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT fk_rsp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent, read-only audit trail (see triggers at the end of the file)
DROP TABLE IF EXISTS audit_trail;
CREATE TABLE audit_trail (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(50)  NULL,
  user_roles  VARCHAR(255) NULL,
  ip_address  VARCHAR(45)  NULL,
  workstation VARCHAR(255) NULL,
  site_id     INT UNSIGNED NULL,
  terminal_id INT UNSIGNED NULL,
  module      VARCHAR(20)  NOT NULL,
  page_key    VARCHAR(60)  NULL,
  action      VARCHAR(30)  NOT NULL,
  record_type VARCHAR(40)  NULL,
  record_id   BIGINT       NULL,
  record_ref  VARCHAR(160) NULL,
  amount      DECIMAL(15,2) NULL,
  changes     LONGTEXT     NULL,   -- JSON [{field, old, new}]
  snapshot    LONGTEXT     NULL,   -- JSON copy of the record
  reason      VARCHAR(255) NULL,
  approved_by VARCHAR(50)  NULL,
  KEY ix_audit_date (created_at),
  KEY ix_audit_user (user_id, created_at),
  KEY ix_audit_record (record_type, record_id),
  KEY ix_audit_action (action),
  KEY ix_audit_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS memorized_reports;
CREATE TABLE memorized_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  group_name  VARCHAR(60)  NULL,
  report_key  VARCHAR(60)  NOT NULL,
  query_string TEXT        NULL,
  is_shared   TINYINT(1)   NOT NULL DEFAULT 0,
  schedule    VARCHAR(10)  NULL,          -- daily, weekly, monthly (tools/send_scheduled_reports.php)
  email_to    VARCHAR(255) NULL,
  last_sent_at DATETIME    NULL,
  created_by  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mr_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sequences;
CREATE TABLE sequences (
  seq_key VARCHAR(30) NOT NULL PRIMARY KEY,
  prefix  VARCHAR(10) NOT NULL DEFAULT '',
  next_no INT UNSIGNED NOT NULL DEFAULT 1,
  pad     TINYINT      NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CHART OF ACCOUNTS AND LISTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS accounts;
CREATE TABLE accounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  account_type    ENUM('Bank','Accounts Receivable','Other Current Asset','Fixed Asset','Other Asset',
                       'Accounts Payable','Credit Card','Other Current Liability','Long Term Liability',
                       'Equity','Income','Other Income','Cost of Goods Sold','Expense','Other Expense') NOT NULL,
  category        ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
  normal_balance  ENUM('Debit','Credit') NOT NULL,
  parent_id       INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  bank_account_no VARCHAR(40)  NULL,
  with_sl         TINYINT(1)   NOT NULL DEFAULT 0,    -- keeps a subsidiary ledger: every entry needs a name
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  is_system       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_accounts_code (code),
  KEY ix_acc_with_sl (with_sl),
  CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module default accounts (ar, ap, undeposited_funds, output_tax, ...)
DROP TABLE IF EXISTS default_accounts;
CREATE TABLE default_accounts (
  setting_key VARCHAR(40)  NOT NULL PRIMARY KEY,
  label       VARCHAR(120) NOT NULL,
  module      VARCHAR(20)  NOT NULL,
  account_id  INT UNSIGNED NULL,
  CONSTRAINT fk_da_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS terms;
CREATE TABLE terms (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(40)  NOT NULL,
  net_days      INT          NOT NULL DEFAULT 0,
  discount_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_days INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS tax_codes;
CREATE TABLE tax_codes (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(80)  NOT NULL,
  rate                DECIMAL(6,3) NOT NULL DEFAULT 0,
  sales_account_id    INT UNSIGNED NULL,   -- Output Tax
  purchase_account_id INT UNSIGNED NULL,   -- Input Tax
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tax_code (code),
  CONSTRAINT fk_tax_sales FOREIGN KEY (sales_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tax_purch FOREIGN KEY (purchase_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS payment_methods;
CREATE TABLE payment_methods (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(40)  NOT NULL,
  method_type         ENUM('cash','check','card','ewallet','bank_transfer','voucher','other') NOT NULL DEFAULT 'other',
  clearing_account_id INT UNSIGNED NULL,   -- POS clearing account for card / e-wallet / voucher / bank
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  show_in_pos         TINYINT(1)   NOT NULL DEFAULT 1,   -- offered as a payment option at the POS register
  CONSTRAINT fk_pm_account FOREIGN KEY (clearing_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_levels;
CREATE TABLE price_levels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(40)  NOT NULL,
  adjust_pct DECIMAL(6,2) NOT NULL DEFAULT 0,  -- e.g. -10 = 10% below standard price
  is_active  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sales_reps;
CREATE TABLE sales_reps (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  initials  VARCHAR(10)  NULL,
  user_id   INT UNSIGNED NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_rep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS customer_types;
CREATE TABLE customer_types (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS billing_groups;
CREATE TABLE billing_groups (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class List: your own way of splitting the books (branches, departments, projects ...).
-- Every transaction can carry a class; the ledger keeps it, so financial reports can filter by it.
DROP TABLE IF EXISTS classes;
CREATE TABLE classes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_classes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employees and other names (payees and subsidiary ledgers that are neither customers nor vendors)
DROP TABLE IF EXISTS other_names;
CREATE TABLE other_names (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name_type   VARCHAR(10)  NOT NULL DEFAULT 'other',  -- employee, other
  name        VARCHAR(120) NOT NULL,
  employee_no VARCHAR(30)  NULL,
  position    VARCHAR(80)  NULL,
  phone       VARCHAR(40)  NULL,
  address     VARCHAR(255) NULL,
  tin         VARCHAR(30)  NULL,
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_other_names_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CUSTOMERS AND VENDORS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id           INT UNSIGNED NULL,          -- job / project under a customer
  name                VARCHAR(120) NOT NULL,
  company_name        VARCHAR(120) NULL,
  contact_person      VARCHAR(120) NULL,
  treasurer_name      VARCHAR(120) NULL,          -- v35: the customer's treasurer
  phone               VARCHAR(40)  NULL,
  mobile              VARCHAR(40)  NULL,
  email               VARCHAR(120) NULL,
  billing_address     VARCHAR(255) NULL,
  shipping_address    VARCHAR(255) NULL,
  zip_code            VARCHAR(10)  NULL,          -- v35: the zip code of the address
  tin                 VARCHAR(30)  NULL,
  wht_ids             VARCHAR(100) NULL,          -- withholding taxes it usually withholds from its payments
  terms_id            INT UNSIGNED NULL,
  credit_limit        DECIMAL(15,2) NOT NULL DEFAULT 0,  -- 0 = no limit
  price_level_id      INT UNSIGNED NULL,
  tax_code_id         INT UNSIGNED NULL,
  payment_method_id   INT UNSIGNED NULL,
  customer_type_id    INT UNSIGNED NULL,
  sales_rep_id        INT UNSIGNED NULL,
  billing_group_id    INT UNSIGNED NULL,
  allow_pos_charge    TINYINT(1)   NOT NULL DEFAULT 0,  -- approved account customer at the POS
  notes               TEXT         NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_customers_name (name),
  CONSTRAINT fk_cust_parent FOREIGN KEY (parent_id) REFERENCES customers(id),
  CONSTRAINT fk_cust_terms  FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_cust_pl     FOREIGN KEY (price_level_id) REFERENCES price_levels(id),
  CONSTRAINT fk_cust_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_cust_pm     FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_cust_type   FOREIGN KEY (customer_type_id) REFERENCES customer_types(id),
  CONSTRAINT fk_cust_rep    FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_cust_bg     FOREIGN KEY (billing_group_id) REFERENCES billing_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS vendors;
CREATE TABLE vendors (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                       VARCHAR(120) NOT NULL,
  company_name               VARCHAR(120) NULL,
  contact_person             VARCHAR(120) NULL,
  phone                      VARCHAR(40)  NULL,
  fax                        VARCHAR(40)  NULL,
  email                      VARCHAR(120) NULL,
  address                    VARCHAR(255) NULL,
  tin                        VARCHAR(30)  NULL,
  terms_id                   INT UNSIGNED NULL,
  credit_limit               DECIMAL(15,2) NOT NULL DEFAULT 0,
  default_expense_account_id INT UNSIGNED NULL,
  tax_code_id                INT UNSIGNED NULL,
  account_no                 VARCHAR(40)  NULL,   -- our account number with the vendor
  wht_ids                    VARCHAR(100) NULL,   -- withholding taxes usually withheld from it (withholding_taxes ids, "1,3")
  notes                      TEXT         NULL,
  is_active                  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at                 TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_vendors_name (name),
  CONSTRAINT fk_vend_terms FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_vend_exp   FOREIGN KEY (default_expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_vend_tax   FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS entity_notes;
CREATE TABLE entity_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('customer','vendor','item') NOT NULL,
  entity_id   INT UNSIGNED NOT NULL,
  note        TEXT         NOT NULL,
  is_todo     TINYINT(1)   NOT NULL DEFAULT 0,
  due_date    DATE         NULL,
  is_done     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notes_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  INVENTORY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS sites;
CREATE TABLE sites (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(20)  NOT NULL,
  name      VARCHAR(80)  NOT NULL,
  site_type ENUM('warehouse','store','stockroom','van','damaged','other') NOT NULL DEFAULT 'warehouse',
  address   VARCHAR(255) NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sites_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_categories;
CREATE TABLE item_categories (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Units of measure: a set holds the base unit (stock and costs are kept in it)
-- plus the larger or smaller units a document may be entered in
DROP TABLE IF EXISTS uom_sets;
CREATE TABLE uom_sets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL,
  uom_kind   VARCHAR(10)  NOT NULL DEFAULT 'count',  -- count, weight, volume, length, time, other
  base_unit  VARCHAR(20)  NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_uom_sets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS uom_units;
CREATE TABLE uom_units (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  set_id              INT UNSIGNED NOT NULL,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(60)  NOT NULL,
  factor              DECIMAL(15,6) NOT NULL DEFAULT 1,  -- base units in one of this unit
  is_base             TINYINT(1)   NOT NULL DEFAULT 0,
  is_sales_default    TINYINT(1)   NOT NULL DEFAULT 0,
  is_purchase_default TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order          INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_uom_units (set_id, code),
  CONSTRAINT fk_uom_units_set FOREIGN KEY (set_id) REFERENCES uom_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS items;
CREATE TABLE items (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_type            ENUM('service','inventory','non_inventory','assembly','other_charge','subtotal','group','discount','payment') NOT NULL,
  code                 VARCHAR(40)  NOT NULL,
  barcode              VARCHAR(60)  NULL,
  name                 VARCHAR(120) NOT NULL,
  description          VARCHAR(255) NULL,
  purchase_description VARCHAR(255) NULL,
  category_id          INT UNSIGNED NULL,
  unit                 VARCHAR(20)  NULL,
  uom_set_id           INT UNSIGNED NULL,                  -- U/M set (blank = single unit)
  sales_uom_id         INT UNSIGNED NULL,                  -- unit offered first on sales forms
  purchase_uom_id      INT UNSIGNED NULL,                  -- unit offered first on purchase forms
  cost                 DECIMAL(15,4) NOT NULL DEFAULT 0,  -- standard purchase cost
  avg_cost             DECIMAL(15,4) NOT NULL DEFAULT 0,  -- weighted average cost
  sales_price          DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_pct         DECIMAL(6,2)  NOT NULL DEFAULT 0,  -- discount items: percent (0 = use sales_price as amount)
  income_account_id    INT UNSIGNED NULL,
  cogs_account_id      INT UNSIGNED NULL,
  asset_account_id     INT UNSIGNED NULL,
  expense_account_id   INT UNSIGNED NULL,
  tax_code_id          INT UNSIGNED NULL,
  group_variance_account_id INT UNSIGNED NULL,             -- group items: account of the group price less the consolidated price
  preferred_vendor_id  INT UNSIGNED NULL,
  reorder_point        DECIMAL(15,4) NOT NULL DEFAULT 0,
  max_stock            DECIMAL(15,4) NOT NULL DEFAULT 0,
  build_point          DECIMAL(15,4) NOT NULL DEFAULT 0,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_items_code (code),
  KEY ix_items_barcode (barcode),
  CONSTRAINT fk_items_cat    FOREIGN KEY (category_id) REFERENCES item_categories(id),
  CONSTRAINT fk_items_inc    FOREIGN KEY (income_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_cogs   FOREIGN KEY (cogs_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_asset  FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_exp    FOREIGN KEY (expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_items_gvar   FOREIGN KEY (group_variance_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_vendor FOREIGN KEY (preferred_vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_items_uom    FOREIGN KEY (uom_set_id) REFERENCES uom_sets(id),
  CONSTRAINT fk_items_suom   FOREIGN KEY (sales_uom_id) REFERENCES uom_units(id),
  CONSTRAINT fk_items_puom   FOREIGN KEY (purchase_uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bill of materials for assemblies, and members of group items
DROP TABLE IF EXISTS item_components;
CREATE TABLE item_components (
  parent_item_id    INT UNSIGNED NOT NULL,
  component_item_id INT UNSIGNED NOT NULL,
  qty               DECIMAL(15,4) NOT NULL DEFAULT 1,
  PRIMARY KEY (parent_item_id, component_item_id),
  CONSTRAINT fk_ic_parent FOREIGN KEY (parent_item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_comp   FOREIGN KEY (component_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Processing formulas (multi-output bills of materials) for disassembly,
-- de-kitting / break-bulk and co-product / by-product processing
DROP TABLE IF EXISTS process_formulas;
CREATE TABLE process_formulas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  process_type VARCHAR(20)  NOT NULL DEFAULT 'co_product',  -- disassembly, dekit, co_product
  alloc_method VARCHAR(20)  NOT NULL DEFAULT 'sales_value', -- sales_value, standard_cost, quantity, weight, percent, average_cost, manual
  site_id      INT UNSIGNED NULL,                           -- usual processing site
  description  VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,                           -- set by the application (DB::update)
  UNIQUE KEY uq_pf_code (code),
  CONSTRAINT fk_pf_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formula lines, quantities per batch. input = consumed, output = produced, cost = labor / overhead absorbed
DROP TABLE IF EXISTS process_formula_lines;
CREATE TABLE process_formula_lines (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  formula_id  INT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL DEFAULT 0,
  line_type   VARCHAR(10)  NOT NULL,                  -- input, output, cost
  item_id     INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,                      -- cost lines: account credited
  description VARCHAR(255) NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  output_role VARCHAR(12)  NULL,                      -- co_product, by_product, scrap
  alloc_basis DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price / weight / percent per unit, by-product value per unit, manual amount per batch
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,       -- cost lines: amount per batch
  KEY ix_pfl_formula (formula_id),
  KEY ix_pfl_item (item_id),
  CONSTRAINT fk_pfl_formula FOREIGN KEY (formula_id) REFERENCES process_formulas(id) ON DELETE CASCADE,
  CONSTRAINT fk_pfl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_pfl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_level_items;
CREATE TABLE price_level_items (
  price_level_id INT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED NOT NULL,
  custom_price   DECIMAL(15,4) NOT NULL,
  PRIMARY KEY (price_level_id, item_id),
  CONSTRAINT fk_pli_level FOREIGN KEY (price_level_id) REFERENCES price_levels(id) ON DELETE CASCADE,
  CONSTRAINT fk_pli_item  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_site_stock;
CREATE TABLE item_site_stock (
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty_on_hand DECIMAL(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (item_id, site_id),
  CONSTRAINT fk_iss_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_iss_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  TRANSACTIONS (all modules)
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS transactions;
CREATE TABLE transactions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_type           VARCHAR(30)  NOT NULL,
  -- estimate, sales_order, invoice, sales_receipt, statement_charge, finance_charge,
  -- payment, credit_memo, refund, purchase_order, bill, vendor_credit, item_receipt,
  -- bill_payment, check, deposit, transfer, journal, inventory_adjustment,
  -- inventory_transfer, build_assembly, pos_sale, pos_return, depreciation,
  -- asset_disposal, bank_charge, bank_interest, item_processing, cash_voucher, vendor_advance
  txn_no             VARCHAR(30)  NULL,
  txn_date           DATE         NOT NULL,
  due_date           DATE         NULL,
  ship_date          DATE         NULL,
  ref_no             VARCHAR(60)  NULL,
  customer_id        INT UNSIGNED NULL,
  vendor_id          INT UNSIGNED NULL,
  payee_name         VARCHAR(120) NULL,
  account_id         INT UNSIGNED NULL,     -- AR / AP / bank / deposit-to / from account
  account2_id        INT UNSIGNED NULL,     -- transfer-to / cash-back / adjustment account
  site_id            INT UNSIGNED NULL,
  site2_id           INT UNSIGNED NULL,     -- transfer-to site
  terms_id           INT UNSIGNED NULL,
  sales_rep_id       INT UNSIGNED NULL,
  payment_method_id  INT UNSIGNED NULL,
  billing_address    VARCHAR(255) NULL,
  shipping_address   VARCHAR(255) NULL,
  memo               VARCHAR(255) NULL,
  customer_message   VARCHAR(255) NULL,
  subtotal           DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  cash_back_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  total              DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(20)  NOT NULL DEFAULT 'open',
  -- open, closed, paid, partial, pending, accepted, suspended, completed, void
  is_pending         TINYINT(1)   NOT NULL DEFAULT 0,
  is_voided          TINYINT(1)   NOT NULL DEFAULT 0,
  void_reason        VARCHAR(255) NULL,
  voided_by          INT UNSIGNED NULL,
  voided_at          DATETIME     NULL,
  to_be_printed      TINYINT(1)   NOT NULL DEFAULT 0,
  is_printed         TINYINT(1)   NOT NULL DEFAULT 0,
  restock            TINYINT(1)   NOT NULL DEFAULT 1,     -- credit memos / POS returns
  credit_disposition VARCHAR(10)  NULL,                   -- keep, apply, refund
  progress_pct       DECIMAL(6,2) NULL,                   -- progress invoicing
  source_txn_id      BIGINT UNSIGNED NULL,                -- converted from / refund of
  terminal_id        INT UNSIGNED NULL,
  pos_shift_id       INT UNSIGNED NULL,
  receipt_no         VARCHAR(30)  NULL,
  amount_tendered    DECIMAL(15,2) NOT NULL DEFAULT 0,
  change_given       DECIMAL(15,2) NOT NULL DEFAULT 0,
  approved_by        INT UNSIGNED NULL,
  batch_ref          VARCHAR(40)  NULL,
  process_type       VARCHAR(20)  NULL,                   -- item processing: disassembly, dekit, co_product
  alloc_method       VARCHAR(20)  NULL,                   -- item processing: cost allocation method
  process_formula_id INT UNSIGNED NULL,                   -- item processing: formula used
  process_batches    DECIMAL(15,4) NULL,                  -- item processing: formula batches run
  class_id           INT UNSIGNED NULL,                   -- class of the whole transaction (lines may override it)
  other_name_id      INT UNSIGNED NULL,                   -- payee from Employees & Other Names
  received_via       VARCHAR(12)  NULL,                   -- receipt = Receive Items, bill = Receive Items and Enter Bills
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_txn_type_date (txn_type, txn_date),
  KEY ix_txn_customer (customer_id, txn_date),
  KEY ix_txn_vendor (vendor_id, txn_date),
  KEY ix_txn_status (status),
  KEY ix_txn_source (source_txn_id),
  KEY ix_txn_shift (pos_shift_id),
  KEY ix_txn_formula (process_formula_id),
  KEY ix_txn_class (class_id),
  KEY ix_txn_other_name (other_name_id),
  CONSTRAINT fk_txn_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_txn_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_txn_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_txn_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_txn_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_formula  FOREIGN KEY (process_formula_id) REFERENCES process_formulas(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_account2 FOREIGN KEY (account2_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_site     FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_site2    FOREIGN KEY (site2_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_terms    FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_txn_rep      FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_txn_pm       FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS transaction_lines;
CREATE TABLE transaction_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id          BIGINT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  line_kind       VARCHAR(10)  NOT NULL DEFAULT 'item',   -- item, expense, journal, deposit, component, input, output, cost
  item_id         INT UNSIGNED NULL,
  account_id      INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit            VARCHAR(20)  NULL,
  uom_id          INT UNSIGNED NULL,                      -- unit the line was entered in
  uom_factor      DECIMAL(15,6) NOT NULL DEFAULT 1,       -- base units in one entered unit
  uom_qty         DECIMAL(15,4) NOT NULL DEFAULT 0,       -- quantity as entered
  uom_rate        DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price as entered
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_kind   VARCHAR(3)    NOT NULL DEFAULT 'pct',   -- pct = percent of the line, amt = amount off
  discount_pct    DECIMAL(6,2)  NOT NULL DEFAULT 0,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,       -- journal: + debit / - credit
  tax_code_id     INT UNSIGNED NULL,
  tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  site_id         INT UNSIGNED NULL,
  customer_id     INT UNSIGNED NULL,                      -- customer:job for billable costs / journal
  vendor_id       INT UNSIGNED NULL,
  is_billable     TINYINT(1)   NOT NULL DEFAULT 0,
  billed_txn_id   BIGINT UNSIGNED NULL,                   -- invoice that billed this cost
  ref_txn_id      BIGINT UNSIGNED NULL,                   -- deposit: payment / receipt being deposited
  pos_shift_id    INT UNSIGNED NULL,                      -- deposit: the POS shift whose cash the line deposits
  source_line_id  BIGINT UNSIGNED NULL,                   -- estimate / SO / PO line
  qty_fulfilled   DECIMAL(15,4) NOT NULL DEFAULT 0,       -- SO invoiced / PO received
  amount_fulfilled DECIMAL(15,2) NOT NULL DEFAULT 0,      -- estimate progress-invoiced
  is_closed       TINYINT(1)   NOT NULL DEFAULT 0,
  cost_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,       -- COGS captured at posting
  value_change    DECIMAL(15,2) NOT NULL DEFAULT 0,       -- inventory value adjustment
  output_role     VARCHAR(12)  NULL,                      -- item processing output: co_product, by_product, scrap
  alloc_basis     DECIMAL(15,4) NOT NULL DEFAULT 0,       -- item processing: price / weight / percent / value entered
  alloc_pct       DECIMAL(9,4)  NOT NULL DEFAULT 0,       -- item processing: share of the total cost
  planned_qty     DECIMAL(15,4) NULL,                     -- item processing: quantity expected by the formula
  class_id        INT UNSIGNED NULL,                      -- class of this line (blank = the transaction's class)
  other_name_id   INT UNSIGNED NULL,                      -- subsidiary ledger: employee / other name
  group_item_id   INT UNSIGNED NULL,                      -- item group sold: the group this line belongs to
  group_ref       INT UNSIGNED NULL,                      -- which group of the document (several of the same group can be on it)
  group_qty       DECIMAL(15,4) NULL,                     -- groups sold on the line's group
  group_rate      DECIMAL(15,4) NULL,                     -- price of one group
  group_role      VARCHAR(10)  NULL,                      -- member = an item of the group, variance = group price less consolidated price
  KEY ix_tl_txn (txn_id),
  KEY ix_tl_group (group_item_id),
  KEY ix_tl_item (item_id),
  KEY ix_tl_ref (ref_txn_id),
  KEY ix_tl_shift (pos_shift_id),
  KEY ix_tl_source (source_line_id),
  KEY ix_tl_class (class_id),
  KEY ix_tl_other_name (other_name_id),
  CONSTRAINT fk_tl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_tl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_tl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_tl_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tl_tax     FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_tl_site    FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_tl_cust    FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_tl_uom     FOREIGN KEY (uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments / credits applied to invoices and bills
DROP TABLE IF EXISTS payment_applications;
CREATE TABLE payment_applications (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,   -- payment, credit memo, bill payment, vendor credit
  target_txn_id  BIGINT UNSIGNED NOT NULL,   -- invoice, statement/finance charge, bill
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding    DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pa_payment (payment_txn_id),
  KEY ix_pa_target (target_txn_id),
  CONSTRAINT fk_pa_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Withholding Tax Table (BIR Reports): the taxes a payment can have withheld
DROP TABLE IF EXISTS withholding_taxes;
CREATE TABLE withholding_taxes (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(20)  NOT NULL,
  atc                   VARCHAR(10)  NULL,                   -- BIR alphanumeric tax code (WC158, WC160, WV010 ...)
  name                  VARCHAR(100) NOT NULL,
  tax_kind              ENUM('income','vat') NOT NULL DEFAULT 'income',   -- expanded withholding of income tax, or VAT / business tax withheld
  rate                  DECIMAL(9,4) NOT NULL DEFAULT 0,     -- percent
  base_type             ENUM('net_of_vat','gross') NOT NULL DEFAULT 'net_of_vat',
  applies_to            ENUM('purchases','sales','both') NOT NULL DEFAULT 'both',
  payable_account_id    INT UNSIGNED NULL,                   -- withheld from suppliers (blank = module default)
  receivable_account_id INT UNSIGNED NULL,                   -- withheld by customers (blank = module default)
  description           VARCHAR(255) NULL,
  sort_order            INT          NOT NULL DEFAULT 0,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_wht_code (code),
  CONSTRAINT fk_wht_payable FOREIGN KEY (payable_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_wht_receivable FOREIGN KEY (receivable_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taxes withheld by a payment (bill payment or customer payment), by document paid and type: BIR Form 2307 and the BIR Reports
DROP TABLE IF EXISTS payment_withholdings;
CREATE TABLE payment_withholdings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,
  target_txn_id  BIGINT UNSIGNED NULL,        -- the bill / invoice it was withheld on
  wht_id         INT UNSIGNED NOT NULL,
  tax_kind       ENUM('income','vat') NOT NULL DEFAULT 'income',
  atc            VARCHAR(10)  NULL,
  rate           DECIMAL(9,4) NOT NULL DEFAULT 0,
  base           DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_id     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pw_payment (payment_txn_id),
  KEY ix_pw_target (target_txn_id),
  KEY ix_pw_wht (wht_id),
  CONSTRAINT fk_pw_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pw_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pw_wht     FOREIGN KEY (wht_id) REFERENCES withholding_taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item group sales (System Administration > Track Item Group Sales transactions): one row per group sold on a document
DROP TABLE IF EXISTS item_group_sales;
CREATE TABLE item_group_sales (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id         BIGINT UNSIGNED NOT NULL,
  group_ref      INT UNSIGNED NOT NULL DEFAULT 0,
  group_item_id  INT UNSIGNED NOT NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  txn_date       DATE         NOT NULL,
  customer_id    INT UNSIGNED NULL,
  site_id        INT UNSIGNED NULL,
  terminal_id    INT UNSIGNED NULL,
  class_id       INT UNSIGNED NULL,
  sales_rep_id   INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  qty            DECIMAL(15,4) NOT NULL DEFAULT 0,   -- groups sold
  group_price    DECIMAL(15,4) NOT NULL DEFAULT 0,   -- price of one group
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,   -- what the groups sold for (before tax)
  consolidated   DECIMAL(15,2) NOT NULL DEFAULT 0,   -- the items of the groups at their own prices
  variance       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- amount less consolidated (to the price variance account)
  tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_igs_txn (txn_id),
  KEY ix_igs_group (group_item_id, txn_date),
  KEY ix_igs_date (txn_type, txn_date),
  CONSTRAINT fk_igs_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_igs_group FOREIGN KEY (group_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Card and e-wallet receipts ticked to settle on Make Deposits (v32, Details > Settle)
DROP TABLE IF EXISTS clearing_settlements;
CREATE TABLE clearing_settlements (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deposit_txn_id  BIGINT UNSIGNED NOT NULL,                -- the deposit that holds the settlement
  account_id      INT UNSIGNED    NOT NULL,                -- the card / e-wallet clearing account
  receipt_txn_id  BIGINT UNSIGNED NOT NULL,                -- the POS sale, payment or sales receipt settled
  amount          DECIMAL(15,2)   NOT NULL DEFAULT 0,      -- what of it was settled
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_deposit (deposit_txn_id),
  KEY ix_cs_receipt (account_id, receipt_txn_id),
  KEY ix_cs_receipt_txn (receipt_txn_id),
  CONSTRAINT fk_cs_deposit FOREIGN KEY (deposit_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_receipt FOREIGN KEY (receipt_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General ledger (generated automatically by the posting engine)
DROP TABLE IF EXISTS journal_lines;
CREATE TABLE journal_lines (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_date          DATE         NOT NULL,
  account_id        INT UNSIGNED NOT NULL,
  debit             DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit            DECIMAL(15,2) NOT NULL DEFAULT 0,
  customer_id       INT UNSIGNED NULL,
  vendor_id         INT UNSIGNED NULL,
  item_id           INT UNSIGNED NULL,
  site_id           INT UNSIGNED NULL,
  class_id          INT UNSIGNED NULL,
  other_name_id     INT UNSIGNED NULL,
  memo              VARCHAR(255) NULL,
  cleared           TINYINT(1)   NOT NULL DEFAULT 0,
  reconciliation_id INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jl_account_date (account_id, txn_date),
  KEY ix_jl_txn (txn_id),
  KEY ix_jl_customer (customer_id),
  KEY ix_jl_vendor (vendor_id),
  KEY ix_jl_class (class_id, txn_date),
  KEY ix_jl_other_name (other_name_id),
  CONSTRAINT fk_jl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_jl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_jl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perpetual inventory movements (generated automatically)
DROP TABLE IF EXISTS inventory_movements;
CREATE TABLE inventory_movements (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id      BIGINT UNSIGNED NOT NULL,
  txn_line_id BIGINT UNSIGNED NULL,
  txn_date    DATE         NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_cost  DECIMAL(15,2) NOT NULL DEFAULT 0,
  qty_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- total on hand (all sites) after the movement
  avg_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- average cost after the movement
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_im_item_date (item_id, txn_date),
  KEY ix_im_txn (txn_id),
  CONSTRAINT fk_im_txn  FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_im_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_im_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  VENDORS - RECEIPT MANAGEMENT
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS receipt_documents;
CREATE TABLE receipt_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_path     VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime_type     VARCHAR(80)  NULL,
  source        ENUM('upload','email','mobile') NOT NULL DEFAULT 'upload',
  vendor_id     INT UNSIGNED NULL,
  receipt_date  DATE         NULL,
  amount        DECIMAL(15,2) NULL,
  account_id    INT UNSIGNED NULL,
  memo          VARCHAR(255) NULL,
  status        ENUM('for_review','matched','created','rejected') NOT NULL DEFAULT 'for_review',
  linked_txn_id BIGINT UNSIGNED NULL,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME     NULL,
  CONSTRAINT fk_rd_vendor  FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_rd_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_rd_txn     FOREIGN KEY (linked_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  FIXED ASSETS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS asset_categories;
CREATE TABLE asset_categories (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                   VARCHAR(80)  NOT NULL,
  asset_account_id       INT UNSIGNED NOT NULL,
  accum_dep_account_id   INT UNSIGNED NOT NULL,
  dep_expense_account_id INT UNSIGNED NOT NULL,
  default_method         ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL DEFAULT 'straight_line',
  default_life_years     DECIMAL(5,2) NOT NULL DEFAULT 5,
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_ac_asset FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_accum FOREIGN KEY (accum_dep_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_exp   FOREIGN KEY (dep_expense_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_locations;
CREATE TABLE asset_locations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_asset_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS fixed_assets;
CREATE TABLE fixed_assets (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_no            VARCHAR(30)  NOT NULL,
  description         VARCHAR(160) NOT NULL,
  category_id         INT UNSIGNED NOT NULL,
  location            VARCHAR(120) NULL,
  location_id         INT UNSIGNED NULL,
  site_id             INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,      -- default class (depreciation entry when no class is chosen)
  serial_no           VARCHAR(60)  NULL,
  vendor_id           INT UNSIGNED NULL,
  acquisition_date    DATE         NOT NULL,
  cost                DECIMAL(15,2) NOT NULL,
  salvage_value       DECIMAL(15,2) NOT NULL DEFAULT 0,
  useful_life_years   DECIMAL(5,2) NOT NULL,
  method              ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL,
  db_multiplier       DECIMAL(4,2) NOT NULL DEFAULT 2.00,
  total_units         DECIMAL(15,2) NULL,      -- units of production
  unit_label          VARCHAR(30)  NULL,
  -- change in estimate: new basis applied from basis_date forward
  basis_date          DATE         NULL,
  basis_nbv           DECIMAL(15,2) NULL,
  basis_life_months   INT          NULL,
  -- existing assets: depreciation recorded before the system was used
  opening_accum_dep   DECIMAL(15,2) NOT NULL DEFAULT 0,
  opening_through     DATE         NULL,
  acquisition_txn_id  BIGINT UNSIGNED NULL,
  status              ENUM('active','fully_depreciated','disposed') NOT NULL DEFAULT 'active',
  disposal_date       DATE         NULL,
  disposal_proceeds   DECIMAL(15,2) NULL,
  disposal_txn_id     BIGINT UNSIGNED NULL,
  notes               TEXT         NULL,
  current_meter       DECIMAL(15,2) NULL,     -- odometer / running hours
  meter_unit          VARCHAR(20)  NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_fa_no (asset_no),
  KEY ix_fa_location (location_id),
  KEY ix_fa_class (class_id),
  CONSTRAINT fk_fa_location FOREIGN KEY (location_id) REFERENCES asset_locations(id),
  CONSTRAINT fk_fa_class  FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_fa_cat    FOREIGN KEY (category_id) REFERENCES asset_categories(id),
  CONSTRAINT fk_fa_site   FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_fa_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_fa_acq    FOREIGN KEY (acquisition_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_fa_disp   FOREIGN KEY (disposal_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_depreciation;
CREATE TABLE asset_depreciation (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units_used   DECIMAL(15,2) NULL,
  amount       DECIMAL(15,2) NOT NULL,
  txn_id       BIGINT UNSIGNED NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ad_period (asset_id, period_year, period_month),
  CONSTRAINT fk_ad_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ad_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_meter_readings;
CREATE TABLE asset_meter_readings (
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units        DECIMAL(15,2) NOT NULL,
  entered_by   INT UNSIGNED NULL,
  entered_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_id, period_year, period_month),
  CONSTRAINT fk_amr_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fixed asset maintenance and preventive maintenance
DROP TABLE IF EXISTS asset_pm_schedules;
CREATE TABLE asset_pm_schedules (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id         INT UNSIGNED NOT NULL,
  task_name        VARCHAR(160) NOT NULL,
  task_type        VARCHAR(30)  NOT NULL DEFAULT 'other',  -- registration, change_oil, cleaning, inspection, insurance, other
  interval_value   INT          NULL,                      -- repeat every N ... (NULL = one time only)
  interval_unit    VARCHAR(10)  NULL,                      -- days, weeks, months, years
  meter_interval   DECIMAL(15,2) NULL,                     -- or every N km / hours
  next_due_date    DATE         NULL,
  next_due_meter   DECIMAL(15,2) NULL,
  remind_days      INT          NOT NULL DEFAULT 14,       -- start reminding this many days before the due date
  last_done_date   DATE         NULL,
  last_done_meter  DECIMAL(15,2) NULL,
  assigned_to      VARCHAR(120) NULL,
  notes            VARCHAR(255) NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  KEY ix_pm_asset (asset_id),
  KEY ix_pm_due (is_active, next_due_date),
  CONSTRAINT fk_pm_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance;
CREATE TABLE asset_maintenance (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maint_no            VARCHAR(30)  NOT NULL,
  asset_id            INT UNSIGNED NOT NULL,
  maint_date          DATE         NOT NULL,
  maint_type          VARCHAR(20)  NOT NULL DEFAULT 'repair',     -- repair, preventive, inspection, upgrade
  status              VARCHAR(20)  NOT NULL DEFAULT 'completed',  -- in_progress, completed, void
  problem             VARCHAR(255) NULL,                          -- what was reported / why
  description         TEXT         NULL,                          -- maintenance description: the work done
  vendor_id           INT UNSIGNED NULL,                          -- supplier of the parts / service
  performed_by        VARCHAR(120) NULL,                          -- mechanic, technician or shop
  ref_no              VARCHAR(60)  NULL,                          -- supplier invoice / job order no.
  meter_reading       DECIMAL(15,2) NULL,
  downtime_days       DECIMAL(7,2) NULL,
  parts_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  labor_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total           DECIMAL(15,2) NOT NULL DEFAULT 0,
  total               DECIMAL(15,2) NOT NULL DEFAULT 0,
  with_vat            TINYINT(1)   NOT NULL DEFAULT 0,
  payment_mode        VARCHAR(20)  NOT NULL DEFAULT 'none',       -- none, bill, check, cash_voucher
  pay_account_id      INT UNSIGNED NULL,                          -- bank (check) or cash account (cash voucher)
  parts_account_id    INT UNSIGNED NULL,
  labor_account_id    INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,
  txn_id              BIGINT UNSIGNED NULL,                       -- the bill, check or cash voucher created for it
  schedule_id         INT UNSIGNED NULL,                          -- preventive maintenance task completed by this record
  next_service_date   DATE         NULL,
  notes               TEXT         NULL,
  void_reason         VARCHAR(255) NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_maint_no (maint_no),
  KEY ix_maint_asset (asset_id, maint_date),
  KEY ix_maint_vendor (vendor_id),
  KEY ix_maint_txn (txn_id),
  KEY ix_maint_class (class_id),
  KEY ix_maint_schedule (schedule_id),
  CONSTRAINT fk_maint_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id),
  CONSTRAINT fk_maint_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_maint_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_maint_txn      FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_maint_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_parts;
CREATE TABLE asset_maintenance_parts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  part_no         VARCHAR(60)  NULL,
  description     VARCHAR(200) NOT NULL,
  serial_no       VARCHAR(60)  NULL,
  model_no        VARCHAR(60)  NULL,
  size_spec       VARCHAR(60)  NULL,
  brand           VARCHAR(60)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 1,
  unit            VARCHAR(20)  NULL,
  unit_cost       DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  warranty_until  DATE         NULL,
  KEY ix_mparts_maint (maintenance_id),
  KEY ix_mparts_partno (part_no),
  CONSTRAINT fk_mparts_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_labor;
CREATE TABLE asset_maintenance_labor (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  description     VARCHAR(200) NOT NULL,
  worker          VARCHAR(120) NULL,
  charge_type     VARCHAR(10)  NOT NULL DEFAULT 'hourly',   -- hourly (man-hours x rate) or bulk (one charge)
  hours           DECIMAL(9,2) NOT NULL DEFAULT 0,
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_mlabor_maint (maintenance_id),
  CONSTRAINT fk_mlabor_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_photos;
CREATE TABLE asset_maintenance_photos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  file_name       VARCHAR(120) NOT NULL,      -- name inside storage/maintenance/
  original_name   VARCHAR(190) NULL,
  mime_type       VARCHAR(60)  NULL,
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  caption         VARCHAR(190) NULL,
  uploaded_by     INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mphotos_maint (maintenance_id),
  CONSTRAINT fk_mphotos_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_pm_log;
CREATE TABLE asset_pm_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_id     INT UNSIGNED NOT NULL,
  asset_id        INT UNSIGNED NOT NULL,
  task_name       VARCHAR(160) NOT NULL,
  due_date        DATE         NULL,
  done_date       DATE         NOT NULL,
  done_meter      DECIMAL(15,2) NULL,
  maintenance_id  INT UNSIGNED NULL,
  remarks         VARCHAR(255) NULL,
  done_by         INT UNSIGNED NULL,          -- the user who acknowledged that the task is complete
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pmlog_schedule (schedule_id),
  KEY ix_pmlog_asset (asset_id, done_date),
  CONSTRAINT fk_pmlog_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_pmlog_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  POINT OF SALE
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS pos_terminals;
CREATE TABLE pos_terminals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(80)  NOT NULL,
  site_id         INT UNSIGNED NOT NULL,
  cash_account_id INT UNSIGNED NOT NULL,
  receipt_prefix  VARCHAR(10)  NOT NULL DEFAULT 'OR',
  next_receipt_no INT UNSIGNED NOT NULL DEFAULT 1,
  next_z_no       INT UNSIGNED NOT NULL DEFAULT 1,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  class_id        INT UNSIGNED NULL,                      -- default class of sales made at this terminal
  UNIQUE KEY uq_term_code (code),
  CONSTRAINT fk_term_class FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_term_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_term_cash FOREIGN KEY (cash_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_shifts;
CREATE TABLE pos_shifts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  terminal_id    INT UNSIGNED NOT NULL,
  cashier_id     INT UNSIGNED NOT NULL,
  business_date  DATE         NOT NULL,
  opened_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_float  DECIMAL(15,2) NOT NULL DEFAULT 0,
  closed_at      DATETIME     NULL,
  expected_cash  DECIMAL(15,2) NULL,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  deposit_txn_id BIGINT UNSIGNED NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_by      INT UNSIGNED NULL,
  corrected_by   INT UNSIGNED NULL,          -- who last corrected the closing entries (float, drops, count)
  corrected_at   DATETIME     NULL,
  KEY ix_shift_term (terminal_id, status),
  CONSTRAINT fk_shift_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_shift_user FOREIGN KEY (cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_tenders;
CREATE TABLE pos_tenders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  payment_method_id INT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,     -- net amount applied to the sale (after change)
  reference         VARCHAR(60)  NULL,
  KEY ix_pt_txn (txn_id),
  CONSTRAINT fk_pt_txn FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_pm  FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_cash_drops;
CREATE TABLE pos_cash_drops (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_id    INT UNSIGNED NOT NULL,
  amount      DECIMAL(15,2) NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cd_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_readings;
CREATE TABLE pos_readings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reading_type   ENUM('X','Z') NOT NULL,
  terminal_id    INT UNSIGNED NOT NULL,
  shift_id       INT UNSIGNED NOT NULL,
  z_no           INT UNSIGNED NULL,
  business_date  DATE         NOT NULL,
  first_receipt  VARCHAR(30)  NULL,
  last_receipt   VARCHAR(30)  NULL,
  sales_count    INT          NOT NULL DEFAULT 0,
  gross_sales    DECIMAL(15,2) NOT NULL DEFAULT 0,
  discounts      DECIMAL(15,2) NOT NULL DEFAULT 0,
  returns        DECIMAL(15,2) NOT NULL DEFAULT 0,
  voids          DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_sales      DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax            DECIMAL(15,2) NOT NULL DEFAULT 0,
  tenders_json   TEXT         NULL,
  cash_drops     DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_cash  DECIMAL(15,2) NOT NULL DEFAULT 0,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  created_by     INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_term  FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_pr_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time approval codes a supervisor generates for a cashier or clerk (used once, then expired)
DROP TABLE IF EXISTS approval_codes;
CREATE TABLE approval_codes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(12)  NOT NULL,
  purpose       VARCHAR(160) NULL,
  for_user_id   INT UNSIGNED NULL,          -- the user it is meant for (NULL: any user)
  page_key      VARCHAR(60)  NULL,          -- the page it may approve on (NULL: any page the issuer may approve on)
  max_amount    DECIMAL(15,2) NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'open',   -- open / used / expired / cancelled
  issued_by     INT UNSIGNED NOT NULL,
  issued_at     DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_by       INT UNSIGNED NULL,
  used_at       DATETIME     NULL,
  used_on       VARCHAR(160) NULL,
  used_txn_id   BIGINT UNSIGNED NULL,
  cancelled_by  INT UNSIGNED NULL,
  cancelled_at  DATETIME     NULL,
  UNIQUE KEY uq_approval_codes_code (code),
  KEY ix_approval_codes_status (status, expires_at),
  KEY ix_approval_codes_issued (issued_by, issued_at),
  CONSTRAINT fk_apc_issuer FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BANKING
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS bank_reconciliations;
CREATE TABLE bank_reconciliations (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id         INT UNSIGNED NOT NULL,
  statement_date     DATE         NOT NULL,
  beginning_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
  ending_balance     DECIMAL(15,2) NOT NULL DEFAULT 0,
  cleared_balance    DECIMAL(15,2) NOT NULL DEFAULT 0,
  difference         DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_charge_txn_id BIGINT UNSIGNED NULL,
  interest_txn_id    BIGINT UNSIGNED NULL,
  service_charge     DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_date       DATE         NULL,
  service_account_id INT UNSIGNED NULL,
  interest_earned    DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_date      DATE         NULL,
  interest_account_id INT UNSIGNED NULL,
  class_id           INT UNSIGNED NULL,
  status             ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_by       INT UNSIGNED NULL,
  completed_at       DATETIME     NULL,
  KEY ix_br_account (account_id, statement_date),
  CONSTRAINT fk_br_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of the items cleared in each reconciliation (used by the reports)
DROP TABLE IF EXISTS reconciliation_items;
CREATE TABLE reconciliation_items (
  reconciliation_id INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,   -- + deposit / - payment
  cleared           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (reconciliation_id, txn_id),
  CONSTRAINT fk_ri_rec FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleared transactions changed after reconciliation
DROP TABLE IF EXISTS reconciliation_discrepancies;
CREATE TABLE reconciliation_discrepancies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reconciliation_id INT UNSIGNED NULL,
  account_id        INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_ref           VARCHAR(80)  NULL,
  change_type       VARCHAR(20)  NOT NULL,   -- amount changed, voided, deleted, uncleared
  cleared_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  new_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  changed_by        INT UNSIGNED NULL,
  changed_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rd_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SECURITY - site and terminal assignments
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS role_sites;
CREATE TABLE role_sites (
  role_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, site_id),
  CONSTRAINT fk_rs_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_sites;
CREATE TABLE user_sites (
  user_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, site_id),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_us_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_terminals;
CREATE TABLE user_terminals (
  user_id     INT UNSIGNED NOT NULL,
  terminal_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, terminal_id),
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ut_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Memorized transactions, budgets and forecasts, report layouts,
--  CSV imports and database backups
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS memorized_txns;
CREATE TABLE memorized_txns (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  group_name     VARCHAR(60)  NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  entity_label   VARCHAR(160) NULL,               -- customer / supplier / payee, for the list
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  payload        LONGTEXT     NOT NULL,           -- the header and the lines of the saved transaction
  how            VARCHAR(20)  NOT NULL DEFAULT 'remind',   -- remind, auto, none (list only)
  frequency      VARCHAR(10)  NOT NULL DEFAULT 'monthly',  -- daily, weekly, months, quarterly, yearly
  every_n        INT          NOT NULL DEFAULT 1,
  next_date      DATE         NULL,
  days_in_advance INT         NOT NULL DEFAULT 5,
  remaining      INT          NULL,               -- how many are left to enter (NULL = no limit)
  end_date       DATE         NULL,
  last_entered   DATE         NULL,
  entered_count  INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  KEY ix_mt_due (is_active, next_date),
  KEY ix_mt_type (txn_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budgets;
CREATE TABLE budgets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  kind          VARCHAR(10)  NOT NULL DEFAULT 'pl',   -- pl = income statement, bs = balance sheet, forecast = sales forecast
  fiscal_year   SMALLINT     NOT NULL,
  start_month   TINYINT      NOT NULL DEFAULT 1,      -- the month the fiscal year starts in
  class_id      INT UNSIGNED NULL,
  customer_id   INT UNSIGNED NULL,                    -- forecast for one customer (NULL = all customers)
  notes         VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_budget (kind, fiscal_year, name),
  KEY ix_budget_class (class_id),
  KEY ix_budget_customer (customer_id),
  CONSTRAINT fk_budget_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_budget_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budget_lines;
CREATE TABLE budget_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id    INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  customer_id  INT UNSIGNED NULL,                 -- forecasts: the customer the amounts belong to
  period_month TINYINT      NOT NULL,             -- 1..12, counted from the start of the fiscal year
  amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_budget_line (budget_id, account_id, customer_id, period_month),
  KEY ix_bl_account (account_id),
  CONSTRAINT fk_bl_budget   FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_bl_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS report_layouts;
CREATE TABLE report_layouts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_key   VARCHAR(60)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  title        VARCHAR(160) NULL,                 -- a heading of your own for the print-out
  columns      TEXT         NULL,                 -- key:Heading, one per line, in the order they print; a leading - means hidden
  options      TEXT         NULL,                 -- name=value, one per line: negatives, zeros, decimals, density and the rest
  is_shared    TINYINT(1)   NOT NULL DEFAULT 0,
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,
  KEY ix_rl_report (report_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS import_batches;
CREATE TABLE import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_type   VARCHAR(40)  NOT NULL,
  file_name     VARCHAR(190) NULL,
  rows_total    INT          NOT NULL DEFAULT 0,
  rows_ok       INT          NOT NULL DEFAULT 0,
  rows_failed   INT          NOT NULL DEFAULT 0,
  created_ids   LONGTEXT     NULL,                -- what the import created, so it can be traced
  messages      LONGTEXT     NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ib_type (import_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS backup_log;
CREATE TABLE backup_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(10)  NOT NULL,             -- backup, restore
  file_name    VARCHAR(190) NOT NULL,
  file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tables_count INT          NOT NULL DEFAULT 0,
  rows_count   INT          NOT NULL DEFAULT 0,
  note         VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bl_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  BARANGAY CONTRACTS (supplies, services and works for barangays and
--  other procuring entities): one traceable record from the opportunity
--  through the bid, award, delivery, acceptance, billing, collection and
--  closeout, posted to the ledger through the transactions above.
-- ---------------------------------------------------------------------

-- Procurement modes: configuration with effective dates, never constants in the program
DROP TABLE IF EXISTS brgy_proc_modes;
CREATE TABLE brgy_proc_modes (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(30)  NOT NULL,
  name                  VARCHAR(120) NOT NULL,
  regime                VARCHAR(10)  NOT NULL DEFAULT 'ra12009',  -- ra12009 (New Government Procurement Act), ra9184 (repealed law, transition cases)
  legal_basis           VARCHAR(255) NULL,
  source_url            VARCHAR(255) NULL,
  max_abc               DECIMAL(15,2) NULL,                     -- ceiling verified from the current rules (blank = not set)
  needs_bid_security    TINYINT(1)   NOT NULL DEFAULT 0,
  needs_perf_security   TINYINT(1)   NOT NULL DEFAULT 0,
  default_retention_pct DECIMAL(6,2) NULL,
  effective_from        DATE         NULL,
  effective_to          DATE         NULL,
  notes                 VARCHAR(255) NULL,
  sort_order            INT          NOT NULL DEFAULT 0,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_bpm_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regulatory references: laws, rules and issuances the module follows, with dates and the last review
DROP TABLE IF EXISTS brgy_references;
CREATE TABLE brgy_references (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40)  NOT NULL,
  title          VARCHAR(255) NOT NULL,
  issuer         VARCHAR(80)  NULL,
  ref_type       VARCHAR(20)  NOT NULL DEFAULT 'law',     -- law, irr, resolution, circular, regulation, memorandum, other
  issued_date    DATE         NULL,
  published_date DATE         NULL,
  effective_date DATE         NULL,
  url            VARCHAR(255) NULL,
  applies_to     VARCHAR(255) NULL,
  notes          TEXT         NULL,
  last_reviewed  DATE         NULL,
  reviewed_by    INT UNSIGNED NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  UNIQUE KEY uq_bref_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kinds of documents kept in the vault (company eligibility papers, bid papers, delivery and billing papers)
DROP TABLE IF EXISTS brgy_doc_types;
CREATE TABLE brgy_doc_types (
  code             VARCHAR(40)  NOT NULL PRIMARY KEY,
  name             VARCHAR(160) NOT NULL,
  doc_group        VARCHAR(20)  NOT NULL DEFAULT 'other',  -- legal, technical, financial, bid, contract, delivery, billing, collection, other
  is_company       TINYINT(1)   NOT NULL DEFAULT 0,        -- a company paper reused on every bid (permits, registrations ...)
  needs_validity   TINYINT(1)   NOT NULL DEFAULT 0,
  validity_months  INT          NULL,                      -- usual validity, to suggest the expiry date
  sort_order       INT          NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Government-customer directory: barangays and other procuring entities
DROP TABLE IF EXISTS brgy_entities;
CREATE TABLE brgy_entities (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(30)  NULL,
  official_name      VARCHAR(160) NOT NULL,
  entity_type        VARCHAR(20)  NOT NULL DEFAULT 'barangay',   -- barangay, municipality, city, province, agency, other
  region             VARCHAR(80)  NULL,
  province           VARCHAR(80)  NULL,
  city_municipality  VARCHAR(80)  NULL,
  barangay           VARCHAR(80)  NULL,
  address            VARCHAR(255) NULL,
  zip_code           VARCHAR(10)  NULL,
  tin                VARCHAR(30)  NULL,
  phone              VARCHAR(40)  NULL,
  email              VARCHAR(120) NULL,
  customer_id        INT UNSIGNED NULL,                          -- the entity as a customer (A/R)
  submission_methods VARCHAR(120) NULL,                          -- physical, email, philgeps, courier, portal
  submission_notes   TEXT         NULL,
  funding_notes      TEXT         NULL,
  requirement_notes  TEXT         NULL,
  seal_file              VARCHAR(120) NULL,                         -- seal of the barangay (picture in storage/contracts)
  lgu_seal_file          VARCHAR(120) NULL,                         -- seal of the city / municipality
  extra_bid_papers       TINYINT(1)   NOT NULL DEFAULT 0,           -- asks for the ABC and the Aboitiz requirements (e.g. Cebu City offices)
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME     NULL,
  KEY ix_be_name (official_name),
  KEY ix_be_customer (customer_id),
  CONSTRAINT fk_be_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Officials and contact persons, kept with their dates so a change of officials keeps the history
DROP TABLE IF EXISTS brgy_contacts;
CREATE TABLE brgy_contacts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_id    INT UNSIGNED NOT NULL,
  role_code    VARCHAR(30)  NOT NULL DEFAULT 'other',
  name         VARCHAR(120) NOT NULL,
  position     VARCHAR(120) NULL,
  department   VARCHAR(120) NULL,
  phone        VARCHAR(40)  NULL,
  email        VARCHAR(120) NULL,
  valid_from   DATE         NULL,
  valid_to     DATE         NULL,                                -- blank = in office now
  is_signatory TINYINT(1)   NOT NULL DEFAULT 0,
  notes        VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bc_entity (entity_id, role_code),
  CONSTRAINT fk_bc_entity FOREIGN KEY (entity_id) REFERENCES brgy_entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The project: opportunity, bid, award, contract, fulfilment, billing, collection and closeout
DROP TABLE IF EXISTS brgy_projects;
CREATE TABLE brgy_projects (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_no             VARCHAR(30)  NOT NULL,
  title                  VARCHAR(255) NOT NULL,
  entity_id              INT UNSIGNED NOT NULL,
  category               VARCHAR(20)  NOT NULL DEFAULT 'goods',     -- goods, services, works, consultancy
  mode_id                INT UNSIGNED NULL,
  regime                 VARCHAR(10)  NOT NULL DEFAULT 'ra12009',
  reference_no           VARCHAR(80)  NULL,                         -- PR / RFQ / ITB number of the procuring entity
  philgeps_ref           VARCHAR(40)  NULL,
  source_url             VARCHAR(255) NULL,
  posting_date           DATE         NULL,
  end_user               VARCHAR(120) NULL,
  funding_source         VARCHAR(160) NULL,
  abc_amount             DECIMAL(15,2) NOT NULL DEFAULT 0,           -- approved budget for the contract
  delivery_site          VARCHAR(255) NULL,
  delivery_days          INT          NULL,                         -- calendar days from the notice to proceed / PO
  site_inspection_at     DATETIME     NULL,
  prebid_at              DATETIME     NULL,
  clarification_deadline DATETIME     NULL,
  bid_deadline           DATETIME     NULL,
  bid_opening_at         DATETIME     NULL,
  specs                  TEXT         NULL,
  eligibility_notes      TEXT         NULL,
  go_decision            VARCHAR(10)  NOT NULL DEFAULT 'pending',   -- pending, go, no_go
  go_reason              VARCHAR(255) NULL,
  go_by                  INT UNSIGNED NULL,
  go_at                  DATETIME     NULL,
  phase                  VARCHAR(20)  NOT NULL DEFAULT 'intake',
  status                 VARCHAR(20)  NOT NULL DEFAULT 'open',      -- open, awarded, lost, no_bid, cancelled, closed
  bid_status             VARCHAR(20)  NOT NULL DEFAULT 'draft',     -- draft, for_review, reviewed, approved, returned, released
  bid_version            INT          NOT NULL DEFAULT 0,
  bid_amount             DECIMAL(15,2) NOT NULL DEFAULT 0,
  est_cost               DECIMAL(15,2) NOT NULL DEFAULT 0,
  bid_submitted_at       DATETIME     NULL,
  result_rank            INT          NULL,
  lost_reason            VARCHAR(255) NULL,
  winning_bidder         VARCHAR(160) NULL,
  winning_amount         DECIMAL(15,2) NULL,
  cancel_reason          VARCHAR(255) NULL,
  contract_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,          -- amount awarded
  co_amount              DECIMAL(15,2) NOT NULL DEFAULT 0,          -- approved change orders
  contract_no            VARCHAR(60)  NULL,
  po_no                  VARCHAR(60)  NULL,
  noa_ref                VARCHAR(60)  NULL,
  noa_date               DATE         NULL,                         -- issued by the procuring entity
  noa_received_date      DATE         NULL,                         -- received by the company
  noa_ack_date           DATE         NULL,                         -- acknowledged / conforme
  contract_signed_date   DATE         NULL,
  ntp_date               DATE         NULL,
  ntp_received_date      DATE         NULL,
  start_date             DATE         NULL,
  due_date               DATE         NULL,                         -- delivery / completion due
  completion_date        DATE         NULL,
  warranty_months        INT          NULL,
  warranty_until         DATE         NULL,
  retention_pct          DECIMAL(6,2) NOT NULL DEFAULT 0,
  ld_rate_pct            DECIMAL(8,4) NOT NULL DEFAULT 0,           -- % of the undelivered portion per day of delay
  terms_id               INT UNSIGNED NULL,
  payment_terms          VARCHAR(255) NULL,
  customer_id            INT UNSIGNED NULL,                         -- the procuring entity as a customer
  job_customer_id        INT UNSIGNED NULL,                         -- Customer:Job made at award (job costing)
  class_id               INT UNSIGNED NULL,
  site_id                INT UNSIGNED NULL,                         -- warehouse the goods are delivered from
  preparer_id            INT UNSIGNED NULL,
  checker_id             INT UNSIGNED NULL,
  approver_id            INT UNSIGNED NULL,
  submitter_id           INT UNSIGNED NULL,
  pm_id                  INT UNSIGNED NULL,
  collector_id           INT UNSIGNED NULL,
  closeout_date          DATE         NULL,
  closeout_notes         TEXT         NULL,
  notes                  TEXT         NULL,
  process                VARCHAR(10)  NOT NULL DEFAULT 'general',   -- svp (small value, the SOP below 100K), bidding (the SOP bidding process), general (the 12 phases)
  sop_step               VARCHAR(20)  NULL,                         -- the SOP step the transaction is at (brgy_sop_steps.code)
  txn_form_no            VARCHAR(30)  NULL,                         -- series no. of the transaction form
  txn_date               DATE         NULL,
  tradename              VARCHAR(160) NULL,                         -- trade name the transaction is under
  txn_type               VARCHAR(20)  NULL,                         -- cash_out, kaliwaan, purchases, construction ... (Contracting Settings)
  requestor              VARCHAR(120) NULL,
  requestor_position     VARCHAR(120) NULL,
  inspector              VARCHAR(120) NULL,
  inspector_position     VARCHAR(120) NULL,
  purpose                VARCHAR(255) NULL,
  sales_rep_id           INT UNSIGNED NULL,                         -- the sales representative who brought the transaction
  details_complete       TINYINT(1)   NOT NULL DEFAULT 0,           -- SOP step 1: the details are complete
  fund                   VARCHAR(60)  NULL,                         -- fund the barangay pays from (DV)
  pr_date                DATE         NULL,
  po_date                DATE         NULL,
  ris_no                 VARCHAR(40)  NULL,
  ris_date               DATE         NULL,
  iar_no                 VARCHAR(40)  NULL,
  iar_date               DATE         NULL,
  dv_no                  VARCHAR(40)  NULL,
  dv_date                DATE         NULL,
  delivery_date          DATE         NULL,                         -- date of delivery on the purchase order
  delivery_term          VARCHAR(120) NULL,
  payment_status         VARCHAR(12)  NOT NULL DEFAULT 'waiting',   -- waiting, released (the cheque is ready)
  cheque_no              VARCHAR(40)  NULL,
  cheque_date            DATE         NULL,
  cheque_bank            VARCHAR(80)  NULL,
  cheque_amount          DECIMAL(15,2) NULL,
  payment_released_at    DATETIME     NULL,
  forwarded_at           DATETIME     NULL,                         -- documents forwarded to the Accounting Department
  forwarded_to           VARCHAR(120) NULL,
  release_method         VARCHAR(12)  NULL,                         -- sales_rep (delivered by the sales representative), pickup (picked up at the office)
  released_at            DATETIME     NULL,
  released_to            VARCHAR(120) NULL,
  transmittal_no         VARCHAR(40)  NULL,
  transmittal_returned_at DATETIME     NULL,                        -- the signed transmittal came back
  bid_endorsed_at        DATETIME     NULL,                         -- bidding documents endorsed to the representative
  lines_lookup_inventory TINYINT(1)   NOT NULL DEFAULT 0,           -- v34: the price schedule looks up the Inventory List
  est_gross              DECIMAL(15,2) NULL,                        -- v34: estimates and targets of the transaction form (no accounting entry)
  est_wt_rate            DECIMAL(5,2) NULL,                         --      withholding tax % of the gross
  est_profit_rate        DECIMAL(5,2) NULL,                         --      profit % of the gross
  est_receipt_rate       DECIMAL(5,2) NULL,                         --      receipt deduction % of the gross
  est_sop                DECIMAL(15,2) NULL,                        --      SOP (amount)
  dv_gross               DECIMAL(15,2) NULL,                        -- v34: gross amount entered on the DV
  dv_vat_rate            DECIMAL(5,2) NULL,                         --      VAT of the DV: 5 (gross / 1.12 x 5%), 3 (gross x 3%), 0 none
  dv_ewt_rate            DECIMAL(5,2) NULL,                         --      EWT of the DV: 2 (gross / 1.12 x 2%), 1, 0 none
  cheque_tradename       VARCHAR(160) NULL,                         -- v34: the tradename the cheque was released to
  lock_version           INT UNSIGNED NOT NULL DEFAULT 0,
  created_by             INT UNSIGNED NULL,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by             INT UNSIGNED NULL,
  updated_at             DATETIME     NULL,
  KEY ix_bp_process (process, sop_step),
  UNIQUE KEY uq_bp_no (project_no),
  KEY ix_bp_entity (entity_id),
  KEY ix_bp_phase (phase, status),
  KEY ix_bp_job (job_customer_id),
  KEY ix_bp_deadline (bid_deadline),
  CONSTRAINT fk_bp_entity   FOREIGN KEY (entity_id) REFERENCES brgy_entities(id),
  CONSTRAINT fk_bp_mode     FOREIGN KEY (mode_id) REFERENCES brgy_proc_modes(id),
  CONSTRAINT fk_bp_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_bp_job      FOREIGN KEY (job_customer_id) REFERENCES customers(id),
  CONSTRAINT fk_bp_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_bp_terms    FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_bp_site     FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price schedule / bill of quantities: the lines quoted, then contracted (change orders included)
DROP TABLE IF EXISTS brgy_project_lines;
CREATE TABLE brgy_project_lines (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id    INT UNSIGNED NOT NULL,
  line_no       INT          NOT NULL DEFAULT 0,
  line_kind     VARCHAR(10)  NOT NULL DEFAULT 'goods',             -- goods, service, works, other
  item_id       INT UNSIGNED NULL,
  description   VARCHAR(255) NOT NULL,
  specs         TEXT         NULL,
  unit          VARCHAR(20)  NULL,
  bid_qty       DECIMAL(15,4) NOT NULL DEFAULT 0,                   -- as quoted
  qty           DECIMAL(15,4) NOT NULL DEFAULT 0,                   -- as contracted (approved change orders included)
  unit_cost     DECIMAL(15,4) NOT NULL DEFAULT 0,                   -- estimated cost per unit (materials, labor, delivery)
  markup_pct    DECIMAL(8,4)  NOT NULL DEFAULT 0,
  unit_price    DECIMAL(15,4) NOT NULL DEFAULT 0,                   -- price per unit as quoted (VAT included when set so)
  est_unit_price         DECIMAL(15,4) NULL,                        -- estimated unit cost on the purchase request (blank = the unit price)
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_code_id   INT UNSIGNED NULL,
  account_id    INT UNSIGNED NULL,                                  -- income account of a line without an item
  qty_delivered DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_accepted  DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_rejected  DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_billed    DECIMAL(15,4) NOT NULL DEFAULT 0,
  co_id         INT UNSIGNED NULL,                                  -- line added by a change order
  delivery_due  DATE         NULL,
  notes         VARCHAR(255) NULL,
  KEY ix_bpl_project (project_id, line_no),
  KEY ix_bpl_item (item_id),
  CONSTRAINT fk_bpl_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_bpl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_bpl_tax     FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_bpl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every revision of the costing and price schedule sent for approval (read-only copies)
DROP TABLE IF EXISTS brgy_cost_versions;
CREATE TABLE brgy_cost_versions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id  INT UNSIGNED NOT NULL,
  version_no  INT          NOT NULL,
  est_cost    DECIMAL(15,2) NOT NULL DEFAULT 0,
  bid_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  margin_pct  DECIMAL(9,4)  NOT NULL DEFAULT 0,
  lines_json  LONGTEXT     NULL,
  note        VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bcv (project_id, version_no),
  CONSTRAINT fk_bcv_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requirement library: suggests each project's checklist from regime, mode, category, amount band and dates
DROP TABLE IF EXISTS brgy_requirements;
CREATE TABLE brgy_requirements (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code             VARCHAR(40)  NOT NULL,
  name             VARCHAR(200) NOT NULL,
  description      TEXT         NULL,
  doc_group        VARCHAR(20)  NOT NULL DEFAULT 'legal',
  stage            VARCHAR(20)  NOT NULL DEFAULT 'eligibility',     -- eligibility, bid, post_qual, contract, delivery, billing
  requirement_type VARCHAR(12)  NOT NULL DEFAULT 'mandatory',       -- mandatory, conditional, optional
  regimes          VARCHAR(40)  NULL,                               -- ra12009,ra9184 (blank = both)
  categories       VARCHAR(60)  NULL,                               -- goods,services,works,consultancy (blank = all)
  mode_codes       VARCHAR(255) NULL,                               -- procurement modes (blank = all)
  min_abc          DECIMAL(15,2) NULL,
  max_abc          DECIMAL(15,2) NULL,
  needs_validity   TINYINT(1)   NOT NULL DEFAULT 0,
  doc_type         VARCHAR(40)  NULL,                               -- the vault document that satisfies it
  template_code    VARCHAR(40)  NULL,                               -- the form that prepares it
  is_prescribed    TINYINT(1)   NOT NULL DEFAULT 0,                 -- a form prescribed by the GPPB / procuring entity
  legal_basis      VARCHAR(255) NULL,
  source_url       VARCHAR(255) NULL,
  entity_id        INT UNSIGNED NULL,                               -- required by one procuring entity only
  effective_from   DATE         NULL,
  effective_to     DATE         NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'approved',        -- proposed, approved
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME     NULL,
  sort_order       INT          NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  UNIQUE KEY uq_breq_code (code),
  KEY ix_breq_entity (entity_id),
  CONSTRAINT fk_breq_entity FOREIGN KEY (entity_id) REFERENCES brgy_entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document vault: files with type, validity, checksum, versions, review and lock
DROP TABLE IF EXISTS brgy_documents;
CREATE TABLE brgy_documents (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope          VARCHAR(10)  NOT NULL DEFAULT 'project',           -- company, project, entity, supplier
  project_id     INT UNSIGNED NULL,
  entity_id      INT UNSIGNED NULL,
  vendor_id      INT UNSIGNED NULL,
  doc_type       VARCHAR(40)  NOT NULL DEFAULT 'other',
  title          VARCHAR(200) NOT NULL,
  doc_no         VARCHAR(80)  NULL,
  issued_by      VARCHAR(160) NULL,
  issue_date     DATE         NULL,
  valid_until    DATE         NULL,
  file_name      VARCHAR(120) NULL,
  original_name  VARCHAR(190) NULL,
  mime_type      VARCHAR(100) NULL,
  file_size      INT UNSIGNED NULL,
  sha256         CHAR(64)     NULL,
  version_no     INT          NOT NULL DEFAULT 1,
  parent_id      INT UNSIGNED NULL,                                 -- the version it replaced
  is_current     TINYINT(1)   NOT NULL DEFAULT 1,
  status         VARCHAR(12)  NOT NULL DEFAULT 'for_review',        -- for_review, approved, returned, final, superseded, void
  is_locked      TINYINT(1)   NOT NULL DEFAULT 0,
  confidential   TINYINT(1)   NOT NULL DEFAULT 0,
  related_type   VARCHAR(20)  NULL,                                 -- requirement, bid, award, delivery, billing, collection, security, change_order, form
  related_id     INT UNSIGNED NULL,
  tags           VARCHAR(255) NULL,
  notes          VARCHAR(255) NULL,
  scan_status    VARCHAR(40)  NULL,
  uploaded_by    INT UNSIGNED NULL,
  uploaded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME     NULL,
  review_remarks VARCHAR(255) NULL,
  KEY ix_bd_project (project_id, doc_type),
  KEY ix_bd_scope (scope, doc_type, is_current),
  KEY ix_bd_related (related_type, related_id),
  KEY ix_bd_valid (valid_until),
  KEY ix_bd_sha (sha256),
  CONSTRAINT fk_bd_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id),
  CONSTRAINT fk_bd_entity  FOREIGN KEY (entity_id) REFERENCES brgy_entities(id),
  CONSTRAINT fk_bd_vendor  FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A project's compliance checklist (made from the library, plus items added for the project)
DROP TABLE IF EXISTS brgy_project_reqs;
CREATE TABLE brgy_project_reqs (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id       INT UNSIGNED NOT NULL,
  requirement_id   INT UNSIGNED NULL,
  name             VARCHAR(200) NOT NULL,
  doc_group        VARCHAR(20)  NOT NULL DEFAULT 'legal',
  stage            VARCHAR(20)  NOT NULL DEFAULT 'eligibility',
  requirement_type VARCHAR(12)  NOT NULL DEFAULT 'mandatory',
  needs_validity   TINYINT(1)   NOT NULL DEFAULT 0,
  doc_type         VARCHAR(40)  NULL,
  template_code    VARCHAR(40)  NULL,
  legal_basis      VARCHAR(255) NULL,
  due_date         DATE         NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'missing',         -- missing, submitted, approved, returned, waived
  document_id      INT UNSIGNED NULL,
  waive_reason     VARCHAR(255) NULL,
  reviewed_by      INT UNSIGNED NULL,
  reviewed_at      DATETIME     NULL,
  remarks          VARCHAR(255) NULL,
  sort_order       INT          NOT NULL DEFAULT 0,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  KEY ix_bpr_project (project_id, stage),
  CONSTRAINT fk_bpr_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_bpr_req     FOREIGN KEY (requirement_id) REFERENCES brgy_requirements(id) ON DELETE SET NULL,
  CONSTRAINT fk_bpr_doc     FOREIGN KEY (document_id) REFERENCES brgy_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proforma and form templates (editable, versioned)
DROP TABLE IF EXISTS brgy_templates;
CREATE TABLE brgy_templates (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code             VARCHAR(40)  NOT NULL,
  name             VARCHAR(160) NOT NULL,
  category         VARCHAR(20)  NOT NULL DEFAULT 'proforma',        -- proforma, contract, letter, internal, prescribed, receipt
  stage            VARCHAR(20)  NULL,                               -- bid, award, contract, delivery, billing, collection, closeout, internal
  doc_type         VARCHAR(40)  NULL,                               -- the vault document a finished copy counts as
  description      VARCHAR(255) NULL,
  body             MEDIUMTEXT   NOT NULL,
  paper            VARCHAR(12)  NOT NULL DEFAULT 'A4',              -- A4, Letter, Folio, Legal
  orientation      VARCHAR(10)  NOT NULL DEFAULT 'portrait',
  is_prescribed    TINYINT(1)   NOT NULL DEFAULT 0,
  official_source  VARCHAR(255) NULL,
  official_version VARCHAR(80)  NULL,
  required_fields  VARCHAR(255) NULL,                               -- placeholders that may not be blank on a final copy
  needs_approval         TINYINT(1)   NOT NULL DEFAULT 1,           -- 0 = forms print clean without review and approval
  print_mode             VARCHAR(12)  NOT NULL DEFAULT 'layout',    -- layout (the text with the layout settings) or positions (fields placed on pre-printed paper)
  layout_json            MEDIUMTEXT   NULL,                         -- layout settings (paper, margins, fonts, header, tables, sections, copies, offsets)
  positions_json         MEDIUMTEXT   NULL,                         -- fields placed in millimetres for pre-printed paper
  bg_file                VARCHAR(120) NULL,                         -- scan of the pre-printed paper, shown only when placing the fields
  version_no       INT          NOT NULL DEFAULT 1,
  sort_order       INT          NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  lock_version     INT UNSIGNED NOT NULL DEFAULT 0,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by       INT UNSIGNED NULL,
  updated_at       DATETIME     NULL,
  UNIQUE KEY uq_bt_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS brgy_template_versions;
CREATE TABLE brgy_template_versions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  version_no  INT          NOT NULL,
  name        VARCHAR(160) NOT NULL,
  body        MEDIUMTEXT   NOT NULL,
  layout_json            MEDIUMTEXT   NULL,
  positions_json         MEDIUMTEXT   NULL,
  change_note VARCHAR(255) NULL,
  changed_by  INT UNSIGNED NULL,
  changed_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_btv (template_id, version_no),
  CONSTRAINT fk_btv_template FOREIGN KEY (template_id) REFERENCES brgy_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents made from the templates for a project (letters, schedules, contracts, receipts ...)
DROP TABLE IF EXISTS brgy_forms;
CREATE TABLE brgy_forms (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  form_no            VARCHAR(30)  NOT NULL,
  project_id         INT UNSIGNED NULL,
  template_id        INT UNSIGNED NULL,
  template_version   INT          NULL,
  title              VARCHAR(200) NOT NULL,
  doc_type           VARCHAR(40)  NULL,
  related_type       VARCHAR(20)  NULL,
  related_id         INT UNSIGNED NULL,
  body               MEDIUMTEXT   NOT NULL,
  paper              VARCHAR(12)  NOT NULL DEFAULT 'A4',
  orientation        VARCHAR(10)  NOT NULL DEFAULT 'portrait',
  needs_approval         TINYINT(1)   NOT NULL DEFAULT 1,
  values_json            MEDIUMTEXT   NULL,                         -- the values printed on pre-printed paper
  status             VARCHAR(12)  NOT NULL DEFAULT 'draft',         -- draft, for_review, reviewed, approved, returned, final, void
  is_prescribed      TINYINT(1)   NOT NULL DEFAULT 0,
  official_version   VARCHAR(80)  NULL,
  signatory_name     VARCHAR(120) NULL,
  signatory_position VARCHAR(120) NULL,
  prepared_by        INT UNSIGNED NULL,
  reviewed_by        INT UNSIGNED NULL,
  reviewed_at        DATETIME     NULL,
  approved_by        INT UNSIGNED NULL,
  approved_at        DATETIME     NULL,
  finalized_at       DATETIME     NULL,
  submitted_at       DATETIME     NULL,
  document_id        INT UNSIGNED NULL,
  lock_version       INT UNSIGNED NOT NULL DEFAULT 0,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_bf_no (form_no),
  KEY ix_bf_project (project_id, status),
  KEY ix_bf_related (related_type, related_id),
  CONSTRAINT fk_bf_project  FOREIGN KEY (project_id) REFERENCES brgy_projects(id),
  CONSTRAINT fk_bf_template FOREIGN KEY (template_id) REFERENCES brgy_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maker / checker / approver: every step of a controlled record
DROP TABLE IF EXISTS brgy_workflow;
CREATE TABLE brgy_workflow (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id   INT UNSIGNED NULL,
  object_type  VARCHAR(20)  NOT NULL,                               -- bid, billing, change_order, form, closeout, phase, requirement, document
  object_id    INT UNSIGNED NOT NULL,
  action       VARCHAR(20)  NOT NULL,                               -- submit, review, approve, return, release, complete, cancel, reopen, phase
  from_status  VARCHAR(20)  NULL,
  to_status    VARCHAR(20)  NULL,
  user_id      INT UNSIGNED NULL,
  on_behalf_of INT UNSIGNED NULL,                                   -- delegation: the approver this user acted for
  amount       DECIMAL(15,2) NULL,
  remarks      VARCHAR(255) NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bw_object (object_type, object_id),
  KEY ix_bw_project (project_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alternate approvers: a reviewer or approver hands their authority to another user for a period
DROP TABLE IF EXISTS brgy_delegations;
CREATE TABLE brgy_delegations (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  from_user_id INT UNSIGNED NOT NULL,
  to_user_id   INT UNSIGNED NOT NULL,
  scope        VARCHAR(10)  NOT NULL DEFAULT 'both',                -- review, approve, both
  valid_from   DATE         NOT NULL,
  valid_to     DATE         NOT NULL,
  reason       VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bdel_to (to_user_id, valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- External submissions: bids, compliance papers, billing packs and follow-ups, with proof of receipt
DROP TABLE IF EXISTS brgy_submissions;
CREATE TABLE brgy_submissions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id       INT UNSIGNED NOT NULL,
  sub_type         VARCHAR(20)  NOT NULL DEFAULT 'bid',             -- bid, clarification, compliance, post_qual, contract, billing, followup, other
  method           VARCHAR(16)  NOT NULL DEFAULT 'physical',        -- physical, email, philgeps, courier, portal, other
  submitted_at     DATETIME     NOT NULL,
  submitted_by     INT UNSIGNED NULL,
  recipient_name   VARCHAR(120) NULL,
  recipient_office VARCHAR(120) NULL,
  destination      VARCHAR(255) NULL,
  tracking_no      VARCHAR(80)  NULL,
  received_by      VARCHAR(120) NULL,
  received_at      DATETIME     NULL,
  ack_ref          VARCHAR(80)  NULL,
  proof_doc_id     INT UNSIGNED NULL,
  related_type     VARCHAR(20)  NULL,
  related_id       INT UNSIGNED NULL,
  outcome          VARCHAR(255) NULL,
  notes            VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bs_project (project_id, submitted_at),
  KEY ix_bs_related (related_type, related_id),
  CONSTRAINT fk_bs_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id),
  CONSTRAINT fk_bs_doc     FOREIGN KEY (proof_doc_id) REFERENCES brgy_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Correspondence and external events: bulletins, clarifications, bid opening, notices, meetings ...
DROP TABLE IF EXISTS brgy_events;
CREATE TABLE brgy_events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id  INT UNSIGNED NULL,
  entity_id   INT UNSIGNED NULL,
  event_type  VARCHAR(20)  NOT NULL DEFAULT 'note',
  event_at    DATETIME     NOT NULL,
  direction   VARCHAR(10)  NOT NULL DEFAULT 'internal',             -- in (from the entity), out (to the entity), internal
  title       VARCHAR(200) NOT NULL,
  details     TEXT         NULL,
  party       VARCHAR(160) NULL,
  doc_id      INT UNSIGNED NULL,
  due_at      DATETIME     NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'done',                 -- open (waiting for an answer or action), done
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bev_project (project_id, event_at),
  KEY ix_bev_entity (entity_id, event_at),
  CONSTRAINT fk_bev_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id),
  CONSTRAINT fk_bev_entity  FOREIGN KEY (entity_id) REFERENCES brgy_entities(id),
  CONSTRAINT fk_bev_doc     FOREIGN KEY (doc_id) REFERENCES brgy_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Change orders (variation orders, extensions): count only once approved with the entity's evidence
DROP TABLE IF EXISTS brgy_change_orders;
CREATE TABLE brgy_change_orders (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  co_no           VARCHAR(30)  NOT NULL,
  project_id      INT UNSIGNED NOT NULL,
  co_date         DATE         NOT NULL,
  title           VARCHAR(200) NOT NULL,
  reason          TEXT         NULL,
  amount_change   DECIMAL(15,2) NOT NULL DEFAULT 0,
  days_extension  INT          NOT NULL DEFAULT 0,
  status          VARCHAR(12)  NOT NULL DEFAULT 'draft',            -- draft, for_review, reviewed, approved, returned, applied, void
  external_ref    VARCHAR(80)  NULL,
  external_date   DATE         NULL,
  evidence_doc_id INT UNSIGNED NULL,
  applied_at      DATETIME     NULL,
  applied_by      INT UNSIGNED NULL,
  lock_version    INT UNSIGNED NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_bco_no (co_no),
  KEY ix_bco_project (project_id),
  CONSTRAINT fk_bco_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS brgy_co_lines;
CREATE TABLE brgy_co_lines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  co_id           INT UNSIGNED NOT NULL,
  project_line_id INT UNSIGNED NULL,                                -- a line changed (blank = a new line)
  line_kind       VARCHAR(10)  NOT NULL DEFAULT 'goods',
  item_id         INT UNSIGNED NULL,
  description     VARCHAR(255) NOT NULL,
  unit            VARCHAR(20)  NULL,
  qty_change      DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_price      DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_code_id     INT UNSIGNED NULL,
  KEY ix_bcol_co (co_id),
  CONSTRAINT fk_bcol_co FOREIGN KEY (co_id) REFERENCES brgy_change_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deliveries, service accomplishment and works progress, with the entity's inspection and acceptance
DROP TABLE IF EXISTS brgy_deliveries;
CREATE TABLE brgy_deliveries (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dr_no             VARCHAR(30)  NOT NULL,
  project_id        INT UNSIGNED NOT NULL,
  delivery_date     DATE         NOT NULL,
  kind              VARCHAR(10)  NOT NULL DEFAULT 'goods',          -- goods, service, works
  from_site_id      INT UNSIGNED NULL,
  delivered_to      VARCHAR(255) NULL,
  vehicle           VARCHAR(60)  NULL,
  received_by_name  VARCHAR(120) NULL,
  received_position VARCHAR(120) NULL,
  status            VARCHAR(12)  NOT NULL DEFAULT 'delivered',      -- delivered, accepted, partial, rejected, void
  inspection_date   DATE         NULL,
  inspectors        VARCHAR(255) NULL,
  acceptance_ref    VARCHAR(80)  NULL,                              -- inspection and acceptance report no.
  acceptance_date   DATE         NULL,
  acceptance_doc_id INT UNSIGNED NULL,
  inspection_notes  TEXT         NULL,
  punch_list        TEXT         NULL,
  transfer_txn_id   BIGINT UNSIGNED NULL,                           -- goods moved to the "delivered, awaiting acceptance" site
  return_txn_id     BIGINT UNSIGNED NULL,                           -- rejected goods moved back
  days_late         INT          NOT NULL DEFAULT 0,
  notes             VARCHAR(255) NULL,
  lock_version      INT UNSIGNED NOT NULL DEFAULT 0,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL,
  UNIQUE KEY uq_bdl_no (dr_no),
  KEY ix_bdl_project (project_id, delivery_date),
  CONSTRAINT fk_bdl_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id),
  CONSTRAINT fk_bdl_site    FOREIGN KEY (from_site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS brgy_delivery_lines;
CREATE TABLE brgy_delivery_lines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  delivery_id     INT UNSIGNED NOT NULL,
  project_line_id INT UNSIGNED NOT NULL,
  item_id         INT UNSIGNED NULL,
  description     VARCHAR(255) NOT NULL,
  unit            VARCHAR(20)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,                 -- delivered (or accomplished)
  qty_accepted    DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_rejected    DECIMAL(15,4) NOT NULL DEFAULT 0,
  serial_batch    VARCHAR(255) NULL,
  remarks         VARCHAR(255) NULL,
  KEY ix_bdll_delivery (delivery_id),
  KEY ix_bdll_line (project_line_id),
  CONSTRAINT fk_bdll_delivery FOREIGN KEY (delivery_id) REFERENCES brgy_deliveries(id) ON DELETE CASCADE,
  CONSTRAINT fk_bdll_line     FOREIGN KEY (project_line_id) REFERENCES brgy_project_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Billing packs: what was accepted and is billed, the invoice posted, the submission and its acknowledgment
DROP TABLE IF EXISTS brgy_billings;
CREATE TABLE brgy_billings (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  billing_no         VARCHAR(30)  NOT NULL,
  project_id         INT UNSIGNED NOT NULL,
  billing_date       DATE         NOT NULL,
  billing_type       VARCHAR(12)  NOT NULL DEFAULT 'partial',       -- partial, progress, milestone, final
  period_from        DATE         NULL,
  period_to          DATE         NULL,
  si_no              VARCHAR(40)  NULL,                             -- the BIR-registered sales invoice number
  gross_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  vat_amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  retention_expected DECIMAL(15,2) NOT NULL DEFAULT 0,
  ewt_expected       DECIMAL(15,2) NOT NULL DEFAULT 0,
  vatwh_expected     DECIMAL(15,2) NOT NULL DEFAULT 0,
  ld_expected        DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(16)  NOT NULL DEFAULT 'draft',         -- draft, for_review, reviewed, approved, returned, invoiced, submitted, acknowledged, void
  invoice_txn_id     BIGINT UNSIGNED NULL,
  due_date           DATE         NULL,
  submitted_at       DATETIME     NULL,
  acknowledged_at    DATETIME     NULL,
  ack_ref            VARCHAR(80)  NULL,
  received_by        VARCHAR(120) NULL,
  notes              VARCHAR(255) NULL,
  lock_version       INT UNSIGNED NOT NULL DEFAULT 0,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_bb_no (billing_no),
  KEY ix_bb_project (project_id, billing_date),
  KEY ix_bb_invoice (invoice_txn_id),
  KEY ix_bb_si (si_no),
  CONSTRAINT fk_bb_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id),
  CONSTRAINT fk_bb_invoice FOREIGN KEY (invoice_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS brgy_billing_lines;
CREATE TABLE brgy_billing_lines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  billing_id      INT UNSIGNED NOT NULL,
  project_line_id INT UNSIGNED NOT NULL,
  line_kind       VARCHAR(10)  NOT NULL DEFAULT 'goods',
  item_id         INT UNSIGNED NULL,
  account_id      INT UNSIGNED NULL,
  description     VARCHAR(255) NOT NULL,
  unit            VARCHAR(20)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_price      DECIMAL(15,4) NOT NULL DEFAULT 0,
  gross           DECIMAL(15,2) NOT NULL DEFAULT 0,
  net             DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax             DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_code_id     INT UNSIGNED NULL,
  KEY ix_bbl_billing (billing_id),
  KEY ix_bbl_line (project_line_id),
  CONSTRAINT fk_bbl_billing FOREIGN KEY (billing_id) REFERENCES brgy_billings(id) ON DELETE CASCADE,
  CONSTRAINT fk_bbl_line    FOREIGN KEY (project_line_id) REFERENCES brgy_project_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Collections: money received against a billing (or retention released), with every deduction kept apart
DROP TABLE IF EXISTS brgy_collections;
CREATE TABLE brgy_collections (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  collection_no       VARCHAR(30)  NOT NULL,
  project_id          INT UNSIGNED NOT NULL,
  billing_id          INT UNSIGNED NULL,
  kind                VARCHAR(10)  NOT NULL DEFAULT 'billing',      -- billing, retention
  collection_date     DATE         NOT NULL,
  method              VARCHAR(16)  NOT NULL DEFAULT 'check',        -- check, lddap_ada, bank_transfer, cash, other
  reference_no        VARCHAR(80)  NULL,
  check_no            VARCHAR(40)  NULL,
  check_date          DATE         NULL,
  bank_name           VARCHAR(80)  NULL,
  gross_applied       DECIMAL(15,2) NOT NULL DEFAULT 0,             -- part of the billing settled
  cash_received       DECIMAL(15,2) NOT NULL DEFAULT 0,
  ewt_amount          DECIMAL(15,2) NOT NULL DEFAULT 0,             -- income tax withheld (1% / 2%)
  vatwh_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,             -- VAT withheld (5%)
  retention_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  ld_amount           DECIMAL(15,2) NOT NULL DEFAULT 0,             -- liquidated damages deducted
  other_deduction     DECIMAL(15,2) NOT NULL DEFAULT 0,
  other_note          VARCHAR(160) NULL,
  other_account_id    INT UNSIGNED NULL,
  bank_charges        DECIMAL(15,2) NOT NULL DEFAULT 0,
  deposit_account_id  INT UNSIGNED NULL,
  receipt_no          VARCHAR(40)  NULL,                             -- acknowledgment / collection receipt issued
  cert_2307           TINYINT(1)   NOT NULL DEFAULT 0,
  cert_2307_doc_id    INT UNSIGNED NULL,
  wht_variance        DECIMAL(15,2) NOT NULL DEFAULT 0,
  variance_note       VARCHAR(255) NULL,
  payment_txn_id      BIGINT UNSIGNED NULL,
  deduction_txn_id    BIGINT UNSIGNED NULL,
  release_txn_id      BIGINT UNSIGNED NULL,
  status              VARCHAR(12)  NOT NULL DEFAULT 'posted',       -- posted, bounced, void
  bounce_date         DATE         NULL,
  bounce_reason       VARCHAR(255) NULL,
  bounce_txn_id       BIGINT UNSIGNED NULL,
  notes               VARCHAR(255) NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_bcol_no (collection_no),
  KEY ix_bcol_project (project_id, collection_date),
  KEY ix_bcol_billing (billing_id),
  CONSTRAINT fk_bcoll_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id),
  CONSTRAINT fk_bcoll_billing FOREIGN KEY (billing_id) REFERENCES brgy_billings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bid, performance and warranty securities (and bid securing declarations)
DROP TABLE IF EXISTS brgy_securities;
CREATE TABLE brgy_securities (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  security_no      VARCHAR(30)  NOT NULL,
  project_id       INT UNSIGNED NOT NULL,
  security_type    VARCHAR(20)  NOT NULL DEFAULT 'bid',             -- bid, performance, warranty
  form             VARCHAR(20)  NOT NULL DEFAULT 'cash',            -- cash, cashiers_check, managers_check, bank_draft, bank_guarantee, letter_of_credit, surety_bond, declaration
  amount           DECIMAL(15,2) NOT NULL DEFAULT 0,
  issuer           VARCHAR(160) NULL,
  reference_no     VARCHAR(80)  NULL,
  issue_date       DATE         NULL,
  valid_until      DATE         NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'active',          -- active, released, forfeited, void
  released_date    DATE         NULL,
  pay_account_id   INT UNSIGNED NULL,
  posted_txn_id    BIGINT UNSIGNED NULL,
  release_txn_id   BIGINT UNSIGNED NULL,
  doc_id           INT UNSIGNED NULL,
  notes            VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bsec_no (security_no),
  KEY ix_bsec_project (project_id),
  CONSTRAINT fk_bsec_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks, milestones, progress logs, risks and defects of a project
DROP TABLE IF EXISTS brgy_tasks;
CREATE TABLE brgy_tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id   INT UNSIGNED NOT NULL,
  task_type    VARCHAR(12)  NOT NULL DEFAULT 'task',                -- task, milestone, progress, risk, defect
  title        VARCHAR(200) NOT NULL,
  details      TEXT         NULL,
  assigned_to  INT UNSIGNED NULL,
  due_date     DATE         NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'open',                -- open, in_progress, done, cancelled
  pct_complete DECIMAL(5,2) NULL,
  completed_at DATETIME     NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,
  KEY ix_btask_project (project_id, status),
  KEY ix_btask_user (assigned_to, status),
  CONSTRAINT fk_btask_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounting handoff: one live ledger transaction per source record and event (idempotent, traceable both ways)
DROP TABLE IF EXISTS brgy_postings;
CREATE TABLE brgy_postings (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id    INT UNSIGNED NOT NULL,
  source_type   VARCHAR(20)  NOT NULL,                              -- delivery, billing, collection, security, purchase
  source_id     INT UNSIGNED NOT NULL,
  event         VARCHAR(20)  NOT NULL,                              -- transfer, return, invoice, payment, deductions, release, bounce, posting, refund, forfeit, po
  txn_id        BIGINT UNSIGNED NULL,
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  status        VARCHAR(10)  NOT NULL DEFAULT 'posted',             -- posted, reversed
  note          VARCHAR(255) NULL,
  posted_by     INT UNSIGNED NULL,
  posted_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at   DATETIME     NULL,
  UNIQUE KEY uq_bpost (source_type, source_id, event),
  KEY ix_bpost_project (project_id),
  KEY ix_bpost_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The company's standard operating procedure, step by step (Barangay Contracts > SOP Steps & Papers)
DROP TABLE IF EXISTS brgy_sop_steps;
CREATE TABLE brgy_sop_steps (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  process       VARCHAR(10)  NOT NULL,                              -- svp, bidding, payment
  code          VARCHAR(20)  NOT NULL,
  step_no       INT          NOT NULL DEFAULT 0,
  section       VARCHAR(4)   NULL,                                  -- A, B, C ... as in the SOP
  section_title VARCHAR(120) NULL,
  title         VARCHAR(160) NOT NULL,
  instructions  TEXT         NULL,
  papers        VARCHAR(500) NULL,                                  -- paper codes handled at the step (brgy_sop_papers), comma separated
  checks        VARCHAR(500) NULL,                                  -- what is ticked off at the step, separated by |
  action        VARCHAR(20)  NOT NULL DEFAULT 'checks',             -- what the program does at the step: details, prepare, canvass, billing_docs, review ...
  role_hint     VARCHAR(80)  NULL,                                  -- who does it
  txn_types     VARCHAR(255) NULL,                                  -- the types of transaction it is for (Contracting Settings), comma separated; NULL = every type
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_bss_code (code),
  KEY ix_bss_process (process, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The papers the SOP handles: how each is made, how many copies, signatures, notarization
DROP TABLE IF EXISTS brgy_sop_papers;
CREATE TABLE brgy_sop_papers (
  code            VARCHAR(40)  NOT NULL PRIMARY KEY,
  name            VARCHAR(160) NOT NULL,
  doc_type        VARCHAR(40)  NULL,                                -- the vault document a signed copy is filed as
  template_code   VARCHAR(40)  NULL,                                -- the proforma that prepares it
  source          VARCHAR(12)  NOT NULL DEFAULT 'prepare',          -- prepare, company, received, supplier, record
  copies          INT          NOT NULL DEFAULT 1,
  needs_signature TINYINT(1)   NOT NULL DEFAULT 1,
  needs_notary    TINYINT(1)   NOT NULL DEFAULT 0,
  needs_ctc       TINYINT(1)   NOT NULL DEFAULT 0,                  -- stamped "Certified True Copy"
  condition_code  VARCHAR(20)  NULL,                                -- extra_bid_papers: only for offices that ask for them
  description     VARCHAR(255) NULL,
  txn_types       VARCHAR(255) NULL,                                -- the types of transaction it is for, comma separated; NULL = every type
  sort_order      INT          NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where each transaction stands in the SOP: one row per step
DROP TABLE IF EXISTS brgy_project_steps;
CREATE TABLE brgy_project_steps (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id   INT UNSIGNED NOT NULL,
  step_code    VARCHAR(20)  NOT NULL,
  status       VARCHAR(10)  NOT NULL DEFAULT 'pending',             -- pending, done, skipped
  checks_done  VARCHAR(500) NULL,                                   -- the checks ticked, separated by |
  remarks      VARCHAR(255) NULL,
  done_by      INT UNSIGNED NULL,
  done_at      DATETIME     NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bps (project_id, step_code),
  CONSTRAINT fk_bps_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The papers of each transaction: prepared from a proforma, then the signed copy attached
DROP TABLE IF EXISTS brgy_project_papers;
CREATE TABLE brgy_project_papers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  step_code       VARCHAR(20)  NOT NULL,
  paper_code      VARCHAR(40)  NOT NULL,
  name            VARCHAR(160) NOT NULL,
  doc_type        VARCHAR(40)  NULL,
  template_code   VARCHAR(40)  NULL,
  source          VARCHAR(12)  NOT NULL DEFAULT 'prepare',
  copies          INT          NOT NULL DEFAULT 1,
  needs_signature TINYINT(1)   NOT NULL DEFAULT 1,
  needs_notary    TINYINT(1)   NOT NULL DEFAULT 0,
  needs_ctc       TINYINT(1)   NOT NULL DEFAULT 0,
  status          VARCHAR(10)  NOT NULL DEFAULT 'open',             -- open, waived
  form_id         INT UNSIGNED NULL,                                -- the form prepared for it
  document_id     INT UNSIGNED NULL,                                -- the signed copy (scan) filed in the vault
  signed_by       INT UNSIGNED NULL,
  signed_at       DATETIME     NULL,
  waive_reason    VARCHAR(255) NULL,
  remarks         VARCHAR(255) NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_bpp (project_id, step_code, paper_code),
  KEY ix_bpp_doc (document_id),
  KEY ix_bpp_form (form_id),
  CONSTRAINT fk_bpp_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_bpp_form    FOREIGN KEY (form_id) REFERENCES brgy_forms(id) ON DELETE SET NULL,
  CONSTRAINT fk_bpp_doc     FOREIGN KEY (document_id) REFERENCES brgy_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canvass: the quotations of the suppliers asked (each signed form attached), for the abstract of canvass
DROP TABLE IF EXISTS brgy_canvass;
CREATE TABLE brgy_canvass (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  supplier_name   VARCHAR(160) NOT NULL,
  is_company      TINYINT(1)   NOT NULL DEFAULT 0,                  -- the company's own quotation
  vendor_id       INT UNSIGNED NULL,
  address         VARCHAR(255) NULL,
  philgeps_no     VARCHAR(40)  NULL,
  contact_no      VARCHAR(60)  NULL,
  total_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  validity        VARCHAR(60)  NULL,
  brand           VARCHAR(120) NULL,
  delivery_period VARCHAR(60)  NULL,
  remarks         VARCHAR(10)  NULL,                                -- passed, failed
  received_date   DATE         NULL,
  document_id     INT UNSIGNED NULL,                                -- the canvass form signed by the supplier
  notes           VARCHAR(255) NULL,
  sort_order      INT          NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  KEY ix_bcn_project (project_id),
  CONSTRAINT fk_bcn_project FOREIGN KEY (project_id) REFERENCES brgy_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_bcn_vendor  FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_bcn_doc     FOREIGN KEY (document_id) REFERENCES brgy_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS txn_attachment_chunks;
DROP TABLE IF EXISTS txn_attachments;
CREATE TABLE txn_attachments (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id  BIGINT UNSIGNED NULL,                 -- empty while the form is not saved yet
  stage_key       VARCHAR(40)     NULL,                 -- the form session of a file not saved yet
  file_name       VARCHAR(255)    NOT NULL,
  mime_type       VARCHAR(150)    NOT NULL DEFAULT 'application/octet-stream',
  file_size       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha1            CHAR(40)        NULL,
  chunks          INT UNSIGNED    NOT NULL DEFAULT 0,
  uploaded_by     INT UNSIGNED    NULL,
  uploaded_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ta_txn (transaction_id),
  KEY ix_ta_stage (stage_key),
  CONSTRAINT fk_ta_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE txn_attachment_chunks (
  attachment_id  INT UNSIGNED NOT NULL,
  seq            INT UNSIGNED NOT NULL,
  data           MEDIUMBLOB   NOT NULL,
  PRIMARY KEY (attachment_id, seq),
  CONSTRAINT fk_tac_att FOREIGN KEY (attachment_id) REFERENCES txn_attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v42: digital voucher signatures (migration 2027_02_11)

DROP TABLE IF EXISTS voucher_sign_requests;
CREATE TABLE voucher_sign_requests (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id   BIGINT UNSIGNED NOT NULL,
  slot             VARCHAR(12)     NOT NULL,                 -- prepared, checked, approved, received
  signer_name      VARCHAR(120)    NOT NULL,
  signer_email     VARCHAR(160)    NULL,
  message          VARCHAR(255)    NULL,
  token_hash       CHAR(64)        NOT NULL,                 -- SHA-256 of the secret part of the link
  token_enc        VARCHAR(255)    NULL,                     -- the link, encrypted, so it can be copied again
  status           VARCHAR(10)     NOT NULL DEFAULT 'pending', -- pending, signed, revoked, expired
  expires_at       DATETIME        NOT NULL,
  created_by       INT UNSIGNED    NULL,
  created_at       DATETIME        NOT NULL,
  first_opened_at  DATETIME        NULL,
  last_opened_at   DATETIME        NULL,
  open_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  revoked_by       INT UNSIGNED    NULL,
  revoked_at       DATETIME        NULL,
  revoke_reason    VARCHAR(255)    NULL,
  signed_at        DATETIME        NULL,
  signature_id     INT UNSIGNED    NULL,
  UNIQUE KEY uq_vsr_token (token_hash),
  KEY ix_vsr_txn (transaction_id, status),
  CONSTRAINT fk_vsr_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS voucher_signatures;
CREATE TABLE voucher_signatures (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id   BIGINT UNSIGNED NOT NULL,
  slot             VARCHAR(12)     NOT NULL,
  signer_name      VARCHAR(120)    NOT NULL,
  signer_user_id   INT UNSIGNED    NULL,                     -- signed in the system
  request_id       INT UNSIGNED    NULL,                     -- signed through a link
  method           VARCHAR(8)      NOT NULL DEFAULT 'internal', -- internal, link
  strokes          MEDIUMTEXT      NOT NULL,                 -- the drawing: {"w":..,"h":..,"s":[[x,y,x,y,...],...]}
  doc_hash         CHAR(64)        NOT NULL,                 -- SHA-256 of the voucher content that was signed
  ip_address       VARCHAR(45)     NULL,
  user_agent       VARCHAR(255)    NULL,
  signed_at        DATETIME        NOT NULL,
  is_active        TINYINT(1)      NULL DEFAULT 1,           -- 1 = on the voucher; empty once reset
  cleared_by       INT UNSIGNED    NULL,
  cleared_at       DATETIME        NULL,
  clear_reason     VARCHAR(255)    NULL,
  UNIQUE KEY uq_vs_slot (transaction_id, slot, is_active),  -- one active signature per line
  KEY ix_vs_user (signer_user_id),
  CONSTRAINT fk_vs_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS voucher_documents;
CREATE TABLE voucher_documents (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id   BIGINT UNSIGNED NOT NULL,
  kind             VARCHAR(8)      NOT NULL,                 -- original, signed
  version          INT UNSIGNED    NOT NULL DEFAULT 1,
  file_name        VARCHAR(160)    NOT NULL,
  file_size        INT UNSIGNED    NOT NULL DEFAULT 0,
  sha256           CHAR(64)        NOT NULL,
  doc_hash         CHAR(64)        NULL,
  signature_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  chunks           INT UNSIGNED    NOT NULL DEFAULT 0,
  created_by       INT UNSIGNED    NULL,
  created_note     VARCHAR(160)    NULL,
  created_at       DATETIME        NOT NULL,
  KEY ix_vd_txn (transaction_id, kind, version),
  CONSTRAINT fk_vd_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS voucher_document_chunks;
CREATE TABLE voucher_document_chunks (
  document_id  INT UNSIGNED NOT NULL,
  seq          INT UNSIGNED NOT NULL,
  data         MEDIUMBLOB   NOT NULL,
  PRIMARY KEY (document_id, seq),
  CONSTRAINT fk_vdc_doc FOREIGN KEY (document_id) REFERENCES voucher_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Barangay Contracts, version 33: tradenames and the people of a transaction
-- ---------------------------------------------------------------------

-- the tradenames the company does business under; a transaction picks one (brgy_projects.tradename keeps the name)
DROP TABLE IF EXISTS brgy_tradenames;
CREATE TABLE brgy_tradenames (
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

-- People Accountable Set-up: the user roles allowed for each person of a transaction (none: every active user)
DROP TABLE IF EXISTS brgy_function_roles;
CREATE TABLE brgy_function_roles (
  function_code VARCHAR(20)  NOT NULL,          -- sales_rep, preparer, checker, approver, submitter, pm, collector
  role_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (function_code, role_id),
  KEY ix_bfr_role (role_id),
  CONSTRAINT fk_bfr_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v34: People Accountable Set-up by user: the users allowed for a function (in place of its roles)
DROP TABLE IF EXISTS brgy_function_users;
CREATE TABLE brgy_function_users (
  function_code VARCHAR(20)  NOT NULL,          -- sales_rep, preparer, checker, approver, submitter, pm, collector
  user_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (function_code, user_id),
  KEY ix_bfu_user (user_id),
  CONSTRAINT fk_bfu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. DATA
-- ---------------------------------------------------------------------

-- settings (102 rows)
INSERT INTO `settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('adopt_group_price','1','2026-11-23 19:54:44'),
('allow_negative_stock','1','2026-11-23 19:54:44'),
('audit_retention_years','10','2026-11-23 19:54:44'),
('auto_recost_limit','2000','2026-11-23 19:54:44'),
('auto_recost_scope','process','2026-11-23 19:54:44'),
('bir_signatory_name','','2026-11-23 19:54:44'),
('bir_signatory_tin','','2026-11-23 19:54:44'),
('bir_signatory_title','','2026-11-23 19:54:44'),
('bir_zip_code','','2026-11-23 19:54:44'),
('block_zero_cost_processing','1','2026-11-23 19:54:44'),
('block_zero_cost_sales','1','2026-11-23 19:54:44'),
('brgy_accept_alert_days','7','2026-11-23 19:54:45'),
('brgy_accounting_dept','Accounting Department','2026-11-23 19:54:45'),
('brgy_ack_alert_days','15','2026-11-23 19:54:45'),
('brgy_alert_days','30','2026-11-23 19:54:45'),
('brgy_alert_email','','2026-11-23 19:54:45'),
('brgy_bank_details','','2026-11-23 19:54:45'),
('brgy_bid_alert_days','7','2026-11-23 19:54:45'),
('brgy_billing_pack','invoice|Sales invoice (BIR-registered)|1\nsoa|Statement of account|1\ndr|Delivery receipt, signed by the entity|1\niar|Inspection and acceptance report|1\npo|Purchase order / contract copy|1\naccomplishment|Accomplishment report (services and works)|0\nphoto|Photos of the delivery / work|0','2026-11-23 19:54:45'),
('brgy_bir_status','vat','2026-11-23 19:54:45'),
('brgy_block_over_abc','1','2026-11-23 19:54:45'),
('brgy_company_reg_no','','2026-11-23 19:54:45'),
('brgy_create_class','1','2026-11-23 19:54:45'),
('brgy_default_ld_rate','0','2026-11-23 19:54:45'),
('brgy_default_retention_pct','0','2026-11-23 19:54:45'),
('brgy_delivered_site_id','6','2026-11-23 19:54:45'),
('brgy_est_profit_rates','10,13,15','2026-11-23 19:54:45'),
('brgy_est_receipt_rates','3','2026-11-23 19:54:45'),
('brgy_est_wt_rates','1,4,5,6,7','2026-11-23 19:54:45'),
('brgy_gate_deadline','1','2026-11-23 19:54:45'),
('brgy_gate_documents','1','2026-11-23 19:54:45'),
('brgy_liaison_mobile','','2026-11-23 19:54:45'),
('brgy_liaison_name','','2026-11-23 19:54:45'),
('brgy_liaison_phone','','2026-11-23 19:54:45'),
('brgy_liaison_position','Liaison Officer','2026-11-23 19:54:45'),
('brgy_manager_mobile','','2026-11-23 19:54:45'),
('brgy_manager_name','','2026-11-23 19:54:45'),
('brgy_manager_phone','','2026-11-23 19:54:45'),
('brgy_manager_position','','2026-11-23 19:54:45'),
('brgy_office_converter','','2026-11-23 19:54:45'),
('brgy_philgeps_class','','2026-11-23 19:54:45'),
('brgy_philgeps_no','','2026-11-23 19:54:45'),
('brgy_prices_vat_inclusive','1','2026-11-23 19:54:45'),
('brgy_require_acceptance_doc','1','2026-11-23 19:54:45'),
('brgy_require_pack_complete','1','2026-11-23 19:54:45'),
('brgy_require_perf_security','0','2026-11-23 19:54:45'),
('brgy_scan_command','','2026-11-23 19:54:45'),
('brgy_signatory_mobile','','2026-11-23 19:54:45'),
('brgy_signatory_name','','2026-11-23 19:54:45'),
('brgy_signatory_position','','2026-11-23 19:54:45'),
('brgy_signatory_title','Mr.','2026-11-23 19:54:45'),
('brgy_sod_strict','1','2026-11-23 19:54:45'),
('brgy_sop_hold_billing','1','2026-11-23 19:54:45'),
('brgy_sop_need_scans','1','2026-11-23 19:54:45'),
('brgy_sop_review_other','1','2026-11-23 19:54:45'),
('brgy_svp_limit','100000','2026-11-23 19:54:45'),
('brgy_tradename','Sample Trading Company','2026-11-23 19:54:45'),
('brgy_txn_types','cash_out|Cash Out (purchases)\nkaliwaan|Kaliwaan (purchases)\npurchases|Purchases\nconstruction|Construction','2026-11-23 19:54:45'),
('brgy_upload_max_mb','15','2026-11-23 19:54:45'),
('brgy_wht_goods','WT-GOODS','2026-11-23 19:54:45'),
('brgy_wht_services','WT-SERVICES','2026-11-23 19:54:45'),
('brgy_wht_vat','WT-VAT','2026-11-23 19:54:45'),
('bt_printer_autoprint','0','2026-11-23 19:54:44'),
('bt_printer_cut','0','2026-11-23 19:54:44'),
('bt_printer_enabled','0','2026-11-23 19:54:44'),
('bt_printer_feed','4','2026-11-23 19:54:44'),
('bt_printer_name','','2026-11-23 19:54:44'),
('bt_printer_width','32','2026-11-23 19:54:44'),
('closing_date','','2026-11-23 19:54:44'),
('company_address','123 Rizal Avenue, Makati City, Metro Manila','2026-11-23 19:54:44'),
('company_email','accounting@sampletrading.test','2026-11-23 19:54:44'),
('company_logo','','2026-11-23 19:54:44'),
('company_name','Sample Trading Company','2026-11-23 19:54:44'),
('company_phone','(02) 8123-4567','2026-11-23 19:54:44'),
('company_tin','000-123-456-000','2026-11-23 19:54:44'),
('costing_method','weighted_average','2026-11-23 19:54:44'),
('finance_charge_grace_days','0','2026-11-23 19:54:44'),
('finance_charge_min','50','2026-11-23 19:54:44'),
('finance_charge_on_charges','0','2026-11-23 19:54:44'),
('finance_charge_rate','12','2026-11-23 19:54:44'),
('fiscal_year_start_month','1','2026-11-23 19:54:44'),
('item_search_by_page','','2026-11-23 19:54:44'),
('item_search_format','code_name','2026-11-23 19:54:44'),
('large_discount_pct','10','2026-11-23 19:54:44'),
('logo_targets','','2026-11-23 19:54:44'),
('max_failed_logins','5','2026-11-23 19:54:44'),
('pos_posting_mode','per_sale','2026-11-23 19:54:44'),
('pos_receipt_footer','Thank you for shopping!\nThis serves as your official receipt.','2026-11-23 19:54:44'),
('pos_receipt_show_customer','1','2026-11-23 19:54:44'),
('pos_receipt_title','OFFICIAL RECEIPT','2026-11-23 19:54:44'),
('pos_receipt_title_charge','CHARGE INVOICE','2026-11-23 19:54:44'),
('pos_receipt_title_payment','ACKNOWLEDGEMENT RECEIPT','2026-11-23 19:54:44'),
('pos_receipt_title_return','RETURN / REFUND','2026-11-23 19:54:44'),
('processing_cost_tolerance','2','2026-11-23 19:54:44'),
('pw_expiry_days','90','2026-11-23 19:54:44'),
('pw_min_length','8','2026-11-23 19:54:44'),
('pw_require_number','1','2026-11-23 19:54:44'),
('pw_require_symbol','1','2026-11-23 19:54:44'),
('pw_require_upper','1','2026-11-23 19:54:44'),
('session_timeout_minutes','30','2026-11-23 19:54:44');
INSERT INTO `settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('track_group_sales','1','2026-11-23 19:54:44'),
('withholding_agent','1','2026-11-23 19:54:44');

-- users (15 rows)
INSERT INTO `users` (`id`,`username`,`password_hash`,`full_name`,`email`,`employee_no`,`pos_default_site_id`,`pos_default_class_id`,`status`,`approval_status`,`created_via`,`approved_by`,`approved_at`,`rejected_reason`,`is_platform`,`must_change_password`,`password_changed_at`,`failed_attempts`,`last_login_at`,`last_login_ip`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'admin','$2y$10$tIhDAgsCzhg3mKTs6vCm6uZqSLVHc3XeGa89QevqianIhEJVPt9ze','System Administrator','admin@sampletrading.test','EMP-001',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(2,'accountant','$2y$10$IWWHFpeK5Icpjoy8WuvFGeOIY7mEPBAaECR9uZpWpOnuDKQf4ISAW','Ana Cruz','accountant@sampletrading.test','EMP-002',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(3,'arclerk','$2y$10$K0bHzlxGWhMT8HGChg66WuloD0ajY79HH842nOg54sHrB8WpdTb5a','Ben Garcia','arclerk@sampletrading.test','EMP-003',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(4,'apclerk','$2y$10$PvlW4Qve4kD3oP3l9he/O.Y46UI3/s1lA2ohoy0naENT3kv4cbrcm','Carla Lim','apclerk@sampletrading.test','EMP-004',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(5,'invclerk','$2y$10$7fmO4R7F3FeUsR44tZnDsOx7KK38pE5vnXy0FQCIVDmp7a1abITIy','Dan Villanueva','invclerk@sampletrading.test','EMP-005',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(6,'salesrep','$2y$10$TRjlY6b7knvsz5vYAb9TGO80.HbvpS9vDbnXh7vgefkMaUxCQ1Y3C','Maria Santos','salesrep@sampletrading.test','EMP-006',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(7,'cashier1','$2y$10$BuRXM6ehONNEcX8oQlkJd.Mz.Pem.XTHb2WLh1RlvM4DUQ3dPJT/O','Ella Ramos','cashier1@sampletrading.test','EMP-007',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(8,'supervisor','$2y$10$elGUheyXDeKJ1qxNe4f1QucZEuNLyR.JvtzMXpT1o8kujl2OrjGD2','Fred Tan','supervisor@sampletrading.test','EMP-008',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(9,'auditor','$2y$10$Ac/vKmYb5g8qg7Tvdsg1v.bT8U14Su.bO4We3Wb8c3wWgswEXIr52','Grace Uy','auditor@sampletrading.test','EMP-009',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(10,'bidofficer','$2y$10$pFFOCyRdmWFxcJAT2PwWy.p06Qqc0hui8dOL2gQtx8sXrlxVW.K7a','Rosa Dizon','bidofficer@sampletrading.test','EMP-010',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(11,'manager','$2y$10$LtPEKVrghNki65U8OHQ6leigPK.cm47RoM9FXdguBBVZAYZRksYj6','Victor Aquino','manager@sampletrading.test','EMP-011',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(12,'pmgr','$2y$10$m3beFpY4SDGuxnCnB4uIMevAG.SA21kh/ck.QLpkFWAm71Hv1.YTy','Leo Navarro','pmgr@sampletrading.test','EMP-012',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(13,'delivery','$2y$10$Mz80oOhc8dnTrHRHYKzQBulDV2DauHJAC6cfLfHABM4iSEl0ArhOm','Nestor Cruz','delivery@sampletrading.test','EMP-013',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:44',0,NULL,NULL,NULL,'2026-11-23 19:54:44',NULL),
(14,'billing','$2y$10$4Cf7M4xIH68s/RTbmhH8oem4/vrB3EKEfbYrymjo6tYV1HGqpY3m6','Joy Mercado','billing@sampletrading.test','EMP-014',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:45',0,NULL,NULL,NULL,'2026-11-23 19:54:45',NULL),
(15,'collector','$2y$10$lCTseS49PvCQ4IlulGLlZej6CULVRYxFWlCwGd8WhUAzrKwtyerV.','Paolo Ramos','collector@sampletrading.test','EMP-015',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-11-23 19:54:45',0,NULL,NULL,NULL,'2026-11-23 19:54:45',NULL);

-- roles (15 rows)
INSERT INTO `roles` (`id`,`name`,`description`,`is_admin`,`is_system`,`all_sites`,`created_at`,`updated_at`) VALUES
(1,'Administrator','Everything in every module, including user accounts, roles, page access, the closing date, and the audit trail.',1,1,1,'2026-11-23 19:54:44',NULL),
(2,'Accountant','Financial (Chart of Accounts), Fixed Assets, Banking and reconciliation, the BIR Reports and Withholding Tax Table, all module reports, and the financial statements.',0,1,1,'2026-11-23 19:54:44',NULL),
(3,'A/R Clerk','Customers module transactions and the Customer and Receivables reports.',0,1,1,'2026-11-23 19:54:44',NULL),
(4,'A/P Clerk','Vendors module transactions, Pay Bills, advances to suppliers, the Vendors and Payables reports, and BIR Form 2307 for suppliers.',0,1,1,'2026-11-23 19:54:44',NULL),
(5,'Inventory Clerk','Items, sites, receiving, adjustments, assembly builds, disassembly and processing orders, stock counts, and inventory quantity reports.',0,1,1,'2026-11-23 19:54:44',NULL),
(6,'Sales Representative','Estimates, sales orders, and invoices, and the Sales reports.',0,1,1,'2026-11-23 19:54:44',NULL),
(7,'Cashier','POS sales, returns, and cash drops at the assigned terminal only.',0,1,0,'2026-11-23 19:54:44',NULL),
(8,'POS Supervisor','Everything a cashier can do, plus approving voids, refunds, price overrides, and large discounts, and running X- and Z-readings.',0,1,1,'2026-11-23 19:54:44',NULL),
(9,'Contracts - Bid & Compliance Officer','Barangay Contracts: finds opportunities, keeps the directory and the document vault, prepares bids, checklists and forms, and records submissions.',0,1,1,'2026-11-23 19:54:44',NULL),
(10,'Contracts - Approver (Management)','Barangay Contracts: approves bids, billing packs, change orders and forms within the approval limit, waives requirements, closes out and reopens contracts, and keeps the set-up lists.',0,1,1,'2026-11-23 19:54:44',NULL),
(11,'Contracts - Project Manager','Barangay Contracts: mobilizes awarded contracts, records deliveries and the entity\'s inspection, prepares change orders, tasks and progress reports.',0,1,1,'2026-11-23 19:54:44',NULL),
(12,'Contracts - Warehouse & Delivery','Barangay Contracts: records deliveries from the warehouse and files the signed delivery receipts.',0,1,1,'2026-11-23 19:54:44',NULL),
(13,'Contracts - Billing Officer','Barangay Contracts: prepares billing packs for accepted deliveries, issues the invoices once approved, submits the packs and follows up their acknowledgment.',0,1,1,'2026-11-23 19:54:44',NULL),
(14,'Contracts - Collection Officer','Barangay Contracts: records collections with the taxes withheld and deductions, returned checks, retention releases and the BIR Forms 2307 received.',0,1,1,'2026-11-23 19:54:44',NULL),
(15,'Auditor','View-only access to all transactions, reports, and the audit trail. Cannot create or change anything.',0,1,1,'2026-11-23 19:54:44',NULL);

-- user_roles (15 rows)
INSERT INTO `user_roles` (`user_id`,`role_id`) VALUES
(1,1),
(2,2),
(3,3),
(4,4),
(5,5),
(6,6),
(7,7),
(8,8),
(9,15),
(10,9),
(11,10),
(12,11),
(13,12),
(14,13),
(15,14);

-- modules (13 rows)
INSERT INTO `modules` (`id`,`code`,`name`,`icon`,`sort_order`) VALUES
(1,'home','Home','house',1),
(2,'rc','Report Center','bar-chart-line',2),
(3,'coa','Financial','journal-bookmark',3),
(4,'fa','Fixed Assets','building',4),
(5,'cust','Customers','people',5),
(6,'vend','Vendors','truck',6),
(7,'inv','Inventory','box-seam',7),
(8,'pos','Point of Sale','cart',8),
(9,'bank','Banking','bank',9),
(10,'bir','BIR Reports','file-earmark-ruled',10),
(11,'brgy','Barangay Contracts','briefcase',11),
(12,'admin','System Administration','shield-lock',12),
(13,'company','Company','buildings',13);

-- pages (240 rows)
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(1,'home.dashboard',1,'Home Dashboard','center','Home',10,1,NULL),
(2,'home.reports',2,'Report Center','center','Report Center',20,1,NULL),
(3,'home.memorized',2,'Memorized Reports','list','Report Center',30,1,NULL),
(4,'home.report_layouts',2,'Saved Report Layouts','list','Report Center',40,1,NULL),
(5,'home.search',1,'Search','center','Home',50,0,NULL),
(6,'home.find',1,'Find','center','Home',60,1,NULL),
(7,'rpt.find',1,'Find Results','report','Reports',70,0,NULL),
(8,'coa.accounts',3,'Chart of Accounts','list','Lists',80,1,NULL),
(9,'coa.classes',3,'Class List','list','Lists',90,1,NULL),
(10,'coa.names',3,'Employees & Other Names','list','Lists',100,1,NULL),
(11,'coa.memorized_txns',3,'Memorized Transactions','list','Lists',110,1,NULL),
(12,'coa.budgets',3,'Set Up Budgets','setup','Setup',120,1,NULL),
(13,'coa.journal',3,'Make Journal Entries','transaction','Transactions',130,1,NULL),
(14,'coa.default_accounts',3,'Module Default Accounts','setup','Setup',140,1,NULL),
(15,'coa.lists',3,'Terms, Tax Codes & Other Lists','setup','Setup',150,1,NULL),
(16,'rpt.trial_balance',3,'Trial Balance','report','Reports',160,1,NULL),
(17,'rpt.trial_balance_class',3,'Trial Balance by Class','report','Reports',170,1,NULL),
(18,'rpt.balance_sheet',3,'Balance Sheet','report','Reports',180,1,NULL),
(19,'rpt.balance_sheet_class',3,'Balance Sheet by Class','report','Reports',190,1,NULL),
(20,'rpt.trial_balance_range',3,'Trial Balance by Date Range','report','Reports',200,1,NULL),
(21,'rpt.trial_balance_totals',3,'Trial Balance of Totals','report','Reports',210,1,NULL),
(22,'rpt.balance_sheet_range',3,'Balance Sheet by Date Range','report','Reports',220,1,NULL),
(23,'rpt.budget_income',3,'Income Statement vs Budget','report','Reports',230,1,NULL),
(24,'rpt.budget_balance',3,'Balance Sheet vs Budget','report','Reports',240,1,NULL),
(25,'rpt.income_statement',3,'Income Statement','report','Reports',250,1,NULL),
(26,'rpt.income_statement_class',3,'Income Statement by Class','report','Reports',260,1,NULL),
(27,'rpt.cmp_is_month',3,'Comparative Income Statement by Month','report','Reports',270,1,NULL),
(28,'rpt.cmp_is_quarter',3,'Comparative Income Statement by Quarter','report','Reports',280,1,NULL),
(29,'rpt.cmp_is_year',3,'Comparative Income Statement by Year','report','Reports',290,1,NULL),
(30,'rpt.cmp_is_custom',3,'Comparative Income Statement by Custom Range','report','Reports',300,1,NULL),
(31,'rpt.cmp_bs_month',3,'Comparative Balance Sheet by Month','report','Reports',310,1,NULL),
(32,'rpt.cmp_bs_quarter',3,'Comparative Balance Sheet by Quarter','report','Reports',320,1,NULL),
(33,'rpt.cmp_bs_year',3,'Comparative Balance Sheet by Year','report','Reports',330,1,NULL),
(34,'rpt.cmp_bs_custom',3,'Comparative Balance Sheet by Custom Date','report','Reports',340,1,NULL),
(35,'rpt.cash_flow',3,'Statement of Cash Flows','report','Reports',350,1,NULL),
(36,'rpt.cash_flow_class',3,'Statement of Cash Flows by Class','report','Reports',360,1,NULL),
(37,'rpt.general_ledger',3,'General Ledger','report','Reports',370,1,NULL),
(38,'rpt.subsidiary_ledger',3,'Subsidiary Ledger','report','Reports',380,1,NULL),
(39,'rpt.schedule_of_accounts',3,'Schedule of Accounts','report','Reports',390,1,NULL),
(40,'rpt.journal',3,'Journal','report','Reports',400,1,NULL),
(41,'fa.register',4,'Fixed Asset Register','center','Transactions',410,1,NULL),
(42,'fa.depreciation',4,'Run Depreciation','transaction','Transactions',420,1,NULL),
(43,'fa.disposal',4,'Dispose of Assets','transaction','Transactions',430,1,NULL),
(44,'fa.maintenance',4,'Asset Maintenance','transaction','Transactions',440,1,NULL),
(45,'fa.pm',4,'Preventive Maintenance','transaction','Transactions',450,1,NULL),
(46,'fa.categories',4,'Asset Categories','setup','Setup',460,1,NULL),
(47,'fa.locations',4,'Asset Locations','setup','Setup',470,1,NULL),
(48,'rpt.fa_register',4,'Fixed Asset Register Report','report','Reports',480,1,NULL),
(49,'rpt.fa_schedule',4,'Depreciation Schedule by Asset','report','Reports',490,1,NULL),
(50,'rpt.fa_by_category',4,'Depreciation by Category','report','Reports',500,1,NULL),
(51,'rpt.fa_additions',4,'Additions and Disposals','report','Reports',510,1,NULL),
(52,'rpt.fa_maint_history',4,'Maintenance History','report','Reports',520,1,NULL),
(53,'rpt.fa_maint_cost',4,'Maintenance Cost by Asset','report','Reports',530,1,NULL),
(54,'rpt.fa_maint_parts',4,'Spare Parts Used','report','Reports',540,1,NULL),
(55,'rpt.fa_maint_labor',4,'Maintenance Labor','report','Reports',550,1,NULL),
(56,'rpt.fa_pm_upcoming',4,'Upcoming Preventive Maintenance','report','Reports',560,1,NULL),
(57,'rpt.fa_pm_log',4,'Completed Preventive Maintenance','report','Reports',570,1,NULL),
(58,'cust.center',5,'Customer Center','center','Transactions',580,1,NULL),
(59,'cust.estimates',5,'Create Estimates','transaction','Transactions',590,1,NULL),
(60,'cust.sales_orders',5,'Create Sales Orders','transaction','Transactions',600,1,NULL),
(61,'cust.invoices',5,'Create Invoices','transaction','Transactions',610,1,NULL),
(62,'cust.batch_invoices',5,'Create Batch Invoices','transaction','Transactions',620,1,NULL),
(63,'cust.sales_receipts',5,'Enter Sales Receipts','transaction','Transactions',630,1,NULL),
(64,'cust.statement_charges',5,'Enter Statement Charges','transaction','Transactions',640,1,NULL),
(65,'cust.statements',5,'Create Statements','transaction','Transactions',650,1,NULL),
(66,'cust.finance_charges',5,'Assess Finance Charges','transaction','Transactions',660,1,NULL),
(67,'cust.forecast',5,'Set Up Forecast','setup','Transactions',670,1,NULL),
(68,'cust.payments',5,'Receive Payments','transaction','Transactions',680,1,NULL),
(69,'cust.credit_memos',5,'Create Credit Memos/Refunds','transaction','Transactions',690,1,NULL),
(70,'rpt.ar_aging_summary',5,'A/R Aging Summary','report','Customer and Receivables Reports',700,1,NULL),
(71,'rpt.ar_aging_detail',5,'A/R Aging Detail','report','Customer and Receivables Reports',710,1,NULL),
(72,'rpt.customer_balance_summary',5,'Customer Balance Summary','report','Customer and Receivables Reports',720,1,NULL),
(73,'rpt.customer_balance_detail',5,'Customer Balance Detail','report','Customer and Receivables Reports',730,1,NULL),
(74,'rpt.open_invoices',5,'Open Invoices','report','Customer and Receivables Reports',740,1,NULL),
(75,'rpt.collections',5,'Collections Report','report','Customer and Receivables Reports',750,1,NULL),
(76,'rpt.avg_days_summary',5,'Average Days to Pay Summary','report','Customer and Receivables Reports',760,1,NULL),
(77,'rpt.avg_days_detail',5,'Average Days to Pay','report','Customer and Receivables Reports',770,1,NULL),
(78,'rpt.ar_graph',5,'Accounts Receivable Graph','report','Customer and Receivables Reports',780,1,NULL),
(79,'rpt.unbilled_costs',5,'Unbilled Cost by Job','report','Customer and Receivables Reports',790,1,NULL),
(80,'rpt.txn_list_customer',5,'Transaction List by Customer','report','Customer and Receivables Reports',800,1,NULL),
(81,'rpt.item_price_list',5,'Item Price List','report','Customer and Receivables Reports',810,1,NULL),
(82,'rpt.sales_customer_summary',5,'Sales by Customer Summary','report','Sales Reports',820,1,NULL),
(83,'rpt.forecast_vs_actual',5,'Forecast vs Actual by Customer','report','Sales Reports',830,1,NULL),
(84,'rpt.sales_customer_detail',5,'Sales by Customer Detail','report','Sales Reports',840,1,NULL),
(85,'rpt.income_customer_summary',5,'Income by Customer Summary','report','Sales Reports',850,1,NULL),
(86,'rpt.income_customer_detail',5,'Income by Customer Detail','report','Sales Reports',860,1,NULL),
(87,'rpt.sales_item_summary',5,'Sales by Item Summary','report','Sales Reports',870,1,NULL),
(88,'rpt.sales_item_detail',5,'Sales by Item Detail','report','Sales Reports',880,1,NULL),
(89,'rpt.sales_item_group',5,'Sales by Item Group','report','Sales Reports',890,1,NULL),
(90,'rpt.sales_rep_summary',5,'Sales by Rep Summary','report','Sales Reports',900,1,NULL),
(91,'rpt.sales_rep_detail',5,'Sales by Rep Detail','report','Sales Reports',910,1,NULL),
(92,'rpt.inventory_sales_site',5,'Inventory Sales by Site','report','Sales Reports',920,1,NULL),
(93,'rpt.sales_inv_movement',5,'Sales Report Through Inventory Movement','report','Sales Reports',930,1,NULL),
(94,'rpt.pending_sales',5,'Pending Sales','report','Sales Reports',940,1,NULL),
(95,'rpt.open_so_customer',5,'Open Sales Orders by Customer','report','Sales Reports',950,1,NULL),
(96,'rpt.open_so_item',5,'Open Sales Orders by Item','report','Sales Reports',960,1,NULL),
(97,'vend.center',6,'Vendor Center','center','Transactions',970,1,NULL),
(98,'vend.bill_tracker',6,'Bill Tracker','center','Transactions',980,1,NULL),
(99,'vend.bills',6,'Enter Bills','transaction','Transactions',990,1,NULL),
(100,'vend.debit_memos',6,'Supplier Debit Memos','transaction','Transactions',1000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(101,'vend.pay_bills',6,'Pay Bills','transaction','Transactions',1010,1,NULL),
(102,'vend.receipts',6,'Receipt Management','transaction','Transactions',1020,1,NULL),
(103,'vend.purchase_orders',6,'Create Purchase Orders','transaction','Transactions',1030,1,NULL),
(104,'vend.receive_bill',6,'Receive Items and Enter Bills','transaction','Transactions',1040,1,NULL),
(105,'vend.receive_items',6,'Receive Items','transaction','Transactions',1050,1,NULL),
(106,'vend.bill_received',6,'Enter Bill for Received Items','transaction','Transactions',1060,1,NULL),
(107,'vend.advances',6,'Advances to Suppliers','transaction','Transactions',1070,1,NULL),
(108,'rpt.ap_aging_summary',6,'A/P Aging Summary','report','Vendors and Payables Reports',1080,1,NULL),
(109,'rpt.ap_aging_detail',6,'A/P Aging Detail','report','Vendors and Payables Reports',1090,1,NULL),
(110,'rpt.vendor_balance_summary',6,'Vendor Balance Summary','report','Vendors and Payables Reports',1100,1,NULL),
(111,'rpt.vendor_balance_detail',6,'Vendor Balance Detail','report','Vendors and Payables Reports',1110,1,NULL),
(112,'rpt.unpaid_bills',6,'Unpaid Bills Detail','report','Vendors and Payables Reports',1120,1,NULL),
(113,'rpt.txn_list_vendor',6,'Transaction List by Vendor','report','Vendors and Payables Reports',1130,1,NULL),
(114,'rpt.vendor_contacts',6,'Vendor Contact List','report','Vendors and Payables Reports',1140,1,NULL),
(115,'rpt.expenses_vendor_summary',6,'Expenses by Vendor Summary','report','Vendors and Payables Reports',1150,1,NULL),
(116,'rpt.expenses_vendor_detail',6,'Expenses by Vendor Detail','report','Vendors and Payables Reports',1160,1,NULL),
(117,'rpt.vendor_advances',6,'Advances to Suppliers','report','Vendors and Payables Reports',1170,1,NULL),
(118,'inv.center',7,'Inventory Center','center','Transactions',1180,1,NULL),
(119,'inv.items',7,'Item List','list','Lists',1190,1,NULL),
(120,'inv.categories',7,'Category List','list','Lists',1200,1,NULL),
(121,'inv.sites',7,'Inventory Site List','list','Lists',1210,1,NULL),
(122,'inv.uom',7,'U/M Set List','list','Lists',1220,1,NULL),
(123,'inv.formulas',7,'Processing Formulas (Multi-Output BOM)','list','Lists',1230,1,NULL),
(124,'inv.transfers',7,'Transfer Inventory','transaction','Transactions',1240,1,NULL),
(125,'inv.adjust',7,'Adjust Quantity/Value on Hand','transaction','Transactions',1250,1,NULL),
(126,'inv.build',7,'Build Assemblies','transaction','Transactions',1260,1,NULL),
(127,'inv.process',7,'Disassemble / Process Items','transaction','Transactions',1270,1,NULL),
(128,'inv.physical_count',7,'Physical Inventory Count','transaction','Transactions',1280,1,NULL),
(129,'inv.change_prices',7,'Change Item Prices','transaction','Transactions',1290,1,NULL),
(130,'inv.set_prices',7,'Set Item Prices and Levels','transaction','Transactions',1300,1,NULL),
(131,'rpt.qoh_by_site',7,'Quantity on Hand by Site','report','Inventory Reports',1310,1,NULL),
(132,'rpt.inv_valuation_summary',7,'Inventory Valuation Summary','report','Inventory Reports',1320,1,NULL),
(133,'rpt.inv_valuation_site',7,'Inventory Valuation Summary by Site','report','Inventory Reports',1330,1,NULL),
(134,'rpt.inv_valuation_detail',7,'Inventory Valuation Detail','report','Inventory Reports',1340,1,NULL),
(135,'rpt.avg_cost_changes',7,'Average Cost Changes','report','Inventory Reports',1350,1,NULL),
(136,'rpt.stock_status_item',7,'Inventory Stock Status by Item','report','Inventory Reports',1360,1,NULL),
(137,'rpt.stock_status_vendor',7,'Inventory Stock Status by Vendor','report','Inventory Reports',1370,1,NULL),
(138,'rpt.stock_status_site',7,'Inventory Stock Status by Site','report','Inventory Reports',1380,1,NULL),
(139,'rpt.physical_worksheet',7,'Physical Inventory Worksheet','report','Inventory Reports',1390,1,NULL),
(140,'rpt.pending_builds',7,'Pending Builds','report','Inventory Reports',1400,1,NULL),
(141,'rpt.pending_builds_site',7,'Pending Builds by Site','report','Inventory Reports',1410,1,NULL),
(142,'rpt.processing_register',7,'Processing & Disassembly Register','report','Inventory Reports',1420,1,NULL),
(143,'rpt.coproduct_costs',7,'Co-product & By-product Cost Allocation','report','Inventory Reports',1430,1,NULL),
(144,'rpt.process_yield',7,'Processing Yield Analysis','report','Inventory Reports',1440,1,NULL),
(145,'rpt.negative_inventory',7,'Negative Inventory Listing','report','Inventory Reports',1450,1,NULL),
(146,'rpt.inv_no_movement',7,'No. of Days Inventory Has No Movement','report','Inventory Reports',1460,1,NULL),
(147,'rpt.inv_custom',7,'Customized Inventory Report','report','Inventory Reports',1470,1,NULL),
(148,'rpt.inv_movement_summary',7,'Inventory Movement Summary Report','report','Inventory Reports',1480,1,NULL),
(149,'rpt.inv_movement_detail',7,'Inventory Movement Detailed Report','report','Inventory Reports',1490,1,NULL),
(150,'rpt.inv_movement_txns',7,'Inventory Movement Transactions','report','Inventory Reports',1500,0,NULL),
(151,'pos.register',8,'POS Register','transaction','Transactions',1510,1,NULL),
(152,'pos.shifts',8,'Shifts, Cash Drops & Readings','transaction','Transactions',1520,1,NULL),
(153,'pos.receipts',8,'POS Receipts, Returns & Voids','transaction','Transactions',1530,1,NULL),
(154,'pos.approval_codes',8,'Approval Codes','transaction','Transactions',1540,1,NULL),
(155,'pos.terminals',8,'POS Terminals','setup','Setup',1550,1,NULL),
(156,'rpt.pos_sales_txn',8,'POS Sales by Transaction No. (detail)','report','Reports',1560,1,NULL),
(157,'rpt.pos_sales_item',8,'POS Sales by Item','report','Reports',1570,1,NULL),
(158,'rpt.pos_sales_category',8,'POS Sales by Category','report','Reports',1580,1,NULL),
(159,'rpt.pos_sales_cashier',8,'POS Sales by Cashier & Payment Method','report','Reports',1590,1,NULL),
(160,'rpt.pos_sales_payment',8,'POS Sales by Payment Method','report','Reports',1600,0,NULL),
(161,'rpt.pos_sales_hour',8,'POS Sales by Hour','report','Reports',1610,1,NULL),
(162,'rpt.pos_daily_summary',8,'POS Daily Sales Summary','report','Reports',1620,1,NULL),
(163,'rpt.pos_readings',8,'X- and Z-Reading Summary','report','Reports',1630,1,NULL),
(164,'rpt.pos_discount_log',8,'POS Discount Log','report','Reports',1640,1,NULL),
(165,'rpt.pos_void_log',8,'POS Void and Refund Log','report','Reports',1650,1,NULL),
(166,'bank.write_checks',9,'Write Checks','transaction','Transactions',1660,1,NULL),
(167,'bank.cash_voucher',9,'Cash Voucher','transaction','Transactions',1670,1,NULL),
(168,'bank.register',9,'Use Register','center','Transactions',1680,1,NULL),
(169,'bank.deposits',9,'Make Deposits','transaction','Transactions',1690,1,NULL),
(170,'bank.transfers',9,'Transfer Funds','transaction','Transactions',1700,1,NULL),
(171,'bank.reconcile',9,'Reconcile','transaction','Transactions',1710,1,NULL),
(172,'rpt.recon_summary',9,'Reconciliation Summary','report','Reports',1720,1,NULL),
(173,'rpt.recon_detail',9,'Reconciliation Detail','report','Reports',1730,1,NULL),
(174,'rpt.recon_discrepancy',9,'Reconciliation Discrepancy','report','Reports',1740,1,NULL),
(175,'rpt.check_register',9,'Check Register','report','Reports',1750,1,NULL),
(176,'bir.form_2307',10,'Print BIR Form 2307','transaction','Transactions',1760,1,NULL),
(177,'bir.wht_table',10,'Withholding Tax Table','setup','Setup',1770,1,NULL),
(178,'rpt.bir_wht_summary',10,'Summary of Taxes Withheld from Vendors/Suppliers','report','BIR Reports',1780,1,NULL),
(179,'rpt.bir_ewt_register',10,'Expanded Withholding Tax Register (1% / 2%)','report','BIR Reports',1790,1,NULL),
(180,'rpt.bir_remittance',10,'Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT)','report','BIR Reports',1800,1,NULL),
(181,'rpt.bir_vat_withholding',10,'VAT Withholding Register (1600-VT)','report','BIR Reports',1810,1,NULL),
(182,'rpt.bir_sales_register',10,'Output VAT / Sales Register','report','BIR Reports',1820,1,NULL),
(183,'rpt.bir_purchase_register',10,'Input VAT / Purchases Register','report','BIR Reports',1830,1,NULL),
(184,'rpt.bir_cvat_withheld',10,'Creditable VAT Withheld Schedule (2550Q Schedule 3)','report','BIR Reports',1840,1,NULL),
(185,'rpt.bir_2550q',10,'Quarterly VAT Return Worksheet (2550Q)','report','BIR Reports',1850,1,NULL),
(186,'brgy.dashboard',11,'Contracting Dashboard','center','Transactions',1860,1,NULL),
(187,'brgy.projects',11,'Projects & Contracts','center','Transactions',1870,1,NULL),
(188,'brgy.approvals',11,'Approval Queue','center','Transactions',1880,1,NULL),
(189,'brgy.deliveries',11,'Deliveries & Acceptance','transaction','Transactions',1890,1,NULL),
(190,'brgy.billing',11,'Billing Packs','transaction','Transactions',1900,1,NULL),
(191,'brgy.collections',11,'Collections & Retention','transaction','Transactions',1910,1,NULL),
(192,'brgy.forms',11,'Forms & Proformas','transaction','Transactions',1920,1,NULL),
(193,'brgy.vault',11,'Document Vault','center','Transactions',1930,1,NULL),
(194,'brgy.entities',11,'Barangays & Procuring Entities','list','Lists',1940,1,NULL),
(195,'brgy.tradenames',11,'Tradenames','list','Lists',1950,1,NULL),
(196,'brgy.templates',11,'Proforma & Form Templates','setup','Setup',1960,1,NULL),
(197,'brgy.requirements',11,'Compliance Requirement Library','setup','Setup',1970,1,NULL),
(198,'brgy.modes',11,'Procurement Modes','setup','Setup',1980,1,NULL),
(199,'brgy.references',11,'Regulatory References','setup','Setup',1990,1,NULL),
(200,'brgy.settings',11,'Contracting Settings','setup','Setup',2000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(201,'brgy.accountable',11,'People Accountable Set-up','setup','Setup',2010,1,NULL),
(202,'brgy.process',11,'SOP Steps & Papers','setup','Setup',2020,1,NULL),
(203,'rpt.brgy_bid_register',11,'Bid Register','report','Barangay Contract Reports',2030,1,NULL),
(204,'rpt.brgy_win_loss',11,'Won and Lost Opportunities','report','Barangay Contract Reports',2040,1,NULL),
(205,'rpt.brgy_eligibility',11,'Eligibility and Compliance Matrix','report','Barangay Contract Reports',2050,1,NULL),
(206,'rpt.brgy_submissions',11,'Tender and Document Submission Log','report','Barangay Contract Reports',2060,1,NULL),
(207,'rpt.brgy_contract_status',11,'Contract Status: Awarded, Delivered, Billed, Collected','report','Barangay Contract Reports',2070,1,NULL),
(208,'rpt.brgy_delivery_variance',11,'Delivery and Implementation Variance','report','Barangay Contract Reports',2080,1,NULL),
(209,'rpt.brgy_inspection',11,'Inspection and Acceptance Register','report','Barangay Contract Reports',2090,1,NULL),
(210,'rpt.brgy_billing_pack',11,'Billing Pack Completeness','report','Barangay Contract Reports',2100,1,NULL),
(211,'rpt.brgy_ar_aging',11,'A/R Aging by Barangay','report','Barangay Contract Reports',2110,1,NULL),
(212,'rpt.brgy_collection_perf',11,'Collection Performance by Barangay','report','Barangay Contract Reports',2120,1,NULL),
(213,'rpt.brgy_change_orders',11,'Change Order Register','report','Barangay Contract Reports',2130,1,NULL),
(214,'rpt.brgy_retention',11,'Retention and Warranty Tracker','report','Barangay Contract Reports',2140,1,NULL),
(215,'rpt.brgy_profitability',11,'Project Profitability','report','Barangay Contract Reports',2150,1,NULL),
(216,'rpt.brgy_wht_credits',11,'Taxes Withheld by Barangays (2307 Tracker)','report','Barangay Contract Reports',2160,1,NULL),
(217,'rpt.brgy_doc_expiry',11,'Document Expiry and Missing Papers','report','Barangay Contract Reports',2170,1,NULL),
(218,'rpt.brgy_cash_forecast',11,'Cash Collection Forecast','report','Barangay Contract Reports',2180,1,NULL),
(219,'rpt.brgy_reconciliation',11,'Contracts-to-Ledger Reconciliation','report','Barangay Contract Reports',2190,1,NULL),
(220,'rpt.brgy_approval_log',11,'Approval Log','report','Barangay Contract Reports',2200,1,NULL),
(221,'rpt.brgy_monitoring',11,'Transaction Monitoring (SOP)','report','Barangay Contract Reports',2210,1,NULL),
(222,'admin.users',12,'User Accounts','admin','Setup',2220,1,NULL),
(223,'admin.roles',12,'User Role Configuration','admin','Setup',2230,1,NULL),
(224,'admin.page_access',12,'Page Access Configuration','admin','Setup',2240,1,NULL),
(225,'admin.effective_access',12,'Effective Access (View as User)','admin','Setup',2250,1,NULL),
(226,'admin.settings',12,'Company, Security & Closing Date','admin','Setup',2260,1,NULL),
(227,'admin.utilities',12,'Utilities: Verify & Rebuild Data','admin','Setup',2270,1,NULL),
(228,'admin.import',12,'Import from CSV','admin','Utilities',2280,1,NULL),
(229,'admin.backup',12,'Backup & Restore','admin','Utilities',2290,1,NULL),
(230,'admin.multi_company',12,'Multi-Company Setup','admin','Setup',2300,1,NULL),
(231,'admin.audit_trail',12,'Audit Trail','report','Audit Reports',2310,1,NULL),
(232,'admin.txn_history',12,'Transaction History','report','Audit Reports',2320,1,NULL),
(233,'rpt.voided_deleted_summary',12,'Voided and Deleted Transactions Summary','report','Audit Reports',2330,1,NULL),
(234,'rpt.voided_deleted_detail',12,'Voided and Deleted Transactions Detail','report','Audit Reports',2340,1,NULL),
(235,'rpt.user_activity',12,'User Activity','report','Audit Reports',2350,1,NULL),
(236,'rpt.login_history',12,'Login History','report','Audit Reports',2360,1,NULL),
(237,'rpt.user_access',12,'User Access and Permission Changes','report','Audit Reports',2370,1,NULL),
(238,'company.profile',13,'Company Profile & Code','admin','Company',2380,1,NULL),
(239,'company.users',13,'Employees & Users','admin','Company',2390,1,NULL),
(240,'company.join_requests',13,'Join Requests','admin','Company',2400,1,NULL);

-- role_page_access (706 rows)
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,1,1,0,0,0,1,0),
(2,2,1,0,0,0,1,0),
(2,3,1,0,0,0,1,0),
(2,4,1,0,0,0,1,0),
(2,5,1,0,0,0,1,0),
(2,6,1,0,0,0,1,0),
(2,7,1,0,0,0,1,0),
(2,8,1,1,1,1,1,0),
(2,9,1,1,1,1,1,0),
(2,10,1,1,1,1,1,0),
(2,11,1,1,1,1,1,0),
(2,12,1,1,1,1,1,0),
(2,13,1,1,1,1,1,0),
(2,14,1,1,1,1,1,0),
(2,15,1,1,1,1,1,0),
(2,16,1,1,1,1,1,0),
(2,17,1,1,1,1,1,0),
(2,18,1,1,1,1,1,0),
(2,19,1,1,1,1,1,0),
(2,20,1,1,1,1,1,0),
(2,21,1,1,1,1,1,0),
(2,22,1,1,1,1,1,0),
(2,23,1,1,1,1,1,0),
(2,24,1,1,1,1,1,0),
(2,25,1,1,1,1,1,0),
(2,26,1,1,1,1,1,0),
(2,27,1,1,1,1,1,0),
(2,28,1,1,1,1,1,0),
(2,29,1,1,1,1,1,0),
(2,30,1,1,1,1,1,0),
(2,31,1,1,1,1,1,0),
(2,32,1,1,1,1,1,0),
(2,33,1,1,1,1,1,0),
(2,34,1,1,1,1,1,0),
(2,35,1,1,1,1,1,0),
(2,36,1,1,1,1,1,0),
(2,37,1,1,1,1,1,0),
(2,38,1,1,1,1,1,0),
(2,39,1,1,1,1,1,0),
(2,40,1,1,1,1,1,0),
(2,41,1,1,1,1,1,0),
(2,42,1,1,1,1,1,0),
(2,43,1,1,1,1,1,0),
(2,44,1,1,1,1,1,0),
(2,45,1,1,1,1,1,0),
(2,46,1,1,1,1,1,0),
(2,47,1,1,1,1,1,0),
(2,48,1,1,1,1,1,0),
(2,49,1,1,1,1,1,0),
(2,50,1,1,1,1,1,0),
(2,51,1,1,1,1,1,0),
(2,52,1,1,1,1,1,0),
(2,53,1,1,1,1,1,0),
(2,54,1,1,1,1,1,0),
(2,55,1,1,1,1,1,0),
(2,56,1,1,1,1,1,0),
(2,57,1,1,1,1,1,0),
(2,58,1,0,0,0,1,0),
(2,61,1,0,0,0,1,0),
(2,68,1,0,0,0,1,0),
(2,70,1,0,0,0,1,0),
(2,71,1,0,0,0,1,0),
(2,72,1,0,0,0,1,0),
(2,73,1,0,0,0,1,0),
(2,74,1,0,0,0,1,0),
(2,75,1,0,0,0,1,0),
(2,76,1,0,0,0,1,0),
(2,77,1,0,0,0,1,0),
(2,78,1,0,0,0,1,0),
(2,79,1,0,0,0,1,0),
(2,80,1,0,0,0,1,0),
(2,81,1,0,0,0,1,0),
(2,82,1,0,0,0,1,0),
(2,83,1,0,0,0,1,0),
(2,84,1,0,0,0,1,0),
(2,85,1,0,0,0,1,0),
(2,86,1,0,0,0,1,0),
(2,87,1,0,0,0,1,0),
(2,88,1,0,0,0,1,0),
(2,89,1,0,0,0,1,0),
(2,90,1,0,0,0,1,0),
(2,91,1,0,0,0,1,0),
(2,92,1,0,0,0,1,0),
(2,93,1,0,0,0,1,0),
(2,94,1,0,0,0,1,0),
(2,95,1,0,0,0,1,0),
(2,96,1,0,0,0,1,0),
(2,97,1,0,0,0,1,0),
(2,98,1,0,0,0,1,0),
(2,99,1,0,0,0,1,0),
(2,101,1,0,0,0,1,0),
(2,107,1,0,0,0,1,0),
(2,108,1,0,0,0,1,0),
(2,109,1,0,0,0,1,0),
(2,110,1,0,0,0,1,0),
(2,111,1,0,0,0,1,0),
(2,112,1,0,0,0,1,0),
(2,113,1,0,0,0,1,0),
(2,114,1,0,0,0,1,0),
(2,115,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,116,1,0,0,0,1,0),
(2,117,1,0,0,0,1,0),
(2,118,1,0,0,0,1,0),
(2,119,1,0,0,0,1,0),
(2,120,1,0,0,0,1,0),
(2,122,1,0,0,0,1,0),
(2,123,1,0,0,0,1,0),
(2,125,1,0,0,0,1,0),
(2,127,1,0,0,0,1,0),
(2,131,1,0,0,0,1,0),
(2,132,1,0,0,0,1,0),
(2,133,1,0,0,0,1,0),
(2,134,1,0,0,0,1,0),
(2,135,1,0,0,0,1,0),
(2,136,1,0,0,0,1,0),
(2,137,1,0,0,0,1,0),
(2,138,1,0,0,0,1,0),
(2,139,1,0,0,0,1,0),
(2,140,1,0,0,0,1,0),
(2,141,1,0,0,0,1,0),
(2,142,1,0,0,0,1,0),
(2,143,1,0,0,0,1,0),
(2,144,1,0,0,0,1,0),
(2,145,1,0,0,0,1,0),
(2,146,1,0,0,0,1,0),
(2,147,1,0,0,0,1,0),
(2,148,1,0,0,0,1,0),
(2,149,1,0,0,0,1,0),
(2,150,1,0,0,0,1,0),
(2,156,1,0,0,0,1,0),
(2,157,1,0,0,0,1,0),
(2,158,1,0,0,0,1,0),
(2,159,1,0,0,0,1,0),
(2,160,1,0,0,0,1,0),
(2,161,1,0,0,0,1,0),
(2,162,1,0,0,0,1,0),
(2,163,1,0,0,0,1,0),
(2,164,1,0,0,0,1,0),
(2,165,1,0,0,0,1,0),
(2,166,1,1,1,1,1,0),
(2,167,1,1,1,1,1,0),
(2,168,1,1,1,1,1,0),
(2,169,1,1,1,1,1,0),
(2,170,1,1,1,1,1,0),
(2,171,1,1,1,1,1,0),
(2,172,1,1,1,1,1,0),
(2,173,1,1,1,1,1,0),
(2,174,1,1,1,1,1,0),
(2,175,1,1,1,1,1,0),
(2,176,1,1,1,1,1,0),
(2,177,1,1,1,1,1,0),
(2,178,1,1,1,1,1,0),
(2,179,1,1,1,1,1,0),
(2,180,1,1,1,1,1,0),
(2,181,1,1,1,1,1,0),
(2,182,1,1,1,1,1,0),
(2,183,1,1,1,1,1,0),
(2,184,1,1,1,1,1,0),
(2,185,1,1,1,1,1,0),
(2,186,1,0,0,0,1,0),
(2,187,1,0,0,0,1,0),
(2,188,1,0,1,0,1,0),
(2,189,1,0,0,0,1,0),
(2,190,1,0,0,0,1,0),
(2,191,1,0,0,0,1,0),
(2,192,1,0,0,0,1,0),
(2,193,1,0,1,0,1,0),
(2,194,1,0,0,0,1,0),
(2,195,1,0,0,0,1,0),
(2,199,1,0,0,0,1,0),
(2,203,1,0,0,0,1,0),
(2,204,1,0,0,0,1,0),
(2,205,1,0,0,0,1,0),
(2,206,1,0,0,0,1,0),
(2,207,1,0,0,0,1,0),
(2,208,1,0,0,0,1,0),
(2,209,1,0,0,0,1,0),
(2,210,1,0,0,0,1,0),
(2,211,1,0,0,0,1,0),
(2,212,1,0,0,0,1,0),
(2,213,1,0,0,0,1,0),
(2,214,1,0,0,0,1,0),
(2,215,1,0,0,0,1,0),
(2,216,1,0,0,0,1,0),
(2,217,1,0,0,0,1,0),
(2,218,1,0,0,0,1,0),
(2,219,1,0,0,0,1,0),
(2,220,1,0,0,0,1,0),
(2,221,1,0,0,0,1,0),
(2,227,1,0,0,0,1,0),
(3,1,1,0,0,0,1,0),
(3,2,1,0,0,0,1,0),
(3,3,1,0,0,0,1,0),
(3,4,1,0,0,0,1,0),
(3,5,1,0,0,0,1,0),
(3,6,1,0,0,0,1,0),
(3,7,1,0,0,0,1,0),
(3,58,1,1,1,0,1,0),
(3,59,1,1,1,0,1,0),
(3,60,1,1,1,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(3,61,1,1,1,0,1,0),
(3,62,1,1,1,0,1,0),
(3,63,1,1,1,0,1,0),
(3,64,1,1,1,0,1,0),
(3,65,1,1,1,0,1,0),
(3,66,1,1,1,0,1,0),
(3,67,1,1,1,0,1,0),
(3,68,1,1,1,0,1,0),
(3,69,1,1,1,0,1,0),
(3,70,1,0,0,0,1,0),
(3,71,1,0,0,0,1,0),
(3,72,1,0,0,0,1,0),
(3,73,1,0,0,0,1,0),
(3,74,1,0,0,0,1,0),
(3,75,1,0,0,0,1,0),
(3,76,1,0,0,0,1,0),
(3,77,1,0,0,0,1,0),
(3,78,1,0,0,0,1,0),
(3,79,1,0,0,0,1,0),
(3,80,1,0,0,0,1,0),
(3,81,1,0,0,0,1,0),
(3,119,1,0,0,0,0,0),
(4,1,1,0,0,0,1,0),
(4,2,1,0,0,0,1,0),
(4,3,1,0,0,0,1,0),
(4,4,1,0,0,0,1,0),
(4,5,1,0,0,0,1,0),
(4,6,1,0,0,0,1,0),
(4,7,1,0,0,0,1,0),
(4,97,1,1,1,0,1,0),
(4,98,1,1,1,0,1,0),
(4,99,1,1,1,0,1,0),
(4,100,1,1,1,0,1,0),
(4,101,1,1,1,0,1,0),
(4,102,1,1,1,0,1,0),
(4,103,1,1,1,0,1,0),
(4,104,1,1,1,0,1,0),
(4,105,1,1,1,0,1,0),
(4,106,1,1,1,0,1,0),
(4,107,1,1,1,0,1,0),
(4,108,1,0,0,0,1,0),
(4,109,1,0,0,0,1,0),
(4,110,1,0,0,0,1,0),
(4,111,1,0,0,0,1,0),
(4,112,1,0,0,0,1,0),
(4,113,1,0,0,0,1,0),
(4,114,1,0,0,0,1,0),
(4,115,1,0,0,0,1,0),
(4,116,1,0,0,0,1,0),
(4,117,1,0,0,0,1,0),
(4,119,1,0,0,0,0,0),
(4,176,1,0,0,0,1,0),
(4,177,1,0,0,0,0,0),
(4,178,1,0,0,0,1,0),
(4,179,1,0,0,0,1,0),
(4,180,1,0,0,0,1,0),
(4,181,1,0,0,0,1,0),
(5,1,1,0,0,0,1,0),
(5,2,1,0,0,0,1,0),
(5,3,1,0,0,0,1,0),
(5,4,1,0,0,0,1,0),
(5,5,1,0,0,0,1,0),
(5,6,1,0,0,0,1,0),
(5,7,1,0,0,0,1,0),
(5,103,1,0,0,0,1,0),
(5,105,1,1,1,0,1,0),
(5,118,1,0,0,0,1,0),
(5,119,1,1,1,0,1,0),
(5,120,1,1,1,1,1,0),
(5,121,1,1,1,0,1,0),
(5,122,1,1,1,0,1,0),
(5,123,1,1,1,0,1,0),
(5,124,1,1,1,0,1,0),
(5,125,1,1,1,0,1,0),
(5,126,1,1,1,0,1,0),
(5,127,1,1,1,0,1,0),
(5,128,1,0,0,0,1,0),
(5,131,1,0,0,0,1,0),
(5,136,1,0,0,0,1,0),
(5,137,1,0,0,0,1,0),
(5,138,1,0,0,0,1,0),
(5,139,1,0,0,0,1,0),
(5,140,1,0,0,0,1,0),
(5,141,1,0,0,0,1,0),
(5,142,1,0,0,0,1,0),
(5,144,1,0,0,0,1,0),
(5,145,1,0,0,0,1,0),
(5,146,1,0,0,0,1,0),
(5,148,1,0,0,0,1,0),
(5,149,1,0,0,0,1,0),
(5,150,1,0,0,0,1,0),
(6,1,1,0,0,0,1,0),
(6,2,1,0,0,0,1,0),
(6,3,1,0,0,0,1,0),
(6,4,1,0,0,0,1,0),
(6,5,1,0,0,0,1,0),
(6,6,1,0,0,0,1,0),
(6,7,1,0,0,0,1,0),
(6,58,1,0,0,0,1,0),
(6,59,1,1,1,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(6,60,1,1,1,0,1,0),
(6,61,1,1,1,0,1,0),
(6,81,1,0,0,0,1,0),
(6,82,1,0,0,0,1,0),
(6,83,1,0,0,0,1,0),
(6,84,1,0,0,0,1,0),
(6,85,1,0,0,0,1,0),
(6,86,1,0,0,0,1,0),
(6,87,1,0,0,0,1,0),
(6,88,1,0,0,0,1,0),
(6,89,1,0,0,0,1,0),
(6,90,1,0,0,0,1,0),
(6,91,1,0,0,0,1,0),
(6,92,1,0,0,0,1,0),
(6,93,1,0,0,0,1,0),
(6,94,1,0,0,0,1,0),
(6,95,1,0,0,0,1,0),
(6,96,1,0,0,0,1,0),
(6,150,1,0,0,0,1,0),
(7,1,1,0,0,0,0,0),
(7,151,1,1,0,0,1,0),
(7,152,1,1,0,0,1,0),
(7,153,1,1,0,1,1,0),
(8,1,1,0,0,0,0,0),
(8,2,1,0,0,0,0,0),
(8,151,1,1,1,1,1,1),
(8,152,1,1,1,1,1,1),
(8,153,1,1,1,1,1,1),
(8,154,1,1,1,1,1,1),
(8,155,1,0,0,0,1,0),
(8,156,1,0,0,0,1,0),
(8,157,1,0,0,0,1,0),
(8,158,1,0,0,0,1,0),
(8,159,1,0,0,0,1,0),
(8,160,1,0,0,0,1,0),
(8,161,1,0,0,0,1,0),
(8,162,1,0,0,0,1,0),
(8,163,1,0,0,0,1,0),
(8,164,1,0,0,0,1,0),
(8,165,1,0,0,0,1,0),
(9,1,1,0,0,0,1,0),
(9,2,1,0,0,0,1,0),
(9,3,1,0,0,0,1,0),
(9,4,1,0,0,0,1,0),
(9,5,1,0,0,0,1,0),
(9,6,1,0,0,0,1,0),
(9,7,1,0,0,0,1,0),
(9,119,1,0,0,0,0,0),
(9,186,1,0,0,0,1,0),
(9,187,1,1,1,0,1,0),
(9,188,1,0,0,0,1,0),
(9,192,1,1,1,0,1,0),
(9,193,1,1,0,0,1,0),
(9,194,1,1,1,0,1,0),
(9,195,1,1,1,0,1,0),
(9,196,1,0,0,0,1,0),
(9,197,1,1,0,0,1,0),
(9,198,1,0,0,0,1,0),
(9,199,1,0,0,0,1,0),
(9,202,1,0,0,0,1,0),
(9,203,1,0,0,0,1,0),
(9,204,1,0,0,0,1,0),
(9,205,1,0,0,0,1,0),
(9,206,1,0,0,0,1,0),
(9,217,1,0,0,0,1,0),
(9,221,1,0,0,0,1,0),
(10,1,1,0,0,0,1,0),
(10,2,1,0,0,0,1,0),
(10,3,1,0,0,0,1,0),
(10,4,1,0,0,0,1,0),
(10,5,1,0,0,0,1,0),
(10,6,1,0,0,0,1,0),
(10,7,1,0,0,0,1,0),
(10,25,1,0,0,0,1,0),
(10,58,1,0,0,0,1,0),
(10,70,1,0,0,0,1,0),
(10,186,1,0,0,0,1,0),
(10,187,1,0,1,0,1,1),
(10,188,1,1,1,0,1,1),
(10,189,1,0,0,0,1,0),
(10,190,1,0,0,0,1,1),
(10,191,1,0,0,0,1,0),
(10,192,1,0,0,0,1,1),
(10,193,1,0,1,0,1,0),
(10,194,1,0,1,0,1,0),
(10,195,1,0,1,0,1,0),
(10,196,1,1,1,1,1,0),
(10,197,1,1,1,1,1,1),
(10,198,1,1,1,1,1,0),
(10,199,1,1,1,1,1,0),
(10,200,1,0,1,0,1,0),
(10,201,1,0,1,0,1,0),
(10,202,1,1,1,1,1,0),
(10,203,1,0,0,0,1,0),
(10,204,1,0,0,0,1,0),
(10,205,1,0,0,0,1,0),
(10,206,1,0,0,0,1,0),
(10,207,1,0,0,0,1,0),
(10,208,1,0,0,0,1,0),
(10,209,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(10,210,1,0,0,0,1,0),
(10,211,1,0,0,0,1,0),
(10,212,1,0,0,0,1,0),
(10,213,1,0,0,0,1,0),
(10,214,1,0,0,0,1,0),
(10,215,1,0,0,0,1,0),
(10,216,1,0,0,0,1,0),
(10,217,1,0,0,0,1,0),
(10,218,1,0,0,0,1,0),
(10,219,1,0,0,0,1,0),
(10,220,1,0,0,0,1,0),
(10,221,1,0,0,0,1,0),
(11,1,1,0,0,0,1,0),
(11,2,1,0,0,0,1,0),
(11,3,1,0,0,0,1,0),
(11,4,1,0,0,0,1,0),
(11,5,1,0,0,0,1,0),
(11,6,1,0,0,0,1,0),
(11,7,1,0,0,0,1,0),
(11,119,1,0,0,0,0,0),
(11,131,1,0,0,0,1,0),
(11,186,1,0,0,0,1,0),
(11,187,1,0,1,0,1,0),
(11,188,1,0,0,0,1,0),
(11,189,1,1,1,0,1,0),
(11,192,1,1,1,0,1,0),
(11,193,1,1,0,0,1,0),
(11,194,1,0,0,0,1,0),
(11,195,1,0,0,0,1,0),
(11,207,1,0,0,0,1,0),
(11,208,1,0,0,0,1,0),
(11,209,1,0,0,0,1,0),
(11,213,1,0,0,0,1,0),
(11,221,1,0,0,0,1,0),
(12,1,1,0,0,0,0,0),
(12,131,1,0,0,0,1,0),
(12,187,1,0,0,0,1,0),
(12,189,1,1,1,0,1,0),
(12,192,1,1,0,0,1,0),
(12,193,1,1,0,0,1,0),
(13,1,1,0,0,0,1,0),
(13,2,1,0,0,0,1,0),
(13,3,1,0,0,0,1,0),
(13,4,1,0,0,0,1,0),
(13,5,1,0,0,0,1,0),
(13,6,1,0,0,0,1,0),
(13,7,1,0,0,0,1,0),
(13,58,1,0,0,0,1,0),
(13,61,1,0,0,0,1,0),
(13,186,1,0,0,0,1,0),
(13,187,1,0,0,0,1,0),
(13,188,1,0,0,0,1,0),
(13,190,1,1,1,1,1,0),
(13,192,1,1,1,0,1,0),
(13,193,1,1,0,0,1,0),
(13,207,1,0,0,0,1,0),
(13,210,1,0,0,0,1,0),
(13,211,1,0,0,0,1,0),
(13,221,1,0,0,0,1,0),
(14,1,1,0,0,0,1,0),
(14,2,1,0,0,0,1,0),
(14,3,1,0,0,0,1,0),
(14,4,1,0,0,0,1,0),
(14,5,1,0,0,0,1,0),
(14,6,1,0,0,0,1,0),
(14,7,1,0,0,0,1,0),
(14,58,1,0,0,0,1,0),
(14,68,1,0,0,0,1,0),
(14,186,1,0,0,0,1,0),
(14,187,1,0,0,0,1,0),
(14,190,1,0,0,0,1,0),
(14,191,1,1,1,1,1,0),
(14,192,1,1,1,0,1,0),
(14,193,1,1,0,0,1,0),
(14,211,1,0,0,0,1,0),
(14,212,1,0,0,0,1,0),
(14,214,1,0,0,0,1,0),
(14,216,1,0,0,0,1,0),
(14,218,1,0,0,0,1,0),
(14,221,1,0,0,0,1,0),
(15,1,1,0,0,0,1,0),
(15,2,1,0,0,0,1,0),
(15,3,1,0,0,0,1,0),
(15,4,1,0,0,0,1,0),
(15,5,1,0,0,0,1,0),
(15,6,1,0,0,0,1,0),
(15,7,1,0,0,0,1,0),
(15,8,1,0,0,0,1,0),
(15,9,1,0,0,0,1,0),
(15,10,1,0,0,0,1,0),
(15,11,1,0,0,0,1,0),
(15,12,1,0,0,0,1,0),
(15,13,1,0,0,0,1,0),
(15,14,1,0,0,0,1,0),
(15,15,1,0,0,0,1,0),
(15,16,1,0,0,0,1,0),
(15,17,1,0,0,0,1,0),
(15,18,1,0,0,0,1,0),
(15,19,1,0,0,0,1,0),
(15,20,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(15,21,1,0,0,0,1,0),
(15,22,1,0,0,0,1,0),
(15,23,1,0,0,0,1,0),
(15,24,1,0,0,0,1,0),
(15,25,1,0,0,0,1,0),
(15,26,1,0,0,0,1,0),
(15,27,1,0,0,0,1,0),
(15,28,1,0,0,0,1,0),
(15,29,1,0,0,0,1,0),
(15,30,1,0,0,0,1,0),
(15,31,1,0,0,0,1,0),
(15,32,1,0,0,0,1,0),
(15,33,1,0,0,0,1,0),
(15,34,1,0,0,0,1,0),
(15,35,1,0,0,0,1,0),
(15,36,1,0,0,0,1,0),
(15,37,1,0,0,0,1,0),
(15,38,1,0,0,0,1,0),
(15,39,1,0,0,0,1,0),
(15,40,1,0,0,0,1,0),
(15,41,1,0,0,0,1,0),
(15,42,1,0,0,0,1,0),
(15,43,1,0,0,0,1,0),
(15,44,1,0,0,0,1,0),
(15,45,1,0,0,0,1,0),
(15,46,1,0,0,0,1,0),
(15,47,1,0,0,0,1,0),
(15,48,1,0,0,0,1,0),
(15,49,1,0,0,0,1,0),
(15,50,1,0,0,0,1,0),
(15,51,1,0,0,0,1,0),
(15,52,1,0,0,0,1,0),
(15,53,1,0,0,0,1,0),
(15,54,1,0,0,0,1,0),
(15,55,1,0,0,0,1,0),
(15,56,1,0,0,0,1,0),
(15,57,1,0,0,0,1,0),
(15,58,1,0,0,0,1,0),
(15,59,1,0,0,0,1,0),
(15,60,1,0,0,0,1,0),
(15,61,1,0,0,0,1,0),
(15,62,1,0,0,0,1,0),
(15,63,1,0,0,0,1,0),
(15,64,1,0,0,0,1,0),
(15,65,1,0,0,0,1,0),
(15,66,1,0,0,0,1,0),
(15,67,1,0,0,0,1,0),
(15,68,1,0,0,0,1,0),
(15,69,1,0,0,0,1,0),
(15,70,1,0,0,0,1,0),
(15,71,1,0,0,0,1,0),
(15,72,1,0,0,0,1,0),
(15,73,1,0,0,0,1,0),
(15,74,1,0,0,0,1,0),
(15,75,1,0,0,0,1,0),
(15,76,1,0,0,0,1,0),
(15,77,1,0,0,0,1,0),
(15,78,1,0,0,0,1,0),
(15,79,1,0,0,0,1,0),
(15,80,1,0,0,0,1,0),
(15,81,1,0,0,0,1,0),
(15,82,1,0,0,0,1,0),
(15,83,1,0,0,0,1,0),
(15,84,1,0,0,0,1,0),
(15,85,1,0,0,0,1,0),
(15,86,1,0,0,0,1,0),
(15,87,1,0,0,0,1,0),
(15,88,1,0,0,0,1,0),
(15,89,1,0,0,0,1,0),
(15,90,1,0,0,0,1,0),
(15,91,1,0,0,0,1,0),
(15,92,1,0,0,0,1,0),
(15,93,1,0,0,0,1,0),
(15,94,1,0,0,0,1,0),
(15,95,1,0,0,0,1,0),
(15,96,1,0,0,0,1,0),
(15,97,1,0,0,0,1,0),
(15,98,1,0,0,0,1,0),
(15,99,1,0,0,0,1,0),
(15,100,1,0,0,0,1,0),
(15,101,1,0,0,0,1,0),
(15,102,1,0,0,0,1,0),
(15,103,1,0,0,0,1,0),
(15,104,1,0,0,0,1,0),
(15,105,1,0,0,0,1,0),
(15,106,1,0,0,0,1,0),
(15,107,1,0,0,0,1,0),
(15,108,1,0,0,0,1,0),
(15,109,1,0,0,0,1,0),
(15,110,1,0,0,0,1,0),
(15,111,1,0,0,0,1,0),
(15,112,1,0,0,0,1,0),
(15,113,1,0,0,0,1,0),
(15,114,1,0,0,0,1,0),
(15,115,1,0,0,0,1,0),
(15,116,1,0,0,0,1,0),
(15,117,1,0,0,0,1,0),
(15,118,1,0,0,0,1,0),
(15,119,1,0,0,0,1,0),
(15,120,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(15,121,1,0,0,0,1,0),
(15,122,1,0,0,0,1,0),
(15,123,1,0,0,0,1,0),
(15,124,1,0,0,0,1,0),
(15,125,1,0,0,0,1,0),
(15,126,1,0,0,0,1,0),
(15,127,1,0,0,0,1,0),
(15,128,1,0,0,0,1,0),
(15,129,1,0,0,0,1,0),
(15,130,1,0,0,0,1,0),
(15,131,1,0,0,0,1,0),
(15,132,1,0,0,0,1,0),
(15,133,1,0,0,0,1,0),
(15,134,1,0,0,0,1,0),
(15,135,1,0,0,0,1,0),
(15,136,1,0,0,0,1,0),
(15,137,1,0,0,0,1,0),
(15,138,1,0,0,0,1,0),
(15,139,1,0,0,0,1,0),
(15,140,1,0,0,0,1,0),
(15,141,1,0,0,0,1,0),
(15,142,1,0,0,0,1,0),
(15,143,1,0,0,0,1,0),
(15,144,1,0,0,0,1,0),
(15,145,1,0,0,0,1,0),
(15,146,1,0,0,0,1,0),
(15,147,1,0,0,0,1,0),
(15,148,1,0,0,0,1,0),
(15,149,1,0,0,0,1,0),
(15,150,1,0,0,0,1,0),
(15,153,1,0,0,0,1,0),
(15,154,1,0,0,0,1,0),
(15,155,1,0,0,0,1,0),
(15,156,1,0,0,0,1,0),
(15,157,1,0,0,0,1,0),
(15,158,1,0,0,0,1,0),
(15,159,1,0,0,0,1,0),
(15,160,1,0,0,0,1,0),
(15,161,1,0,0,0,1,0),
(15,162,1,0,0,0,1,0),
(15,163,1,0,0,0,1,0),
(15,164,1,0,0,0,1,0),
(15,165,1,0,0,0,1,0),
(15,166,1,0,0,0,1,0),
(15,167,1,0,0,0,1,0),
(15,168,1,0,0,0,1,0),
(15,169,1,0,0,0,1,0),
(15,170,1,0,0,0,1,0),
(15,171,1,0,0,0,1,0),
(15,172,1,0,0,0,1,0),
(15,173,1,0,0,0,1,0),
(15,174,1,0,0,0,1,0),
(15,175,1,0,0,0,1,0),
(15,176,1,0,0,0,1,0),
(15,177,1,0,0,0,1,0),
(15,178,1,0,0,0,1,0),
(15,179,1,0,0,0,1,0),
(15,180,1,0,0,0,1,0),
(15,181,1,0,0,0,1,0),
(15,182,1,0,0,0,1,0),
(15,183,1,0,0,0,1,0),
(15,184,1,0,0,0,1,0),
(15,185,1,0,0,0,1,0),
(15,186,1,0,0,0,1,0),
(15,187,1,0,0,0,1,0),
(15,188,1,0,0,0,1,0),
(15,189,1,0,0,0,1,0),
(15,190,1,0,0,0,1,0),
(15,191,1,0,0,0,1,0),
(15,192,1,0,0,0,1,0),
(15,193,1,0,0,0,1,0),
(15,194,1,0,0,0,1,0),
(15,195,1,0,0,0,1,0),
(15,196,1,0,0,0,1,0),
(15,197,1,0,0,0,1,0),
(15,198,1,0,0,0,1,0),
(15,199,1,0,0,0,1,0),
(15,200,1,0,0,0,1,0),
(15,201,1,0,0,0,1,0),
(15,202,1,0,0,0,1,0),
(15,203,1,0,0,0,1,0),
(15,204,1,0,0,0,1,0),
(15,205,1,0,0,0,1,0),
(15,206,1,0,0,0,1,0),
(15,207,1,0,0,0,1,0),
(15,208,1,0,0,0,1,0),
(15,209,1,0,0,0,1,0),
(15,210,1,0,0,0,1,0),
(15,211,1,0,0,0,1,0),
(15,212,1,0,0,0,1,0),
(15,213,1,0,0,0,1,0),
(15,214,1,0,0,0,1,0),
(15,215,1,0,0,0,1,0),
(15,216,1,0,0,0,1,0),
(15,217,1,0,0,0,1,0),
(15,218,1,0,0,0,1,0),
(15,219,1,0,0,0,1,0),
(15,220,1,0,0,0,1,0),
(15,221,1,0,0,0,1,0),
(15,231,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(15,232,1,0,0,0,1,0),
(15,233,1,0,0,0,1,0),
(15,234,1,0,0,0,1,0),
(15,235,1,0,0,0,1,0),
(15,236,1,0,0,0,1,0),
(15,237,1,0,0,0,1,0);

-- role_special_permissions (43 rows)
INSERT INTO `role_special_permissions` (`role_id`,`perm_key`,`perm_value`) VALUES
(2,'closed_period_changes','0'),
(2,'coa_changes','1'),
(2,'credit_limit_override','0'),
(2,'design_reports','1'),
(2,'max_amount',''),
(2,'max_discount_pct','0'),
(2,'max_price_override_pct','0'),
(2,'view_bank_balances','1'),
(2,'view_costs','1'),
(3,'credit_limit_override','0'),
(3,'max_amount','50000'),
(3,'max_discount_pct','10'),
(3,'max_price_override_pct','5'),
(3,'view_bank_balances','0'),
(3,'view_costs','0'),
(4,'max_amount','100000'),
(4,'view_bank_balances','0'),
(4,'view_costs','1'),
(5,'max_amount','20000'),
(5,'view_costs','0'),
(6,'max_discount_pct','5'),
(6,'max_price_override_pct','0'),
(6,'view_costs','0'),
(7,'max_amount','0'),
(7,'max_discount_pct','5'),
(7,'max_price_override_pct','0'),
(7,'view_costs','0'),
(8,'max_amount','20000'),
(8,'max_discount_pct','30'),
(8,'max_price_override_pct','20'),
(8,'view_costs','0'),
(9,'brgy_approval_limit','0'),
(9,'view_costs','1'),
(10,'brgy_approval_limit',''),
(10,'view_costs','1'),
(11,'brgy_approval_limit','0'),
(11,'view_costs','0'),
(12,'view_costs','0'),
(13,'brgy_approval_limit','0'),
(13,'view_costs','0'),
(14,'view_costs','0'),
(15,'view_bank_balances','1'),
(15,'view_costs','1');

-- sequences (34 rows)
INSERT INTO `sequences` (`seq_key`,`prefix`,`next_no`,`pad`) VALUES
('adjustment','ADJ-',1,5),
('advance_apply','AAP-',1,5),
('asset','FA-',1,5),
('bill','BILL-',1,5),
('brgy_billing','BBL-',1,5),
('brgy_co','BCO-',1,5),
('brgy_collection','BCL-',1,5),
('brgy_delivery','BDR-',1,5),
('brgy_form','BFM-',1,5),
('brgy_project','BCP-',1,5),
('brgy_security','BSC-',1,5),
('brgy_txn_form','TF-',1,4),
('build','BLD-',1,5),
('cash_voucher','CAV-',1,5),
('check','',1001,4),
('credit_memo','CM-',1,5),
('debit_memo','DM-',1,5),
('deposit','DEP-',1,5),
('estimate','EST-',1,5),
('finance_charge','FC-',1,5),
('inv_transfer','IT-',1,5),
('invoice','INV-',1,5),
('item_receipt','IR-',1,5),
('journal','JE-',1,5),
('maintenance','MNT-',1,5),
('payment','PMT-',1,5),
('pos','POS-',1,6),
('processing','PRC-',1,5),
('purchase_order','PO-',1,5),
('sales_order','SO-',1,5),
('sales_receipt','SR-',1,5),
('statement_charge','SC-',1,5),
('transfer','TRF-',1,5),
('vendor_advance','ADV-',1,5);

-- accounts (77 rows)
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`is_active`,`is_system`,`created_at`,`updated_at`) VALUES
(1,'1000','Cash','Bank','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(2,'1001','Cash on Hand - Store Registers','Bank','Asset','Debit',1,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(3,'1005','Petty Cash Fund','Bank','Asset','Debit',1,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(4,'1006','Revolving Fund','Bank','Asset','Debit',1,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(5,'1010','Cash in Bank - BDO Checking','Bank','Asset','Debit',1,NULL,'0012-3456-7890',0,1,0,'2026-11-23 19:54:44',NULL),
(6,'1020','Cash in Bank - BPI Savings','Bank','Asset','Debit',1,NULL,'1234-5678-90',0,1,0,'2026-11-23 19:54:44',NULL),
(7,'1050','Undeposited Funds','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-11-23 19:54:44',NULL),
(8,'1060','Card Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(9,'1065','E-wallet Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(10,'1070','P.O. Card Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(11,'1100','Accounts Receivable','Accounts Receivable','Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-11-23 19:54:44',NULL),
(12,'1150','Allowance for Doubtful Accounts','Other Current Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(13,'1160','Retention Receivable','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(14,'1200','Inventory','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-11-23 19:54:44',NULL),
(15,'1300','Input Tax','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-11-23 19:54:44',NULL),
(16,'1310','Creditable Withholding Tax','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(17,'1315','Creditable VAT Withheld','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(18,'1400','Prepaid Expenses','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(19,'1410','Advances to Suppliers','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(20,'1420','Bid and Performance Security Deposits','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(21,'1500','Land','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(22,'1510','Building','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(23,'1515','Accumulated Depreciation - Building','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(24,'1520','Machinery and Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(25,'1525','Accumulated Depreciation - Machinery','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(26,'1530','Transportation Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(27,'1535','Accumulated Depreciation - Transportation','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(28,'1540','Furniture and Fixtures','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(29,'1545','Accumulated Depreciation - Furniture','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(30,'1550','Computer and POS Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(31,'1555','Accumulated Depreciation - Computer and POS','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(32,'1600','Security Deposits','Other Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(33,'2000','Accounts Payable','Accounts Payable','Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-11-23 19:54:44',NULL),
(34,'2050','Company Credit Card','Credit Card','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(35,'2100','Output Tax','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-11-23 19:54:44',NULL),
(36,'2110','Withholding Tax Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(37,'2115','VAT Withheld Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(38,'2120','Gift Vouchers Outstanding','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(39,'2130','Customer Deposits','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(40,'2200','Accrued Expenses','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(41,'2500','Loans Payable','Long Term Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(42,'3000','Owner\'s Capital','Equity','Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(43,'3100','Owner\'s Drawings','Equity','Equity','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(44,'3200','Retained Earnings','Equity','Equity','Credit',NULL,NULL,NULL,0,1,1,'2026-11-23 19:54:44',NULL),
(45,'3900','Opening Balance Equity','Equity','Equity','Credit',NULL,NULL,NULL,0,1,1,'2026-11-23 19:54:44',NULL),
(46,'4000','Sales','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(47,'4010','Service Income','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(48,'4015','Contract Revenue - Works','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(49,'4020','Delivery Income','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(50,'4050','Sales Returns and Allowances','Income','Income','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(51,'4060','Sales Discounts','Income','Income','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(52,'4070','Item Group Price Variance','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(53,'4110','Reimbursable Expense Income','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(54,'4100','Finance Charge Income','Other Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(55,'4200','Interest Income','Other Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(56,'4300','Gain (Loss) on Disposal of Assets','Other Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(57,'5000','Cost of Goods Sold','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(58,'5050','Purchase Discounts','Cost of Goods Sold','Expense','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(59,'5100','Inventory Adjustments and Shrinkage','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(60,'5200','Direct Labor Absorbed','Cost of Goods Sold','Expense','Credit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(61,'6000','Salaries and Wages','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(62,'6010','Rent Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(63,'6020','Utilities Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(64,'6030','Repairs and Maintenance','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(65,'6040','Office Supplies Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(66,'6050','Fuel and Oil','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(67,'6060','Meals and Representation','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(68,'6070','Professional Fees','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(69,'6080','Bank and Merchant Charges','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(70,'6085','Liquidated Damages','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(71,'6090','Depreciation Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(72,'6100','Freight and Delivery','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(73,'6110','Purchases - Non-inventory','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(74,'6200','Cash Over and Short','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(75,'6900','Miscellaneous Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(76,'7000','Interest Expense','Other Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL),
(77,'7010','Loss on Forfeited Security','Other Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-11-23 19:54:44',NULL);

-- default_accounts (39 rows)
INSERT INTO `default_accounts` (`setting_key`,`label`,`module`,`account_id`) VALUES
('ap','Accounts Payable','vend',33),
('ar','Accounts Receivable','cust',11),
('card_clearing','Card Clearing','pos',8),
('cash_on_hand','Cash on Hand (Register)','pos',2),
('cash_over_short','Cash Over and Short','pos',74),
('cogs','Cost of Goods Sold','inv',57),
('cvat_withheld','Creditable VAT Withheld','cust',17),
('cwt','Creditable Withholding Tax','cust',16),
('default_expense','Default Purchases / Expense','vend',73),
('ewallet_clearing','E-wallet Clearing','pos',9),
('finance_charge_income','Finance Charge Income','cust',54),
('forfeited_security','Loss on Forfeited Security','brgy',77),
('gain_loss_disposal','Gain / Loss on Disposal of Assets','fa',56),
('gov_goods_income','Contract Revenue - Goods','brgy',46),
('gov_services_income','Contract Revenue - Services','brgy',47),
('gov_works_income','Contract Revenue - Works','brgy',48),
('group_price_variance','Item Group Price Variance','inv',52),
('input_tax','Input Tax','vend',15),
('interest_income','Interest Income','bank',55),
('inventory_adjustment','Inventory Adjustment (Shrinkage)','inv',59),
('inventory_asset','Inventory Asset','inv',14),
('liquidated_damages','Liquidated Damages','brgy',70),
('merchant_fees','Bank / Merchant Charges','bank',69),
('opening_equity','Opening Balance Equity','coa',45),
('output_tax','Output Tax','cust',35),
('processing_absorbed','Processing Labor & Overhead Absorbed','inv',60),
('processing_variance','Processing / Disassembly Variance','inv',59),
('purchase_discounts','Purchase Discounts','vend',58),
('reimbursable_income','Reimbursable Expense Income','cust',53),
('retained_earnings','Retained Earnings','coa',44),
('retention_receivable','Retention Receivable','brgy',13),
('sales_discounts','Sales Discounts','cust',51),
('sales_income','Default Sales Income','cust',46),
('sales_returns','Sales Returns and Allowances','cust',50),
('security_deposits','Bid and Performance Security Deposits','brgy',20),
('undeposited_funds','Undeposited Funds','cust',7),
('vat_withheld_payable','VAT Withheld Payable','vend',37),
('vendor_advances','Advances to Suppliers','vend',19),
('wht_payable','Withholding Tax Payable (Expanded)','vend',36);

-- terms (5 rows)
INSERT INTO `terms` (`id`,`name`,`net_days`,`discount_pct`,`discount_days`,`is_active`) VALUES
(1,'Due on receipt',0,'0.00',0,1),
(2,'Net 15',15,'0.00',0,1),
(3,'Net 30',30,'0.00',0,1),
(4,'2% 10 Net 30',30,'2.00',10,1),
(5,'Net 60',60,'0.00',0,1);

-- tax_codes (4 rows)
INSERT INTO `tax_codes` (`id`,`code`,`name`,`rate`,`sales_account_id`,`purchase_account_id`,`is_active`) VALUES
(1,'VAT12','VAT 12%','12.000',35,15,1),
(2,'ZR','Zero-rated','0.000',35,15,1),
(3,'EX','VAT-exempt','0.000',35,15,1),
(4,'NT','Non-taxable','0.000',35,15,1);

-- payment_methods (9 rows)
INSERT INTO `payment_methods` (`id`,`name`,`method_type`,`clearing_account_id`,`is_active`,`show_in_pos`) VALUES
(1,'Cash','cash',NULL,1,1),
(2,'Check','check',NULL,1,0),
(3,'Credit/Debit Card','card',8,1,1),
(4,'GCash','ewallet',9,1,1),
(5,'Maya','ewallet',9,1,1),
(6,'Bank Transfer','bank_transfer',NULL,1,0),
(7,'Gift Voucher','voucher',38,1,1),
(8,'P.O. Card','card',10,1,1),
(9,'Bank','bank_transfer',5,1,1);

-- price_levels (10 rows)
INSERT INTO `price_levels` (`id`,`name`,`adjust_pct`,`is_active`) VALUES
(1,'Wholesale','-5.00',1),
(2,'VIP','-10.00',1),
(3,'Retail Plus','5.00',1),
(4,'Price Level 4','0.00',1),
(5,'Price Level 5','0.00',1),
(6,'Price Level 6','0.00',1),
(7,'Price Level 7','0.00',1),
(8,'Price Level 8','0.00',1),
(9,'Price Level 9','0.00',1),
(10,'Price Level 10','0.00',1);

-- sales_reps (2 rows)
INSERT INTO `sales_reps` (`id`,`name`,`initials`,`user_id`,`commission_pct`,`is_active`) VALUES
(1,'Maria Santos','MS',6,'3.00',1),
(2,'Jose Reyes','JR',NULL,'2.50',1);

-- customer_types (4 rows)
INSERT INTO `customer_types` (`id`,`name`) VALUES
(1,'Corporate'),
(2,'Retail'),
(3,'School'),
(4,'Government');

-- billing_groups (1 rows)
INSERT INTO `billing_groups` (`id`,`name`) VALUES
(1,'Monthly Service Contracts');

-- customers (9 rows)
INSERT INTO `customers` (`id`,`parent_id`,`name`,`company_name`,`contact_person`,`treasurer_name`,`phone`,`mobile`,`email`,`billing_address`,`shipping_address`,`zip_code`,`tin`,`wht_ids`,`terms_id`,`credit_limit`,`price_level_id`,`tax_code_id`,`payment_method_id`,`customer_type_id`,`sales_rep_id`,`billing_group_id`,`allow_pos_charge`,`notes`,`is_active`,`created_at`,`updated_at`) VALUES
(1,NULL,'ABC Trading Corp.','ABC Trading Corporation','Mr. Albert Co',NULL,'(02) 8777-1001',NULL,'ap@abctrading.test','45 Buendia Ave., Makati City','45 Buendia Ave., Makati City',NULL,'111-222-333-000',NULL,3,'250000.00',NULL,1,2,1,1,NULL,1,NULL,1,'2026-11-23 19:54:45',NULL),
(2,1,'Office Renovation Project',NULL,NULL,NULL,NULL,NULL,NULL,'45 Buendia Ave., Makati City',NULL,NULL,NULL,NULL,3,'0.00',NULL,1,NULL,1,1,NULL,0,NULL,1,'2026-11-23 19:54:45',NULL),
(3,NULL,'Metro School Supplies','Metro School Supplies Inc.','Ms. Bea Lim',NULL,'(02) 8777-2002',NULL,'purchasing@metroschool.test','88 España Blvd., Manila',NULL,NULL,NULL,NULL,2,'100000.00',1,1,NULL,2,2,NULL,0,NULL,1,'2026-11-23 19:54:45',NULL),
(4,NULL,'Cebu Tech Hub','Cebu Tech Hub Co.','Mr. Carlo Sy',NULL,'(032) 255-3003',NULL,'finance@cebutech.test','IT Park, Lahug, Cebu City',NULL,NULL,NULL,NULL,4,'150000.00',2,1,NULL,1,2,NULL,0,NULL,1,'2026-11-23 19:54:45',NULL),
(5,NULL,'Bright Minds Academy','Bright Minds Academy','Dr. Dina Reyes',NULL,'(02) 8777-4004',NULL,'admin@brightminds.test','12 Katipunan Ave., Quezon City',NULL,NULL,NULL,NULL,2,'60000.00',NULL,1,NULL,3,1,1,0,NULL,1,'2026-11-23 19:54:45',NULL),
(6,NULL,'Green Valley Clinic','Green Valley Clinic','Dr. Eric Tan',NULL,'(02) 8777-5005',NULL,NULL,'5 Shaw Blvd., Mandaluyong',NULL,NULL,NULL,NULL,2,'40000.00',NULL,1,NULL,1,1,1,0,NULL,1,'2026-11-23 19:54:45',NULL),
(7,NULL,'City Government of Makati','City Government of Makati','Procurement Office',NULL,'(02) 8870-1000',NULL,'procurement@makati.test','Makati City Hall, J.P. Rizal St., Makati City',NULL,NULL,'000-456-789-000','3,1',3,'0.00',NULL,1,2,4,NULL,NULL,0,NULL,1,'2026-11-23 19:54:45',NULL),
(8,NULL,'Juan Dela Cruz',NULL,'Juan Dela Cruz',NULL,NULL,'0917-555-0000',NULL,NULL,NULL,NULL,NULL,NULL,1,'0.00',NULL,1,1,2,NULL,NULL,0,NULL,1,'2026-11-23 19:54:45',NULL),
(9,NULL,'Walk-in Customer',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'0.00',NULL,1,1,2,NULL,NULL,0,NULL,1,'2026-11-23 19:54:45',NULL);

-- vendors (8 rows)
INSERT INTO `vendors` (`id`,`name`,`company_name`,`contact_person`,`phone`,`fax`,`email`,`address`,`tin`,`terms_id`,`credit_limit`,`default_expense_account_id`,`tax_code_id`,`account_no`,`wht_ids`,`notes`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'TechDistributors Inc.','TechDistributors Inc.','Rico Lopez','(02) 8555-0101',NULL,'sales@techdist.test','Pasig City','577-517-873-000',3,'0.00',NULL,1,'TD-88012','1',NULL,1,'2026-11-23 19:54:45',NULL),
(2,'Office Depot PH','Office Depot PH','Liza Go','(02) 8555-0202',NULL,'orders@officedepot.test','Quezon City','416-759-913-000',2,'0.00',65,1,'OD-4410',NULL,NULL,1,'2026-11-23 19:54:45',NULL),
(3,'Meralco','Meralco','Customer Service','16211',NULL,'billing@meralco.test','Pasig City','754-573-582-000',1,'0.00',63,1,'1234-5678-9012',NULL,NULL,1,'2026-11-23 19:54:45',NULL),
(4,'Metro Property Leasing','Metro Property Leasing','Tony Chua','(02) 8555-0303',NULL,'leasing@metroprop.test','Makati City','771-834-188-000',1,'0.00',62,1,'L-2291',NULL,NULL,1,'2026-11-23 19:54:45',NULL),
(5,'Petron Service Station','Petron Service Station',NULL,'(02) 8555-0404',NULL,NULL,'Makati City','713-905-193-000',1,'0.00',66,1,NULL,NULL,NULL,1,'2026-11-23 19:54:45',NULL),
(6,'JRS Express','JRS Express','Operations','(02) 8555-0505',NULL,'ops@jrs.test','Manila','123-520-130-000',2,'0.00',72,1,'JRS-7781','2',NULL,1,'2026-11-23 19:54:45',NULL),
(7,'Accounting Partners & Co.','Accounting Partners & Co.','Atty. Reyes','(02) 8555-0606',NULL,'hello@apco.test','Taguig City','906-260-161-000',3,'0.00',68,1,NULL,'2',NULL,1,'2026-11-23 19:54:45',NULL),
(8,'Industrial Machines Corp.','Industrial Machines Corp.','Sales','(02) 8555-0707',NULL,'sales@imc.test','Laguna','792-388-305-000',3,'0.00',NULL,1,'IMC-100','1',NULL,1,'2026-11-23 19:54:45',NULL);

-- sites (6 rows)
INSERT INTO `sites` (`id`,`code`,`name`,`site_type`,`address`,`is_active`) VALUES
(1,'WH','Main Warehouse','warehouse',NULL,1),
(2,'ST1','Makati Store','store',NULL,1),
(3,'ST2','Cebu Store','store',NULL,1),
(4,'VAN1','Delivery Van 1','van',NULL,1),
(5,'DMG','Damaged Goods','damaged',NULL,1),
(6,'BRGY-DLV','Delivered, awaiting acceptance','other','Goods at the procuring entity, not yet accepted',1);

-- item_categories (6 rows)
INSERT INTO `item_categories` (`id`,`name`) VALUES
(1,'Computers'),
(2,'Accessories'),
(3,'Services'),
(4,'Bundles'),
(5,'Supplies'),
(6,'Charges');

-- uom_sets (5 rows)
INSERT INTO `uom_sets` (`id`,`name`,`uom_kind`,`base_unit`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'Piece','count','pc',1,'2026-11-23 19:54:44',NULL),
(2,'Weight (kg)','weight','kg',1,'2026-11-23 19:54:44',NULL),
(3,'Volume (L)','volume','L',1,'2026-11-23 19:54:44',NULL),
(4,'Length (m)','length','m',1,'2026-11-23 19:54:44',NULL),
(5,'Time (hour)','time','hr',1,'2026-11-23 19:54:44',NULL);

-- uom_units (16 rows)
INSERT INTO `uom_units` (`id`,`set_id`,`code`,`name`,`factor`,`is_base`,`is_sales_default`,`is_purchase_default`,`sort_order`) VALUES
(1,1,'pc','Piece','1.000000',1,1,0,0),
(2,1,'dz','Dozen (12 pieces)','12.000000',0,0,0,1),
(3,1,'box','Box of 24','24.000000',0,0,1,2),
(4,1,'case','Case of 144','144.000000',0,0,0,3),
(5,2,'kg','Kilogram','1.000000',1,1,1,0),
(6,2,'g','Gram','0.001000',0,0,0,1),
(7,2,'sack','Sack of 25 kg','25.000000',0,0,0,2),
(8,3,'L','Liter','1.000000',1,1,1,0),
(9,3,'mL','Milliliter','0.001000',0,0,0,1),
(10,3,'drum','Drum of 200 L','200.000000',0,0,0,2),
(11,4,'m','Meter','1.000000',1,1,1,0),
(12,4,'cm','Centimeter','0.010000',0,0,0,1),
(13,4,'roll','Roll of 100 m','100.000000',0,0,0,2),
(14,5,'hr','Hour','1.000000',1,1,1,0),
(15,5,'day','Day (8 hours)','8.000000',0,0,0,1),
(16,5,'wk','Week (40 hours)','40.000000',0,0,0,2);

-- items (27 rows)
INSERT INTO `items` (`id`,`item_type`,`code`,`barcode`,`name`,`description`,`purchase_description`,`category_id`,`unit`,`uom_set_id`,`sales_uom_id`,`purchase_uom_id`,`cost`,`avg_cost`,`sales_price`,`discount_pct`,`income_account_id`,`cogs_account_id`,`asset_account_id`,`expense_account_id`,`tax_code_id`,`group_variance_account_id`,`preferred_vendor_id`,`reorder_point`,`max_stock`,`build_point`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'inventory','LAP-001','4800000000011','Laptop 14-inch i5 16GB','Laptop 14-inch i5 16GB','Laptop 14-inch i5 16GB',1,'pc',1,NULL,NULL,'32000.0000','0.0000','39990.0000','0.00',46,57,14,NULL,1,NULL,1,'5.0000','20.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(2,'inventory','MON-024','4800000000028','Monitor 24-inch IPS','Monitor 24-inch IPS','Monitor 24-inch IPS',1,'pc',1,NULL,NULL,'6500.0000','0.0000','8990.0000','0.00',46,57,14,NULL,1,NULL,1,'5.0000','30.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(3,'inventory','KEY-001','4800000000035','Keyboard USB','Keyboard USB','Keyboard USB',2,'pc',1,NULL,NULL,'650.0000','0.0000','999.0000','0.00',46,57,14,NULL,1,NULL,1,'10.0000','50.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(4,'inventory','MOU-001','4800000000042','Wireless Mouse','Wireless Mouse','Wireless Mouse',2,'pc',1,NULL,NULL,'350.0000','0.0000','599.0000','0.00',46,57,14,NULL,1,NULL,1,'15.0000','80.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(5,'inventory','USB-032','4800000000059','USB Flash Drive 32GB','USB Flash Drive 32GB','USB Flash Drive 32GB',2,'pc',1,NULL,NULL,'180.0000','0.0000','350.0000','0.00',46,57,14,NULL,1,NULL,1,'20.0000','100.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(6,'inventory','BAG-001','4800000000066','Laptop Backpack','Laptop Backpack','Laptop Backpack',2,'pc',1,NULL,NULL,'500.0000','0.0000','899.0000','0.00',46,57,14,NULL,1,NULL,1,'10.0000','40.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(7,'inventory','CAB-HDMI','4800000000073','HDMI Cable 1.5m','HDMI Cable 1.5m','HDMI Cable 1.5m',2,'pc',1,NULL,NULL,'90.0000','0.0000','199.0000','0.00',46,57,14,NULL,1,NULL,1,'20.0000','100.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(8,'inventory','RAM-8GB','4800000000080','RAM 8GB DDR4','RAM 8GB DDR4','RAM 8GB DDR4',2,'pc',1,NULL,NULL,'1100.0000','0.0000','1690.0000','0.00',46,57,14,NULL,1,NULL,1,'10.0000','40.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(9,'inventory','SSD-512','4800000000097','SSD 512GB NVMe','SSD 512GB NVMe','SSD 512GB NVMe',2,'pc',1,NULL,NULL,'2200.0000','0.0000','3290.0000','0.00',46,57,14,NULL,1,NULL,1,'10.0000','40.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(10,'service','LAB-ASM',NULL,'Assembly Labor (per set)','Assembly labor',NULL,3,'hr',5,NULL,NULL,'150.0000','0.0000','0.0000','0.00',47,60,NULL,60,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(11,'assembly','SET-OFFICE','4800000000103','Office Monitor Set','Monitor + keyboard + mouse + HDMI cable, assembled',NULL,4,'pc',1,NULL,NULL,'0.0000','0.0000','12990.0000','0.00',46,57,14,NULL,1,NULL,NULL,'3.0000','15.0000','3.0000',1,'2026-11-23 19:54:45',NULL),
(12,'service','SRV-INSTALL',NULL,'Installation and Setup','On-site installation and setup',NULL,3,'hr',5,NULL,NULL,'0.0000','0.0000','1500.0000','0.00',47,57,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(13,'service','SRV-REPAIR',NULL,'Repair Service (per hour)','Repair service',NULL,3,'hr',5,NULL,NULL,'0.0000','0.0000','800.0000','0.00',47,57,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(14,'service','SRV-MAINT',NULL,'Monthly IT Maintenance','Monthly IT maintenance contract',NULL,3,'hr',5,NULL,NULL,'0.0000','0.0000','5000.0000','0.00',47,57,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(15,'non_inventory','NI-TONER',NULL,'Toner Cartridge (special order)','Toner cartridge - special order',NULL,5,'pc',1,NULL,NULL,'2500.0000','0.0000','3200.0000','0.00',46,57,NULL,57,1,NULL,2,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(16,'other_charge','DEL-FEE',NULL,'Delivery Fee','Delivery fee',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','250.0000','0.00',49,57,NULL,72,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(17,'other_charge','REIMB',NULL,'Reimbursable Expense','Reimbursable expense',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','0.00',53,57,NULL,75,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(18,'other_charge','LATE-FEE',NULL,'Late Payment Fee','Late payment fee',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','200.0000','0.00',54,57,NULL,NULL,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(19,'discount','DISC-10',NULL,'10% Discount','10% discount',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','10.00',51,57,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(20,'discount','DISC-AMT',NULL,'Fixed Discount','Discount',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','100.0000','0.00',51,57,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(21,'subtotal','SUBTOTAL',NULL,'Subtotal','Subtotal',NULL,NULL,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','0.00',46,57,NULL,NULL,NULL,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(22,'group','GRP-WFH',NULL,'Work-from-Home Bundle','Laptop + mouse + backpack',NULL,4,'pc',1,NULL,NULL,'0.0000','0.0000','40000.0000','0.00',46,57,NULL,NULL,NULL,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(23,'payment','DOWNPMT',NULL,'Down Payment Received','Less: down payment received',NULL,NULL,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','0.00',46,57,NULL,NULL,NULL,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(24,'inventory','USB-032-BX50','4800000000110','USB Flash Drive 32GB (Box of 50)','USB Flash Drive 32GB, box of 50','USB Flash Drive 32GB, box of 50',2,'box',NULL,NULL,NULL,'8500.0000','0.0000','16500.0000','0.00',46,57,14,NULL,1,NULL,1,'1.0000','5.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(25,'inventory','TRD-PC',NULL,'Trade-in Desktop PC (used)','Used desktop PC taken in for parts / refurbishing','Trade-in desktop PC',1,'pc',1,NULL,NULL,'3000.0000','0.0000','0.0000','0.00',46,57,14,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(26,'inventory','REF-PC',NULL,'Refurbished Desktop PC','Refurbished desktop PC with new SSD, 90-day warranty',NULL,1,'pc',1,NULL,NULL,'0.0000','0.0000','14990.0000','0.00',46,57,14,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL),
(27,'inventory','SCRAP-KG',NULL,'Scrap Metal and Plastic','Scrap metal and plastic (sold by the kilo)',NULL,5,'kg',2,NULL,NULL,'15.0000','0.0000','25.0000','0.00',46,57,14,NULL,3,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-11-23 19:54:45',NULL);

-- item_components (8 rows)
INSERT INTO `item_components` (`parent_item_id`,`component_item_id`,`qty`) VALUES
(11,2,'1.0000'),
(11,3,'1.0000'),
(11,4,'1.0000'),
(11,7,'1.0000'),
(11,10,'1.0000'),
(22,1,'1.0000'),
(22,4,'1.0000'),
(22,6,'1.0000');

-- process_formulas (3 rows)
INSERT INTO `process_formulas` (`id`,`code`,`name`,`process_type`,`alloc_method`,`site_id`,`description`,`is_active`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'BRK-USB50','Break bulk: USB 32GB box of 50','dekit','quantity',NULL,'Open a box of 50 and sell the drives one by one.',1,NULL,'2026-11-23 19:54:45',NULL),
(2,'TD-PC','Trade-in PC teardown','disassembly','sales_value',NULL,'Strip a trade-in PC for resaleable parts; the chassis is sold as scrap by the kilo.',1,NULL,'2026-11-23 19:54:45',NULL),
(3,'RFB-PC','Refurbish trade-in PC (new SSD)','co_product','percent',NULL,'Trade-in PC plus a new SSD become a refurbished PC; the old RAM is recovered for resale.',1,NULL,'2026-11-23 19:54:45',NULL);

-- process_formula_lines (13 rows)
INSERT INTO `process_formula_lines` (`id`,`formula_id`,`line_no`,`line_type`,`item_id`,`account_id`,`description`,`qty`,`output_role`,`alloc_basis`,`amount`) VALUES
(1,1,1,'input',24,NULL,NULL,'1.0000',NULL,'0.0000','0.00'),
(2,1,2,'output',5,NULL,NULL,'50.0000','co_product','0.0000','0.00'),
(3,2,1,'input',25,NULL,NULL,'1.0000',NULL,'0.0000','0.00'),
(4,2,2,'cost',NULL,60,'Technician labor (1 hour)','0.0000',NULL,'0.0000','150.00'),
(5,2,3,'output',8,NULL,NULL,'2.0000','co_product','0.0000','0.00'),
(6,2,4,'output',9,NULL,NULL,'1.0000','co_product','0.0000','0.00'),
(7,2,5,'output',27,NULL,NULL,'4.0000','by_product','15.0000','0.00'),
(8,3,1,'input',25,NULL,NULL,'1.0000',NULL,'0.0000','0.00'),
(9,3,2,'input',9,NULL,NULL,'1.0000',NULL,'0.0000','0.00'),
(10,3,3,'cost',NULL,60,'Refurbishing labor and testing','0.0000',NULL,'0.0000','400.00'),
(11,3,4,'output',26,NULL,NULL,'1.0000','co_product','85.0000','0.00'),
(12,3,5,'output',8,NULL,NULL,'2.0000','co_product','15.0000','0.00'),
(13,3,6,'output',27,NULL,NULL,'1.0000','scrap','0.0000','0.00');

-- price_level_items (1 rows)
INSERT INTO `price_level_items` (`price_level_id`,`item_id`,`custom_price`) VALUES
(2,1,'36500.0000');

-- withholding_taxes (3 rows)
INSERT INTO `withholding_taxes` (`id`,`code`,`atc`,`name`,`tax_kind`,`rate`,`base_type`,`applies_to`,`payable_account_id`,`receivable_account_id`,`description`,`sort_order`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'WT-GOODS','WC158','WT on Goods','income','1.0000','net_of_vat','both',NULL,NULL,'Goods bought from regular suppliers by government payors and top withholding agents (RR 11-2018)',10,1,'2026-11-23 19:54:44',NULL),
(2,'WT-SERVICES','WC160','WT on Services','income','2.0000','net_of_vat','both',NULL,NULL,'Services bought from regular suppliers by government payors and top withholding agents (RR 11-2018)',20,1,'2026-11-23 19:54:44',NULL),
(3,'WT-VAT','WV010','WT on VAT','vat','5.0000','net_of_vat','both',NULL,NULL,'Creditable VAT withheld by government buyers on VATable purchases (5% of the VAT-exclusive payment)',30,1,'2026-11-23 19:54:44',NULL);

-- asset_categories (6 rows)
INSERT INTO `asset_categories` (`id`,`name`,`asset_account_id`,`accum_dep_account_id`,`dep_expense_account_id`,`default_method`,`default_life_years`,`is_active`) VALUES
(1,'Buildings',22,23,71,'straight_line','25.00',1),
(2,'Machinery and Equipment',24,25,71,'straight_line','5.00',1),
(3,'Production Machinery (usage-based)',24,25,71,'units_of_production','5.00',1),
(4,'Transportation Equipment',26,27,71,'declining_balance','5.00',1),
(5,'Furniture and Fixtures',28,29,71,'straight_line','10.00',1),
(6,'Computer and POS Equipment',30,31,71,'declining_balance','3.00',1);

-- pos_terminals (2 rows)
INSERT INTO `pos_terminals` (`id`,`code`,`name`,`site_id`,`cash_account_id`,`receipt_prefix`,`next_receipt_no`,`next_z_no`,`is_active`,`class_id`) VALUES
(1,'POS-01','Makati Counter 1',2,2,'MK1-',1,1,1,NULL),
(2,'POS-02','Cebu Counter 1',3,2,'CB1-',1,1,1,NULL);

-- role_sites (2 rows)
INSERT INTO `role_sites` (`role_id`,`site_id`) VALUES
(7,2),
(7,3);

-- user_sites (2 rows)
INSERT INTO `user_sites` (`user_id`,`site_id`) VALUES
(7,2),
(8,2);

-- user_terminals (2 rows)
INSERT INTO `user_terminals` (`user_id`,`terminal_id`) VALUES
(7,1),
(8,1);

-- brgy_proc_modes (13 rows)
INSERT INTO `brgy_proc_modes` (`id`,`code`,`name`,`regime`,`legal_basis`,`source_url`,`max_abc`,`needs_bid_security`,`needs_perf_security`,`default_retention_pct`,`effective_from`,`effective_to`,`notes`,`sort_order`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'competitive_bidding','Competitive Bidding','ra12009','RA 12009 and its IRR (default mode)','https://www.gppb.gov.ph/',NULL,1,1,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',10,1,'2026-11-23 19:54:45',NULL),
(2,'limited_source','Limited Source Bidding','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,1,1,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',20,1,'2026-11-23 19:54:45',NULL),
(3,'direct_contracting','Direct Contracting','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,0,1,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',30,1,'2026-11-23 19:54:45',NULL),
(4,'direct_acquisition','Direct Acquisition','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,0,0,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',40,1,'2026-11-23 19:54:45',NULL),
(5,'repeat_order','Repeat Order','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,0,1,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',50,1,'2026-11-23 19:54:45',NULL),
(6,'small_value','Small Value Procurement','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,0,0,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',60,1,'2026-11-23 19:54:45',NULL),
(7,'negotiated_emergency','Negotiated Procurement (Emergency Cases)','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,0,0,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',70,1,'2026-11-23 19:54:45',NULL),
(8,'negotiated_failure','Negotiated Procurement (Two Failed Biddings)','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,0,1,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',80,1,'2026-11-23 19:54:45',NULL),
(9,'negotiated_other','Negotiated Procurement (Other Cases)','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,0,0,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',90,1,'2026-11-23 19:54:45',NULL),
(10,'direct_sales','Direct Sales','ra12009','RA 12009 and its IRR','https://www.gppb.gov.ph/',NULL,0,0,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',100,1,'2026-11-23 19:54:45',NULL),
(11,'public_bidding_9184','Public Bidding','ra9184','RA 9184 and its 2016 revised IRR (procurement started before the RA 12009 rules applied)','https://www.gppb.gov.ph/',NULL,1,1,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',110,1,'2026-11-23 19:54:45',NULL),
(12,'shopping_9184','Shopping','ra9184','RA 9184 and its 2016 revised IRR (transition cases)','https://www.gppb.gov.ph/',NULL,0,0,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',120,1,'2026-11-23 19:54:45',NULL),
(13,'svp_9184','Small Value Procurement','ra9184','RA 9184 and its 2016 revised IRR (transition cases)','https://www.gppb.gov.ph/',NULL,0,0,NULL,NULL,NULL,'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.',130,1,'2026-11-23 19:54:45',NULL);

-- brgy_references (10 rows)
INSERT INTO `brgy_references` (`id`,`code`,`title`,`issuer`,`ref_type`,`issued_date`,`published_date`,`effective_date`,`url`,`applies_to`,`notes`,`last_reviewed`,`reviewed_by`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'RA-12009','Republic Act No. 12009, New Government Procurement Act','Congress of the Philippines','law','2024-07-20',NULL,'2024-08-13','https://www.gppb.gov.ph/','All procurement of goods, infrastructure projects and consulting services by government entities, barangays included','Signed 20 July 2024; took effect 13 August 2024. Repealed RA 9184. Procurement started before the new rules applied follows the transitory provisions (see GPPB Resolution No. 05-2025).','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(2,'IRR-RA-12009','Implementing Rules and Regulations of RA 12009','Government Procurement Policy Board','irr',NULL,NULL,NULL,'https://www.gppb.gov.ph/','Procedures, procurement modes, documentary requirements, securities, contract implementation','Approved by the GPPB in February 2025 and published in the same month. Enter the effectivity date after checking the official copy.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(3,'GPPB-RES-05-2025','GPPB Resolution No. 05-2025 approving Circular No. 01-2025: guidance on Section 113 (Transitory Provision) in relation to Section 115 (Repealing Clause) of RA 12009 and its IRR','Government Procurement Policy Board','resolution','2025-09-18',NULL,NULL,'https://www.gppb.gov.ph/wp-content/uploads/2025/10/GPPB-Resolution-No.-05-2025.pdf','Which law and forms govern a procurement during the transition','Covers whether RA 9184 still applies to ongoing procurement, the use of RA 9184 forms, when the new standard procurement forms become mandatory, and a three-year transitory period counted from 14 May 2025.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(4,'GPPB-FORMS','GPPB Philippine Bidding Documents and standard procurement forms','Government Procurement Policy Board','other',NULL,NULL,NULL,'https://www.gppb.gov.ph/','Bid forms, omnibus sworn statement, bid / performance securing declarations, contract forms, price schedules','Use the version the procuring entity issued with its bidding documents or RFQ. The program keeps these as prescribed-form shells: paste the official text, do not reword it.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(5,'COA-2023-004','COA Circular No. 2023-004, updated documentary requirements for common government transactions','Commission on Audit','circular','2023-06-14',NULL,NULL,'https://www.coa.gov.ph/','Supporting papers the entity needs to pay a supplier or contractor','Amends COA Circular No. 2012-001. Use it to build the billing pack the entity\'s accountant and auditor will look for.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(6,'BIR-RR-7-2024','Revenue Regulations No. 7-2024 (invoicing under the Ease of Paying Taxes Act)','Bureau of Internal Revenue','regulation',NULL,'2024-04-12','2024-04-27','https://www.bir.gov.ph/','Sales invoice as the primary document for sales of goods and services','Official receipts may still be used as supplementary documents marked \"This document is not valid for claim of input tax.\" A document printed by this program is not by itself a BIR-registered invoice.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(7,'BIR-RR-11-2024','Revenue Regulations No. 11-2024 (amending RR 7-2024: registration and invoicing transition)','Bureau of Internal Revenue','regulation','2024-06-13',NULL,NULL,'https://www.bir.gov.ph/','Transition for invoices and receipts','Read with RR 7-2024 and the later BIR issuances, including e-invoicing guidance.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(8,'BIR-RR-11-2018','Revenue Regulations No. 11-2018 (withholding tax rates, including government payors)','Bureau of Internal Revenue','regulation',NULL,NULL,NULL,'https://www.bir.gov.ph/','Income tax and VAT withheld by government buyers (BIR Reports > Withholding Tax Table)','Rates are kept on the Withholding Tax Table, never in the program; check them against the current rules and the entity\'s Form 2307.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(9,'RA-9184','Republic Act No. 9184, Government Procurement Reform Act (repealed)','Congress of the Philippines','law','2003-01-10',NULL,NULL,'https://www.gppb.gov.ph/','Procurement started before the RA 12009 rules applied','Repealed by RA 12009; still governs transition cases as the transitory provisions allow.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL),
(10,'RA-10173','Republic Act No. 10173, Data Privacy Act of 2012','Congress of the Philippines','law','2012-08-15',NULL,NULL,'https://www.privacy.gov.ph/','Personal information of officials and contact persons, documents kept in the vault','Keep only what is needed, limit access (confidential documents), and log every download.','2026-11-23',NULL,1,'2026-11-23 19:54:45',NULL);

-- brgy_doc_types (71 rows)
INSERT INTO `brgy_doc_types` (`code`,`name`,`doc_group`,`is_company`,`needs_validity`,`validity_months`,`sort_order`,`is_active`) VALUES
('abc','Approved budget for the contract (ABC)','bid',0,0,NULL,610,1),
('aboitiz','Aboitiz requirements','bid',0,0,NULL,620,1),
('abstract','Abstract of canvass / bids','bid',0,0,NULL,570,1),
('accomplishment','Accomplishment / service report','delivery',0,0,NULL,380,1),
('afs','Audited financial statements','financial',1,1,12,110,1),
('authority','Secretary\'s certificate / special power of attorney','legal',1,0,NULL,70,1),
('bac_resolution','BAC resolution','contract',0,0,NULL,680,1),
('bid_security','Bid security / bid securing declaration','bid',0,1,NULL,270,1),
('bidding_form','Bidding form (details of the bidding)','bid',0,0,NULL,530,1),
('billing_letter','Billing cover letter / request for payment','billing',0,0,NULL,420,1),
('bir_2303','BIR Certificate of Registration (Form 2303)','legal',1,0,NULL,40,1),
('brochure','Brochures and technical data sheets','technical',0,0,NULL,240,1),
('bulletin','Supplemental bid bulletin','bid',0,0,NULL,160,1),
('business_tax_return','Business tax returns (VAT / percentage tax)','financial',1,0,NULL,100,1),
('canvass','Canvass / request for quotation signed by a supplier','bid',0,0,NULL,560,1),
('change_order','Approved variation / change order','contract',0,0,NULL,350,1),
('closeout','Completion / closeout papers','contract',0,0,NULL,480,1),
('coa_letter','Letter to COA','bid',0,0,NULL,600,1),
('company_profile','Company profile','technical',1,0,NULL,140,1),
('completion_cert','Certificate of completion','delivery',0,0,NULL,710,1),
('contract','Contract agreement','contract',0,0,NULL,320,1),
('correspondence','Letter / correspondence','other',0,0,NULL,290,1),
('cover_letter','Cover letter','bid',0,0,NULL,250,1),
('dr','Delivery receipt (signed)','delivery',0,0,NULL,360,1),
('dv','Disbursement voucher (DV)','billing',0,0,NULL,580,1),
('followup_letter','Collection follow-up letter','collection',0,0,NULL,470,1),
('form_2307','BIR Form 2307 from the entity','collection',0,0,NULL,460,1),
('iar','Inspection and acceptance report','delivery',0,0,NULL,370,1),
('internal','Internal sheet (approval, review, reconciliation)','other',0,0,NULL,500,1),
('invoice','Sales invoice','billing',0,0,NULL,400,1),
('itb','Invitation to bid (ITB)','bid',0,0,NULL,590,1),
('itr','Latest income tax return','financial',1,0,NULL,90,1),
('letter_intent','Letter of intent','bid',0,0,NULL,640,1),
('manpower','Manpower schedule','technical',0,0,NULL,660,1),
('mayors_permit','Mayor\'s / business permit','legal',1,1,12,20,1),
('method_statement','Method statement','technical',0,0,NULL,210,1),
('nfcc','NFCC computation / committed line of credit','financial',1,0,NULL,120,1),
('noa','Notice of award','contract',0,0,NULL,300,1),
('ntp','Notice to proceed','contract',0,0,NULL,330,1),
('omnibus','Omnibus sworn statement','bid',0,0,NULL,260,1),
('or_ar','Acknowledgment / collection receipt','collection',0,0,NULL,450,1),
('other','Other document','other',0,0,NULL,510,1),
('pcab_license','PCAB contractor license','legal',1,1,12,60,1),
('perf_security','Performance security','contract',0,1,NULL,340,1),
('philgeps_cert','PhilGEPS registration certificate (Platinum)','legal',1,1,12,10,1),
('photo','Photos of the delivery / work','delivery',0,0,NULL,390,1),
('po','Purchase order / job order','contract',0,0,NULL,310,1),
('post_qual','Post-qualification documents','contract',0,0,NULL,690,1),
('post_qual_notice','Notice of post-qualification','contract',0,0,NULL,700,1),
('pr','Purchase request (PR)','contract',0,0,NULL,540,1),
('price_schedule','Price schedule / bill of quantities','bid',0,0,NULL,180,1),
('production','Production details','technical',0,0,NULL,650,1),
('prof_license','Professional licenses of key personnel','legal',1,1,36,80,1),
('proposal','Supply / services / scope-of-work proposal','technical',0,0,NULL,230,1),
('quotation','Price quotation / bid form','bid',0,0,NULL,190,1),
('receiving_copy','Receiving copy / acknowledgment of the entity','billing',0,0,NULL,440,1),
('rfq','RFQ / invitation to bid / notice','bid',0,0,NULL,150,1),
('ris','Requisition and issue slip (RIS)','delivery',0,0,NULL,550,1),
('schedule','Implementation / delivery schedule','technical',0,0,NULL,220,1),
('sec_dti','SEC / DTI / CDA registration','legal',1,0,NULL,30,1),
('slcc','Statement of ongoing and completed contracts','technical',1,0,NULL,130,1),
('soa','Statement of account','billing',0,0,NULL,410,1),
('spa','Special power of attorney (SPA) of the representative','bid',0,0,NULL,630,1),
('specs','Technical specifications / terms of reference','technical',0,0,NULL,170,1),
('submission_proof','Proof of submission / receiving copy','bid',0,0,NULL,280,1),
('supplier_request','Request to a supplier / subcontractor','other',0,0,NULL,490,1),
('tax_clearance','Tax clearance','legal',1,1,12,50,1),
('technical_proposal','Technical proposal / statement of compliance','technical',0,0,NULL,200,1),
('transmittal','Transmittal letter','billing',0,0,NULL,430,1),
('txn_form','Transaction form','other',0,0,NULL,520,1),
('warranty_cert','Warranty certificate','technical',0,0,NULL,670,1);

-- brgy_requirements (29 rows)
INSERT INTO `brgy_requirements` (`id`,`code`,`name`,`description`,`doc_group`,`stage`,`requirement_type`,`regimes`,`categories`,`mode_codes`,`min_abc`,`max_abc`,`needs_validity`,`doc_type`,`template_code`,`is_prescribed`,`legal_basis`,`source_url`,`entity_id`,`effective_from`,`effective_to`,`status`,`approved_by`,`approved_at`,`sort_order`,`is_active`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'REQ-PHILGEPS','PhilGEPS registration certificate (Platinum membership where required)',NULL,'legal','eligibility','mandatory',NULL,NULL,NULL,NULL,NULL,1,'philgeps_cert',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,10,1,NULL,'2026-11-23 19:54:45',NULL),
(2,'REQ-MAYORS','Mayor\'s / business permit (current)',NULL,'legal','eligibility','mandatory',NULL,NULL,NULL,NULL,NULL,1,'mayors_permit',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,20,1,NULL,'2026-11-23 19:54:45',NULL),
(3,'REQ-REG','SEC / DTI / CDA registration',NULL,'legal','eligibility','mandatory',NULL,NULL,'competitive_bidding,limited_source,public_bidding_9184',NULL,NULL,0,'sec_dti',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,30,1,NULL,'2026-11-23 19:54:45',NULL),
(4,'REQ-BIR2303','BIR Certificate of Registration (Form 2303)','Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.','legal','eligibility','conditional',NULL,NULL,NULL,NULL,NULL,0,'bir_2303',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,40,1,NULL,'2026-11-23 19:54:45',NULL),
(5,'REQ-TAXCLEAR','Tax clearance',NULL,'legal','eligibility','mandatory',NULL,NULL,'competitive_bidding,limited_source,public_bidding_9184',NULL,NULL,1,'tax_clearance',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,50,1,NULL,'2026-11-23 19:54:45',NULL),
(6,'REQ-PCAB','PCAB contractor license (category and size range for the project)',NULL,'legal','eligibility','mandatory',NULL,'works',NULL,NULL,NULL,1,'pcab_license',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,60,1,NULL,'2026-11-23 19:54:45',NULL),
(7,'REQ-PROF','Professional licenses of the key personnel','Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.','legal','eligibility','conditional',NULL,'consultancy,works',NULL,NULL,NULL,1,'prof_license',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,70,1,NULL,'2026-11-23 19:54:45',NULL),
(8,'REQ-AUTH','Authority of the signatory (secretary\'s certificate / special power of attorney)','Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.','legal','eligibility','conditional',NULL,NULL,NULL,NULL,NULL,0,'authority',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,80,1,NULL,'2026-11-23 19:54:45',NULL),
(9,'REQ-ITR','Latest income tax return / business tax returns','Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.','financial','eligibility','conditional',NULL,NULL,NULL,NULL,NULL,0,'itr',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,90,1,NULL,'2026-11-23 19:54:45',NULL),
(10,'REQ-SLCC','Statement of all ongoing and completed government and private contracts',NULL,'technical','eligibility','mandatory',NULL,NULL,'competitive_bidding,limited_source,public_bidding_9184',NULL,NULL,0,'slcc',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,100,1,NULL,'2026-11-23 19:54:45',NULL),
(11,'REQ-AFS','Audited financial statements',NULL,'financial','eligibility','mandatory',NULL,NULL,'competitive_bidding,limited_source,public_bidding_9184',NULL,NULL,1,'afs',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,110,1,NULL,'2026-11-23 19:54:45',NULL),
(12,'REQ-NFCC','Net financial contracting capacity (NFCC) or committed line of credit',NULL,'financial','eligibility','mandatory',NULL,NULL,'competitive_bidding,limited_source,public_bidding_9184',NULL,NULL,0,'nfcc',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,120,1,NULL,'2026-11-23 19:54:45',NULL),
(13,'REQ-OSS','Omnibus sworn statement (prescribed form)',NULL,'bid','bid','mandatory',NULL,NULL,NULL,NULL,NULL,0,'omnibus','omnibus_sworn_statement',1,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,130,1,NULL,'2026-11-23 19:54:45',NULL),
(14,'REQ-BIDSEC','Bid security or bid securing declaration',NULL,'bid','bid','mandatory',NULL,NULL,'competitive_bidding,limited_source,public_bidding_9184',NULL,NULL,1,'bid_security','bid_securing_declaration',1,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,140,1,NULL,'2026-11-23 19:54:45',NULL),
(15,'REQ-QUOTE','Signed price quotation / bid form (in the form the entity issued)',NULL,'bid','bid','mandatory',NULL,NULL,NULL,NULL,NULL,0,'quotation','bid_form',1,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,150,1,NULL,'2026-11-23 19:54:45',NULL),
(16,'REQ-PRICESCHED','Price schedule / bill of quantities',NULL,'bid','bid','mandatory',NULL,NULL,NULL,NULL,NULL,0,'price_schedule','price_schedule',0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,160,1,NULL,'2026-11-23 19:54:45',NULL),
(17,'REQ-SPECS','Technical proposal / statement of compliance with the specifications',NULL,'technical','bid','mandatory',NULL,NULL,NULL,NULL,NULL,0,'technical_proposal','technical_proposal',0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,170,1,NULL,'2026-11-23 19:54:45',NULL),
(18,'REQ-BROCHURE','Brochures and technical data sheets of the products offered',NULL,'technical','bid','optional',NULL,'goods',NULL,NULL,NULL,0,'brochure',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,180,1,NULL,'2026-11-23 19:54:45',NULL),
(19,'REQ-SCHED','Delivery / implementation schedule','Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.','technical','bid','conditional',NULL,'services,works',NULL,NULL,NULL,0,'schedule','implementation_schedule',0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,190,1,NULL,'2026-11-23 19:54:45',NULL),
(20,'REQ-METHOD','Method statement','Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.','technical','bid','conditional',NULL,'works',NULL,NULL,NULL,0,'method_statement','method_statement',0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,200,1,NULL,'2026-11-23 19:54:45',NULL),
(21,'REQ-PERFSEC','Performance security','Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.','contract','contract','conditional',NULL,NULL,NULL,NULL,NULL,1,'perf_security',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,210,1,NULL,'2026-11-23 19:54:45',NULL),
(22,'REQ-CONTRACT','Signed contract / purchase order',NULL,'contract','contract','mandatory',NULL,NULL,NULL,NULL,NULL,0,'contract',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,220,1,NULL,'2026-11-23 19:54:45',NULL),
(23,'REQ-NTP','Notice to proceed','Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.','contract','contract','conditional',NULL,'services,works',NULL,NULL,NULL,0,'ntp',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,230,1,NULL,'2026-11-23 19:54:45',NULL),
(24,'REQ-DR','Delivery receipt signed by the entity',NULL,'delivery','delivery','mandatory',NULL,'goods',NULL,NULL,NULL,0,'dr','delivery_receipt',0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,240,1,NULL,'2026-11-23 19:54:45',NULL),
(25,'REQ-IAR','Inspection and acceptance report',NULL,'delivery','delivery','mandatory',NULL,NULL,NULL,NULL,NULL,0,'iar',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,250,1,NULL,'2026-11-23 19:54:45',NULL),
(26,'REQ-ACCOMP','Accomplishment / service completion report',NULL,'delivery','delivery','mandatory',NULL,'services,works',NULL,NULL,NULL,0,'accomplishment','accomplishment_report',0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,260,1,NULL,'2026-11-23 19:54:45',NULL),
(27,'REQ-SI','Sales invoice (BIR-registered)',NULL,'billing','billing','mandatory',NULL,NULL,NULL,NULL,NULL,0,'invoice',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,270,1,NULL,'2026-11-23 19:54:45',NULL),
(28,'REQ-SOA','Statement of account',NULL,'billing','billing','mandatory',NULL,NULL,NULL,NULL,NULL,0,'soa','statement_of_account',0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,280,1,NULL,'2026-11-23 19:54:45',NULL),
(29,'REQ-2307','BIR Form 2307 issued by the entity for the taxes it withheld',NULL,'billing','billing','optional',NULL,NULL,NULL,NULL,NULL,0,'form_2307',NULL,0,'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity','https://www.gppb.gov.ph/',NULL,NULL,NULL,'approved',NULL,NULL,290,1,NULL,'2026-11-23 19:54:45',NULL);

-- brgy_templates (58 rows)
INSERT INTO `brgy_templates` (`id`,`code`,`name`,`category`,`stage`,`doc_type`,`description`,`body`,`paper`,`orientation`,`is_prescribed`,`official_source`,`official_version`,`required_fields`,`needs_approval`,`print_mode`,`layout_json`,`positions_json`,`bg_file`,`version_no`,`sort_order`,`is_active`,`lock_version`,`created_by`,`created_at`,`updated_by`,`updated_at`) VALUES
(1,'cover_letter','Cover letter: submission of bid / quotation','letter','bid','cover_letter','Covering letter of the bid or quotation, listing what is enclosed.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Submission of bid / quotation for {{project.title}} ({{project.reference_no}})</b></p><p>Dear Sir / Madam:</p><p>In response to your invitation, {{company.name}} respectfully submits its bid / quotation for <b>{{project.title}}</b> in the total amount of <b>{{project.bid_amount}}</b> ({{project.bid_amount_words}}), inclusive of all applicable taxes, under the terms and specifications of your request.</p><p>Enclosed are the documents your request asks for, as listed in our checklist:</p>{{requirements.table}}<p>Our offer is valid for the period stated in your bidding documents. For clarifications, please contact the undersigned at {{company.phone}} or {{company.email}}.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'signatory.name,entity.name',1,'layout',NULL,NULL,NULL,1,10,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(2,'company_profile','Company profile','proforma','bid','company_profile','Short profile of the company for bid and eligibility envelopes.','<div>{{letterhead}}</div><h2 style=\"text-align:center;margin:10px 0 14px\">COMPANY PROFILE</h2><table class=\"ft\"><tbody><tr><td style=\"width:34%\">Registered name</td><td><b>{{company.name}}</b></td></tr><tr><td>Business address</td><td>{{company.address}}</td></tr><tr><td>Registration</td><td>{{company.reg_no}}</td></tr><tr><td>TIN / tax status</td><td>{{company.tin}}   ({{company.vat_status}})</td></tr><tr><td>PhilGEPS registration</td><td>{{company.philgeps_no}}   {{company.philgeps_class}}</td></tr><tr><td>Contact</td><td>{{company.phone}}   {{company.email}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr></tbody></table><h3>Lines of business</h3><p>Supply and delivery of office, school, medical and household supplies, IT equipment and furniture; general services (janitorial, maintenance, repairs); and, through its licensed construction arm, minor infrastructure works for barangays and local government units.</p><h3>Facilities and people</h3><p>[Describe the warehouse, delivery vehicles, technical staff and the after-sales service offered.]</p><h3>Major government clients</h3><p>[List recent contracts: entity, project, amount, date completed.]</p>','A4','portrait',0,NULL,NULL,'company.name',1,'layout',NULL,NULL,NULL,1,20,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(3,'quotation','Price quotation','proforma','bid','quotation','Company price quotation with the price schedule (when the entity has no form of its own).','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">PRICE QUOTATION</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>We offer to supply the following under the terms of your request for quotation:</p>{{lines.table}}<p><b>Total amount: {{lines.total}}</b> ({{lines.total_words}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Delivery period</td><td>{{project.delivery_days}} from receipt of the purchase order / notice to proceed</td></tr><tr><td>Delivery site</td><td>{{project.delivery_site}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr><tr><td>Payment terms</td><td>{{project.payment_terms}}</td></tr><tr><td>Validity of the offer</td><td>As stated in the RFQ</td></tr></tbody></table><p>Prices include all applicable taxes, delivery and handling. We confirm that the items offered comply with the specifications stated in your request.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'signatory.name,project.title',1,'layout','{\"signatories\":[{\"lead\":\"Submitted by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,30,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(4,'price_schedule','Price schedule / bill of quantities','proforma','bid','price_schedule','Price schedule from the project lines (use the entity\'s own schedule form when its documents prescribe one).','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">PRICE SCHEDULE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p></p>{{lines.table}}<p><b>Total bid price: {{lines.total}}</b><br>({{lines.total_words}})</p><p>Name of bidder: <b>{{company.name}}</b><br>Signature of the authorized representative:</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'project.title',1,'layout','{\"signatories\":[{\"lead\":\"\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,40,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(5,'technical_proposal','Technical proposal and statement of compliance','proforma','bid','technical_proposal','Item-by-item statement of compliance with the technical specifications.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">TECHNICAL PROPOSAL AND STATEMENT OF COMPLIANCE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>We state below, item by item, our compliance with the technical specifications. Where \"Comply\" is stated, the offer meets or exceeds the requirement; supporting brochures and data sheets are attached.</p><table class=\"ft\"><thead><tr><th style=\"width:6%\">Item</th><th>Specification required</th><th style=\"width:30%\">Offer (brand, model, specification)</th><th style=\"width:12%\">Statement</th></tr></thead><tbody><tr><td>1</td><td>{{project.specs}}</td><td>[Brand / model offered]</td><td>Comply</td></tr></tbody></table><p><b>Delivery:</b> {{project.delivery_days}} at {{project.delivery_site}}. <b>Warranty:</b> {{project.warranty_months}}.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'project.title',1,'layout','{\"signatories\":[{\"lead\":\"Certified correct:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,50,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(6,'method_statement','Method statement (works / services)','proforma','bid','method_statement','How the works or services will be carried out: sequence, resources, safety and quality control.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">METHOD STATEMENT</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><h3>1. Scope</h3><p>{{project.specs}}</p><h3>2. Mobilization</h3><p>[Site set-up, permits, coordination with the barangay and the end-user ({{project.end_user}}).]</p><h3>3. Sequence of work</h3><ol><li>[Activity]</li><li>[Activity]</li><li>[Activity]</li></ol><h3>4. Manpower and equipment</h3><p>[List of key personnel and equipment.]</p><h3>5. Quality control and testing</h3><p>[Tests, inspections and hold points; acceptance by the entity\'s inspection committee.]</p><h3>6. Safety and environment</h3><p>[Safety plan, signage, housekeeping, disposal.]</p><h3>7. Schedule</h3><p>Completion within {{project.delivery_days}} from the notice to proceed.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'project.title',1,'layout','{\"signatories\":[{\"lead\":\"Prepared by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,60,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(7,'implementation_schedule','Implementation / delivery schedule','proforma','bid','schedule','Schedule of deliveries or activities over the contract period.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">IMPLEMENTATION / DELIVERY SCHEDULE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><table class=\"ft\"><thead><tr><th style=\"width:6%\">No.</th><th>Activity / delivery</th><th style=\"width:18%\">Start</th><th style=\"width:18%\">Finish</th><th style=\"width:16%\">Weight %</th></tr></thead><tbody><tr><td>1</td><td>Mobilization / receipt of PO or NTP</td><td>Day 1</td><td>Day 3</td><td>5</td></tr><tr><td>2</td><td>[Delivery / work package]</td><td></td><td></td><td></td></tr><tr><td>3</td><td>Inspection and acceptance</td><td></td><td></td><td></td></tr></tbody></table><p>Total contract period: {{project.delivery_days}}.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'project.title',1,'layout','{\"signatories\":[{\"lead\":\"Prepared by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,70,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(8,'supply_proposal','Supply and services proposal','proforma','bid','proposal','Narrative proposal for supply and related services (training, installation, after-sales).','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">SUPPLY AND SERVICES PROPOSAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>{{company.name}} proposes to supply and deliver the items below, together with the related services described, for <b>{{lines.total}}</b>.</p>{{lines.table}}<h3>Related services</h3><ul><li>Delivery, unloading and installation at {{project.delivery_site}}</li><li>Orientation of the end-users</li><li>Warranty service: {{project.warranty_months}}</li></ul><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'project.title',1,'layout',NULL,NULL,NULL,1,80,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(9,'scope_of_work','Scope-of-work proposal','proforma','bid','proposal','Proposal for services or works: scope, deliverables, exclusions and price.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">SCOPE-OF-WORK PROPOSAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><h3>Scope of work</h3><p>{{project.specs}}</p><h3>Deliverables</h3><ul><li>[Deliverable]</li><li>[Deliverable]</li></ul><h3>Exclusions</h3><p>[Items not included.]</p><h3>Price</h3>{{lines.table}}<p>Duration: {{project.delivery_days}}. Payment: {{project.payment_terms}}.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'project.title',1,'layout',NULL,NULL,NULL,1,90,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(10,'supplier_request','Request for quotation to a supplier / subcontractor','internal','internal','supplier_request','Asks suppliers and subcontractors for prices and their certifications (kept apart from the sale to the barangay).','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">REQUEST FOR QUOTATION (SUPPLIER / SUBCONTRACTOR)</h2><p>To: [Supplier / subcontractor]<br>Address: [Address]</p><p>We are preparing an offer for <b>{{project.title}}</b> for {{entity.name}}. Please quote your best price and delivery for the following, valid for at least 30 days:</p>{{lines.table}}<p>Please include with your quotation: your business permit, BIR registration, product certifications or licenses, warranty terms and delivery lead time. Prices to us are separate from our prices to the procuring entity; this request does not disclose the entity\'s budget.</p><p>Reply to {{project.preparer}} at {{company.email}} by [date].</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'project.title',1,'layout','{\"signatories\":[{\"lead\":\"Requested by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,100,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(11,'bid_approval_sheet','Internal bid approval sheet','internal','internal','internal','Go / no-go, costing and margin, compliance, and the sign-off of the preparer, checker and approver before submission.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">INTERNAL BID APPROVAL SHEET</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project no.</td><td>{{project.no}}</td></tr><tr><td>Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode / law</td><td>{{project.mode}}, {{project.regime}}</td></tr><tr><td>ABC</td><td>{{project.abc}}</td></tr><tr><td>Bid amount</td><td><b>{{project.bid_amount}}</b></td></tr><tr><td>Bid deadline</td><td>{{project.bid_deadline}}</td></tr></tbody></table><h3>Costing and margin (confidential)</h3>{{costing.table}}<h3>Compliance checklist</h3>{{requirements.table}}<h3>Remarks</h3><p>[Risks, assumptions, supplier quotations relied on, payment record of the entity.]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.preparer}}</div><div>Prepared by</div></td><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.checker}}</div><div>Checked by</div></td><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>','A4','portrait',0,NULL,NULL,'project.no',1,'layout',NULL,NULL,NULL,1,110,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(12,'contract_review_sheet','Contract review sheet','internal','award','internal','Review of the notice of award, purchase order or contract against the bid before signing.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">CONTRACT REVIEW SHEET</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b> ({{project.no}})</td></tr><tr><td>Notice of award</td><td>{{project.noa_ref}}, {{project.noa_date}}</td></tr><tr><td>Contract / PO no.</td><td>{{project.contract_ref}}</td></tr><tr><td>Amount awarded</td><td>{{project.contract_amount}}</td></tr><tr><td>Delivery / completion</td><td>{{project.delivery_days}}; due {{project.due_date}}</td></tr><tr><td>Retention</td><td>{{project.retention_pct}}</td></tr><tr><td>Liquidated damages</td><td>{{project.ld_rate}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr><tr><td>Payment terms</td><td>{{project.payment_terms}}</td></tr></tbody></table><h3>Checks</h3><table class=\"ft\"><thead><tr><th>Point</th><th style=\"width:16%\">OK</th><th style=\"width:36%\">Remarks</th></tr></thead><tbody><tr><td>Items, quantities and prices agree with the approved bid</td><td></td><td></td></tr><tr><td>Delivery period and site can be met</td><td></td><td></td></tr><tr><td>Performance security / warranty requirements understood</td><td></td><td></td></tr><tr><td>Billing documents and payment terms understood</td><td></td><td></td></tr><tr><td>Taxes to be withheld by the entity noted</td><td></td><td></td></tr></tbody></table><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.checker}}</div><div>Reviewed by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>','A4','portrait',0,NULL,NULL,'project.no',1,'layout',NULL,NULL,NULL,1,120,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(13,'handover_memo','Project handover memo (sales to operations)','internal','award','internal','Hands the awarded contract to the project manager and warehouse with what was promised.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">PROJECT HANDOVER MEMO</h2><p><b>To:</b> {{project.pm}}, Project Manager; Warehouse and Delivery<br><b>From:</b> {{project.preparer}}<br><b>Re:</b> {{project.title}} ({{project.no}})</p><p>The contract below was awarded to the company by {{entity.name}} (notice of award {{project.noa_ref}}, {{project.noa_date}}). Please mobilize as follows:</p>{{lines.contract_table}}<table class=\"ft\"><tbody><tr><td style=\"width:32%\">Delivery site</td><td>{{project.delivery_site}}</td></tr><tr><td>Due</td><td>{{project.due_date}}</td></tr><tr><td>End-user</td><td>{{project.end_user}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr></tbody></table><p>Deliveries are recorded on Deliveries &amp; Acceptance; the signed delivery receipt and the inspection and acceptance report are filed in the Document Vault, as billing depends on them.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.preparer}}</div><div>Handed over by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Received by</div></td></tr></table>','A4','portrait',0,NULL,NULL,'project.no',1,'layout',NULL,NULL,NULL,1,130,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(14,'delivery_receipt','Delivery receipt','proforma','delivery','dr','Delivery receipt of the goods delivered, for the entity\'s signature.','<div>{{letterhead}}</div><h2 style=\"text-align:center;margin:10px 0 14px\">DELIVERY RECEIPT</h2><table class=\"ft\"><tbody><tr><td style=\"width:24%\">DR no.</td><td><b>{{delivery.no}}</b></td><td style=\"width:20%\">Date</td><td>{{delivery.date}}</td></tr><tr><td>Delivered to</td><td colspan=\"3\">{{entity.name}}, {{delivery.to}}</td></tr><tr><td>Project</td><td colspan=\"3\">{{project.title}} (contract / PO {{project.contract_ref}})</td></tr></tbody></table><p></p>{{delivery.table}}<p>Received the above items in good order and condition, subject to inspection and acceptance by the entity.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Delivered by ({{company.name}})</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{delivery.received_by}} {{delivery.received_position}}</div><div>Received by (name, position, date)</div></td></tr></table>','A4','portrait',0,NULL,NULL,'delivery.no,entity.name',1,'layout',NULL,NULL,NULL,1,140,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(15,'accomplishment_report','Service / accomplishment report','proforma','delivery','accomplishment','Report of services rendered or work accomplished for the period, for the end-user\'s certification.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">SERVICE / ACCOMPLISHMENT REPORT</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>Period covered: [from] to [to]</p>{{delivery.table}}<h3>Work done</h3><p>[Describe the services rendered / work accomplished, with dates.]</p><h3>Issues and actions</h3><p>[None]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Submitted by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.end_user}}</div><div>Certified by the end-user</div></td></tr></table>','A4','portrait',0,NULL,NULL,'project.title',1,'layout',NULL,NULL,NULL,1,150,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(16,'transmittal','Transmittal letter','letter','billing','transmittal','Transmits documents to the entity and asks for the receiving copy.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">TRANSMITTAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Re: {{project.title}}</b></p><p>We transmit the following documents for your appropriate action:</p><table class=\"ft\"><thead><tr><th style=\"width:6%\">No.</th><th>Document</th><th style=\"width:16%\">Copies</th></tr></thead><tbody><tr><td>1</td><td>[Document]</td><td>1</td></tr><tr><td>2</td><td>[Document]</td><td>1</td></tr></tbody></table><p>Kindly acknowledge receipt by signing below.</p><div>{{signatories}}</div><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Received by (name, position)</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Date and time received</div></td></tr></table>','A4','portrait',0,NULL,NULL,'entity.name',1,'layout',NULL,NULL,NULL,1,160,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(17,'billing_cover_letter','Billing cover letter / request for payment','letter','billing','billing_letter','Requests payment of a billing, listing the pack enclosed.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Request for payment, {{billing.type}} {{billing.no}} for {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>We respectfully request payment of <b>{{billing.gross}}</b> ({{billing.gross_words}}) for the deliveries / services accepted under {{project.contract_ref}}, as detailed below:</p>{{billing.table}}<p>Enclosed are our sales invoice no. {{billing.si_no}}, the statement of account, and the signed delivery and inspection and acceptance documents.</p><p>Payment may be made by check payable to <b>{{company.name}}</b> or by credit to: {{company.bank_details}}. Kindly furnish us BIR Form 2307 for the taxes withheld.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'billing.no,signatory.name',1,'layout',NULL,NULL,NULL,1,170,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(18,'statement_of_account','Statement of account','proforma','billing','soa','Billings, payments (with the taxes withheld and deductions) and the balance of the contract.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">STATEMENT OF ACCOUNT</h2><table class=\"ft\"><tbody><tr><td style=\"width:24%\">Bill to</td><td><b>{{entity.name}}</b><br>{{entity.address}}</td></tr><tr><td>Project</td><td>{{project.title}} ({{project.no}})</td></tr><tr><td>Contract / PO</td><td>{{project.contract_ref}}, {{project.contract_amount}}</td></tr></tbody></table><p></p>{{soa.table}}<p>Please make payment to {{company.name}}: {{company.bank_details}}.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>Noted by</div></td></tr></table>','A4','portrait',0,NULL,NULL,'entity.name',1,'layout',NULL,NULL,NULL,1,180,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(19,'collection_followup','Collection follow-up letter','letter','collection','followup_letter','Courteous follow-up of a billing that is due.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><p><b>{{entity.treasurer}}</b><br>Treasurer<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Subject: Follow-up of payment, {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>May we respectfully follow up the payment of our billing for the above project, which has been accepted and is now due. Our records show the following:</p>{{soa.table}}<p>Should any document be lacking for the processing of the payment, please let us know so we can provide it at once.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'entity.name',1,'layout',NULL,NULL,NULL,1,190,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(20,'acknowledgment_receipt','Acknowledgment receipt (supplementary)','receipt','collection','or_ar','Acknowledges a payment received. A supplementary document only: the sales invoice remains the primary document.','<div>{{letterhead}}</div><h2 style=\"text-align:center;margin:10px 0 14px\">ACKNOWLEDGMENT RECEIPT</h2><table class=\"ft\"><tbody><tr><td style=\"width:26%\">No.</td><td>{{collection.no}}</td><td style=\"width:16%\">Date</td><td>{{collection.date}}</td></tr></tbody></table><p>Received from <b>{{entity.name}}</b> the amount of <b>{{collection.amount_words}} ({{collection.amount}})</b> by {{collection.reference}}, in payment of billing {{billing.no}} (sales invoice no. {{billing.si_no}}) for {{project.title}}.</p>{{collection.table}}<p style=\"margin-top:14px;font-weight:bold;text-align:center;border:1px solid #000;padding:6px\">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:100%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Received by (cashier / collector)</div></td></tr></table>','A4','portrait',0,NULL,NULL,'collection.no',1,'layout',NULL,NULL,NULL,1,200,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(21,'reconciliation_sheet','Contract reconciliation sheet','internal','collection','internal','Reconciles the contract, deliveries, billings, collections, taxes, retention and the ledger.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">CONTRACT RECONCILIATION SHEET</h2><p><b>{{project.title}}</b> ({{project.no}}), {{entity.name}}</p>{{closeout.table}}<h3>Statement of account</h3>{{soa.table}}<h3>Differences and actions</h3><p>[None]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Checked by (accounting)</div></td></tr></table>','A4','portrait',0,NULL,NULL,'project.no',1,'layout',NULL,NULL,NULL,1,210,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(22,'closeout_summary','Project completion and closeout summary','internal','closeout','closeout','Final figures, papers on file, warranty and lessons learned at closeout.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">PROJECT COMPLETION AND CLOSEOUT SUMMARY</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b> ({{project.no}})</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Contract</td><td>{{project.contract_ref}}, NOA {{project.noa_ref}}</td></tr><tr><td>Warranty until</td><td>{{project.warranty_until}}</td></tr></tbody></table><h3>Final figures</h3>{{closeout.table}}<h3>Papers on file</h3>{{requirements.all_table}}<h3>Lessons learned</h3><p>[What went well, what to change next time.]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>','A4','portrait',0,NULL,NULL,'project.no',1,'layout',NULL,NULL,NULL,1,220,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(23,'contract_goods','Contract agreement: supply and delivery of goods (proforma)','contract','contract','contract','Reusable contract for goods: parties, contract documents, scope, price, delivery, inspection, payment, retention, warranty, liquidated damages, changes, termination.','<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">Supply and Delivery of Goods</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall supply and deliver the goods as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The SUPPLIER shall deliver the goods to {{project.delivery_site}} within {{project.delivery_days}} from receipt of the purchase order / notice to proceed, on or before {{project.due_date}}.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. Performance security.</b> Where required by the contract documents, the SUPPLIER shall post the performance security in the form and amount stated there, valid until final acceptance.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>','Folio','portrait',0,NULL,NULL,'entity.head_name,signatory.name,project.contract_amount',1,'layout',NULL,NULL,NULL,1,230,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(24,'contract_services','Contract agreement: general services (proforma)','contract','contract','contract','Reusable contract for janitorial, maintenance, repair, catering and other general services.','<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">General Services</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall render the services as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The services shall be rendered at {{project.delivery_site}} from {{project.start_date}} for {{project.delivery_days}}, under the schedule in the terms of reference. Each period\'s services are certified by the end-user ({{project.end_user}}) through the accomplishment report.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. Personnel.</b> The SUPPLIER employs and pays its personnel, complies with labor, social security and safety laws, and remains solely responsible for them; no employer-employee relationship arises between the ENTITY and the SUPPLIER\'s personnel.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>','Folio','portrait',0,NULL,NULL,'entity.head_name,signatory.name,project.contract_amount',1,'layout',NULL,NULL,NULL,1,240,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(25,'contract_works','Contract agreement: infrastructure works (proforma)','contract','contract','contract','Reusable contract for minor infrastructure works. The contractor must hold the PCAB license the project calls for.','<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">Infrastructure Works</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall carry out and complete the works as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The works at {{project.delivery_site}} shall start upon the notice to proceed dated {{project.ntp_date}} and be completed within {{project.delivery_days}}, on or before {{project.due_date}}. Progress billings follow the accomplishment certified by the ENTITY\'s engineer.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. License, securities and insurance.</b> The CONTRACTOR (the SUPPLIER) holds a valid PCAB license for the category and size of this project, posts the performance security required by the contract documents, and keeps the works and its workers insured as the contract documents require. It is liable for defects of the works as provided by law.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>','Folio','portrait',0,NULL,NULL,'entity.head_name,signatory.name,project.contract_amount',1,'layout',NULL,NULL,NULL,1,250,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(26,'subcontract','Subcontract agreement (company and subcontractor)','contract','internal','contract','Back-to-back subcontract for part of a barangay contract; the company stays responsible to the entity.','<h2 style=\"text-align:center\">SUBCONTRACT AGREEMENT</h2><p>This Agreement is made on {{date.today}} between <b>{{company.name}}</b>, represented by {{signatory.name}}, {{signatory.position}} (the \"CONTRACTOR\"), and <b>[Subcontractor]</b>, [address, TIN], represented by [name, position] (the \"SUBCONTRACTOR\").</p><p><b>1. Main contract.</b> The CONTRACTOR holds the contract for <b>{{project.title}}</b> with {{entity.name}} (NOA {{project.noa_ref}}). Subcontracting is allowed only as the main contract and the procurement rules allow, and the CONTRACTOR remains responsible to the entity for the whole contract.</p><p><b>2. Scope.</b> [Part of the work subcontracted, with quantities.]</p><p><b>3. Price and payment.</b> [Amount], paid within [days] after the entity accepts and pays the corresponding portion, less the same retention and deductions the entity applies to that portion.</p><p><b>4. Schedule and quality.</b> The SUBCONTRACTOR follows the specifications, schedule and inspection of the main contract and corrects rejected work at its cost.</p><p><b>5. Permits, licenses and personnel.</b> The SUBCONTRACTOR holds the licenses its work requires and is solely responsible for its personnel and their compliance with labor and safety laws.</p><p><b>6. Liquidated damages.</b> Delay caused by the SUBCONTRACTOR is charged to it at the rate the entity applies ({{project.ld_rate}}).</p><p><b>7. Confidentiality.</b> Prices and terms of the main contract are confidential.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the CONTRACTOR</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>For the SUBCONTRACTOR</div></td></tr></table>','A4','portrait',0,NULL,NULL,'signatory.name',1,'layout',NULL,NULL,NULL,1,260,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(27,'supply_agreement','Supply agreement with a supplier (back-to-back)','contract','internal','contract','Commits a supplier to the items, prices and delivery dates the company promised the entity.','<h2 style=\"text-align:center\">SUPPLY AGREEMENT</h2><p>Between <b>{{company.name}}</b> (the \"BUYER\"), represented by {{signatory.name}}, and <b>[Supplier]</b> (the \"SELLER\"), dated {{date.today}}.</p><p><b>1. Goods.</b> The SELLER supplies the following for the BUYER\'s contract {{project.no}} ({{project.title}}):</p><p>[Items, quantities and prices to the BUYER]</p><p><b>2. Delivery.</b> To [warehouse / site] on or before [date], allowing the BUYER to deliver to {{entity.name}} by {{project.due_date}}.</p><p><b>3. Quality and warranty.</b> The goods meet the specifications of the main contract; the SELLER replaces goods the entity rejects and honors a warranty of at least {{project.warranty_months}}.</p><p><b>4. Payment.</b> [Terms]. The BUYER withholds the taxes required by law.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the BUYER</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>For the SELLER</div></td></tr></table>','A4','portrait',0,NULL,NULL,'signatory.name',1,'layout',NULL,NULL,NULL,1,270,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(28,'po_conforme','Purchase order acceptance / conforme letter','letter','award','correspondence','Acknowledges the notice of award or purchase order and confirms the delivery commitment.','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Acceptance of Purchase Order / Notice of Award, {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>We acknowledge receipt of your Notice of Award {{project.noa_ref}} dated {{project.noa_date}} and Purchase Order {{project.po_no}} for <b>{{project.contract_amount}}</b>, and confirm our acceptance (conforme) of their terms.</p><p>We will deliver to {{project.delivery_site}} within {{project.delivery_days}}, on or before {{project.due_date}}. Our contact person for this contract is {{project.pm}}.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'project.po_no',1,'layout','{\"signatories\":[{\"lead\":\"Conforme:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,280,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(29,'omnibus_sworn_statement','Omnibus sworn statement (prescribed form)','prescribed','bid','omnibus','Shell for the prescribed omnibus sworn statement: the official text is pasted in, the program fills its blanks and keeps its review.','<h2 style=\"text-align:center\">OMNIBUS SWORN STATEMENT</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr></tbody></table>','A4','portrait',1,'https://www.gppb.gov.ph/ (and the bidding documents of the procuring entity)',NULL,'company.name,signatory.name',1,'layout',NULL,NULL,NULL,1,290,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(30,'bid_securing_declaration','Bid securing declaration (prescribed form)','prescribed','bid','bid_security','Shell for the prescribed bid securing declaration.','<h2 style=\"text-align:center\">BID SECURING DECLARATION</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr><tr><td>ABC</td><td>{{project.abc}}</td></tr></tbody></table>','A4','portrait',1,'https://www.gppb.gov.ph/ (and the bidding documents of the procuring entity)',NULL,'company.name,signatory.name',1,'layout',NULL,NULL,NULL,1,300,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(31,'bid_form','Bid form / price quotation form (prescribed by the entity)','prescribed','bid','quotation','Shell for the bid or quotation form the entity issued; the price schedule of the project is below to copy the figures.','<h2 style=\"text-align:center\">BID FORM / PRICE QUOTATION FORM</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr><tr><td>Total bid</td><td>{{project.bid_amount}} ({{project.bid_amount_words}})</td></tr></tbody></table>{{lines.table}}','A4','portrait',1,'https://www.gppb.gov.ph/ (and the bidding documents of the procuring entity)',NULL,'company.name,signatory.name',1,'layout',NULL,NULL,NULL,1,310,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(32,'txn_form','Transaction form','internal','internal','txn_form','Series-numbered transaction form of the SOP: tradename, type, barangay, cheque, amounts, requestor, inspector and purpose.','<table class=\"fx fx-frame\" style=\"width:100%\"><tbody><tr><td style=\"padding:6px 10px\"><b>Series No:</b> <span class=\"fx-series\">{{txn.series}}</span></td></tr><tr><td class=\"fx-title\" style=\"text-align:center\">TRANSACTION FORM</td></tr><tr><td style=\"padding:8px 10px 10px\"><table class=\"fx-lines\" style=\"width:100%\"><tbody><tr><td class=\"lbl\">DATE:</td><td class=\"val\">{{txn.date}}</td></tr><tr><td class=\"lbl\">TRADENAME:</td><td class=\"val\">{{txn.tradename}}</td></tr><tr><td class=\"lbl\" style=\"vertical-align:middle\">TYPE:</td><td>{{txn.types}}</td></tr><tr><td class=\"lbl\">BARANGAY:</td><td class=\"val\">{{txn.barangay}}</td></tr><tr><td class=\"lbl\">CHEQUE NUMBER:</td><td class=\"val\">{{txn.cheque_no}}</td></tr><tr><td class=\"lbl\">GROSS AMOUNT:</td><td class=\"val\">{{txn.gross}}</td></tr><tr><td class=\"lbl\">WITHHOLDING TAX:</td><td class=\"val\">{{txn.wht}}</td></tr><tr><td class=\"lbl\">CHEQUE AMOUNT:</td><td class=\"val\">{{txn.cheque_amount}}</td></tr><tr data-section=\"profit\" title=\"Profit\"><td class=\"lbl\">PROFIT:</td><td class=\"val\">{{txn.profit}}</td></tr><tr><td class=\"lbl\">REQUESTOR:</td><td class=\"val\">{{txn.requestor}}</td></tr><tr><td class=\"lbl\">INSPECTOR:</td><td class=\"val\">{{txn.inspector}}</td></tr><tr><td class=\"lbl\">PURPOSE:</td><td class=\"val\">{{txn.purpose}}</td></tr></tbody></table></td></tr><tr><td style=\"padding:6px 10px\"><table class=\"fx-lines\" style=\"width:100%\"><tbody><tr><td class=\"lbl\" style=\"width:48%;text-align:left\">PREPARED / RECEIVED BY:</td><td class=\"val\">{{txn.prepared_by}}</td></tr></tbody></table></td></tr></tbody></table>','HalfLetter','portrait',0,NULL,NULL,'txn.series,txn.date,txn.barangay',0,'layout','{\"margin_top\":7,\"margin_right\":7,\"margin_bottom\":7,\"margin_left\":7,\"line_height\":1.25,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":12.5,\"lh_style\":\"none\",\"signatories\":[]}','{\"paper\":\"HalfLetter\",\"orientation\":\"portrait\",\"width_mm\":\"\",\"height_mm\":\"\",\"offset_x\":0,\"offset_y\":0,\"font\":\"Arial\",\"font_size\":9.5,\"fields\":[{\"key\":\"txn.series\",\"text\":\"\",\"x\":42,\"y\":5,\"w\":50,\"size\":12,\"bold\":1,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.date\",\"text\":\"\",\"x\":43,\"y\":23,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.tradename\",\"text\":\"\",\"x\":43,\"y\":29.5,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.mark_cash_out\",\"text\":\"\",\"x\":45.5,\"y\":35,\"w\":5,\"size\":10,\"bold\":1,\"align\":\"center\",\"upper\":0},{\"key\":\"txn.mark_kaliwaan\",\"text\":\"\",\"x\":45.5,\"y\":42,\"w\":5,\"size\":10,\"bold\":1,\"align\":\"center\",\"upper\":0},{\"key\":\"txn.mark_purchases\",\"text\":\"\",\"x\":45.5,\"y\":49,\"w\":5,\"size\":10,\"bold\":1,\"align\":\"center\",\"upper\":0},{\"key\":\"txn.mark_construction\",\"text\":\"\",\"x\":45.5,\"y\":56,\"w\":5,\"size\":10,\"bold\":1,\"align\":\"center\",\"upper\":0},{\"key\":\"txn.barangay\",\"text\":\"\",\"x\":44,\"y\":64,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.cheque_no\",\"text\":\"\",\"x\":44,\"y\":69.5,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.gross\",\"text\":\"\",\"x\":44,\"y\":75,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.wht\",\"text\":\"\",\"x\":44,\"y\":80.5,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.cheque_amount\",\"text\":\"\",\"x\":44,\"y\":86,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.profit\",\"text\":\"\",\"x\":44,\"y\":100,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.requestor\",\"text\":\"\",\"x\":44,\"y\":122,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.inspector\",\"text\":\"\",\"x\":44,\"y\":127.5,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.purpose\",\"text\":\"\",\"x\":44,\"y\":133,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.prepared_by\",\"text\":\"\",\"x\":46,\"y\":148,\"w\":48,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0}],\"tables\":[]}',NULL,1,320,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(33,'purchase_request','Purchase request (PR)','proforma','bid','pr','Purchase request of the barangay, with the estimated unit costs, prepared by the company for the requisitioner and the Punong Barangay.','<table class=\"fx\" style=\"width:100%\"><tbody><tr><td colspan=\"2\" class=\"fx-title\" style=\"text-align:center;padding:12px\">PURCHASE REQUEST</td></tr><tr><td style=\"width:52%\">Barangay: <b>{{entity.short}}</b><br>City/Municipality: {{entity.city}}</td><td>P.R. No. {{pr.no}}<br>Date: {{pr.date}}</td></tr><tr><td colspan=\"2\" style=\"text-align:center;font-weight:bold\">REQUISITION</td></tr><tr><td colspan=\"2\" class=\"fx-slot\">{{pr.items}}</td></tr><tr><td colspan=\"2\"><b>Purpose : {{project.purpose}}</b></td></tr><tr><td style=\"height:140px;vertical-align:top\">Requested by:<div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{project.requestor}}</div><div class=\"sg-cap\">Signature over Printed Name<br>Requisitioner</div></div><div class=\"fx-dateline\">Date</div></td><td style=\"vertical-align:top\">Approved by:<div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{entity.head_name}}</div><div class=\"sg-cap\">Signature over Printed Name<br>Punong Barangay</div></div><div class=\"fx-dateline\">Date</div></td></tr></tbody></table>','A4','portrait',0,NULL,NULL,'entity.name,pr.no',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"blank_rows\":3,\"lh_style\":\"none\",\"signatories\":[]}',NULL,NULL,1,330,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(34,'purchase_order','Purchase order (PO)','proforma','contract','po','Purchase order of the barangay to the company: mode of procurement, items, penalty clause, conforme and appropriations.','<table class=\"fx\" style=\"width:100%\"><tbody><tr><td colspan=\"2\" class=\"fx-title\" style=\"text-align:center;padding:12px\">PURCHASE ORDER</td></tr><tr><td colspan=\"2\" style=\"padding:0\"><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:50%\">Barangay: <b>{{entity.short_upper}}</b><br>Tel. No. {{entity.phone}}</td><td>City/Municipality: {{entity.city}}<br>Province: {{entity.province}}</td></tr></tbody></table></td></tr><tr><td style=\"width:52%\">Supplier: <b>{{company.name}}</b></td><td>P.O. No. {{po.no}}</td></tr><tr><td style=\"vertical-align:top\">Address: {{company.address}}<br>TIN:   {{company.tin}}</td><td>Date {{po.date}}<br>Mode of procurement<div style=\"margin-top:6px\">{{po.modes}}</div></td></tr><tr><td colspan=\"2\">Gentlemen:<br><span style=\"padding-left:28px\">Please deliver to this office the following articles subject to the terms and conditions contained herein</span></td></tr><tr><td>Place of Delivery: {{po.place}}<br>Date of Delivery: {{po.delivery_date}}</td><td>Delivery term: {{po.delivery_term}}<br>Payment term: {{po.payment_term}}</td></tr><tr><td colspan=\"2\" class=\"fx-slot\">{{po.items}}</td></tr><tr><td colspan=\"2\"><b>**{{po.total_words}} Only. **</b></td></tr><tr><td colspan=\"2\" style=\"padding:6px 10px 14px\"><div data-section=\"penalty\" title=\"Penalty clause\">    In case of failure to make full delivery within the time specified above, a penalty of one-tenth (1/10) of one percent for everyday of delay shall be imposed.</div><table class=\"fx-plain\" style=\"width:100%;margin-top:14px\"><tbody><tr><td style=\"width:55%\"></td><td>Very truly yours,<div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{entity.head_name}}</div><div class=\"sg-cap\">Signature Over Printed Name<br>Punong Barangay</div></div></td></tr></tbody></table></td></tr><tr><td style=\"vertical-align:top;height:150px\">Conforme:<div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{company.name_upper}}</div><div class=\"sg-cap\">Signature over Printed Name<br>Supplier</div></div><div class=\"fx-dateline\">Date</div></td><td style=\"vertical-align:top\" data-section=\"appropriations\" title=\"Existence of available appropriations\">Existence of Available Appropriations of<div style=\"margin:6px 0\">( ____________________________________ )</div><div>(amount in words) ( figure )</div><div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{entity.appropriations_chair}}</div><div class=\"sg-cap\">Signature over Printed Name<br>Chairman, Committee on Appropriations</div></div><div>Date:</div></td></tr></tbody></table>','A4','portrait',0,NULL,NULL,'entity.name,po.no',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9,\"table_size\":9,\"title_size\":13,\"blank_rows\":2,\"lh_style\":\"none\",\"signatories\":[]}',NULL,NULL,1,340,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(35,'inspection_acceptance_report','Inspection and acceptance report (IAR)','proforma','delivery','iar','Inspection and acceptance report of the barangay for the items delivered: inspection by the inspector, acceptance by the custodian.','<div class=\"fx-title\" style=\"text-align:center;margin-bottom:8px\">INSPECTION AND ACCEPTANCE REPORT</div><table class=\"fx\" style=\"width:100%\"><tbody><tr><td style=\"width:55%\">Entity Name: <b>{{entity.name}}</b></td><td>Fund Cluster: {{project.fund}}</td></tr><tr><td>Supplier: <b>{{company.name}}</b><br>PO No./Date: {{po.no}} / {{po.date}}</td><td><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:55%;padding:0\">IAR No.: {{iar.no}}<br>Date: {{iar.date}}</td><td style=\"padding:0\">RIS No.: {{ris.no}}<br>Date: {{ris.date}}</td></tr><tr><td colspan=\"2\" style=\"padding:0\">Invoice No.: {{iar.invoice_no}}<br>Date: {{iar.invoice_date}}</td></tr></tbody></table></td></tr><tr><td colspan=\"2\" class=\"fx-slot\">{{iar.items}}</td></tr><tr><td style=\"text-align:center;font-weight:bold\">INSPECTION</td><td style=\"text-align:center;font-weight:bold\">ACCEPTANCE</td></tr><tr><td style=\"vertical-align:top;height:170px\">Date Inspected: {{iar.date}}<p style=\"margin-top:10px\"><span class=\"fx-chk is-on\"></span> Inspected, verified and found in order as to quantity and specifications</p><div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{project.inspector}}</div><div class=\"sg-cap\">Inspection Officer / Inspection Committee</div></div></td><td style=\"vertical-align:top\">Date Received: {{iar.date}}<p style=\"margin-top:10px\"><span class=\"fx-chk is-on\"></span> Complete<br><span class=\"fx-chk\"></span> Partial (pls. specify quantity)</p><div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{entity.treasurer}}</div><div class=\"sg-cap\">Supply and/or Property Custodian</div></div></td></tr></tbody></table>','A4','portrait',0,NULL,NULL,'entity.name,iar.no',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"blank_rows\":3,\"lh_style\":\"none\",\"signatories\":[]}',NULL,NULL,1,350,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(36,'requisition_issue_slip','Requisition and issue slip (RIS)','proforma','delivery','ris','Requisition and issue slip of the barangay: requisition, issuance, and the four signatures.','<table class=\"fx\" style=\"width:100%\"><tbody><tr><td colspan=\"6\" class=\"fx-title\" style=\"text-align:center;padding:12px\">REQUISITION AND ISSUE SLIP</td></tr><tr><td style=\"width:12%\">Barangay:<br>Tel No.:</td><td style=\"width:24%\"><b>{{entity.short_upper}}</b><br>{{entity.phone}}</td><td style=\"width:20%\">City/Municipality:<br>Province:</td><td style=\"width:20%\">{{entity.city}}<br>{{entity.province}}</td><td style=\"width:9%\">RIS No.<br>Date:</td><td>{{ris.no}}<br>{{ris.date}}</td></tr><tr><td colspan=\"6\" class=\"fx-slot\">{{ris.items}}</td></tr><tr><td colspan=\"6\"><b>Purpose : {{project.purpose}}</b></td></tr><tr><td colspan=\"6\" style=\"padding:0\"><table class=\"fx fx-inner\" style=\"width:100%\"><tbody><tr><td style=\"width:15%;vertical-align:bottom;line-height:1.9\">Signature<br>Printed Name<br>Designation<br>Date</td><td style=\"vertical-align:top;height:110px\">Requested by:<div style=\"margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto\"></div><div style=\"text-align:center;font-weight:bold;text-decoration:underline\">{{project.requestor}}</div><div style=\"text-align:center\">Requisitioner</div></td><td style=\"vertical-align:top;height:110px\">Approved for Issuance:<div style=\"margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto\"></div><div style=\"text-align:center;font-weight:bold;text-decoration:underline\">{{entity.head_name}}</div><div style=\"text-align:center\">Punong Barangay</div></td><td style=\"vertical-align:top;height:110px\">Received by:<div style=\"margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto\"></div><div style=\"text-align:center;font-weight:bold;text-decoration:underline\">{{project.requestor}}</div><div style=\"text-align:center\">Requisitioner</div></td><td style=\"vertical-align:top;height:110px\">Issued by:<div style=\"margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto\"></div><div style=\"text-align:center;font-weight:bold;text-decoration:underline\">{{entity.treasurer}}</div><div style=\"text-align:center\">Barangay Treasurer</div></td></tr></tbody></table></td></tr></tbody></table>','A4','portrait',0,NULL,NULL,'entity.name,ris.no',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"blank_rows\":4,\"lh_style\":\"none\",\"signatories\":[]}',NULL,NULL,1,360,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(37,'request_for_quotation','Request for quotation (canvass form)','proforma','bid','canvass','The canvass form given to each supplier: the letterhead of the BAC with the seal, the general conditions, the items with blank prices for the supplier to fill in and sign.','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:12px 0 8px\">REQUEST FOR QUOTATION</div><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:56%\"></td><td>DATE: <u>{{rfq.date}}</u><br>QUOTATION NO.: <u>{{pr.no}}</u></td></tr></tbody></table><p style=\"margin:12px 0 0;text-align:justify\">Please quote your lowest price on the item/s listed below, subject to the General Conditions stated, stating the shortest time of delivery and submit your quotation duly signed by your representative not later than ____________________, __________ thru email: {{entity.email}} or personally at The Secretariat, Bids and Awards Committee, {{entity.short}} Barangay Hall, {{entity.short}}, {{entity.city}}.</p><div>{{signatories}}</div><div data-section=\"conditions\" title=\"General conditions\"><p style=\"margin:10px 0 2px\">GENERAL CONDITIONS:</p><ol style=\"margin:0 0 6px;padding-left:2em\"><li>All entries must be handwritten / typewritten legibly.</li><li>Approved Budget of the Contract (ABC) is <b>PHP {{project.abc_plain}}</b></li><li>Delivery period is within {{rfq.delivery_words}}.</li><li>Warranty shall be for a period of six (6) months for supplies and materials, one (1) year for equipment, from date of acceptance by the Procuring Entity.</li><li>Price shall be inclusive of VAT.</li><li>Please submit the following documents:<ol type=\"a\" style=\"margin:0;padding-left:2.6em\"><li>PhilGeps Registration Number</li><li>DTI/SEC Registration</li><li>Mayor\'s Permit</li><li>BIR Form No. 2303</li><li>Tax Clearance, if available</li></ol></li></ol></div>{{rfq.items}}<p style=\"margin:6px 0 0\">After having carefully read and accepted your General Conditions, I/We quote you on the item at prices noted above.</p><table class=\"fx-plain\" style=\"width:100%;margin-top:28px\"><tbody><tr><td style=\"width:56%\"></td><td><div style=\"border-top:1px solid #000;text-align:center\">Printed Name / Signature</div></td></tr><tr><td style=\"padding-top:28px\"><div style=\"border-top:1px solid #000;width:88%\">NAME OF ESTABLISHMENT</div></td><td style=\"padding-top:28px\"><div style=\"border-top:1px solid #000\">DATE</div></td></tr></tbody></table>','A4','portrait',0,NULL,NULL,'entity.name',0,'layout','{\"margin_top\":10,\"margin_right\":15,\"margin_bottom\":10,\"margin_left\":15,\"line_height\":1.25,\"cell_pad\":2,\"font_size\":10,\"table_size\":9.5,\"title_size\":13,\"blank_rows\":6,\"sig_top\":6,\"sig_space\":8,\"signatories\":[{\"lead\":\"\",\"name\":\"{{entity.bac_chair_upper}}\",\"pos\":\"BAC Chairman\",\"extra\":\"\",\"align\":\"half\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"own\",\"lh_left\":\"seal\",\"lh_right\":\"pic_a\",\"lh_lines\":\"**REPUBLIC OF THE PHILIPPINES**\\nProvince of {{entity.province}}\\n{{entity.lgu_line}}\\n{{entity.title}}\\n**BIDS AND AWARD COMMITTEE**\",\"lh_align\":\"center\",\"lh_font\":\"\",\"lh_size\":10.5,\"lh_big\":15,\"lh_pic_mm\":24,\"lh_box\":0,\"lh_box_bg\":\"#ffffff\",\"lh_box_fg\":\"#111111\",\"lh_rule\":0,\"lh_rule_color\":\"#111111\",\"lh_space\":4,\"lh_pic_a\":\"\",\"lh_pic_b\":\"\"}',NULL,NULL,1,370,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(38,'abstract_of_canvass','Abstract of bids as quoted (abstract of canvass)','proforma','bid','abstract','The quotations of the suppliers canvassed side by side (total, validity, brand, remarks), signed by the BAC and approved by the Punong Barangay.','<table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:58%\"></td><td>P.R No. : {{pr.no}}<br>Name of Project: {{project.title}}<br>Location of the Project: {{entity.location}}</td></tr></tbody></table><div class=\"fx-title\" style=\"text-align:center;margin:18px 0 4px;text-decoration:underline\">ABSTRACT OF BIDS AS QUOTED</div><p style=\"text-align:right\">( {{abstract.date}} )</p><p><b>Project Name: {{project.title}}<br>Project Location: {{entity.name}}, {{entity.city}}<br>Implementing Office: {{project.end_user}}</b></p><p style=\"margin:12px 0 2px\"><b>Approved Budget for the Contract: {{project.abc}}</b></p>{{abstract.table}}<div style=\"margin-top:34px\">{{entity.bac_signatures}}</div><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":10,\"table_size\":9,\"title_size\":13,\"lh_style\":\"none\",\"sig_top\":8,\"signatories\":[{\"lead\":\"Approved by:\",\"name\":\"{{entity.head_name_upper}}\",\"pos\":\"PUNONG BARANGAY\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,380,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(39,'disbursement_voucher','Disbursement voucher (DV)','proforma','billing','dv','Disbursement voucher of the barangay for the payment to the company: particulars, taxes withheld, net amount in words, supporting papers, boxes A to E.','<table class=\"fx\" style=\"width:100%\"><tbody><tr><td colspan=\"2\" class=\"fx-title\" style=\"text-align:center;padding:10px\">DISBURSEMENT VOUCHER</td><td style=\"width:22%;vertical-align:bottom\">DV No. {{dv.no}}</td></tr><tr><td style=\"width:46%\">Barangay: <b>{{entity.short}}</b></td><td>City/Municipality: {{entity.city}}</td><td>Date: {{dv.date}}</td></tr><tr><td>Payee: <b>{{company.name}}</b></td><td>Province: {{entity.province}}</td><td>Fund: {{dv.fund}}</td></tr><tr><td>Address: {{company.address}}</td><td>TIN: {{company.tin}}</td><td></td></tr><tr><td colspan=\"3\" style=\"text-align:center;font-weight:bold\">PARTICULARS</td></tr><tr><td colspan=\"3\" style=\"padding:10px 14px\"><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:70%\">To Withdraw Payment for the <b>{{dv.particulars}}</b><br>per attached supporting documents in the amount of ------------</td><td style=\"text-align:right;font-size:12pt;font-weight:bold;vertical-align:bottom\">{{dv.gross}}</td></tr><tr data-section=\"taxes\" title=\"Taxes withheld\"><td style=\"padding-top:16px\">{{dv.taxes}}</td><td style=\"text-align:right;font-size:12pt;font-weight:bold;vertical-align:bottom\">{{dv.net}}</td></tr><tr><td colspan=\"2\" style=\"padding:18px 0 0 18px\">Amount in words:<br><b>{{dv.net_words_upper}}</b></td></tr><tr data-section=\"papers\" title=\"Supporting papers attached\"><td colspan=\"2\" style=\"padding:18px 0 0 18px\">Supporting Papers Attached:{{dv.papers}}</td></tr><tr><td></td><td style=\"text-align:right;font-size:12pt\"><b>TOTAL:   {{dv.net}}</b></td></tr></tbody></table></td></tr><tr><td colspan=\"3\" style=\"padding:0\"><table class=\"fx fx-inner\" style=\"width:100%\"><tbody><tr><td style=\"width:34%;vertical-align:top\"><span class=\"fx-tag\">A</span> <b>Certified:</b><br>Existence of available appropriations for the charges/expenses indicated above<div style=\"margin-top:26px\">Signature:<br>Printed Name: <b><u>{{entity.appropriations_chair_upper}}</u></b><br>Position: Chairman, Committee on Appropriations<br><b>Date:</b></div></td><td style=\"width:33%;vertical-align:top\"><span class=\"fx-tag\">B</span> <b>Certified:</b><br>Funds (Cash) Available:<br><br><div style=\"margin-top:26px\">Signature:<br>Printed Name: <b><u>{{entity.treasurer_upper}}</u></b><br>Position: Barangay Treasurer<br><b>Date:</b></div></td><td style=\"vertical-align:top\"><span class=\"fx-tag\">C</span> <b>Certified:</b><br>As to validity, propriety, and legality of claim<br>Approved: FOR PAYMENT<div style=\"margin-top:26px\">Signature:<br>Printed Name: <b><u>{{entity.head_name_upper}}</u></b><br>Position: Barangay Captain<br><b>Date:</b></div></td></tr></tbody></table></td></tr><tr><td colspan=\"3\" style=\"vertical-align:top;height:110px\"><span class=\"fx-tag\">D</span> <b>Received Payment:</b><table class=\"fx-plain\" style=\"width:100%;margin-top:16px\"><tbody><tr><td style=\"width:50%;padding-left:40px\"><b><u>{{company.name_upper}}</u></b><br>Signature Over Printed Name</td><td>Check No. <u>{{dv.check_no}}</u><br>Bank Name: <u>{{dv.bank}}</u><br>OR Number: <u>{{dv.or_no}}</u></td><td style=\"vertical-align:middle\">Date: __________</td></tr><tr><td style=\"padding-top:10px\">Date: __________</td><td></td><td></td></tr></tbody></table></td></tr><tr><td colspan=\"3\" style=\"padding:0\" data-section=\"entries\" title=\"E. Accounting entries\"><table class=\"fx fx-inner\" style=\"width:100%\"><tbody><tr><td colspan=\"4\"><b>E. Accounting Entries:</b></td></tr><tr><th style=\"width:36%\">Account</th><th style=\"width:22%\">Account Code</th><th style=\"width:21%\">Debit</th><th>Credit</th></tr><tr><td> </td><td></td><td></td><td></td></tr><tr><td> </td><td></td><td></td><td></td></tr><tr><td> </td><td></td><td></td><td></td></tr><tr><td> </td><td></td><td></td><td></td></tr><tr><td style=\"vertical-align:top;height:150px\"></td><td colspan=\"3\" style=\"vertical-align:top\">Prepared By:<table class=\"fx-plain\" style=\"width:100%;margin-top:26px\"><tbody><tr><td style=\"width:62%;text-align:center\"><div style=\"border-top:1px solid #000;width:70%;margin:0 auto\"></div>{{entity.bookkeeper}}<br>Barangay Bookkeeper</td><td style=\"text-align:center\"><div style=\"border-top:1px solid #000;width:70%;margin:0 auto\"></div>Date</td></tr></tbody></table><div style=\"margin-top:12px\">Approved By:</div><table class=\"fx-plain\" style=\"width:100%;margin-top:22px\"><tbody><tr><td style=\"width:62%;text-align:center\"><div style=\"border-top:1px solid #000;width:70%;margin:0 auto\"></div>{{entity.city_accountant}}<br>City/Municipal Accountant</td><td style=\"text-align:center\"><div style=\"border-top:1px solid #000;width:70%;margin:0 auto\"></div>Date</td></tr></tbody></table></td></tr></tbody></table></td></tr></tbody></table>','A4','portrait',0,NULL,NULL,'entity.name,dv.gross',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9,\"table_size\":8.5,\"title_size\":13,\"lh_style\":\"none\",\"signatories\":[]}',NULL,NULL,1,390,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(40,'document_transmittal','Transmittal of documents (SOP)','letter','billing','transmittal','Transmittal of the papers released to the barangay (delivered by the sales representative or picked up at the office); the signed copy must come back.','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:6px 0 10px\">TRANSMITTAL</div><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:60%\">To: <b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</td><td style=\"vertical-align:top\">Transmittal No.: <b>{{transmittal.no}}</b><br>Date: {{transmittal.date}}<br>Transaction: {{project.txn_form_no}}<br>P.O. No.: {{po.no}}</td></tr></tbody></table><p style=\"margin-top:12px\">We are transmitting herewith the following documents for <b>{{project.title}}</b>:</p>{{sop.papers_table}}<p>Kindly acknowledge receipt by signing below and returning a copy of this transmittal.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'entity.name,transmittal.no',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":8,\"signatories\":[{\"lead\":\"Released by:\",\"name\":\"{{transmittal.released_by}}\",\"pos\":\"{{company.name}}\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"Received by:\",\"name\":\"\",\"pos\":\"Signature over Printed Name \\/ Date\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":1,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"company\"}',NULL,NULL,1,400,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(41,'bidding_form','Bidding form (details of the bidding)','internal','internal','bidding_form','The details of a bidding given by the sales representative: project, budget, schedule, specifications and submission requirements.','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:6px 0 10px\">BIDDING FORM</div><table class=\"fx\" style=\"width:100%\"><tbody><tr><td style=\"width:32%\">Series / project no.</td><td>{{project.no}}   {{project.txn_form_no}}</td></tr><tr><td>Procuring entity</td><td><b>{{entity.name}}</b>, {{entity.city}}, {{entity.province}}</td></tr><tr><td>Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Pre-bid conference</td><td>{{project.prebid}}</td></tr><tr><td>Bid deadline / opening</td><td>{{project.bid_deadline}}</td></tr><tr><td>Requestor / end-user</td><td>{{project.requestor}} {{project.end_user}}</td></tr><tr><td>Inspector</td><td>{{project.inspector}}</td></tr><tr><td>Submission requirements</td><td>{{entity.requirement_notes}}</td></tr><tr><td>Specifications</td><td>{{project.specs}}</td></tr><tr><td>Sales representative</td><td>{{project.sales_rep}}</td></tr></tbody></table>{{lines.table}}','A4','portrait',0,NULL,NULL,'project.title,entity.name',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"signatories\":[],\"lh_style\":\"company\"}',NULL,NULL,1,410,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(42,'invitation_to_bid','Invitation to bid (ITB)','proforma','bid','itb','Invitation to bid in the name of the Bids and Awards Committee of the barangay.','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:12px 0\">INVITATION TO BID</div><p style=\"text-align:center\"><b>{{project.title}}</b><br>{{project.reference_no}}</p><ol><li>The {{entity.name}}, through its approved budget, intends to apply the sum of <b>{{project.abc_words}} ({{project.abc}})</b> being the Approved Budget for the Contract (ABC) to payments under the contract for {{project.title}}. Bids received in excess of the ABC shall be automatically rejected at bid opening.</li><li>The {{entity.name}} now invites bids for the above procurement project. Delivery of the goods is required within {{project.delivery_days}}. Bidders should have completed a contract similar to the project.</li><li>Bidding will be conducted through open competitive bidding procedures using a non-discretionary \"pass/fail\" criterion as specified in the applicable procurement law and its implementing rules.</li><li>A pre-bid conference shall be held on {{project.prebid}} at {{entity.address}}.</li><li>Bids must be duly received by the BAC Secretariat at {{entity.address}} on or before {{project.bid_deadline}}. Late bids shall not be accepted.</li><li>The {{entity.name}} reserves the right to reject any and all bids, declare a failure of bidding, or not award the contract at any time prior to contract award, without thereby incurring any liability to the affected bidder or bidders.</li></ol><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'entity.name,project.title,project.abc',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":12,\"signatories\":[{\"lead\":\"\",\"name\":\"{{entity.bac_chair_upper}}\",\"pos\":\"BAC Chairman\",\"extra\":\"\",\"align\":\"half\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\"}',NULL,NULL,1,420,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(43,'letter_to_coa','Letter to COA','letter','bid','coa_letter','Letter of the BAC informing the COA auditor of the bidding schedule and inviting a representative as observer.','<div>{{letterhead}}</div><p style=\"text-align:right\">{{date.today}}</p><p><b>The Audit Team Leader</b><br>Commission on Audit<br>{{entity.city}}, {{entity.province}}</p><p><b>Subject: Invitation to observe the bidding for {{project.title}}</b></p><p>Sir/Madam:</p><p>The Bids and Awards Committee of {{entity.name}} respectfully informs your office of the procurement of <b>{{project.title}}</b> ({{project.reference_no}}) with an Approved Budget for the Contract of {{project.abc}}.</p><p>The pre-bid conference is scheduled on {{project.prebid}} and the opening of bids on {{project.bid_deadline}}, at {{entity.address}}. We would be glad to have a representative of your office attend as observer.</p><p>Thank you.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":6,\"signatories\":[{\"lead\":\"Very truly yours,\",\"name\":\"{{entity.bac_chair_upper}}\",\"pos\":\"BAC Chairman\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\"}',NULL,NULL,1,430,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(44,'approved_budget_contract','Approved budget for the contract (ABC)','proforma','bid','abc','Certification of the approved budget for the contract, with its breakdown (asked by the Cebu City offices).','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:12px 0\">APPROVED BUDGET FOR THE CONTRACT</div><table class=\"fx\" style=\"width:100%\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference</td><td>{{project.reference_no}}</td></tr><tr><td>Source of funds</td><td>{{project.funding_source}}</td></tr><tr><td>Approved budget</td><td><b>{{project.abc}}</b> ({{project.abc_words}})</td></tr></tbody></table>{{pr.items}}<p>This is to certify that the above amount is the approved budget for the contract of the project.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'entity.name,project.abc',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":12,\"signatories\":[{\"lead\":\"\",\"name\":\"{{entity.treasurer_upper}}\",\"pos\":\"Barangay Treasurer\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"{{entity.head_name_upper}}\",\"pos\":\"Punong Barangay\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\"}',NULL,NULL,1,440,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(45,'special_power_of_attorney','Special power of attorney (SPA)','contract','bid','spa','Special power of attorney of the proprietor appointing the representative for the pre-bid conference and the bidding (for notarization).','<div class=\"fx-title\" style=\"text-align:center;margin:6px 0 14px\">SPECIAL POWER OF ATTORNEY</div><p><b>KNOW ALL MEN BY THESE PRESENTS:</b></p><p>I, <b>{{signatory.name}}</b>, of legal age, Filipino, {{signatory.position}} of <b>{{company.name}}</b>, with business address at {{company.address}}, do hereby name, constitute and appoint <b>{{project.submitter}}</b>, of legal age, as my true and lawful attorney-in-fact, for me and in my name, place and stead, to do and perform the following acts:</p><ol><li>To attend the pre-bid conference and the opening of bids of <b>{{project.title}}</b> ({{project.reference_no}}) of the {{entity.name}};</li><li>To submit, sign and receive all documents relative to the said bidding, including the bid, clarifications and notices;</li><li>To do all acts necessary and incidental to the foregoing.</li></ol><p>HEREBY GIVING AND GRANTING unto my said attorney-in-fact full power and authority to do and perform the acts above, and hereby ratifying all that my said attorney-in-fact shall lawfully do by virtue hereof.</p><p>IN WITNESS WHEREOF, I have hereunto set my hand this ____ day of __________ at __________.</p><div>{{signatories}}</div><p style=\"margin-top:22px\"><b>ACKNOWLEDGMENT</b></p><p>[Notarial acknowledgment]</p>','A4','portrait',0,NULL,NULL,'signatory.name,project.submitter',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"lh_style\":\"none\",\"sig_top\":12,\"signatories\":[{\"lead\":\"\",\"name\":\"{{signatory.name}}\",\"pos\":\"Principal\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"{{project.submitter}}\",\"pos\":\"Attorney-in-fact\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}]}',NULL,NULL,1,450,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(46,'statement_ongoing_contracts','Statement of all ongoing government and private contracts','proforma','bid','slcc','Ongoing government and private contracts, incl. those awarded but not started (step 8 of the bidding): the company\'s other awarded contracts not closed fill the government part; private ones are typed on the form.','<p style=\"margin:0;line-height:1.3\"><i>Procuring Entity:</i> <b>{{entity.title}}</b><br><i>Name of the Project:</i> <b>{{project.title}}</b><br><i>Location of the Project:</i> <b>{{project.location}}</b></p><p style=\"margin:8px 0 6px\"><span class=\"fx-hl\" style=\"font-size:14pt;font-weight:bold\">Statement of all Ongoing Government &amp; Private Contracts including contracts awarded but not yet started</span></p><table class=\"fx-plain fx-kv\" style=\"margin:4px 0 6px\"><tbody><tr><td class=\"k\">Business Name</td><td>: <u>{{company.name}}</u></td></tr><tr><td class=\"k\">Business Address</td><td>: <u>{{company.address}}</u></td></tr></tbody></table><div style=\"margin-top:6px\">{{contracts.ongoing_table}}</div><p style=\"margin:6px 0 0\">Note: This statement shall be supported with:</p><ol style=\"margin:0;padding-left:2.4em\"><li>Purchase Order / Notice to Proceed</li><li>Notice of Award</li></ol><table class=\"fx-plain fx-kv\" style=\"margin-top:22px\"><tbody><tr><td class=\"k\" style=\"width:110px\">Submitted by</td><td>: <b><u>{{signatory.name}}</u></b></td></tr><tr><td></td><td style=\"padding-left:14px;font-size:.92em\">(Printed Name &amp; Signature)</td></tr><tr><td class=\"k\">Designation</td><td>: <u>{{signatory.position}}</u></td></tr><tr><td class=\"k\">Date</td><td>: {{date.today}}</td></tr></tbody></table>','Legal','landscape',0,NULL,NULL,'company.name,project.title',0,'layout','{\"margin_top\":15,\"margin_right\":18,\"margin_bottom\":15,\"margin_left\":18,\"line_height\":1.25,\"cell_pad\":3,\"font_size\":10,\"table_size\":9.5,\"title_size\":14,\"paper\":\"Legal\",\"orientation\":\"landscape\",\"font\":\"CG Omega\",\"date_style\":\"mdy\",\"lh_style\":\"none\",\"signatories\":[]}',NULL,NULL,1,460,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(47,'statement_similar_contracts','Statement of the single largest completed contract similar to the contract to be bid (SLCC)','proforma','bid','slcc','Statement identifying the bidder\'s single largest completed contract similar to the contract to be bid (step 8 of the bidding): the largest closed contract of the company of the same category.','<p style=\"margin:0;line-height:1.3\"><i>Procuring Entity:</i> <b>{{entity.title}}</b><br><i>Name of the Project:</i> <b>{{project.title}}</b><br><i>Location of the Project:</i> <b>{{project.location}}</b></p><p style=\"margin:6px 0 6px\"><span class=\"fx-hl\" style=\"font-size:14pt;font-weight:bold\">Statement identifying the bidder’s single largest completed contract similar to the contract to be bid</span></p><table class=\"fx-plain fx-kv\" style=\"margin:4px 0 6px\"><tbody><tr><td class=\"k\">Business Name</td><td>: <u>{{company.name}}</u></td></tr><tr><td class=\"k\">Business Address</td><td>: <u>{{company.address}}</u></td></tr></tbody></table><div style=\"margin-top:6px\">{{contracts.slcc_table}}</div><p style=\"margin:6px 0 0\">Note: This statement shall be supported with:</p><p style=\"margin:0;padding-left:2em\">1. Purchase Order     2. Sales Invoice     3. Certificate of Completion / Delivery Receipt     4. Notice of Award     5. Notice to Proceed</p><table class=\"fx-plain fx-kv\" style=\"margin-top:22px\"><tbody><tr><td class=\"k\" style=\"width:110px\">Submitted by</td><td>: <b><u>{{signatory.name}}</u></b></td></tr><tr><td></td><td style=\"padding-left:14px;font-size:.92em\">(Printed Name &amp; Signature)</td></tr><tr><td class=\"k\">Designation</td><td>: <u>{{signatory.position}}</u></td></tr><tr><td class=\"k\">Date</td><td>: {{date.today}}</td></tr></tbody></table>','Folio','landscape',0,NULL,NULL,'company.name,project.title',0,'layout','{\"margin_top\":15,\"margin_right\":18,\"margin_bottom\":15,\"margin_left\":18,\"line_height\":1.25,\"cell_pad\":3,\"font_size\":10,\"table_size\":9.5,\"title_size\":14,\"paper\":\"Folio\",\"orientation\":\"landscape\",\"font\":\"CG Omega\",\"date_style\":\"mdy\",\"lh_style\":\"none\",\"signatories\":[]}',NULL,NULL,1,470,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(48,'supplier_profile','Supplier profile','proforma','bid','company_profile','The supplier profile asked by the barangay (step 8 of the bidding): the firm, its general information, the person managing its affairs and the authorized liaison officer (Contracting Settings).','<div>{{letterhead}}</div><p style=\"text-align:center;margin:14px 0 28px\"><span style=\"font-size:20pt;font-weight:bold;text-decoration:underline\">SUPPLIER</span></p><p style=\"margin:0 0 6px\"><b><u>Profile</u></b></p><table class=\"fx-plain fx-kv\" style=\"width:100%\"><tbody><tr><td class=\"k\" style=\"width:34%\">Date</td><td>: {{date.today}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Name of Firm/Company</td><td>: <b>{{company.name_upper}}</b></td></tr><tr><td class=\"k\" style=\"width:34%\">Tax Account Number</td><td>: {{company.tin}}</td></tr></tbody></table><p style=\"margin:18px 0 6px\"><b><u>General Information</u></b></p><table class=\"fx-plain fx-kv\" style=\"width:100%\"><tbody><tr><td class=\"k\" style=\"width:34%\">Address</td><td>: {{company.address}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Telephone Number</td><td>: {{company.phone}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Email Address</td><td>: {{company.email}}</td></tr></tbody></table><p style=\"margin:18px 0 6px\"><b><u>Legal Aspect</u></b></p><p style=\"margin:0 0 6px\">Persons Managing affairs of the Firm</p><table class=\"fx-plain fx-kv\" style=\"width:100%\"><tbody><tr><td class=\"k\" style=\"width:34%\">Name</td><td>: {{company.manager_upper}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Designation</td><td>: {{company.manager_position}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Telephone</td><td>: {{company.manager_phone}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Cellphone No.</td><td>: {{company.manager_mobile}}</td></tr></tbody></table><p style=\"margin:18px 0 6px\">Authorized Liaison Officer</p><table class=\"fx-plain fx-kv\" style=\"width:100%\"><tbody><tr><td class=\"k\" style=\"width:34%\">Name</td><td>: {{company.liaison_upper}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Designation</td><td>: {{company.liaison_position}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Telephone</td><td>: {{company.liaison_phone}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Cellphone No.</td><td>: {{company.liaison_mobile}}</td></tr></tbody></table>','Legal','portrait',0,NULL,NULL,'company.name,company.tin',0,'layout','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"signatories\":[],\"lh_style\":\"own\",\"lh_left\":\"none\",\"lh_right\":\"none\",\"lh_lines\":\"Republic of the Philippines\\nProvince of {{entity.province}}\\n{{entity.lgu_line}}\\n**{{entity.short_upper}} Barangay Hall**\",\"lh_align\":\"center\",\"lh_font\":\"\",\"lh_size\":11,\"lh_big\":16,\"lh_pic_mm\":24,\"lh_box\":0,\"lh_box_bg\":\"#ffffff\",\"lh_box_fg\":\"#111111\",\"lh_rule\":0,\"lh_rule_color\":\"#111111\",\"lh_space\":8,\"lh_pic_a\":\"\",\"lh_pic_b\":\"\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,NULL,1,480,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(49,'letter_of_intent','Letter of intent (manifestation of intent to participate)','letter','bid','letter_intent','Manifestation of intent to participate in the public bidding (step 8 of the bidding), on the company letterhead.','<div>{{letterhead}}</div><p style=\"text-align:center;margin:22px 0 26px\"><span style=\"font-size:16pt;font-weight:bold\">Manifestation of Intent to Participate</span></p><p><b>{{date.today}}</b></p><p style=\"margin-top:18px\"><b>OFFICE OF THE CHAIRMAN</b><br>Bids and Awards Committee<br><b>{{entity.title}}</b><br>{{entity.city}}, {{entity.province}}</p><p>Dear Sir/Madam:</p><p style=\"text-align:justify\">This refers to the procurement project entitled <b>{{project.title}}.</b></p><p style=\"text-align:justify\">In this regard, <b>{{company.name}}</b>, with business address at <b>{{company.address}}</b>, hereby formally manifests its intent to participate in the public bidding for the above-mentioned project, in accordance with {{project.law_text}} and its Implementing Rules and Regulations (IRR).</p><p style=\"text-align:justify\">This manifestation is submitted in compliance with the requirements of the Bidding Documents issued by your office. Should our participation be accepted, we commit to comply with all eligibility requirements, submission of bidding documents, and other conditions set forth by the Bids and Awards Committee (BAC) within the prescribed timelines.</p><p style=\"text-align:justify\">We trust that this manifestation satisfies your requirement for expression of interest. We look forward to participating in the bidding process.</p><p style=\"margin-top:18px\">Thank you for your consideration.</p><div>{{signatories}}</div>','Legal','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":12,\"sig_space\":12,\"lh_space\":4,\"signatories\":[{\"lead\":\"Respectfully yours,\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"company\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,NULL,1,490,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(50,'technical_specifications','Technical specifications (Section VII) with the statement of compliance','proforma','bid','technical_proposal','Section VII - Technical specifications of the bid (step 8 of the bidding): each item with its specifications, one a line, and the statement of compliance. A line in capitals or ending with \":\" prints bold; **text** is bold too.','<p style=\"text-align:center;margin:0 0 20px\"><span style=\"font-size:16pt;font-weight:bold;text-decoration:underline\">SECTION VII-TECHNICAL SPECIFICATIONS</span></p><p><b>Date:</b> {{date.today}}</p><p>To: {{entity.short}} Barangay Hall, {{entity.city}}, {{entity.province}}<br>Address: {{entity.address}}</p><p>Gentlemen and/ or Ladies,</p><div>{{specs.items}}</div><p style=\"margin-top:10px\">We can <b><u>comply</u></b> the technical specification stated in the Bid Form.</p><div>{{signatories}}</div>','Legal','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":22,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"lh_style\":\"none\",\"sig_cols\":1,\"sig_top\":16,\"sig_space\":14,\"comply_text\":\"\\\"comply\\\"\",\"signatories\":[{\"lead\":\"\",\"name\":\"{{signatory.name}}\",\"pos\":\"Dealer\'s Signature\",\"extra\":\"**{{company.name_upper}}**\\n{{company.address}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,NULL,1,500,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(51,'production_details','Production details (Section VI: schedule of requirements)','proforma','bid','production','Section VI - Schedule of requirements (step 8 of the bidding): the items with their specifications, the quantity, and the delivery period in weeks or months.','<div>{{letterhead}}</div><p style=\"text-align:center;margin:8px 0 18px\"><span style=\"font-size:16pt;font-weight:bold;text-decoration:underline\">SECTION VI-SCHEDULE OF REQUIREMENTS</span></p><div>{{sched.items}}</div><div>{{signatories}}</div>','Legal','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":20,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":1,\"font_size\":10,\"table_size\":9.5,\"title_size\":14,\"sig_cols\":1,\"sig_top\":14,\"sig_space\":14,\"th_bg\":\"#fff2cc\",\"signatories\":[{\"lead\":\"**{{company.name_upper}}**\\n{{company.address_upper}}\",\"name\":\"{{signatory.name}}\",\"pos\":\"Dealer\'s Signature\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"own\",\"lh_left\":\"none\",\"lh_right\":\"none\",\"lh_lines\":\"Republic of the Philippines\\nProvince of {{entity.province}}\\n{{entity.lgu_line}}\\n**{{entity.short}}** Barangay Hall\",\"lh_align\":\"center\",\"lh_font\":\"\",\"lh_size\":11,\"lh_big\":16,\"lh_pic_mm\":24,\"lh_box\":0,\"lh_box_bg\":\"#ffffff\",\"lh_box_fg\":\"#111111\",\"lh_rule\":0,\"lh_rule_color\":\"#111111\",\"lh_space\":8,\"lh_pic_a\":\"\",\"lh_pic_b\":\"\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,NULL,1,510,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(52,'manpower_schedule','Manpower schedule (manpower requirements)','proforma','bid','manpower','Manpower requirements of the delivery (step 8 of the bidding), on the company letterhead.','<div>{{letterhead}}</div><p style=\"text-align:center;margin:18px 0 30px\"><span style=\"font-size:18pt;font-weight:bold\">MANPOWER REQUIREMENTS</span></p><p style=\"text-align:justify\">In compliance with the requirements of the Bids and Awards Committee (BAC), and based on our standard delivery procedures and actual delivery operations, the following manpower requirement is hereby submitted:</p><p style=\"text-align:justify\">All goods and services shall be delivered to the designated delivery site and shall be subject to inspection upon delivery.</p><table class=\"fx\" style=\"width:100%;margin-top:22px\"><thead><tr><th style=\"width:28%\">PARTICULARS</th><th>DETAILS</th></tr></thead><tbody><tr><td><b>Project Name:</b></td><td>{{project.title}}</td></tr><tr><td style=\"vertical-align:middle\"><b>Project Location:</b></td><td>{{project.location}}</td></tr><tr><td><b>Delivery Vehicle:</b></td><td>Any available vehicle</td></tr><tr><td style=\"vertical-align:middle\"><b>Required Personnel</b></td><td>At least two (2) personnel composed of:<br>• One (1) Driver<br>• One (1) Sales Representative</td></tr></tbody></table><div>{{signatories}}</div>','Legal','portrait',0,NULL,NULL,'project.title',0,'layout','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":22,\"sig_space\":12,\"signatories\":[{\"lead\":\"Respectfully submitted by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"company\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,NULL,1,520,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(53,'warranty_certificate','Warranty certificate (after-sales service and warranty provision)','proforma','bid','warranty_cert','After-sales service and warranty provision (step 8 of the bidding), on the company letterhead. The warranty period comes from the project (warranty in months).','<div>{{letterhead}}</div><p style=\"text-align:center;margin:10px 0 14px\"><span style=\"font-size:11.5pt;font-weight:bold\">After-Sales Service and Warranty Provision</span></p><p style=\"text-align:justify\">The Supplier shall warrant that the {{project.title}}, including all its components, accessories, and attachments, is brand new, free from defects in materials and workmanship, and compliant with the technical specifications prescribed in the Contract.</p><p style=\"margin-bottom:0\"><b>1. Warranty Period</b></p><p style=\"text-align:justify\">The warranty shall be for a minimum period of <b>{{project.warranty_words}}</b>, reckoned from the date of final acceptance by the Procuring Entity.</p><p style=\"margin-bottom:0\"><b>2. Scope of Warranty</b></p><p style=\"margin-bottom:2px\">The warranty shall cover, but not be limited to, defects or failures in the following:</p><ul style=\"margin-top:4px\"><li>Materials and workmanship of every item delivered</li><li>The performance of the goods according to the technical specifications</li><li>Components, parts, and assemblies</li><li>Electrical, mechanical, and operating systems, where applicable</li><li>All factory-installed parts, equipment, and accessories</li></ul><p style=\"text-align:justify\">The Supplier shall undertake, at its own expense, the repair or replacement of any defective parts, including the provision of labor, tools, and consumables necessary to restore the goods to their optimal operating condition.</p><p style=\"margin-bottom:0\"><b>3. After-Sales Service Obligations</b></p><p style=\"margin-bottom:0\">The Supplier shall:</p><p style=\"text-align:justify;margin-bottom:0\">a. Maintain or have access to an <b>authorized service center</b> capable of providing adequate technical support within a reasonable distance from the Procuring Entity;<br>b. Ensure the availability of <b>genuine spare parts</b> for a minimum period of <b>five (5) years</b> from delivery;<br>c. Provide <b>preventive maintenance services</b> in accordance with the manufacturer’s recommended schedule, where applicable;<br>d. Respond to service requests within <b>forty-eight (48) to seventy-two (72) hours</b> from receipt of notice;<br>e. Deploy qualified technical personnel to perform necessary inspections, diagnostics, and repairs.</p><p style=\"margin:10px 0 0\"><b>4. Exclusions from Warranty</b></p><p style=\"margin-bottom:2px\">The warranty shall not apply to defects or damages resulting from:</p><ul style=\"margin-top:4px\"><li>Misuse, negligence, or improper operation by the end-user;</li><li>Unauthorized repairs, alterations, or modifications;</li><li>Accidents, force majeure, or external causes beyond normal operating conditions;</li><li>Normal wear and tear of consumable items, unless proven to be factory-defective.</li></ul><p style=\"margin-bottom:0\"><b>5. Warranty Security</b></p><p style=\"text-align:justify\">In accordance with the applicable procurement law and its Implementing Rules and Regulations, the Supplier shall post a <b>warranty security</b> in any of the acceptable forms prescribed by law to guarantee the faithful performance of its warranty obligations.</p><div>{{signatories}}</div>','Legal','portrait',0,NULL,NULL,'project.title',0,'layout','{\"margin_top\":18,\"margin_right\":25.4,\"margin_bottom\":18,\"margin_left\":25.4,\"line_height\":1.22,\"cell_pad\":3,\"font_size\":10.5,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":12,\"sig_space\":12,\"lh_space\":4,\"signatories\":[{\"lead\":\"Sincerely yours,\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"company\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,NULL,1,530,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(54,'notice_of_post_qualification','Notice of post-qualification','proforma','award','post_qual_notice','Notice of the BAC that the company passed the post-qualification and its bid is the lowest calculated and responsive bid (step 13 of the bidding), prepared for the BAC Chairman\'s signature.','<div>{{letterhead}}</div><p style=\"text-align:center;margin:4px 0 22px\"><span style=\"font-size:14pt;font-weight:bold\">Notice of Post Qualification</span></p><p>{{date.today}}</p><p style=\"margin-top:18px\"><b>{{signatory.addressee}}</b><br>{{signatory.position_upper}}<br><b>{{company.name_upper}}</b><br>{{company.address}}</p><p style=\"margin-top:22px\"><b><u>ATTN.: {{signatory.addressee}}</u></b></p><p style=\"margin-top:22px\">Good day!</p><p style=\"text-align:justify;line-height:1.7\">After determining your bid as the Lowest Calculated Bid for the <b>{{project.title}}</b> for {{entity.title}}, {{entity.city}} and upon the post qualification involving the careful examination, validation and verification of the eligibility, technical and financial requirements that you have submitted for bidding. We are happy to inform you that you have successfully passed the Post Qualification and therefore, now consider your bid as the Lowest Calculated and Responsive for the said Contract.</p><div>{{signatories}}</div>','Legal','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":14,\"sig_space\":10,\"sig_gap\":8,\"signatories\":[{\"lead\":\"Very truly yours,\",\"name\":\"HON. {{entity.bac_chair_upper}}\",\"pos\":\"BAC CHAIRMAN\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":1},{\"lead\":\"Received by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"PRINTED NAME AND SIGNATURE\",\"extra\":\"\",\"align\":\"left\",\"newrow\":1,\"line\":0,\"bold\":0,\"underline\":1,\"upper\":0,\"italic\":1}],\"footer\":\"OFFICE OF THE BARANGAY\",\"footer_align\":\"center\",\"footer_bold\":1,\"footer_size\":11,\"footer_pos\":\"bottom\",\"lh_style\":\"barangay\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"Cambria\"}',NULL,NULL,1,540,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(55,'notice_of_award','Notice of award (NOA)','proforma','award','noa','Notice of award to the company (step 13 of the bidding), for the Punong Barangay\'s signature and the conforme of the company.','<div>{{letterhead}}</div><p style=\"text-align:center;margin:4px 0 22px\"><span style=\"font-size:14pt;font-weight:bold\">NOTICE OF AWARD</span></p><p>{{date.today}}</p><p style=\"margin-top:18px\"><b>{{signatory.addressee}}</b><br>{{signatory.position_upper}}<br><b>{{company.name_upper}}</b><br>{{company.address}}</p><p style=\"margin-top:18px\">Dear {{signatory.salutation}}:</p><p style=\"text-align:justify;line-height:1.6\">We are happy to notify you that the contract for the <b>{{project.title}}</b> is hereby awarded to you as the single lowest calculated and responsive Bid at a contract price equivalent to <b>{{project.contract_price_text}}</b>.</p><p style=\"text-align:justify;line-height:1.6\">You are therefore required to provide the performance security in the form and amount stipulated in the instruction to the Bidders within ten (10) calendar days from receipt of Notice of Award. Contract signing will be done upon submission of the required performance security. Failure to provide the performance security on the said date shall constitute ground for cancellation of the award and forfeiture of the bid security.</p><div>{{signatories}}</div>','Legal','portrait',0,NULL,NULL,'entity.name,project.title,project.bid_amount',0,'layout','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":12,\"sig_space\":10,\"sig_gap\":8,\"signatories\":[{\"lead\":\"Very truly yours,\",\"name\":\"HON. {{entity.head_name_upper}}\",\"pos\":\"Barangay Chairman\",\"extra\":\"{{entity.location}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"Conforme:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{company.name_upper}}\",\"extra\":\"Date: {{date.today}}\",\"align\":\"left\",\"newrow\":1,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"footer\":\"OFFICE OF THE BARANGAY\",\"footer_align\":\"center\",\"footer_bold\":1,\"footer_size\":11,\"footer_pos\":\"bottom\",\"lh_style\":\"barangay\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"Cambria\"}',NULL,NULL,1,550,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(56,'notice_to_proceed','Notice to proceed (NTP)','proforma','award','ntp','Notice to proceed (step 13 of the bidding), for the Punong Barangay\'s signature and the acknowledgment of the company\'s representative.','<div>{{letterhead}}</div><p style=\"text-align:center;margin:4px 0 22px\"><span style=\"font-size:14pt;font-weight:bold\">NOTICE TO PROCEED</span></p><p>{{date.today}}</p><p style=\"margin-top:18px\"><b>{{signatory.addressee}}</b><br>{{signatory.position_upper}}<br><b>{{company.name_upper}}</b><br>{{company.address}}</p><p style=\"margin-top:18px\">Dear {{signatory.salutation}}:</p><p style=\"text-align:justify;text-indent:3em;line-height:1.6\">The attached Purchase Order No. <u>{{po.no}}</u> Dated <u>{{po.date}}</u> having been approved. Notice is hereby given to <b>{{company.name_upper}}</b> on the <b>{{project.title}}</b> and may proceed immediately to the delivery of the items enumerated therein within the specified delivery schedule.</p><p style=\"text-align:justify;line-height:1.6\">Please acknowledge receipt and acceptance of this notice by signing both copies in the space provided below and be guided accordingly.</p><div>{{signatories}}</div><table class=\"fx-plain fx-kv\" style=\"width:100%;margin-top:26px\"><tbody><tr><td class=\"k\" style=\"width:48%\">I acknowledge receipt of this Notice on</td><td>: <u>{{date.today}}</u></td></tr><tr><td class=\"k\">Name of the Representative of the Bidder</td><td>: <u>{{signatory.name}}</u></td></tr><tr><td class=\"k\">Authorized Signature</td><td>: _________________________</td></tr></tbody></table>','Legal','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":12,\"sig_space\":10,\"signatories\":[{\"lead\":\"Sincerely yours,\",\"name\":\"HON. {{entity.head_name_upper}}\",\"pos\":\"Barangay Chairman\",\"extra\":\"{{entity.location}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"Cambria\"}',NULL,NULL,1,560,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(57,'bac_resolution','BAC resolution recommending the award','proforma','award','bac_resolution','Resolution of the Bids and Awards Committee recommending the award to the company (step 13 of the bidding), prepared for the signatures of the BAC and the Punong Barangay.','<div>{{letterhead}}</div><p style=\"text-align:center;margin:0\">Office of the Barangay</p><p style=\"text-align:center;margin:2px 0 2px\"><span style=\"font-size:13pt;font-weight:bold\">BIDS AND AWARDS COMMITTEE</span></p><p style=\"text-align:center;font-weight:bold;margin-bottom:18px\">RESOLUTION NO.__________</p><p style=\"text-align:justify;line-height:1.6\">WHEREAS, after a complete and final evaluation by the BAC Technical Working Group it was determined that <b>{{company.name_upper}}</b> has the CALCULATED RESPONSIVE BID for the {{project.title}} located at <b>{{entity.title_upper}}, {{entity.lgu_line_upper}}</b> for the Contract Price equivalent to <b>{{project.contract_price_text}}</b>.</p><p style=\"text-align:justify;line-height:1.6\">WHEREAS, the Approved Budget for the purchase is in the amount of <b>{{project.abc_text}}</b>.</p><p style=\"text-align:justify;line-height:1.6\">WHEREAS, the Bids and Awards Committee (BAC), through Resolution No.__________ dated______________ had recommended to the Barangay CHAIRMAN, <b>HON. {{entity.head_name_upper}}</b> to award the {{project.title}} located at {{entity.title_upper}}, {{entity.lgu_line_upper}}, to <b>{{company.name_upper}}</b> which recommendation was approved.</p><p style=\"text-align:justify;line-height:1.6\">NOW THEREFORE, we the members of the Bids and Awards Committee (BAC), hereby RESOLVED as it is hereby RESOLVED, to recommend to the Honorable Members, Barangay Council of {{entity.title}}, {{entity.city}}, to authorize <b>HON. {{entity.head_name_upper}}</b>, Barangay CHAIRMAN of {{entity.title_upper}}, {{entity.city}}, to enter into a Contract with <b>{{company.name_upper}}</b> at the Contract Price equivalent to <b>{{project.contract_price_text}}</b>.</p><p style=\"text-align:center;margin-top:14px\">{{entity.title}}, {{entity.city}}</p><div>{{signatories}}</div>','Legal','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":3,\"sig_top\":8,\"sig_space\":11,\"sig_gap\":6,\"signatories\":[{\"lead\":\"\",\"name\":\"HON. {{entity.bac_chair_upper}}\",\"pos\":\"BAC Chairman\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_1_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_2_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_3_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_4_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_5_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.head_name_upper}}\",\"pos\":\"Barangay CHAIRMAN\",\"extra\":\"\",\"align\":\"center\",\"newrow\":1,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"Cambria\"}',NULL,NULL,1,570,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL),
(58,'certificate_of_completion','Certificate of completion','proforma','closeout','completion_cert','Certificate of completion signed by the inspector and the Barangay Captain.','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:16px 0\">CERTIFICATE OF COMPLETION</div><p>This is to certify that <b>{{company.name}}</b> has satisfactorily completed / delivered <b>{{project.title}}</b> under {{project.contract_ref}} for the <b>{{entity.name}}</b> in accordance with the specifications, in the amount of <b>{{project.contract_amount}}</b>.</p><p>Date of completion: <b>{{project.completion_date}}</b></p><p>Issued this ____ day of __________ for whatever legal purpose it may serve.</p><div>{{signatories}}</div>','A4','portrait',0,NULL,NULL,'entity.name,project.title',0,'layout','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":14,\"signatories\":[{\"lead\":\"Inspected by:\",\"name\":\"{{project.inspector_upper}}\",\"pos\":\"Inspector\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"Approved by:\",\"name\":\"{{entity.head_name_upper}}\",\"pos\":\"Barangay Captain\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\"}',NULL,NULL,1,580,1,0,NULL,'2026-11-23 19:54:45',NULL,NULL);

-- brgy_template_versions (58 rows)
INSERT INTO `brgy_template_versions` (`id`,`template_id`,`version_no`,`name`,`body`,`layout_json`,`positions_json`,`change_note`,`changed_by`,`changed_at`) VALUES
(1,1,1,'Cover letter: submission of bid / quotation','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Submission of bid / quotation for {{project.title}} ({{project.reference_no}})</b></p><p>Dear Sir / Madam:</p><p>In response to your invitation, {{company.name}} respectfully submits its bid / quotation for <b>{{project.title}}</b> in the total amount of <b>{{project.bid_amount}}</b> ({{project.bid_amount_words}}), inclusive of all applicable taxes, under the terms and specifications of your request.</p><p>Enclosed are the documents your request asks for, as listed in our checklist:</p>{{requirements.table}}<p>Our offer is valid for the period stated in your bidding documents. For clarifications, please contact the undersigned at {{company.phone}} or {{company.email}}.</p><div>{{signatories}}</div>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(2,2,1,'Company profile','<div>{{letterhead}}</div><h2 style=\"text-align:center;margin:10px 0 14px\">COMPANY PROFILE</h2><table class=\"ft\"><tbody><tr><td style=\"width:34%\">Registered name</td><td><b>{{company.name}}</b></td></tr><tr><td>Business address</td><td>{{company.address}}</td></tr><tr><td>Registration</td><td>{{company.reg_no}}</td></tr><tr><td>TIN / tax status</td><td>{{company.tin}}   ({{company.vat_status}})</td></tr><tr><td>PhilGEPS registration</td><td>{{company.philgeps_no}}   {{company.philgeps_class}}</td></tr><tr><td>Contact</td><td>{{company.phone}}   {{company.email}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr></tbody></table><h3>Lines of business</h3><p>Supply and delivery of office, school, medical and household supplies, IT equipment and furniture; general services (janitorial, maintenance, repairs); and, through its licensed construction arm, minor infrastructure works for barangays and local government units.</p><h3>Facilities and people</h3><p>[Describe the warehouse, delivery vehicles, technical staff and the after-sales service offered.]</p><h3>Major government clients</h3><p>[List recent contracts: entity, project, amount, date completed.]</p>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(3,3,1,'Price quotation','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">PRICE QUOTATION</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>We offer to supply the following under the terms of your request for quotation:</p>{{lines.table}}<p><b>Total amount: {{lines.total}}</b> ({{lines.total_words}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Delivery period</td><td>{{project.delivery_days}} from receipt of the purchase order / notice to proceed</td></tr><tr><td>Delivery site</td><td>{{project.delivery_site}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr><tr><td>Payment terms</td><td>{{project.payment_terms}}</td></tr><tr><td>Validity of the offer</td><td>As stated in the RFQ</td></tr></tbody></table><p>Prices include all applicable taxes, delivery and handling. We confirm that the items offered comply with the specifications stated in your request.</p><div>{{signatories}}</div>','{\"signatories\":[{\"lead\":\"Submitted by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(4,4,1,'Price schedule / bill of quantities','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">PRICE SCHEDULE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p></p>{{lines.table}}<p><b>Total bid price: {{lines.total}}</b><br>({{lines.total_words}})</p><p>Name of bidder: <b>{{company.name}}</b><br>Signature of the authorized representative:</p><div>{{signatories}}</div>','{\"signatories\":[{\"lead\":\"\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(5,5,1,'Technical proposal and statement of compliance','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">TECHNICAL PROPOSAL AND STATEMENT OF COMPLIANCE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>We state below, item by item, our compliance with the technical specifications. Where \"Comply\" is stated, the offer meets or exceeds the requirement; supporting brochures and data sheets are attached.</p><table class=\"ft\"><thead><tr><th style=\"width:6%\">Item</th><th>Specification required</th><th style=\"width:30%\">Offer (brand, model, specification)</th><th style=\"width:12%\">Statement</th></tr></thead><tbody><tr><td>1</td><td>{{project.specs}}</td><td>[Brand / model offered]</td><td>Comply</td></tr></tbody></table><p><b>Delivery:</b> {{project.delivery_days}} at {{project.delivery_site}}. <b>Warranty:</b> {{project.warranty_months}}.</p><div>{{signatories}}</div>','{\"signatories\":[{\"lead\":\"Certified correct:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(6,6,1,'Method statement (works / services)','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">METHOD STATEMENT</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><h3>1. Scope</h3><p>{{project.specs}}</p><h3>2. Mobilization</h3><p>[Site set-up, permits, coordination with the barangay and the end-user ({{project.end_user}}).]</p><h3>3. Sequence of work</h3><ol><li>[Activity]</li><li>[Activity]</li><li>[Activity]</li></ol><h3>4. Manpower and equipment</h3><p>[List of key personnel and equipment.]</p><h3>5. Quality control and testing</h3><p>[Tests, inspections and hold points; acceptance by the entity\'s inspection committee.]</p><h3>6. Safety and environment</h3><p>[Safety plan, signage, housekeeping, disposal.]</p><h3>7. Schedule</h3><p>Completion within {{project.delivery_days}} from the notice to proceed.</p><div>{{signatories}}</div>','{\"signatories\":[{\"lead\":\"Prepared by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(7,7,1,'Implementation / delivery schedule','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">IMPLEMENTATION / DELIVERY SCHEDULE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><table class=\"ft\"><thead><tr><th style=\"width:6%\">No.</th><th>Activity / delivery</th><th style=\"width:18%\">Start</th><th style=\"width:18%\">Finish</th><th style=\"width:16%\">Weight %</th></tr></thead><tbody><tr><td>1</td><td>Mobilization / receipt of PO or NTP</td><td>Day 1</td><td>Day 3</td><td>5</td></tr><tr><td>2</td><td>[Delivery / work package]</td><td></td><td></td><td></td></tr><tr><td>3</td><td>Inspection and acceptance</td><td></td><td></td><td></td></tr></tbody></table><p>Total contract period: {{project.delivery_days}}.</p><div>{{signatories}}</div>','{\"signatories\":[{\"lead\":\"Prepared by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(8,8,1,'Supply and services proposal','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">SUPPLY AND SERVICES PROPOSAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>{{company.name}} proposes to supply and deliver the items below, together with the related services described, for <b>{{lines.total}}</b>.</p>{{lines.table}}<h3>Related services</h3><ul><li>Delivery, unloading and installation at {{project.delivery_site}}</li><li>Orientation of the end-users</li><li>Warranty service: {{project.warranty_months}}</li></ul><div>{{signatories}}</div>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(9,9,1,'Scope-of-work proposal','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">SCOPE-OF-WORK PROPOSAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><h3>Scope of work</h3><p>{{project.specs}}</p><h3>Deliverables</h3><ul><li>[Deliverable]</li><li>[Deliverable]</li></ul><h3>Exclusions</h3><p>[Items not included.]</p><h3>Price</h3>{{lines.table}}<p>Duration: {{project.delivery_days}}. Payment: {{project.payment_terms}}.</p><div>{{signatories}}</div>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(10,10,1,'Request for quotation to a supplier / subcontractor','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">REQUEST FOR QUOTATION (SUPPLIER / SUBCONTRACTOR)</h2><p>To: [Supplier / subcontractor]<br>Address: [Address]</p><p>We are preparing an offer for <b>{{project.title}}</b> for {{entity.name}}. Please quote your best price and delivery for the following, valid for at least 30 days:</p>{{lines.table}}<p>Please include with your quotation: your business permit, BIR registration, product certifications or licenses, warranty terms and delivery lead time. Prices to us are separate from our prices to the procuring entity; this request does not disclose the entity\'s budget.</p><p>Reply to {{project.preparer}} at {{company.email}} by [date].</p><div>{{signatories}}</div>','{\"signatories\":[{\"lead\":\"Requested by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(11,11,1,'Internal bid approval sheet','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">INTERNAL BID APPROVAL SHEET</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project no.</td><td>{{project.no}}</td></tr><tr><td>Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode / law</td><td>{{project.mode}}, {{project.regime}}</td></tr><tr><td>ABC</td><td>{{project.abc}}</td></tr><tr><td>Bid amount</td><td><b>{{project.bid_amount}}</b></td></tr><tr><td>Bid deadline</td><td>{{project.bid_deadline}}</td></tr></tbody></table><h3>Costing and margin (confidential)</h3>{{costing.table}}<h3>Compliance checklist</h3>{{requirements.table}}<h3>Remarks</h3><p>[Risks, assumptions, supplier quotations relied on, payment record of the entity.]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.preparer}}</div><div>Prepared by</div></td><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.checker}}</div><div>Checked by</div></td><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(12,12,1,'Contract review sheet','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">CONTRACT REVIEW SHEET</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b> ({{project.no}})</td></tr><tr><td>Notice of award</td><td>{{project.noa_ref}}, {{project.noa_date}}</td></tr><tr><td>Contract / PO no.</td><td>{{project.contract_ref}}</td></tr><tr><td>Amount awarded</td><td>{{project.contract_amount}}</td></tr><tr><td>Delivery / completion</td><td>{{project.delivery_days}}; due {{project.due_date}}</td></tr><tr><td>Retention</td><td>{{project.retention_pct}}</td></tr><tr><td>Liquidated damages</td><td>{{project.ld_rate}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr><tr><td>Payment terms</td><td>{{project.payment_terms}}</td></tr></tbody></table><h3>Checks</h3><table class=\"ft\"><thead><tr><th>Point</th><th style=\"width:16%\">OK</th><th style=\"width:36%\">Remarks</th></tr></thead><tbody><tr><td>Items, quantities and prices agree with the approved bid</td><td></td><td></td></tr><tr><td>Delivery period and site can be met</td><td></td><td></td></tr><tr><td>Performance security / warranty requirements understood</td><td></td><td></td></tr><tr><td>Billing documents and payment terms understood</td><td></td><td></td></tr><tr><td>Taxes to be withheld by the entity noted</td><td></td><td></td></tr></tbody></table><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.checker}}</div><div>Reviewed by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(13,13,1,'Project handover memo (sales to operations)','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">PROJECT HANDOVER MEMO</h2><p><b>To:</b> {{project.pm}}, Project Manager; Warehouse and Delivery<br><b>From:</b> {{project.preparer}}<br><b>Re:</b> {{project.title}} ({{project.no}})</p><p>The contract below was awarded to the company by {{entity.name}} (notice of award {{project.noa_ref}}, {{project.noa_date}}). Please mobilize as follows:</p>{{lines.contract_table}}<table class=\"ft\"><tbody><tr><td style=\"width:32%\">Delivery site</td><td>{{project.delivery_site}}</td></tr><tr><td>Due</td><td>{{project.due_date}}</td></tr><tr><td>End-user</td><td>{{project.end_user}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr></tbody></table><p>Deliveries are recorded on Deliveries &amp; Acceptance; the signed delivery receipt and the inspection and acceptance report are filed in the Document Vault, as billing depends on them.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.preparer}}</div><div>Handed over by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Received by</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(14,14,1,'Delivery receipt','<div>{{letterhead}}</div><h2 style=\"text-align:center;margin:10px 0 14px\">DELIVERY RECEIPT</h2><table class=\"ft\"><tbody><tr><td style=\"width:24%\">DR no.</td><td><b>{{delivery.no}}</b></td><td style=\"width:20%\">Date</td><td>{{delivery.date}}</td></tr><tr><td>Delivered to</td><td colspan=\"3\">{{entity.name}}, {{delivery.to}}</td></tr><tr><td>Project</td><td colspan=\"3\">{{project.title}} (contract / PO {{project.contract_ref}})</td></tr></tbody></table><p></p>{{delivery.table}}<p>Received the above items in good order and condition, subject to inspection and acceptance by the entity.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Delivered by ({{company.name}})</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{delivery.received_by}} {{delivery.received_position}}</div><div>Received by (name, position, date)</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(15,15,1,'Service / accomplishment report','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">SERVICE / ACCOMPLISHMENT REPORT</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>Period covered: [from] to [to]</p>{{delivery.table}}<h3>Work done</h3><p>[Describe the services rendered / work accomplished, with dates.]</p><h3>Issues and actions</h3><p>[None]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Submitted by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.end_user}}</div><div>Certified by the end-user</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(16,16,1,'Transmittal letter','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">TRANSMITTAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Re: {{project.title}}</b></p><p>We transmit the following documents for your appropriate action:</p><table class=\"ft\"><thead><tr><th style=\"width:6%\">No.</th><th>Document</th><th style=\"width:16%\">Copies</th></tr></thead><tbody><tr><td>1</td><td>[Document]</td><td>1</td></tr><tr><td>2</td><td>[Document]</td><td>1</td></tr></tbody></table><p>Kindly acknowledge receipt by signing below.</p><div>{{signatories}}</div><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Received by (name, position)</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Date and time received</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(17,17,1,'Billing cover letter / request for payment','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Request for payment, {{billing.type}} {{billing.no}} for {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>We respectfully request payment of <b>{{billing.gross}}</b> ({{billing.gross_words}}) for the deliveries / services accepted under {{project.contract_ref}}, as detailed below:</p>{{billing.table}}<p>Enclosed are our sales invoice no. {{billing.si_no}}, the statement of account, and the signed delivery and inspection and acceptance documents.</p><p>Payment may be made by check payable to <b>{{company.name}}</b> or by credit to: {{company.bank_details}}. Kindly furnish us BIR Form 2307 for the taxes withheld.</p><div>{{signatories}}</div>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(18,18,1,'Statement of account','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">STATEMENT OF ACCOUNT</h2><table class=\"ft\"><tbody><tr><td style=\"width:24%\">Bill to</td><td><b>{{entity.name}}</b><br>{{entity.address}}</td></tr><tr><td>Project</td><td>{{project.title}} ({{project.no}})</td></tr><tr><td>Contract / PO</td><td>{{project.contract_ref}}, {{project.contract_amount}}</td></tr></tbody></table><p></p>{{soa.table}}<p>Please make payment to {{company.name}}: {{company.bank_details}}.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>Noted by</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(19,19,1,'Collection follow-up letter','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><p><b>{{entity.treasurer}}</b><br>Treasurer<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Subject: Follow-up of payment, {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>May we respectfully follow up the payment of our billing for the above project, which has been accepted and is now due. Our records show the following:</p>{{soa.table}}<p>Should any document be lacking for the processing of the payment, please let us know so we can provide it at once.</p><div>{{signatories}}</div>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(20,20,1,'Acknowledgment receipt (supplementary)','<div>{{letterhead}}</div><h2 style=\"text-align:center;margin:10px 0 14px\">ACKNOWLEDGMENT RECEIPT</h2><table class=\"ft\"><tbody><tr><td style=\"width:26%\">No.</td><td>{{collection.no}}</td><td style=\"width:16%\">Date</td><td>{{collection.date}}</td></tr></tbody></table><p>Received from <b>{{entity.name}}</b> the amount of <b>{{collection.amount_words}} ({{collection.amount}})</b> by {{collection.reference}}, in payment of billing {{billing.no}} (sales invoice no. {{billing.si_no}}) for {{project.title}}.</p>{{collection.table}}<p style=\"margin-top:14px;font-weight:bold;text-align:center;border:1px solid #000;padding:6px\">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:100%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Received by (cashier / collector)</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(21,21,1,'Contract reconciliation sheet','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">CONTRACT RECONCILIATION SHEET</h2><p><b>{{project.title}}</b> ({{project.no}}), {{entity.name}}</p>{{closeout.table}}<h3>Statement of account</h3>{{soa.table}}<h3>Differences and actions</h3><p>[None]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Checked by (accounting)</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(22,22,1,'Project completion and closeout summary','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><h2 style=\"text-align:center;margin:10px 0 14px\">PROJECT COMPLETION AND CLOSEOUT SUMMARY</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b> ({{project.no}})</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Contract</td><td>{{project.contract_ref}}, NOA {{project.noa_ref}}</td></tr><tr><td>Warranty until</td><td>{{project.warranty_until}}</td></tr></tbody></table><h3>Final figures</h3>{{closeout.table}}<h3>Papers on file</h3>{{requirements.all_table}}<h3>Lessons learned</h3><p>[What went well, what to change next time.]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(23,23,1,'Contract agreement: supply and delivery of goods (proforma)','<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">Supply and Delivery of Goods</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall supply and deliver the goods as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The SUPPLIER shall deliver the goods to {{project.delivery_site}} within {{project.delivery_days}} from receipt of the purchase order / notice to proceed, on or before {{project.due_date}}.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. Performance security.</b> Where required by the contract documents, the SUPPLIER shall post the performance security in the form and amount stated there, valid until final acceptance.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(24,24,1,'Contract agreement: general services (proforma)','<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">General Services</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall render the services as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The services shall be rendered at {{project.delivery_site}} from {{project.start_date}} for {{project.delivery_days}}, under the schedule in the terms of reference. Each period\'s services are certified by the end-user ({{project.end_user}}) through the accomplishment report.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. Personnel.</b> The SUPPLIER employs and pays its personnel, complies with labor, social security and safety laws, and remains solely responsible for them; no employer-employee relationship arises between the ENTITY and the SUPPLIER\'s personnel.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(25,25,1,'Contract agreement: infrastructure works (proforma)','<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">Infrastructure Works</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall carry out and complete the works as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The works at {{project.delivery_site}} shall start upon the notice to proceed dated {{project.ntp_date}} and be completed within {{project.delivery_days}}, on or before {{project.due_date}}. Progress billings follow the accomplishment certified by the ENTITY\'s engineer.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. License, securities and insurance.</b> The CONTRACTOR (the SUPPLIER) holds a valid PCAB license for the category and size of this project, posts the performance security required by the contract documents, and keeps the works and its workers insured as the contract documents require. It is liable for defects of the works as provided by law.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(26,26,1,'Subcontract agreement (company and subcontractor)','<h2 style=\"text-align:center\">SUBCONTRACT AGREEMENT</h2><p>This Agreement is made on {{date.today}} between <b>{{company.name}}</b>, represented by {{signatory.name}}, {{signatory.position}} (the \"CONTRACTOR\"), and <b>[Subcontractor]</b>, [address, TIN], represented by [name, position] (the \"SUBCONTRACTOR\").</p><p><b>1. Main contract.</b> The CONTRACTOR holds the contract for <b>{{project.title}}</b> with {{entity.name}} (NOA {{project.noa_ref}}). Subcontracting is allowed only as the main contract and the procurement rules allow, and the CONTRACTOR remains responsible to the entity for the whole contract.</p><p><b>2. Scope.</b> [Part of the work subcontracted, with quantities.]</p><p><b>3. Price and payment.</b> [Amount], paid within [days] after the entity accepts and pays the corresponding portion, less the same retention and deductions the entity applies to that portion.</p><p><b>4. Schedule and quality.</b> The SUBCONTRACTOR follows the specifications, schedule and inspection of the main contract and corrects rejected work at its cost.</p><p><b>5. Permits, licenses and personnel.</b> The SUBCONTRACTOR holds the licenses its work requires and is solely responsible for its personnel and their compliance with labor and safety laws.</p><p><b>6. Liquidated damages.</b> Delay caused by the SUBCONTRACTOR is charged to it at the rate the entity applies ({{project.ld_rate}}).</p><p><b>7. Confidentiality.</b> Prices and terms of the main contract are confidential.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the CONTRACTOR</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>For the SUBCONTRACTOR</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(27,27,1,'Supply agreement with a supplier (back-to-back)','<h2 style=\"text-align:center\">SUPPLY AGREEMENT</h2><p>Between <b>{{company.name}}</b> (the \"BUYER\"), represented by {{signatory.name}}, and <b>[Supplier]</b> (the \"SELLER\"), dated {{date.today}}.</p><p><b>1. Goods.</b> The SELLER supplies the following for the BUYER\'s contract {{project.no}} ({{project.title}}):</p><p>[Items, quantities and prices to the BUYER]</p><p><b>2. Delivery.</b> To [warehouse / site] on or before [date], allowing the BUYER to deliver to {{entity.name}} by {{project.due_date}}.</p><p><b>3. Quality and warranty.</b> The goods meet the specifications of the main contract; the SELLER replaces goods the entity rejects and honors a warranty of at least {{project.warranty_months}}.</p><p><b>4. Payment.</b> [Terms]. The BUYER withholds the taxes required by law.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the BUYER</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>For the SELLER</div></td></tr></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(28,28,1,'Purchase order acceptance / conforme letter','<div>{{letterhead}}</div><p style=\"text-align:right;margin:0 0 6px\">{{doc.no}}<br>{{date.today}}</p><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Acceptance of Purchase Order / Notice of Award, {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>We acknowledge receipt of your Notice of Award {{project.noa_ref}} dated {{project.noa_date}} and Purchase Order {{project.po_no}} for <b>{{project.contract_amount}}</b>, and confirm our acceptance (conforme) of their terms.</p><p>We will deliver to {{project.delivery_site}} within {{project.delivery_days}}, on or before {{project.due_date}}. Our contact person for this contract is {{project.pm}}.</p><div>{{signatories}}</div>','{\"signatories\":[{\"lead\":\"Conforme:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(29,29,1,'Omnibus sworn statement (prescribed form)','<h2 style=\"text-align:center\">OMNIBUS SWORN STATEMENT</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr></tbody></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(30,30,1,'Bid securing declaration (prescribed form)','<h2 style=\"text-align:center\">BID SECURING DECLARATION</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr><tr><td>ABC</td><td>{{project.abc}}</td></tr></tbody></table>',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(31,31,1,'Bid form / price quotation form (prescribed by the entity)','<h2 style=\"text-align:center\">BID FORM / PRICE QUOTATION FORM</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr><tr><td>Total bid</td><td>{{project.bid_amount}} ({{project.bid_amount_words}})</td></tr></tbody></table>{{lines.table}}',NULL,NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(32,32,1,'Transaction form','<table class=\"fx fx-frame\" style=\"width:100%\"><tbody><tr><td style=\"padding:6px 10px\"><b>Series No:</b> <span class=\"fx-series\">{{txn.series}}</span></td></tr><tr><td class=\"fx-title\" style=\"text-align:center\">TRANSACTION FORM</td></tr><tr><td style=\"padding:8px 10px 10px\"><table class=\"fx-lines\" style=\"width:100%\"><tbody><tr><td class=\"lbl\">DATE:</td><td class=\"val\">{{txn.date}}</td></tr><tr><td class=\"lbl\">TRADENAME:</td><td class=\"val\">{{txn.tradename}}</td></tr><tr><td class=\"lbl\" style=\"vertical-align:middle\">TYPE:</td><td>{{txn.types}}</td></tr><tr><td class=\"lbl\">BARANGAY:</td><td class=\"val\">{{txn.barangay}}</td></tr><tr><td class=\"lbl\">CHEQUE NUMBER:</td><td class=\"val\">{{txn.cheque_no}}</td></tr><tr><td class=\"lbl\">GROSS AMOUNT:</td><td class=\"val\">{{txn.gross}}</td></tr><tr><td class=\"lbl\">WITHHOLDING TAX:</td><td class=\"val\">{{txn.wht}}</td></tr><tr><td class=\"lbl\">CHEQUE AMOUNT:</td><td class=\"val\">{{txn.cheque_amount}}</td></tr><tr data-section=\"profit\" title=\"Profit\"><td class=\"lbl\">PROFIT:</td><td class=\"val\">{{txn.profit}}</td></tr><tr><td class=\"lbl\">REQUESTOR:</td><td class=\"val\">{{txn.requestor}}</td></tr><tr><td class=\"lbl\">INSPECTOR:</td><td class=\"val\">{{txn.inspector}}</td></tr><tr><td class=\"lbl\">PURPOSE:</td><td class=\"val\">{{txn.purpose}}</td></tr></tbody></table></td></tr><tr><td style=\"padding:6px 10px\"><table class=\"fx-lines\" style=\"width:100%\"><tbody><tr><td class=\"lbl\" style=\"width:48%;text-align:left\">PREPARED / RECEIVED BY:</td><td class=\"val\">{{txn.prepared_by}}</td></tr></tbody></table></td></tr></tbody></table>','{\"margin_top\":7,\"margin_right\":7,\"margin_bottom\":7,\"margin_left\":7,\"line_height\":1.25,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":12.5,\"lh_style\":\"none\",\"signatories\":[]}','{\"paper\":\"HalfLetter\",\"orientation\":\"portrait\",\"width_mm\":\"\",\"height_mm\":\"\",\"offset_x\":0,\"offset_y\":0,\"font\":\"Arial\",\"font_size\":9.5,\"fields\":[{\"key\":\"txn.series\",\"text\":\"\",\"x\":42,\"y\":5,\"w\":50,\"size\":12,\"bold\":1,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.date\",\"text\":\"\",\"x\":43,\"y\":23,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.tradename\",\"text\":\"\",\"x\":43,\"y\":29.5,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.mark_cash_out\",\"text\":\"\",\"x\":45.5,\"y\":35,\"w\":5,\"size\":10,\"bold\":1,\"align\":\"center\",\"upper\":0},{\"key\":\"txn.mark_kaliwaan\",\"text\":\"\",\"x\":45.5,\"y\":42,\"w\":5,\"size\":10,\"bold\":1,\"align\":\"center\",\"upper\":0},{\"key\":\"txn.mark_purchases\",\"text\":\"\",\"x\":45.5,\"y\":49,\"w\":5,\"size\":10,\"bold\":1,\"align\":\"center\",\"upper\":0},{\"key\":\"txn.mark_construction\",\"text\":\"\",\"x\":45.5,\"y\":56,\"w\":5,\"size\":10,\"bold\":1,\"align\":\"center\",\"upper\":0},{\"key\":\"txn.barangay\",\"text\":\"\",\"x\":44,\"y\":64,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.cheque_no\",\"text\":\"\",\"x\":44,\"y\":69.5,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.gross\",\"text\":\"\",\"x\":44,\"y\":75,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.wht\",\"text\":\"\",\"x\":44,\"y\":80.5,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.cheque_amount\",\"text\":\"\",\"x\":44,\"y\":86,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.profit\",\"text\":\"\",\"x\":44,\"y\":100,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.requestor\",\"text\":\"\",\"x\":44,\"y\":122,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.inspector\",\"text\":\"\",\"x\":44,\"y\":127.5,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.purpose\",\"text\":\"\",\"x\":44,\"y\":133,\"w\":52,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0},{\"key\":\"txn.prepared_by\",\"text\":\"\",\"x\":46,\"y\":148,\"w\":48,\"size\":0,\"bold\":0,\"align\":\"left\",\"upper\":0}],\"tables\":[]}','Supplied with the program',NULL,'2026-11-23 19:54:45'),
(33,33,1,'Purchase request (PR)','<table class=\"fx\" style=\"width:100%\"><tbody><tr><td colspan=\"2\" class=\"fx-title\" style=\"text-align:center;padding:12px\">PURCHASE REQUEST</td></tr><tr><td style=\"width:52%\">Barangay: <b>{{entity.short}}</b><br>City/Municipality: {{entity.city}}</td><td>P.R. No. {{pr.no}}<br>Date: {{pr.date}}</td></tr><tr><td colspan=\"2\" style=\"text-align:center;font-weight:bold\">REQUISITION</td></tr><tr><td colspan=\"2\" class=\"fx-slot\">{{pr.items}}</td></tr><tr><td colspan=\"2\"><b>Purpose : {{project.purpose}}</b></td></tr><tr><td style=\"height:140px;vertical-align:top\">Requested by:<div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{project.requestor}}</div><div class=\"sg-cap\">Signature over Printed Name<br>Requisitioner</div></div><div class=\"fx-dateline\">Date</div></td><td style=\"vertical-align:top\">Approved by:<div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{entity.head_name}}</div><div class=\"sg-cap\">Signature over Printed Name<br>Punong Barangay</div></div><div class=\"fx-dateline\">Date</div></td></tr></tbody></table>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"blank_rows\":3,\"lh_style\":\"none\",\"signatories\":[]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(34,34,1,'Purchase order (PO)','<table class=\"fx\" style=\"width:100%\"><tbody><tr><td colspan=\"2\" class=\"fx-title\" style=\"text-align:center;padding:12px\">PURCHASE ORDER</td></tr><tr><td colspan=\"2\" style=\"padding:0\"><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:50%\">Barangay: <b>{{entity.short_upper}}</b><br>Tel. No. {{entity.phone}}</td><td>City/Municipality: {{entity.city}}<br>Province: {{entity.province}}</td></tr></tbody></table></td></tr><tr><td style=\"width:52%\">Supplier: <b>{{company.name}}</b></td><td>P.O. No. {{po.no}}</td></tr><tr><td style=\"vertical-align:top\">Address: {{company.address}}<br>TIN:   {{company.tin}}</td><td>Date {{po.date}}<br>Mode of procurement<div style=\"margin-top:6px\">{{po.modes}}</div></td></tr><tr><td colspan=\"2\">Gentlemen:<br><span style=\"padding-left:28px\">Please deliver to this office the following articles subject to the terms and conditions contained herein</span></td></tr><tr><td>Place of Delivery: {{po.place}}<br>Date of Delivery: {{po.delivery_date}}</td><td>Delivery term: {{po.delivery_term}}<br>Payment term: {{po.payment_term}}</td></tr><tr><td colspan=\"2\" class=\"fx-slot\">{{po.items}}</td></tr><tr><td colspan=\"2\"><b>**{{po.total_words}} Only. **</b></td></tr><tr><td colspan=\"2\" style=\"padding:6px 10px 14px\"><div data-section=\"penalty\" title=\"Penalty clause\">    In case of failure to make full delivery within the time specified above, a penalty of one-tenth (1/10) of one percent for everyday of delay shall be imposed.</div><table class=\"fx-plain\" style=\"width:100%;margin-top:14px\"><tbody><tr><td style=\"width:55%\"></td><td>Very truly yours,<div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{entity.head_name}}</div><div class=\"sg-cap\">Signature Over Printed Name<br>Punong Barangay</div></div></td></tr></tbody></table></td></tr><tr><td style=\"vertical-align:top;height:150px\">Conforme:<div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{company.name_upper}}</div><div class=\"sg-cap\">Signature over Printed Name<br>Supplier</div></div><div class=\"fx-dateline\">Date</div></td><td style=\"vertical-align:top\" data-section=\"appropriations\" title=\"Existence of available appropriations\">Existence of Available Appropriations of<div style=\"margin:6px 0\">( ____________________________________ )</div><div>(amount in words) ( figure )</div><div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{entity.appropriations_chair}}</div><div class=\"sg-cap\">Signature over Printed Name<br>Chairman, Committee on Appropriations</div></div><div>Date:</div></td></tr></tbody></table>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9,\"table_size\":9,\"title_size\":13,\"blank_rows\":2,\"lh_style\":\"none\",\"signatories\":[]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(35,35,1,'Inspection and acceptance report (IAR)','<div class=\"fx-title\" style=\"text-align:center;margin-bottom:8px\">INSPECTION AND ACCEPTANCE REPORT</div><table class=\"fx\" style=\"width:100%\"><tbody><tr><td style=\"width:55%\">Entity Name: <b>{{entity.name}}</b></td><td>Fund Cluster: {{project.fund}}</td></tr><tr><td>Supplier: <b>{{company.name}}</b><br>PO No./Date: {{po.no}} / {{po.date}}</td><td><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:55%;padding:0\">IAR No.: {{iar.no}}<br>Date: {{iar.date}}</td><td style=\"padding:0\">RIS No.: {{ris.no}}<br>Date: {{ris.date}}</td></tr><tr><td colspan=\"2\" style=\"padding:0\">Invoice No.: {{iar.invoice_no}}<br>Date: {{iar.invoice_date}}</td></tr></tbody></table></td></tr><tr><td colspan=\"2\" class=\"fx-slot\">{{iar.items}}</td></tr><tr><td style=\"text-align:center;font-weight:bold\">INSPECTION</td><td style=\"text-align:center;font-weight:bold\">ACCEPTANCE</td></tr><tr><td style=\"vertical-align:top;height:170px\">Date Inspected: {{iar.date}}<p style=\"margin-top:10px\"><span class=\"fx-chk is-on\"></span> Inspected, verified and found in order as to quantity and specifications</p><div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{project.inspector}}</div><div class=\"sg-cap\">Inspection Officer / Inspection Committee</div></div></td><td style=\"vertical-align:top\">Date Received: {{iar.date}}<p style=\"margin-top:10px\"><span class=\"fx-chk is-on\"></span> Complete<br><span class=\"fx-chk\"></span> Partial (pls. specify quantity)</p><div class=\"fx-sig\" style=\"text-align:center\"><div class=\"sg-name\">{{entity.treasurer}}</div><div class=\"sg-cap\">Supply and/or Property Custodian</div></div></td></tr></tbody></table>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"blank_rows\":3,\"lh_style\":\"none\",\"signatories\":[]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(36,36,1,'Requisition and issue slip (RIS)','<table class=\"fx\" style=\"width:100%\"><tbody><tr><td colspan=\"6\" class=\"fx-title\" style=\"text-align:center;padding:12px\">REQUISITION AND ISSUE SLIP</td></tr><tr><td style=\"width:12%\">Barangay:<br>Tel No.:</td><td style=\"width:24%\"><b>{{entity.short_upper}}</b><br>{{entity.phone}}</td><td style=\"width:20%\">City/Municipality:<br>Province:</td><td style=\"width:20%\">{{entity.city}}<br>{{entity.province}}</td><td style=\"width:9%\">RIS No.<br>Date:</td><td>{{ris.no}}<br>{{ris.date}}</td></tr><tr><td colspan=\"6\" class=\"fx-slot\">{{ris.items}}</td></tr><tr><td colspan=\"6\"><b>Purpose : {{project.purpose}}</b></td></tr><tr><td colspan=\"6\" style=\"padding:0\"><table class=\"fx fx-inner\" style=\"width:100%\"><tbody><tr><td style=\"width:15%;vertical-align:bottom;line-height:1.9\">Signature<br>Printed Name<br>Designation<br>Date</td><td style=\"vertical-align:top;height:110px\">Requested by:<div style=\"margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto\"></div><div style=\"text-align:center;font-weight:bold;text-decoration:underline\">{{project.requestor}}</div><div style=\"text-align:center\">Requisitioner</div></td><td style=\"vertical-align:top;height:110px\">Approved for Issuance:<div style=\"margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto\"></div><div style=\"text-align:center;font-weight:bold;text-decoration:underline\">{{entity.head_name}}</div><div style=\"text-align:center\">Punong Barangay</div></td><td style=\"vertical-align:top;height:110px\">Received by:<div style=\"margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto\"></div><div style=\"text-align:center;font-weight:bold;text-decoration:underline\">{{project.requestor}}</div><div style=\"text-align:center\">Requisitioner</div></td><td style=\"vertical-align:top;height:110px\">Issued by:<div style=\"margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto\"></div><div style=\"text-align:center;font-weight:bold;text-decoration:underline\">{{entity.treasurer}}</div><div style=\"text-align:center\">Barangay Treasurer</div></td></tr></tbody></table></td></tr></tbody></table>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"blank_rows\":4,\"lh_style\":\"none\",\"signatories\":[]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(37,37,1,'Request for quotation (canvass form)','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:12px 0 8px\">REQUEST FOR QUOTATION</div><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:56%\"></td><td>DATE: <u>{{rfq.date}}</u><br>QUOTATION NO.: <u>{{pr.no}}</u></td></tr></tbody></table><p style=\"margin:12px 0 0;text-align:justify\">Please quote your lowest price on the item/s listed below, subject to the General Conditions stated, stating the shortest time of delivery and submit your quotation duly signed by your representative not later than ____________________, __________ thru email: {{entity.email}} or personally at The Secretariat, Bids and Awards Committee, {{entity.short}} Barangay Hall, {{entity.short}}, {{entity.city}}.</p><div>{{signatories}}</div><div data-section=\"conditions\" title=\"General conditions\"><p style=\"margin:10px 0 2px\">GENERAL CONDITIONS:</p><ol style=\"margin:0 0 6px;padding-left:2em\"><li>All entries must be handwritten / typewritten legibly.</li><li>Approved Budget of the Contract (ABC) is <b>PHP {{project.abc_plain}}</b></li><li>Delivery period is within {{rfq.delivery_words}}.</li><li>Warranty shall be for a period of six (6) months for supplies and materials, one (1) year for equipment, from date of acceptance by the Procuring Entity.</li><li>Price shall be inclusive of VAT.</li><li>Please submit the following documents:<ol type=\"a\" style=\"margin:0;padding-left:2.6em\"><li>PhilGeps Registration Number</li><li>DTI/SEC Registration</li><li>Mayor\'s Permit</li><li>BIR Form No. 2303</li><li>Tax Clearance, if available</li></ol></li></ol></div>{{rfq.items}}<p style=\"margin:6px 0 0\">After having carefully read and accepted your General Conditions, I/We quote you on the item at prices noted above.</p><table class=\"fx-plain\" style=\"width:100%;margin-top:28px\"><tbody><tr><td style=\"width:56%\"></td><td><div style=\"border-top:1px solid #000;text-align:center\">Printed Name / Signature</div></td></tr><tr><td style=\"padding-top:28px\"><div style=\"border-top:1px solid #000;width:88%\">NAME OF ESTABLISHMENT</div></td><td style=\"padding-top:28px\"><div style=\"border-top:1px solid #000\">DATE</div></td></tr></tbody></table>','{\"margin_top\":10,\"margin_right\":15,\"margin_bottom\":10,\"margin_left\":15,\"line_height\":1.25,\"cell_pad\":2,\"font_size\":10,\"table_size\":9.5,\"title_size\":13,\"blank_rows\":6,\"sig_top\":6,\"sig_space\":8,\"signatories\":[{\"lead\":\"\",\"name\":\"{{entity.bac_chair_upper}}\",\"pos\":\"BAC Chairman\",\"extra\":\"\",\"align\":\"half\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"own\",\"lh_left\":\"seal\",\"lh_right\":\"pic_a\",\"lh_lines\":\"**REPUBLIC OF THE PHILIPPINES**\\nProvince of {{entity.province}}\\n{{entity.lgu_line}}\\n{{entity.title}}\\n**BIDS AND AWARD COMMITTEE**\",\"lh_align\":\"center\",\"lh_font\":\"\",\"lh_size\":10.5,\"lh_big\":15,\"lh_pic_mm\":24,\"lh_box\":0,\"lh_box_bg\":\"#ffffff\",\"lh_box_fg\":\"#111111\",\"lh_rule\":0,\"lh_rule_color\":\"#111111\",\"lh_space\":4,\"lh_pic_a\":\"\",\"lh_pic_b\":\"\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(38,38,1,'Abstract of bids as quoted (abstract of canvass)','<table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:58%\"></td><td>P.R No. : {{pr.no}}<br>Name of Project: {{project.title}}<br>Location of the Project: {{entity.location}}</td></tr></tbody></table><div class=\"fx-title\" style=\"text-align:center;margin:18px 0 4px;text-decoration:underline\">ABSTRACT OF BIDS AS QUOTED</div><p style=\"text-align:right\">( {{abstract.date}} )</p><p><b>Project Name: {{project.title}}<br>Project Location: {{entity.name}}, {{entity.city}}<br>Implementing Office: {{project.end_user}}</b></p><p style=\"margin:12px 0 2px\"><b>Approved Budget for the Contract: {{project.abc}}</b></p>{{abstract.table}}<div style=\"margin-top:34px\">{{entity.bac_signatures}}</div><div>{{signatories}}</div>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":10,\"table_size\":9,\"title_size\":13,\"lh_style\":\"none\",\"sig_top\":8,\"signatories\":[{\"lead\":\"Approved by:\",\"name\":\"{{entity.head_name_upper}}\",\"pos\":\"PUNONG BARANGAY\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(39,39,1,'Disbursement voucher (DV)','<table class=\"fx\" style=\"width:100%\"><tbody><tr><td colspan=\"2\" class=\"fx-title\" style=\"text-align:center;padding:10px\">DISBURSEMENT VOUCHER</td><td style=\"width:22%;vertical-align:bottom\">DV No. {{dv.no}}</td></tr><tr><td style=\"width:46%\">Barangay: <b>{{entity.short}}</b></td><td>City/Municipality: {{entity.city}}</td><td>Date: {{dv.date}}</td></tr><tr><td>Payee: <b>{{company.name}}</b></td><td>Province: {{entity.province}}</td><td>Fund: {{dv.fund}}</td></tr><tr><td>Address: {{company.address}}</td><td>TIN: {{company.tin}}</td><td></td></tr><tr><td colspan=\"3\" style=\"text-align:center;font-weight:bold\">PARTICULARS</td></tr><tr><td colspan=\"3\" style=\"padding:10px 14px\"><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:70%\">To Withdraw Payment for the <b>{{dv.particulars}}</b><br>per attached supporting documents in the amount of ------------</td><td style=\"text-align:right;font-size:12pt;font-weight:bold;vertical-align:bottom\">{{dv.gross}}</td></tr><tr data-section=\"taxes\" title=\"Taxes withheld\"><td style=\"padding-top:16px\">{{dv.taxes}}</td><td style=\"text-align:right;font-size:12pt;font-weight:bold;vertical-align:bottom\">{{dv.net}}</td></tr><tr><td colspan=\"2\" style=\"padding:18px 0 0 18px\">Amount in words:<br><b>{{dv.net_words_upper}}</b></td></tr><tr data-section=\"papers\" title=\"Supporting papers attached\"><td colspan=\"2\" style=\"padding:18px 0 0 18px\">Supporting Papers Attached:{{dv.papers}}</td></tr><tr><td></td><td style=\"text-align:right;font-size:12pt\"><b>TOTAL:   {{dv.net}}</b></td></tr></tbody></table></td></tr><tr><td colspan=\"3\" style=\"padding:0\"><table class=\"fx fx-inner\" style=\"width:100%\"><tbody><tr><td style=\"width:34%;vertical-align:top\"><span class=\"fx-tag\">A</span> <b>Certified:</b><br>Existence of available appropriations for the charges/expenses indicated above<div style=\"margin-top:26px\">Signature:<br>Printed Name: <b><u>{{entity.appropriations_chair_upper}}</u></b><br>Position: Chairman, Committee on Appropriations<br><b>Date:</b></div></td><td style=\"width:33%;vertical-align:top\"><span class=\"fx-tag\">B</span> <b>Certified:</b><br>Funds (Cash) Available:<br><br><div style=\"margin-top:26px\">Signature:<br>Printed Name: <b><u>{{entity.treasurer_upper}}</u></b><br>Position: Barangay Treasurer<br><b>Date:</b></div></td><td style=\"vertical-align:top\"><span class=\"fx-tag\">C</span> <b>Certified:</b><br>As to validity, propriety, and legality of claim<br>Approved: FOR PAYMENT<div style=\"margin-top:26px\">Signature:<br>Printed Name: <b><u>{{entity.head_name_upper}}</u></b><br>Position: Barangay Captain<br><b>Date:</b></div></td></tr></tbody></table></td></tr><tr><td colspan=\"3\" style=\"vertical-align:top;height:110px\"><span class=\"fx-tag\">D</span> <b>Received Payment:</b><table class=\"fx-plain\" style=\"width:100%;margin-top:16px\"><tbody><tr><td style=\"width:50%;padding-left:40px\"><b><u>{{company.name_upper}}</u></b><br>Signature Over Printed Name</td><td>Check No. <u>{{dv.check_no}}</u><br>Bank Name: <u>{{dv.bank}}</u><br>OR Number: <u>{{dv.or_no}}</u></td><td style=\"vertical-align:middle\">Date: __________</td></tr><tr><td style=\"padding-top:10px\">Date: __________</td><td></td><td></td></tr></tbody></table></td></tr><tr><td colspan=\"3\" style=\"padding:0\" data-section=\"entries\" title=\"E. Accounting entries\"><table class=\"fx fx-inner\" style=\"width:100%\"><tbody><tr><td colspan=\"4\"><b>E. Accounting Entries:</b></td></tr><tr><th style=\"width:36%\">Account</th><th style=\"width:22%\">Account Code</th><th style=\"width:21%\">Debit</th><th>Credit</th></tr><tr><td> </td><td></td><td></td><td></td></tr><tr><td> </td><td></td><td></td><td></td></tr><tr><td> </td><td></td><td></td><td></td></tr><tr><td> </td><td></td><td></td><td></td></tr><tr><td style=\"vertical-align:top;height:150px\"></td><td colspan=\"3\" style=\"vertical-align:top\">Prepared By:<table class=\"fx-plain\" style=\"width:100%;margin-top:26px\"><tbody><tr><td style=\"width:62%;text-align:center\"><div style=\"border-top:1px solid #000;width:70%;margin:0 auto\"></div>{{entity.bookkeeper}}<br>Barangay Bookkeeper</td><td style=\"text-align:center\"><div style=\"border-top:1px solid #000;width:70%;margin:0 auto\"></div>Date</td></tr></tbody></table><div style=\"margin-top:12px\">Approved By:</div><table class=\"fx-plain\" style=\"width:100%;margin-top:22px\"><tbody><tr><td style=\"width:62%;text-align:center\"><div style=\"border-top:1px solid #000;width:70%;margin:0 auto\"></div>{{entity.city_accountant}}<br>City/Municipal Accountant</td><td style=\"text-align:center\"><div style=\"border-top:1px solid #000;width:70%;margin:0 auto\"></div>Date</td></tr></tbody></table></td></tr></tbody></table></td></tr></tbody></table>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9,\"table_size\":8.5,\"title_size\":13,\"lh_style\":\"none\",\"signatories\":[]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(40,40,1,'Transmittal of documents (SOP)','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:6px 0 10px\">TRANSMITTAL</div><table class=\"fx-plain\" style=\"width:100%\"><tbody><tr><td style=\"width:60%\">To: <b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</td><td style=\"vertical-align:top\">Transmittal No.: <b>{{transmittal.no}}</b><br>Date: {{transmittal.date}}<br>Transaction: {{project.txn_form_no}}<br>P.O. No.: {{po.no}}</td></tr></tbody></table><p style=\"margin-top:12px\">We are transmitting herewith the following documents for <b>{{project.title}}</b>:</p>{{sop.papers_table}}<p>Kindly acknowledge receipt by signing below and returning a copy of this transmittal.</p><div>{{signatories}}</div>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":8,\"signatories\":[{\"lead\":\"Released by:\",\"name\":\"{{transmittal.released_by}}\",\"pos\":\"{{company.name}}\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"Received by:\",\"name\":\"\",\"pos\":\"Signature over Printed Name \\/ Date\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":1,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"company\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(41,41,1,'Bidding form (details of the bidding)','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:6px 0 10px\">BIDDING FORM</div><table class=\"fx\" style=\"width:100%\"><tbody><tr><td style=\"width:32%\">Series / project no.</td><td>{{project.no}}   {{project.txn_form_no}}</td></tr><tr><td>Procuring entity</td><td><b>{{entity.name}}</b>, {{entity.city}}, {{entity.province}}</td></tr><tr><td>Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Pre-bid conference</td><td>{{project.prebid}}</td></tr><tr><td>Bid deadline / opening</td><td>{{project.bid_deadline}}</td></tr><tr><td>Requestor / end-user</td><td>{{project.requestor}} {{project.end_user}}</td></tr><tr><td>Inspector</td><td>{{project.inspector}}</td></tr><tr><td>Submission requirements</td><td>{{entity.requirement_notes}}</td></tr><tr><td>Specifications</td><td>{{project.specs}}</td></tr><tr><td>Sales representative</td><td>{{project.sales_rep}}</td></tr></tbody></table>{{lines.table}}','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"signatories\":[],\"lh_style\":\"company\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(42,42,1,'Invitation to bid (ITB)','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:12px 0\">INVITATION TO BID</div><p style=\"text-align:center\"><b>{{project.title}}</b><br>{{project.reference_no}}</p><ol><li>The {{entity.name}}, through its approved budget, intends to apply the sum of <b>{{project.abc_words}} ({{project.abc}})</b> being the Approved Budget for the Contract (ABC) to payments under the contract for {{project.title}}. Bids received in excess of the ABC shall be automatically rejected at bid opening.</li><li>The {{entity.name}} now invites bids for the above procurement project. Delivery of the goods is required within {{project.delivery_days}}. Bidders should have completed a contract similar to the project.</li><li>Bidding will be conducted through open competitive bidding procedures using a non-discretionary \"pass/fail\" criterion as specified in the applicable procurement law and its implementing rules.</li><li>A pre-bid conference shall be held on {{project.prebid}} at {{entity.address}}.</li><li>Bids must be duly received by the BAC Secretariat at {{entity.address}} on or before {{project.bid_deadline}}. Late bids shall not be accepted.</li><li>The {{entity.name}} reserves the right to reject any and all bids, declare a failure of bidding, or not award the contract at any time prior to contract award, without thereby incurring any liability to the affected bidder or bidders.</li></ol><div>{{signatories}}</div>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":12,\"signatories\":[{\"lead\":\"\",\"name\":\"{{entity.bac_chair_upper}}\",\"pos\":\"BAC Chairman\",\"extra\":\"\",\"align\":\"half\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(43,43,1,'Letter to COA','<div>{{letterhead}}</div><p style=\"text-align:right\">{{date.today}}</p><p><b>The Audit Team Leader</b><br>Commission on Audit<br>{{entity.city}}, {{entity.province}}</p><p><b>Subject: Invitation to observe the bidding for {{project.title}}</b></p><p>Sir/Madam:</p><p>The Bids and Awards Committee of {{entity.name}} respectfully informs your office of the procurement of <b>{{project.title}}</b> ({{project.reference_no}}) with an Approved Budget for the Contract of {{project.abc}}.</p><p>The pre-bid conference is scheduled on {{project.prebid}} and the opening of bids on {{project.bid_deadline}}, at {{entity.address}}. We would be glad to have a representative of your office attend as observer.</p><p>Thank you.</p><div>{{signatories}}</div>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":6,\"signatories\":[{\"lead\":\"Very truly yours,\",\"name\":\"{{entity.bac_chair_upper}}\",\"pos\":\"BAC Chairman\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(44,44,1,'Approved budget for the contract (ABC)','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:12px 0\">APPROVED BUDGET FOR THE CONTRACT</div><table class=\"fx\" style=\"width:100%\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference</td><td>{{project.reference_no}}</td></tr><tr><td>Source of funds</td><td>{{project.funding_source}}</td></tr><tr><td>Approved budget</td><td><b>{{project.abc}}</b> ({{project.abc_words}})</td></tr></tbody></table>{{pr.items}}<p>This is to certify that the above amount is the approved budget for the contract of the project.</p><div>{{signatories}}</div>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":12,\"signatories\":[{\"lead\":\"\",\"name\":\"{{entity.treasurer_upper}}\",\"pos\":\"Barangay Treasurer\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"{{entity.head_name_upper}}\",\"pos\":\"Punong Barangay\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(45,45,1,'Special power of attorney (SPA)','<div class=\"fx-title\" style=\"text-align:center;margin:6px 0 14px\">SPECIAL POWER OF ATTORNEY</div><p><b>KNOW ALL MEN BY THESE PRESENTS:</b></p><p>I, <b>{{signatory.name}}</b>, of legal age, Filipino, {{signatory.position}} of <b>{{company.name}}</b>, with business address at {{company.address}}, do hereby name, constitute and appoint <b>{{project.submitter}}</b>, of legal age, as my true and lawful attorney-in-fact, for me and in my name, place and stead, to do and perform the following acts:</p><ol><li>To attend the pre-bid conference and the opening of bids of <b>{{project.title}}</b> ({{project.reference_no}}) of the {{entity.name}};</li><li>To submit, sign and receive all documents relative to the said bidding, including the bid, clarifications and notices;</li><li>To do all acts necessary and incidental to the foregoing.</li></ol><p>HEREBY GIVING AND GRANTING unto my said attorney-in-fact full power and authority to do and perform the acts above, and hereby ratifying all that my said attorney-in-fact shall lawfully do by virtue hereof.</p><p>IN WITNESS WHEREOF, I have hereunto set my hand this ____ day of __________ at __________.</p><div>{{signatories}}</div><p style=\"margin-top:22px\"><b>ACKNOWLEDGMENT</b></p><p>[Notarial acknowledgment]</p>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"lh_style\":\"none\",\"sig_top\":12,\"signatories\":[{\"lead\":\"\",\"name\":\"{{signatory.name}}\",\"pos\":\"Principal\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"{{project.submitter}}\",\"pos\":\"Attorney-in-fact\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(46,46,1,'Statement of all ongoing government and private contracts','<p style=\"margin:0;line-height:1.3\"><i>Procuring Entity:</i> <b>{{entity.title}}</b><br><i>Name of the Project:</i> <b>{{project.title}}</b><br><i>Location of the Project:</i> <b>{{project.location}}</b></p><p style=\"margin:8px 0 6px\"><span class=\"fx-hl\" style=\"font-size:14pt;font-weight:bold\">Statement of all Ongoing Government &amp; Private Contracts including contracts awarded but not yet started</span></p><table class=\"fx-plain fx-kv\" style=\"margin:4px 0 6px\"><tbody><tr><td class=\"k\">Business Name</td><td>: <u>{{company.name}}</u></td></tr><tr><td class=\"k\">Business Address</td><td>: <u>{{company.address}}</u></td></tr></tbody></table><div style=\"margin-top:6px\">{{contracts.ongoing_table}}</div><p style=\"margin:6px 0 0\">Note: This statement shall be supported with:</p><ol style=\"margin:0;padding-left:2.4em\"><li>Purchase Order / Notice to Proceed</li><li>Notice of Award</li></ol><table class=\"fx-plain fx-kv\" style=\"margin-top:22px\"><tbody><tr><td class=\"k\" style=\"width:110px\">Submitted by</td><td>: <b><u>{{signatory.name}}</u></b></td></tr><tr><td></td><td style=\"padding-left:14px;font-size:.92em\">(Printed Name &amp; Signature)</td></tr><tr><td class=\"k\">Designation</td><td>: <u>{{signatory.position}}</u></td></tr><tr><td class=\"k\">Date</td><td>: {{date.today}}</td></tr></tbody></table>','{\"margin_top\":15,\"margin_right\":18,\"margin_bottom\":15,\"margin_left\":18,\"line_height\":1.25,\"cell_pad\":3,\"font_size\":10,\"table_size\":9.5,\"title_size\":14,\"paper\":\"Legal\",\"orientation\":\"landscape\",\"font\":\"CG Omega\",\"date_style\":\"mdy\",\"lh_style\":\"none\",\"signatories\":[]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(47,47,1,'Statement of the single largest completed contract similar to the contract to be bid (SLCC)','<p style=\"margin:0;line-height:1.3\"><i>Procuring Entity:</i> <b>{{entity.title}}</b><br><i>Name of the Project:</i> <b>{{project.title}}</b><br><i>Location of the Project:</i> <b>{{project.location}}</b></p><p style=\"margin:6px 0 6px\"><span class=\"fx-hl\" style=\"font-size:14pt;font-weight:bold\">Statement identifying the bidder’s single largest completed contract similar to the contract to be bid</span></p><table class=\"fx-plain fx-kv\" style=\"margin:4px 0 6px\"><tbody><tr><td class=\"k\">Business Name</td><td>: <u>{{company.name}}</u></td></tr><tr><td class=\"k\">Business Address</td><td>: <u>{{company.address}}</u></td></tr></tbody></table><div style=\"margin-top:6px\">{{contracts.slcc_table}}</div><p style=\"margin:6px 0 0\">Note: This statement shall be supported with:</p><p style=\"margin:0;padding-left:2em\">1. Purchase Order     2. Sales Invoice     3. Certificate of Completion / Delivery Receipt     4. Notice of Award     5. Notice to Proceed</p><table class=\"fx-plain fx-kv\" style=\"margin-top:22px\"><tbody><tr><td class=\"k\" style=\"width:110px\">Submitted by</td><td>: <b><u>{{signatory.name}}</u></b></td></tr><tr><td></td><td style=\"padding-left:14px;font-size:.92em\">(Printed Name &amp; Signature)</td></tr><tr><td class=\"k\">Designation</td><td>: <u>{{signatory.position}}</u></td></tr><tr><td class=\"k\">Date</td><td>: {{date.today}}</td></tr></tbody></table>','{\"margin_top\":15,\"margin_right\":18,\"margin_bottom\":15,\"margin_left\":18,\"line_height\":1.25,\"cell_pad\":3,\"font_size\":10,\"table_size\":9.5,\"title_size\":14,\"paper\":\"Folio\",\"orientation\":\"landscape\",\"font\":\"CG Omega\",\"date_style\":\"mdy\",\"lh_style\":\"none\",\"signatories\":[]}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(48,48,1,'Supplier profile','<div>{{letterhead}}</div><p style=\"text-align:center;margin:14px 0 28px\"><span style=\"font-size:20pt;font-weight:bold;text-decoration:underline\">SUPPLIER</span></p><p style=\"margin:0 0 6px\"><b><u>Profile</u></b></p><table class=\"fx-plain fx-kv\" style=\"width:100%\"><tbody><tr><td class=\"k\" style=\"width:34%\">Date</td><td>: {{date.today}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Name of Firm/Company</td><td>: <b>{{company.name_upper}}</b></td></tr><tr><td class=\"k\" style=\"width:34%\">Tax Account Number</td><td>: {{company.tin}}</td></tr></tbody></table><p style=\"margin:18px 0 6px\"><b><u>General Information</u></b></p><table class=\"fx-plain fx-kv\" style=\"width:100%\"><tbody><tr><td class=\"k\" style=\"width:34%\">Address</td><td>: {{company.address}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Telephone Number</td><td>: {{company.phone}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Email Address</td><td>: {{company.email}}</td></tr></tbody></table><p style=\"margin:18px 0 6px\"><b><u>Legal Aspect</u></b></p><p style=\"margin:0 0 6px\">Persons Managing affairs of the Firm</p><table class=\"fx-plain fx-kv\" style=\"width:100%\"><tbody><tr><td class=\"k\" style=\"width:34%\">Name</td><td>: {{company.manager_upper}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Designation</td><td>: {{company.manager_position}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Telephone</td><td>: {{company.manager_phone}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Cellphone No.</td><td>: {{company.manager_mobile}}</td></tr></tbody></table><p style=\"margin:18px 0 6px\">Authorized Liaison Officer</p><table class=\"fx-plain fx-kv\" style=\"width:100%\"><tbody><tr><td class=\"k\" style=\"width:34%\">Name</td><td>: {{company.liaison_upper}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Designation</td><td>: {{company.liaison_position}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Telephone</td><td>: {{company.liaison_phone}}</td></tr><tr><td class=\"k\" style=\"width:34%\">Cellphone No.</td><td>: {{company.liaison_mobile}}</td></tr></tbody></table>','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"signatories\":[],\"lh_style\":\"own\",\"lh_left\":\"none\",\"lh_right\":\"none\",\"lh_lines\":\"Republic of the Philippines\\nProvince of {{entity.province}}\\n{{entity.lgu_line}}\\n**{{entity.short_upper}} Barangay Hall**\",\"lh_align\":\"center\",\"lh_font\":\"\",\"lh_size\":11,\"lh_big\":16,\"lh_pic_mm\":24,\"lh_box\":0,\"lh_box_bg\":\"#ffffff\",\"lh_box_fg\":\"#111111\",\"lh_rule\":0,\"lh_rule_color\":\"#111111\",\"lh_space\":8,\"lh_pic_a\":\"\",\"lh_pic_b\":\"\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(49,49,1,'Letter of intent (manifestation of intent to participate)','<div>{{letterhead}}</div><p style=\"text-align:center;margin:22px 0 26px\"><span style=\"font-size:16pt;font-weight:bold\">Manifestation of Intent to Participate</span></p><p><b>{{date.today}}</b></p><p style=\"margin-top:18px\"><b>OFFICE OF THE CHAIRMAN</b><br>Bids and Awards Committee<br><b>{{entity.title}}</b><br>{{entity.city}}, {{entity.province}}</p><p>Dear Sir/Madam:</p><p style=\"text-align:justify\">This refers to the procurement project entitled <b>{{project.title}}.</b></p><p style=\"text-align:justify\">In this regard, <b>{{company.name}}</b>, with business address at <b>{{company.address}}</b>, hereby formally manifests its intent to participate in the public bidding for the above-mentioned project, in accordance with {{project.law_text}} and its Implementing Rules and Regulations (IRR).</p><p style=\"text-align:justify\">This manifestation is submitted in compliance with the requirements of the Bidding Documents issued by your office. Should our participation be accepted, we commit to comply with all eligibility requirements, submission of bidding documents, and other conditions set forth by the Bids and Awards Committee (BAC) within the prescribed timelines.</p><p style=\"text-align:justify\">We trust that this manifestation satisfies your requirement for expression of interest. We look forward to participating in the bidding process.</p><p style=\"margin-top:18px\">Thank you for your consideration.</p><div>{{signatories}}</div>','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":12,\"sig_space\":12,\"lh_space\":4,\"signatories\":[{\"lead\":\"Respectfully yours,\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"{{company.name}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"company\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(50,50,1,'Technical specifications (Section VII) with the statement of compliance','<p style=\"text-align:center;margin:0 0 20px\"><span style=\"font-size:16pt;font-weight:bold;text-decoration:underline\">SECTION VII-TECHNICAL SPECIFICATIONS</span></p><p><b>Date:</b> {{date.today}}</p><p>To: {{entity.short}} Barangay Hall, {{entity.city}}, {{entity.province}}<br>Address: {{entity.address}}</p><p>Gentlemen and/ or Ladies,</p><div>{{specs.items}}</div><p style=\"margin-top:10px\">We can <b><u>comply</u></b> the technical specification stated in the Bid Form.</p><div>{{signatories}}</div>','{\"margin_top\":22,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"lh_style\":\"none\",\"sig_cols\":1,\"sig_top\":16,\"sig_space\":14,\"comply_text\":\"\\\"comply\\\"\",\"signatories\":[{\"lead\":\"\",\"name\":\"{{signatory.name}}\",\"pos\":\"Dealer\'s Signature\",\"extra\":\"**{{company.name_upper}}**\\n{{company.address}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(51,51,1,'Production details (Section VI: schedule of requirements)','<div>{{letterhead}}</div><p style=\"text-align:center;margin:8px 0 18px\"><span style=\"font-size:16pt;font-weight:bold;text-decoration:underline\">SECTION VI-SCHEDULE OF REQUIREMENTS</span></p><div>{{sched.items}}</div><div>{{signatories}}</div>','{\"margin_top\":20,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":1,\"font_size\":10,\"table_size\":9.5,\"title_size\":14,\"sig_cols\":1,\"sig_top\":14,\"sig_space\":14,\"th_bg\":\"#fff2cc\",\"signatories\":[{\"lead\":\"**{{company.name_upper}}**\\n{{company.address_upper}}\",\"name\":\"{{signatory.name}}\",\"pos\":\"Dealer\'s Signature\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"own\",\"lh_left\":\"none\",\"lh_right\":\"none\",\"lh_lines\":\"Republic of the Philippines\\nProvince of {{entity.province}}\\n{{entity.lgu_line}}\\n**{{entity.short}}** Barangay Hall\",\"lh_align\":\"center\",\"lh_font\":\"\",\"lh_size\":11,\"lh_big\":16,\"lh_pic_mm\":24,\"lh_box\":0,\"lh_box_bg\":\"#ffffff\",\"lh_box_fg\":\"#111111\",\"lh_rule\":0,\"lh_rule_color\":\"#111111\",\"lh_space\":8,\"lh_pic_a\":\"\",\"lh_pic_b\":\"\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(52,52,1,'Manpower schedule (manpower requirements)','<div>{{letterhead}}</div><p style=\"text-align:center;margin:18px 0 30px\"><span style=\"font-size:18pt;font-weight:bold\">MANPOWER REQUIREMENTS</span></p><p style=\"text-align:justify\">In compliance with the requirements of the Bids and Awards Committee (BAC), and based on our standard delivery procedures and actual delivery operations, the following manpower requirement is hereby submitted:</p><p style=\"text-align:justify\">All goods and services shall be delivered to the designated delivery site and shall be subject to inspection upon delivery.</p><table class=\"fx\" style=\"width:100%;margin-top:22px\"><thead><tr><th style=\"width:28%\">PARTICULARS</th><th>DETAILS</th></tr></thead><tbody><tr><td><b>Project Name:</b></td><td>{{project.title}}</td></tr><tr><td style=\"vertical-align:middle\"><b>Project Location:</b></td><td>{{project.location}}</td></tr><tr><td><b>Delivery Vehicle:</b></td><td>Any available vehicle</td></tr><tr><td style=\"vertical-align:middle\"><b>Required Personnel</b></td><td>At least two (2) personnel composed of:<br>• One (1) Driver<br>• One (1) Sales Representative</td></tr></tbody></table><div>{{signatories}}</div>','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":22,\"sig_space\":12,\"signatories\":[{\"lead\":\"Respectfully submitted by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"company\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(53,53,1,'Warranty certificate (after-sales service and warranty provision)','<div>{{letterhead}}</div><p style=\"text-align:center;margin:10px 0 14px\"><span style=\"font-size:11.5pt;font-weight:bold\">After-Sales Service and Warranty Provision</span></p><p style=\"text-align:justify\">The Supplier shall warrant that the {{project.title}}, including all its components, accessories, and attachments, is brand new, free from defects in materials and workmanship, and compliant with the technical specifications prescribed in the Contract.</p><p style=\"margin-bottom:0\"><b>1. Warranty Period</b></p><p style=\"text-align:justify\">The warranty shall be for a minimum period of <b>{{project.warranty_words}}</b>, reckoned from the date of final acceptance by the Procuring Entity.</p><p style=\"margin-bottom:0\"><b>2. Scope of Warranty</b></p><p style=\"margin-bottom:2px\">The warranty shall cover, but not be limited to, defects or failures in the following:</p><ul style=\"margin-top:4px\"><li>Materials and workmanship of every item delivered</li><li>The performance of the goods according to the technical specifications</li><li>Components, parts, and assemblies</li><li>Electrical, mechanical, and operating systems, where applicable</li><li>All factory-installed parts, equipment, and accessories</li></ul><p style=\"text-align:justify\">The Supplier shall undertake, at its own expense, the repair or replacement of any defective parts, including the provision of labor, tools, and consumables necessary to restore the goods to their optimal operating condition.</p><p style=\"margin-bottom:0\"><b>3. After-Sales Service Obligations</b></p><p style=\"margin-bottom:0\">The Supplier shall:</p><p style=\"text-align:justify;margin-bottom:0\">a. Maintain or have access to an <b>authorized service center</b> capable of providing adequate technical support within a reasonable distance from the Procuring Entity;<br>b. Ensure the availability of <b>genuine spare parts</b> for a minimum period of <b>five (5) years</b> from delivery;<br>c. Provide <b>preventive maintenance services</b> in accordance with the manufacturer’s recommended schedule, where applicable;<br>d. Respond to service requests within <b>forty-eight (48) to seventy-two (72) hours</b> from receipt of notice;<br>e. Deploy qualified technical personnel to perform necessary inspections, diagnostics, and repairs.</p><p style=\"margin:10px 0 0\"><b>4. Exclusions from Warranty</b></p><p style=\"margin-bottom:2px\">The warranty shall not apply to defects or damages resulting from:</p><ul style=\"margin-top:4px\"><li>Misuse, negligence, or improper operation by the end-user;</li><li>Unauthorized repairs, alterations, or modifications;</li><li>Accidents, force majeure, or external causes beyond normal operating conditions;</li><li>Normal wear and tear of consumable items, unless proven to be factory-defective.</li></ul><p style=\"margin-bottom:0\"><b>5. Warranty Security</b></p><p style=\"text-align:justify\">In accordance with the applicable procurement law and its Implementing Rules and Regulations, the Supplier shall post a <b>warranty security</b> in any of the acceptable forms prescribed by law to guarantee the faithful performance of its warranty obligations.</p><div>{{signatories}}</div>','{\"margin_top\":18,\"margin_right\":25.4,\"margin_bottom\":18,\"margin_left\":25.4,\"line_height\":1.22,\"cell_pad\":3,\"font_size\":10.5,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":12,\"sig_space\":12,\"lh_space\":4,\"signatories\":[{\"lead\":\"Sincerely yours,\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{signatory.position}}\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"company\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"CG Omega\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(54,54,1,'Notice of post-qualification','<div>{{letterhead}}</div><p style=\"text-align:center;margin:4px 0 22px\"><span style=\"font-size:14pt;font-weight:bold\">Notice of Post Qualification</span></p><p>{{date.today}}</p><p style=\"margin-top:18px\"><b>{{signatory.addressee}}</b><br>{{signatory.position_upper}}<br><b>{{company.name_upper}}</b><br>{{company.address}}</p><p style=\"margin-top:22px\"><b><u>ATTN.: {{signatory.addressee}}</u></b></p><p style=\"margin-top:22px\">Good day!</p><p style=\"text-align:justify;line-height:1.7\">After determining your bid as the Lowest Calculated Bid for the <b>{{project.title}}</b> for {{entity.title}}, {{entity.city}} and upon the post qualification involving the careful examination, validation and verification of the eligibility, technical and financial requirements that you have submitted for bidding. We are happy to inform you that you have successfully passed the Post Qualification and therefore, now consider your bid as the Lowest Calculated and Responsive for the said Contract.</p><div>{{signatories}}</div>','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":14,\"sig_space\":10,\"sig_gap\":8,\"signatories\":[{\"lead\":\"Very truly yours,\",\"name\":\"HON. {{entity.bac_chair_upper}}\",\"pos\":\"BAC CHAIRMAN\",\"extra\":\"\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":1},{\"lead\":\"Received by:\",\"name\":\"{{signatory.name}}\",\"pos\":\"PRINTED NAME AND SIGNATURE\",\"extra\":\"\",\"align\":\"left\",\"newrow\":1,\"line\":0,\"bold\":0,\"underline\":1,\"upper\":0,\"italic\":1}],\"footer\":\"OFFICE OF THE BARANGAY\",\"footer_align\":\"center\",\"footer_bold\":1,\"footer_size\":11,\"footer_pos\":\"bottom\",\"lh_style\":\"barangay\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"Cambria\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(55,55,1,'Notice of award (NOA)','<div>{{letterhead}}</div><p style=\"text-align:center;margin:4px 0 22px\"><span style=\"font-size:14pt;font-weight:bold\">NOTICE OF AWARD</span></p><p>{{date.today}}</p><p style=\"margin-top:18px\"><b>{{signatory.addressee}}</b><br>{{signatory.position_upper}}<br><b>{{company.name_upper}}</b><br>{{company.address}}</p><p style=\"margin-top:18px\">Dear {{signatory.salutation}}:</p><p style=\"text-align:justify;line-height:1.6\">We are happy to notify you that the contract for the <b>{{project.title}}</b> is hereby awarded to you as the single lowest calculated and responsive Bid at a contract price equivalent to <b>{{project.contract_price_text}}</b>.</p><p style=\"text-align:justify;line-height:1.6\">You are therefore required to provide the performance security in the form and amount stipulated in the instruction to the Bidders within ten (10) calendar days from receipt of Notice of Award. Contract signing will be done upon submission of the required performance security. Failure to provide the performance security on the said date shall constitute ground for cancellation of the award and forfeiture of the bid security.</p><div>{{signatories}}</div>','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":12,\"sig_space\":10,\"sig_gap\":8,\"signatories\":[{\"lead\":\"Very truly yours,\",\"name\":\"HON. {{entity.head_name_upper}}\",\"pos\":\"Barangay Chairman\",\"extra\":\"{{entity.location}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"Conforme:\",\"name\":\"{{signatory.name}}\",\"pos\":\"{{company.name_upper}}\",\"extra\":\"Date: {{date.today}}\",\"align\":\"left\",\"newrow\":1,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"footer\":\"OFFICE OF THE BARANGAY\",\"footer_align\":\"center\",\"footer_bold\":1,\"footer_size\":11,\"footer_pos\":\"bottom\",\"lh_style\":\"barangay\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"Cambria\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(56,56,1,'Notice to proceed (NTP)','<div>{{letterhead}}</div><p style=\"text-align:center;margin:4px 0 22px\"><span style=\"font-size:14pt;font-weight:bold\">NOTICE TO PROCEED</span></p><p>{{date.today}}</p><p style=\"margin-top:18px\"><b>{{signatory.addressee}}</b><br>{{signatory.position_upper}}<br><b>{{company.name_upper}}</b><br>{{company.address}}</p><p style=\"margin-top:18px\">Dear {{signatory.salutation}}:</p><p style=\"text-align:justify;text-indent:3em;line-height:1.6\">The attached Purchase Order No. <u>{{po.no}}</u> Dated <u>{{po.date}}</u> having been approved. Notice is hereby given to <b>{{company.name_upper}}</b> on the <b>{{project.title}}</b> and may proceed immediately to the delivery of the items enumerated therein within the specified delivery schedule.</p><p style=\"text-align:justify;line-height:1.6\">Please acknowledge receipt and acceptance of this notice by signing both copies in the space provided below and be guided accordingly.</p><div>{{signatories}}</div><table class=\"fx-plain fx-kv\" style=\"width:100%;margin-top:26px\"><tbody><tr><td class=\"k\" style=\"width:48%\">I acknowledge receipt of this Notice on</td><td>: <u>{{date.today}}</u></td></tr><tr><td class=\"k\">Name of the Representative of the Bidder</td><td>: <u>{{signatory.name}}</u></td></tr><tr><td class=\"k\">Authorized Signature</td><td>: _________________________</td></tr></tbody></table>','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":1,\"sig_top\":12,\"sig_space\":10,\"signatories\":[{\"lead\":\"Sincerely yours,\",\"name\":\"HON. {{entity.head_name_upper}}\",\"pos\":\"Barangay Chairman\",\"extra\":\"{{entity.location}}\",\"align\":\"left\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"Cambria\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(57,57,1,'BAC resolution recommending the award','<div>{{letterhead}}</div><p style=\"text-align:center;margin:0\">Office of the Barangay</p><p style=\"text-align:center;margin:2px 0 2px\"><span style=\"font-size:13pt;font-weight:bold\">BIDS AND AWARDS COMMITTEE</span></p><p style=\"text-align:center;font-weight:bold;margin-bottom:18px\">RESOLUTION NO.__________</p><p style=\"text-align:justify;line-height:1.6\">WHEREAS, after a complete and final evaluation by the BAC Technical Working Group it was determined that <b>{{company.name_upper}}</b> has the CALCULATED RESPONSIVE BID for the {{project.title}} located at <b>{{entity.title_upper}}, {{entity.lgu_line_upper}}</b> for the Contract Price equivalent to <b>{{project.contract_price_text}}</b>.</p><p style=\"text-align:justify;line-height:1.6\">WHEREAS, the Approved Budget for the purchase is in the amount of <b>{{project.abc_text}}</b>.</p><p style=\"text-align:justify;line-height:1.6\">WHEREAS, the Bids and Awards Committee (BAC), through Resolution No.__________ dated______________ had recommended to the Barangay CHAIRMAN, <b>HON. {{entity.head_name_upper}}</b> to award the {{project.title}} located at {{entity.title_upper}}, {{entity.lgu_line_upper}}, to <b>{{company.name_upper}}</b> which recommendation was approved.</p><p style=\"text-align:justify;line-height:1.6\">NOW THEREFORE, we the members of the Bids and Awards Committee (BAC), hereby RESOLVED as it is hereby RESOLVED, to recommend to the Honorable Members, Barangay Council of {{entity.title}}, {{entity.city}}, to authorize <b>HON. {{entity.head_name_upper}}</b>, Barangay CHAIRMAN of {{entity.title_upper}}, {{entity.city}}, to enter into a Contract with <b>{{company.name_upper}}</b> at the Contract Price equivalent to <b>{{project.contract_price_text}}</b>.</p><p style=\"text-align:center;margin-top:14px\">{{entity.title}}, {{entity.city}}</p><div>{{signatories}}</div>','{\"margin_top\":25.4,\"margin_right\":25.4,\"margin_bottom\":25.4,\"margin_left\":25.4,\"line_height\":1.3,\"cell_pad\":3,\"font_size\":11,\"table_size\":10,\"title_size\":14,\"sig_cols\":3,\"sig_top\":8,\"sig_space\":11,\"sig_gap\":6,\"signatories\":[{\"lead\":\"\",\"name\":\"HON. {{entity.bac_chair_upper}}\",\"pos\":\"BAC Chairman\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_1_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_2_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_3_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_4_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.bac_member_5_upper}}\",\"pos\":\"BAC Member\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0},{\"lead\":\"\",\"name\":\"HON. {{entity.head_name_upper}}\",\"pos\":\"Barangay CHAIRMAN\",\"extra\":\"\",\"align\":\"center\",\"newrow\":1,\"line\":0,\"bold\":1,\"underline\":0,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\",\"paper\":\"Legal\",\"orientation\":\"portrait\",\"date_style\":\"mdy\",\"blank_rows\":0,\"nothing_follows\":0,\"font\":\"Cambria\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45'),
(58,58,1,'Certificate of completion','<div>{{letterhead}}</div><div class=\"fx-title\" style=\"text-align:center;margin:16px 0\">CERTIFICATE OF COMPLETION</div><p>This is to certify that <b>{{company.name}}</b> has satisfactorily completed / delivered <b>{{project.title}}</b> under {{project.contract_ref}} for the <b>{{entity.name}}</b> in accordance with the specifications, in the amount of <b>{{project.contract_amount}}</b>.</p><p>Date of completion: <b>{{project.completion_date}}</b></p><p>Issued this ____ day of __________ for whatever legal purpose it may serve.</p><div>{{signatories}}</div>','{\"margin_top\":10,\"margin_right\":12,\"margin_bottom\":10,\"margin_left\":12,\"line_height\":1.2,\"cell_pad\":2,\"font_size\":9.5,\"table_size\":9,\"title_size\":13,\"sig_top\":14,\"signatories\":[{\"lead\":\"Inspected by:\",\"name\":\"{{project.inspector_upper}}\",\"pos\":\"Inspector\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0},{\"lead\":\"Approved by:\",\"name\":\"{{entity.head_name_upper}}\",\"pos\":\"Barangay Captain\",\"extra\":\"\",\"align\":\"center\",\"newrow\":0,\"line\":0,\"bold\":1,\"underline\":1,\"upper\":0,\"italic\":0}],\"lh_style\":\"barangay\"}',NULL,'Supplied with the program',NULL,'2026-11-23 19:54:45');

-- brgy_sop_steps (30 rows)
INSERT INTO `brgy_sop_steps` (`id`,`process`,`code`,`step_no`,`section`,`section_title`,`title`,`instructions`,`papers`,`checks`,`action`,`role_hint`,`txn_types`,`sort_order`,`is_active`,`updated_by`,`updated_at`) VALUES
(1,'svp','svp1',1,'A','Pre-processing','Receive transaction details','The Sales Representative shall provide the project title, the approved budget, the requestor, the inspector and the complete item specifications / particulars. If complete details are already available, document preparation may proceed immediately. Otherwise, additional research and price validation shall be conducted before processing.','txn_form','Project title|Approved budget|Requestor|Inspector|Complete item specifications / particulars','details','Sales Representative',NULL,10,1,NULL,NULL),
(2,'svp','svp2',2,'B','Preparation of procurement documents','Prepare the required documents','The following documents shall be prepared: Purchase Request (PR), Purchase Order (PO), Inspection and Acceptance Report (IAR), Requisition and Issue Slip (RIS), Canvass, Abstract of Canvass, Disbursement Voucher (DV).','pr,po,iar,ris,canvass,abstract,dv',NULL,'prepare','Documentation staff',NULL,20,1,NULL,NULL),
(3,'svp','svp3',3,'B','Preparation of procurement documents','Supplier canvassing','Three (3) supplier representatives shall complete and sign the canvass forms. For a complete canvass: three suppliers signed.',NULL,'Three suppliers completed and signed the canvass forms','canvass','Documentation staff',NULL,30,1,NULL,NULL),
(4,'svp','svp4',4,'C','Billing and tax documents','Prepare billing documents','Prepare the Sales Invoice (SI), the Official Receipt (OR) and the BIR Form 2307 (3 copies). All copies of BIR Form 2307 must be signed by the proprietor. Signed copies are uploaded to the system. Special case (F): if payment has not yet been released, do NOT prepare the sales invoice, the official receipt and the BIR Form 2307; monitor the transaction until payment is available. Once payment is released, proceed with the preparation of the billing documents and continue the normal closing process.','si,or,form_2307','All copies of BIR Form 2307 signed by the proprietor','billing_docs','Billing staff',NULL,40,1,NULL,NULL),
(5,'svp','svp5',5,'D','Review and monitoring','Review documents','Conduct a thorough review to ensure accuracy, completeness, proper signatures and correct computations.',NULL,'Accuracy|Completeness|Proper signatures|Correct computations','review','Reviewer',NULL,50,1,NULL,NULL),
(6,'svp','svp6',6,'D','Review and monitoring','File and monitor records','Retain copies of all essential documents for monitoring and future reference. Encode transaction details into the Monitoring System.',NULL,'Copies of all essential documents retained|Transaction details encoded in the Monitoring System','monitor','Documentation staff',NULL,60,1,NULL,NULL),
(7,'svp','svp7',7,'E','Accounting and release','Forward documents','Forward all retained copies to the Accounting Department for final processing and closing.',NULL,NULL,'forward','Documentation staff',NULL,70,1,NULL,NULL),
(8,'svp','svp8',8,'E','Accounting and release','Prepare transmittal','Prepare the transmittal documents for routing and tracking purposes.','transmittal',NULL,'transmittal','Documentation staff',NULL,80,1,NULL,NULL),
(9,'svp','svp9',9,'E','Accounting and release','Release of documents','Documents may be delivered by the Sales Representative, or released directly to the Barangay Office for pick-up. The signed transmittal must always be returned for documentation and tracking.',NULL,NULL,'release','Sales Representative / office',NULL,90,1,NULL,NULL),
(10,'bidding','bid1',1,'A','Receipt of bidding information','Receive bidding details','The Sales Representative shall provide the bidding form and complete project details.','bidding_form','Bidding form received|Complete project details','details','Sales Representative',NULL,100,1,NULL,NULL),
(11,'bidding','bid2',2,'A','Receipt of bidding information','Verify completeness','Ensure the following are finalized before processing: specifications, approved schedule, bid dates, submission requirements.',NULL,'Specifications|Approved schedule|Bid dates|Submission requirements','checks','Bid officer',NULL,110,1,NULL,NULL),
(12,'bidding','bid3',3,'A','Receipt of bidding information','Posting on PhilGEPS','Post the bidding opportunity manually on PhilGEPS.',NULL,'Posted on PhilGEPS','philgeps','Bid officer',NULL,120,1,NULL,NULL),
(13,'bidding','bid4',4,'B','Preparation of bidding documents','Prepare pre-bid documents','Prepare the Invitation to Bid (ITB), the Purchase Request (PR) and the Letter to COA; for the Cebu City offices also the Approved Budget for the Contract (ABC) and the Aboitiz requirements.','itb,pr,coa_letter,abc,aboitiz',NULL,'prepare','Bid officer',NULL,130,1,NULL,NULL),
(14,'bidding','bid5',5,'C','Representative assignment','Assign authorized representative','Assign a representative to attend the pre-bid conference and the actual bidding. Prepare the Special Power of Attorney (SPA).','spa','Representative for the pre-bid conference|Representative for the actual bidding','representative','Proprietor',NULL,140,1,NULL,NULL),
(15,'bidding','bid6',6,'D','Eligibility and legal documents','Prepare legal documents','Prepare the following for notarization: Bid Securing Declaration, Omnibus Sworn Statement.','bsd,oss','Notarized','prepare','Bid officer',NULL,150,1,NULL,NULL),
(16,'bidding','bid7',7,'D','Eligibility and legal documents','Print eligibility documents','Retrieve and print from PhilGEPS: PhilGEPS registration, DTI/SEC registration, Mayor\'s permit, Certificate of Registration (COR), Tax clearance, Audited financial statements, Income tax return.','philgeps_reg,sec_dti,mayors_permit,cor,tax_clearance,afs,itr',NULL,'prepare','Bid officer',NULL,160,1,NULL,NULL),
(17,'bidding','bid8',8,'E','Additional supporting documents','Prepare additional requirements','Statement of ongoing contracts, statement of similar contracts, supplier profile, letter of intent, technical specifications, production details, manpower schedule, warranty certificate.','slcc_ongoing,slcc_similar,supplier_profile,letter_intent,tech_specs,production,manpower,warranty_cert',NULL,'prepare','Bid officer',NULL,170,1,NULL,NULL),
(18,'bidding','bid9',9,'F','Final review and submission','Organize and label documents','Arrange all documents properly and stamp them as \"Certified True Copy.\"',NULL,'Documents arranged and labelled|Stamped \"Certified True Copy\"','checks','Bid officer',NULL,180,1,NULL,NULL),
(19,'bidding','bid10',10,'F','Final review and submission','Secure signatures','Present documents to the proprietor for signature.',NULL,NULL,'signatures','Proprietor',NULL,190,1,NULL,NULL),
(20,'bidding','bid11',11,'F','Final review and submission','Final verification','Conduct final checking before submission. The bid is reviewed and approved for submission (two different people).',NULL,NULL,'verify','Checker and approver',NULL,200,1,NULL,NULL),
(21,'bidding','bid12',12,'F','Final review and submission','Submit documents','Endorse documents to the assigned bidding representative.',NULL,NULL,'submit','Authorized representative',NULL,210,1,NULL,NULL),
(22,'bidding','bid13',13,'G','Post-bidding process','Prepare post-bidding documents','For the winner: Notice of Post Qualification, BAC Resolution, Notice of Award (NOA), Notice to Proceed (NTP), Post-Qualification Documents.','post_qual_notice,bac_resolution,noa,ntp,post_qual',NULL,'post_bid','Bid officer',NULL,220,1,NULL,NULL),
(23,'bidding','bid14',14,'G','Post-bidding process','Project completion','Prepare the Certificate of Completion signed by the Inspector and the Barangay Captain.','completion_cert',NULL,'completion','Project manager',NULL,230,1,NULL,NULL),
(24,'bidding','bid15',15,'G','Post-bidding process','Coordinate payment schedule','Coordinate with the Barangay regarding cheque release and payment schedule. Check cheque availability: for cheque ready, proceed; for payment pending, follow up and wait.',NULL,NULL,'payment','Collection staff',NULL,240,1,NULL,NULL),
(25,'bidding','pay1',16,'H','Payment process','Preparation of SI, OR and BIR 2307','Prepare the Sales Invoice (SI), the Official Receipt (OR) and the BIR Form 2307 (3 copies, signed by the proprietor). Signed copies are uploaded to the system. Special case (F): if payment has not yet been released, do NOT prepare the sales invoice, the official receipt and the BIR Form 2307; monitor the transaction until payment is available. Once payment is released, proceed with the preparation of the billing documents and continue the normal closing process.','si,or,form_2307','All copies of BIR Form 2307 signed by the proprietor','billing_docs','Billing staff',NULL,250,1,NULL,NULL),
(26,'bidding','pay2',17,'H','Payment process','Encode in monitoring','Encode the transaction in the Monitoring System and retain copies of all essential documents.',NULL,'Copies of all essential documents retained|Transaction details encoded in the Monitoring System','monitor','Documentation staff',NULL,260,1,NULL,NULL),
(27,'bidding','pay3',18,'H','Payment process','Forward to accounting','Forward all retained copies to the Accounting Department for final processing and closing.',NULL,NULL,'forward','Documentation staff',NULL,270,1,NULL,NULL),
(28,'bidding','pay4',19,'H','Payment process','Prepare transmittal','Prepare the transmittal documents for routing and tracking purposes.','transmittal',NULL,'transmittal','Documentation staff',NULL,280,1,NULL,NULL),
(29,'bidding','pay5',20,'H','Payment process','Secure signature','Release the documents and secure the signature on the transmittal; the signed transmittal must always be returned.',NULL,NULL,'release','Sales Representative / office',NULL,290,1,NULL,NULL),
(30,'bidding','pay6',21,'H','Payment process','File for record','File the transaction for record. The contract is closed out when it agrees with the ledger.',NULL,'Filed for record','file','Documentation staff',NULL,300,1,NULL,NULL);

-- brgy_sop_papers (41 rows)
INSERT INTO `brgy_sop_papers` (`code`,`name`,`doc_type`,`template_code`,`source`,`copies`,`needs_signature`,`needs_notary`,`needs_ctc`,`condition_code`,`description`,`txn_types`,`sort_order`,`is_active`) VALUES
('abc','Approved budget for the contract (ABC)','abc','approved_budget_contract','prepare',1,1,0,0,'extra_bid_papers','Asked by the Cebu City offices.',NULL,160,1),
('aboitiz','Aboitiz requirements','aboitiz',NULL,'prepare',1,1,0,0,'extra_bid_papers','Asked by the Cebu City offices; attach the papers as submitted.',NULL,170,1),
('abstract','Abstract of canvass','abstract','abstract_of_canvass','prepare',1,1,0,0,NULL,'The quotations side by side, signed by the BAC and approved by the Punong Barangay.',NULL,70,1),
('afs','Audited financial statements','afs',NULL,'company',1,0,0,1,NULL,'Printed from PhilGEPS; stamped Certified True Copy.',NULL,260,1),
('bac_resolution','BAC resolution','bac_resolution','bac_resolution','prepare',1,1,0,0,NULL,'Recommends the award; signed by the BAC.',NULL,360,1),
('bidding_form','Bidding form','bidding_form','bidding_form','prepare',1,0,0,0,NULL,'The details of the bidding given by the sales representative.',NULL,130,1),
('bsd','Bid securing declaration','bid_security','bid_securing_declaration','prepare',1,1,1,0,NULL,'For notarization.',NULL,190,1),
('canvass','Canvass forms (request for quotation)','canvass','request_for_quotation','prepare',3,0,0,0,NULL,'One for each of the three suppliers canvassed; each supplier fills in and signs its own.',NULL,60,1),
('coa_letter','Letter to COA','coa_letter','letter_to_coa','prepare',1,1,0,0,NULL,'Informs the COA auditor of the bidding schedule.',NULL,150,1),
('completion_cert','Certificate of completion','completion_cert','certificate_of_completion','prepare',1,1,0,0,NULL,'Signed by the inspector and the Barangay Captain.',NULL,410,1),
('cor','Certificate of registration (COR)','bir_2303',NULL,'company',1,0,0,1,NULL,'BIR Form 2303; stamped Certified True Copy.',NULL,240,1),
('dv','Disbursement voucher (DV)','dv','disbursement_voucher','prepare',1,1,0,0,NULL,'Boxes A to E: appropriations, funds available, approval, payment received, accounting entries.',NULL,80,1),
('form_2307','BIR Form 2307','form_2307',NULL,'prepare',3,1,0,0,NULL,'Prepared on the BIR form for the barangay; all three copies signed by the proprietor.',NULL,110,1),
('iar','Inspection and acceptance report (IAR)','iar','inspection_acceptance_report','prepare',1,1,0,0,NULL,'Signed by the inspector and the property custodian.',NULL,40,1),
('itb','Invitation to bid (ITB)','itb','invitation_to_bid','prepare',1,1,0,0,NULL,'Issued in the name of the BAC.',NULL,140,1),
('itr','Income tax return','itr',NULL,'company',1,0,0,1,NULL,'Printed from PhilGEPS; stamped Certified True Copy.',NULL,270,1),
('letter_intent','Letter of intent','letter_intent','letter_of_intent','prepare',1,1,0,0,NULL,'Intent to participate in the bidding.',NULL,310,1),
('manpower','Manpower schedule','manpower','manpower_schedule','prepare',1,1,0,0,NULL,'People assigned to the project and when.',NULL,340,1),
('mayors_permit','Mayor\'s permit','mayors_permit',NULL,'company',1,0,0,1,NULL,'Printed from PhilGEPS; stamped Certified True Copy.',NULL,230,1),
('noa','Notice of award (NOA)','noa','notice_of_award','prepare',1,1,0,0,NULL,'Signed by the Punong Barangay; conforme of the company.',NULL,370,1),
('ntp','Notice to proceed (NTP)','ntp','notice_to_proceed','prepare',1,1,0,0,NULL,'Signed by the Punong Barangay; conforme of the company.',NULL,380,1),
('or','Official receipt (OR) / collection receipt','or_ar',NULL,'record',1,1,0,0,NULL,'Issued for the cheque received (Collections & Retention); attach the signed copy.',NULL,100,1),
('oss','Omnibus sworn statement','omnibus','omnibus_sworn_statement','prepare',1,1,1,0,NULL,'For notarization.',NULL,200,1),
('philgeps_reg','PhilGEPS registration','philgeps_cert',NULL,'company',1,0,0,1,NULL,'Printed from PhilGEPS; stamped Certified True Copy.',NULL,210,1),
('po','Purchase order (PO)','po','purchase_order','prepare',1,1,0,0,NULL,'Signed by the Punong Barangay; conforme of the supplier; appropriations certified.',NULL,30,1),
('post_qual','Post-qualification documents','post_qual',NULL,'prepare',1,1,0,0,NULL,'The papers asked for the post-qualification; attach them as submitted.',NULL,400,1),
('post_qual_notice','Notice of post-qualification','post_qual_notice','notice_of_post_qualification','prepare',1,1,0,0,NULL,'The BAC informs the company that it passed the post-qualification; received by the company.',NULL,390,1),
('pr','Purchase request (PR)','pr','purchase_request','prepare',1,1,0,0,NULL,'Signed by the requisitioner and approved by the Punong Barangay.',NULL,20,1),
('production','Production details','production','production_details','prepare',1,1,0,0,NULL,'Where and how the goods are produced or sourced.',NULL,330,1),
('ris','Requisition and issue slip (RIS)','ris','requisition_issue_slip','prepare',1,1,0,0,NULL,'Requested, approved for issuance, received and issued.',NULL,50,1),
('sec_dti','DTI / SEC registration','sec_dti',NULL,'company',1,0,0,1,NULL,'Printed from PhilGEPS; stamped Certified True Copy.',NULL,220,1),
('si','Sales invoice (SI)','invoice',NULL,'record',1,1,0,0,NULL,'The BIR-registered invoice issued from the billing pack; attach the signed copy.',NULL,90,1),
('slcc_ongoing','Statement of ongoing contracts','slcc','statement_ongoing_contracts','prepare',1,1,0,0,NULL,'All ongoing government and private contracts.',NULL,280,1),
('slcc_similar','Statement of similar contracts','slcc','statement_similar_contracts','prepare',1,1,0,0,NULL,'Completed contracts similar to the project.',NULL,290,1),
('spa','Special power of attorney (SPA)','spa','special_power_of_attorney','prepare',1,1,1,0,NULL,'Authorizes the representative to attend the pre-bid conference and the bidding.',NULL,180,1),
('supplier_profile','Supplier profile','company_profile','supplier_profile','prepare',1,1,0,0,NULL,'The supplier profile asked by the barangay: the firm, the person managing its affairs, the liaison officer.',NULL,300,1),
('tax_clearance','Tax clearance','tax_clearance',NULL,'company',1,0,0,1,NULL,'Printed from PhilGEPS; stamped Certified True Copy.',NULL,250,1),
('tech_specs','Technical specifications','technical_proposal','technical_specifications','prepare',1,1,0,0,NULL,'Section VII: the specifications with the statement of compliance.',NULL,320,1),
('transmittal','Transmittal','transmittal','document_transmittal','prepare',1,1,0,0,NULL,'Lists the papers released; the signed transmittal must always come back.',NULL,120,1),
('txn_form','Transaction form','txn_form','txn_form','prepare',1,1,0,0,NULL,'Series-numbered form of the transaction: tradename, type, barangay, cheque, amounts, requestor, inspector, purpose.',NULL,10,1),
('warranty_cert','Warranty certificate','warranty_cert','warranty_certificate','prepare',1,1,0,0,NULL,'The warranty on the goods or the work.',NULL,350,1);

-- brgy_tradenames (1 rows)
INSERT INTO `brgy_tradenames` (`id`,`name`,`description`,`is_default`,`is_active`,`sort_order`,`created_by`,`created_at`,`updated_by`,`updated_at`) VALUES
(1,'Sample Trading Company',NULL,1,1,10,NULL,'2026-11-23 19:54:45',NULL,NULL);

-- brgy_function_roles (1 rows)
INSERT INTO `brgy_function_roles` (`function_code`,`role_id`) VALUES
('sales_rep',6);

-- ---------------------------------------------------------------------
--  3. AUDIT TRAIL PROTECTION (created after the data is loaded)
-- ---------------------------------------------------------------------
--  Audit trail protection: entries can never be changed or deleted,
--  not even by administrators or directly in the database.
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_audit_trail_no_update;
DROP TRIGGER IF EXISTS trg_audit_trail_no_delete;

DELIMITER $$
CREATE TRIGGER trg_audit_trail_no_update BEFORE UPDATE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are read-only and cannot be changed.';
END$$

CREATE TRIGGER trg_audit_trail_no_delete BEFORE DELETE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are permanent and cannot be deleted.';
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
