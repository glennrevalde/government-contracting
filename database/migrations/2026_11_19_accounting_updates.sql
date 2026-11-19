-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System
--  Migration 2026_11_19: accounting updates
--
--  Run it ONCE on every company database, after a backup (phpMyAdmin: select
--  the database > Import; Navicat: Execute SQL File; or the mysql command).
--  Written for MySQL 5.5.3+ and MariaDB 5.5+. Safe to run more than once.
--  New installs do not need it (database/schema.sql includes everything).
--
--  What it changes
--    1. Item groups: a Price variance GL account on items (empty column
--       group_variance_account_id), the group of each sales line (five empty
--       columns on transaction_lines: group_item_id, group_ref, group_qty,
--       group_rate, group_role), the table item_group_sales, the account
--       "Item Group Price Variance" (Income, code 4070 or the next free code)
--       with its module default account, and the report Sales by Item Group.
--    2. Receiving lists: an empty column received_via on transactions, filled in
--       for the item receipts (and the bills they became) and the bills entered
--       with Receive Items and Enter Bills.
--    3. The eight comparative income statements and balance sheets, Trial Balance
--       by Class and Statement of Cash Flows by Class (Chart of Accounts > Reports),
--       given to the roles that may open the Income Statement, the Balance Sheet,
--       the Trial Balance and the Statement of Cash Flows.
--    4. Average cost on the books: the quantities on hand by site
--       (item_site_stock) set to the totals of the inventory movements, every
--       average cost set to the remaining value divided by the remaining quantity,
--       and the report Average Cost Changes (Inventory Reports). Then run
--       System Administration > Utilities: Verify & Rebuild Data > Rebuild data once.
--    5. The table clearing_settlements: the card and e-wallet receipts a
--       settlement on Make Deposits was ticked for.
--    6. The tables txn_attachments and txn_attachment_chunks: files attached to
--       journal entries, checks, cash vouchers, bills, purchase orders, sales
--       orders and invoices (kept in the database, so they go with every backup).
--    7. The tables voucher_sign_requests, voucher_signatures, voucher_documents
--       and voucher_document_chunks: signatures on check vouchers, cash vouchers
--       and journal entries, and their signed PDF copies.
--
--  Nothing is deleted and no posted amount changes. The menu keeps its name,
--  Chart of Accounts.
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
--  1. Item groups and receiving lists: the columns
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE items ADD COLUMN group_variance_account_id INT UNSIGNED NULL AFTER tax_code_id',
  'SELECT ''items: group_variance_account_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'items' AND column_name = 'group_variance_account_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transaction_lines ADD COLUMN group_item_id INT UNSIGNED NULL AFTER other_name_id, ADD COLUMN group_ref INT UNSIGNED NULL AFTER group_item_id, ADD COLUMN group_qty DECIMAL(15,4) NULL AFTER group_ref, ADD COLUMN group_rate DECIMAL(15,4) NULL AFTER group_qty, ADD COLUMN group_role VARCHAR(10) NULL AFTER group_rate, ADD KEY ix_tl_group (group_item_id)',
  'SELECT ''transaction_lines: group columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transaction_lines' AND column_name = 'group_item_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transactions ADD COLUMN received_via VARCHAR(12) NULL AFTER other_name_id',
  'SELECT ''transactions: received_via already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'received_via');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  1b. Item group sales
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS item_group_sales (
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
  qty            DECIMAL(15,4) NOT NULL DEFAULT 0,
  group_price    DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  consolidated   DECIMAL(15,2) NOT NULL DEFAULT 0,
  variance       DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_igs_txn (txn_id),
  KEY ix_igs_group (group_item_id, txn_date),
  KEY ix_igs_date (txn_type, txn_date),
  CONSTRAINT fk_igs_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_igs_group FOREIGN KEY (group_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  1c. The price variance account and its module default
-- ---------------------------------------------------------------------

SET @a = (SELECT id FROM accounts WHERE name = 'Item Group Price Variance' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '4070' AS c, 1 AS o UNION ALL SELECT '4071', 2 UNION ALL SELECT '4075', 3 UNION ALL SELECT '4080', 4 UNION ALL SELECT '4090', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Item Group Price Variance', 'Income', 'Income', 'Credit', 'Item groups sold: the group price less the prices of the items in the group'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Item Group Price Variance' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('group_price_variance', 'Item Group Price Variance', 'inv', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'group_price_variance' AND account_id IS NULL;

-- ---------------------------------------------------------------------
--  2. Receiving lists: which page items were received on
-- ---------------------------------------------------------------------

-- item receipts (bill to follow), and the bills they were turned into
UPDATE transactions SET received_via = 'receipt' WHERE txn_type = 'item_receipt' AND received_via IS NULL;
UPDATE transactions t
  JOIN audit_trail a ON a.record_type = 'transaction' AND a.record_id = t.id AND a.action = 'convert'
   SET t.received_via = 'receipt'
 WHERE t.txn_type = 'bill' AND t.received_via IS NULL;
-- bills entered against a purchase order with Receive Items and Enter Bills
UPDATE transactions t
   SET t.received_via = 'bill'
 WHERE t.txn_type = 'bill' AND t.received_via IS NULL AND t.memo LIKE 'Received against %';

-- ---------------------------------------------------------------------
--  1d. The report Sales by Item Group and its page rights
-- ---------------------------------------------------------------------

SET @igs_cust = (SELECT id FROM modules WHERE code = 'cust' LIMIT 1);
SET @igs_csort = (SELECT COALESCE(MAX(sort_order), 200) FROM pages WHERE page_key = 'rpt.sales_item_detail');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.sales_item_group',     @igs_cust, 'Sales by Item Group',     'report', 'Sales Reports', @igs_csort + 1, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.sales_item_summary'
  JOIN pages dst ON dst.page_key = 'rpt.sales_item_group'
 WHERE src.allowed = 1;


-- ---------------------------------------------------------------------
--  3a. Comparative income statements and balance sheets: the eight reports
-- ---------------------------------------------------------------------

SET @cmp_coa  = (SELECT id FROM modules WHERE code = 'coa' LIMIT 1);
SET @cmp_sort = (SELECT COALESCE(MAX(sort_order), 300) FROM pages WHERE page_key = 'rpt.income_statement_class');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.cmp_is_month',   @cmp_coa, 'Comparative Income Statement by Month',        'report', 'Reports', @cmp_sort + 1, 1),
  ('rpt.cmp_is_quarter', @cmp_coa, 'Comparative Income Statement by Quarter',      'report', 'Reports', @cmp_sort + 2, 1),
  ('rpt.cmp_is_year',    @cmp_coa, 'Comparative Income Statement by Year',         'report', 'Reports', @cmp_sort + 3, 1),
  ('rpt.cmp_is_custom',  @cmp_coa, 'Comparative Income Statement by Custom Range', 'report', 'Reports', @cmp_sort + 4, 1),
  ('rpt.cmp_bs_month',   @cmp_coa, 'Comparative Balance Sheet by Month',           'report', 'Reports', @cmp_sort + 5, 1),
  ('rpt.cmp_bs_quarter', @cmp_coa, 'Comparative Balance Sheet by Quarter',         'report', 'Reports', @cmp_sort + 6, 1),
  ('rpt.cmp_bs_year',    @cmp_coa, 'Comparative Balance Sheet by Year',            'report', 'Reports', @cmp_sort + 7, 1),
  ('rpt.cmp_bs_custom',  @cmp_coa, 'Comparative Balance Sheet by Custom Date',     'report', 'Reports', @cmp_sort + 8, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.income_statement'
  JOIN pages dst ON dst.page_key IN ('rpt.cmp_is_month', 'rpt.cmp_is_quarter', 'rpt.cmp_is_year', 'rpt.cmp_is_custom')
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.balance_sheet'
  JOIN pages dst ON dst.page_key IN ('rpt.cmp_bs_month', 'rpt.cmp_bs_quarter', 'rpt.cmp_bs_year', 'rpt.cmp_bs_custom')
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  4. Average cost on the books: quantities on hand by site = the total of the movements there
-- ---------------------------------------------------------------------

INSERT INTO item_site_stock (item_id, site_id, qty_on_hand)
SELECT m.item_id, m.site_id, SUM(m.qty)
  FROM inventory_movements m
  JOIN items i ON i.id = m.item_id
  JOIN sites s ON s.id = m.site_id
 GROUP BY m.item_id, m.site_id
ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand);

UPDATE item_site_stock x
  LEFT JOIN (SELECT DISTINCT item_id, site_id FROM inventory_movements) m
         ON m.item_id = x.item_id AND m.site_id = x.site_id
   SET x.qty_on_hand = 0
 WHERE m.item_id IS NULL AND x.qty_on_hand <> 0;

-- ---------------------------------------------------------------------
--  4b. Average cost = remaining value / remaining quantity
-- ---------------------------------------------------------------------

UPDATE items i
  JOIN (SELECT item_id, SUM(qty) AS q, SUM(total_cost) AS v FROM inventory_movements GROUP BY item_id) b
    ON b.item_id = i.id
   SET i.avg_cost = ROUND(b.v / b.q, 4)
 WHERE i.item_type IN ('inventory', 'assembly') AND b.q > 0.000001 AND b.v > 0.004;

-- ---------------------------------------------------------------------
--  4c. The Average Cost Changes report
-- ---------------------------------------------------------------------

SET @acc_mod  = (SELECT module_id FROM pages WHERE page_key = 'rpt.inv_valuation_detail' LIMIT 1);
SET @acc_mod  = COALESCE(@acc_mod, (SELECT id FROM modules WHERE code = 'inv' LIMIT 1));
SET @acc_sort = (SELECT COALESCE(MAX(sort_order), 1240) FROM pages WHERE page_key = 'rpt.inv_valuation_detail');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.avg_cost_changes', @acc_mod, 'Average Cost Changes', 'report', 'Inventory Reports', @acc_sort + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.inv_valuation_detail'
  JOIN pages dst ON dst.page_key = 'rpt.avg_cost_changes'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  5. Card and e-wallet settlements ticked on Make Deposits: the receipts ticked to settle
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS clearing_settlements (
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

-- ---------------------------------------------------------------------
--  3b. Trial Balance by Class, Statement of Cash Flows by Class
-- ---------------------------------------------------------------------
SET @cr_coa   = (SELECT id FROM modules WHERE code = 'coa' LIMIT 1);
SET @cr_tb    = (SELECT COALESCE(MAX(sort_order), 160) FROM pages WHERE page_key = 'rpt.trial_balance');
SET @cr_cf    = (SELECT COALESCE(MAX(sort_order), 260) FROM pages WHERE page_key = 'rpt.cash_flow');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.trial_balance_class', @cr_coa, 'Trial Balance by Class',          'report', 'Reports', @cr_tb + 5, 1),
  ('rpt.cash_flow_class',     @cr_coa, 'Statement of Cash Flows by Class', 'report', 'Reports', @cr_cf + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.trial_balance'
  JOIN pages dst ON dst.page_key = 'rpt.trial_balance_class'
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.cash_flow'
  JOIN pages dst ON dst.page_key = 'rpt.cash_flow_class'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  6. Attachments: the files attached to a transaction
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS txn_attachments (
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

-- ---------------------------------------------------------------------
--  6b. The content of the files, in pieces
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS txn_attachment_chunks (
  attachment_id  INT UNSIGNED NOT NULL,
  seq            INT UNSIGNED NOT NULL,
  data           MEDIUMBLOB   NOT NULL,
  PRIMARY KEY (attachment_id, seq),
  CONSTRAINT fk_tac_att FOREIGN KEY (attachment_id) REFERENCES txn_attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  7. Voucher signatures: signature request links
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS voucher_sign_requests (
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

-- ---------------------------------------------------------------------
--  7b. The signatures
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS voucher_signatures (
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

-- ---------------------------------------------------------------------
--  7c. The PDF copies: the original unsigned voucher and each signed copy
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS voucher_documents (
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

CREATE TABLE IF NOT EXISTS voucher_document_chunks (
  document_id  INT UNSIGNED NOT NULL,
  seq          INT UNSIGNED NOT NULL,
  data         MEDIUMBLOB   NOT NULL,
  PRIMARY KEY (document_id, seq),
  CONSTRAINT fk_vdc_doc FOREIGN KEY (document_id) REFERENCES voucher_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  The audit trail
-- ---------------------------------------------------------------------
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Accounting updates',
        'Migration 2026_11_19: item groups, receiving lists, comparative statements, class reports, average cost, settlements, attachments, voucher signatures');
