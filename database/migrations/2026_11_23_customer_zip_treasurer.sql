-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System
--  Migration 2026_11_23: Zip code and Treasurer name of a customer
--
--  Run it ONCE on every company database, after a backup (phpMyAdmin: select
--  the database > Import; Navicat: Execute SQL File; or the mysql command).
--  It needs 2026_11_21_people_users_estimates_dv_rfq_iar.sql (version 34) to have been run first.
--  Written for MySQL 5.5.3+ and MariaDB 5.5+. Safe to run more than once.
--  New installs do not need it (database/schema.sql includes everything).
--
--  What it changes
--    Two empty columns on customers (Customers > Customer Center > a customer > Edit):
--      treasurer_name   the name of the customer's treasurer (after the contact person)
--      zip_code         the zip code of the customer's address (after the shipping address)
--  Nothing is deleted and nothing else changes.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE customers ADD COLUMN treasurer_name VARCHAR(120) NULL AFTER contact_person',
  'SELECT ''customers: treasurer_name already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'treasurer_name');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE customers ADD COLUMN zip_code VARCHAR(10) NULL AFTER shipping_address',
  'SELECT ''customers: zip_code already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'zip_code');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  The audit trail
-- ---------------------------------------------------------------------
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Customer zip code and treasurer name',
        'Migration 2026_11_23: the columns zip_code and treasurer_name of customers (Customer Center)');
