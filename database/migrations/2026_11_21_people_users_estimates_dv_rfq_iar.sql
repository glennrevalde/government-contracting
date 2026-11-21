-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System
--  Migration 2026_11_21: people accountable by user, estimates of the transaction form,
--  the disbursement voucher amounts, the cheque's tradename, the request for quotation
--  and the inspection and acceptance report of the barangay
--
--  Run it ONCE on every company database, after a backup (phpMyAdmin: select
--  the database > Import; Navicat: Execute SQL File; or the mysql command).
--  It needs 2026_11_20_tradenames_people_report_center.sql (version 33) to have been run first.
--  Written for MySQL 5.5.3+ and MariaDB 5.5+. Safe to run more than once.
--  New installs do not need it (database/schema.sql includes everything).
--
--  What it changes
--    1. The table brgy_function_users: People Accountable Set-up can name the users
--       allowed for a function, in place of its roles. Nothing is ticked by it: every
--       function keeps the roles set in version 33.
--    2. Ten empty columns on brgy_projects:
--         lines_lookup_inventory   the price schedule looks up the Inventory List (0 = off)
--         est_gross, est_wt_rate, est_profit_rate, est_receipt_rate, est_sop
--                                  the estimates and targets of the transaction form
--                                  (no accounting entry is made from them)
--         dv_gross, dv_vat_rate, dv_ewt_rate
--                                  the gross amount, VAT and EWT entered on the DV
--         cheque_tradename         the tradename the cheque was released to
--    3. The template "Request for quotation (canvass form)": the barangay's format
--       (the BAC letterhead with the seal, the general conditions, UNIT / ITEM
--       DESCRIPTION / QUANTITY / UNIT PRICE / TOTAL). A new version; the version before
--       stays under Proforma & Form Templates > the template > Versions.
--    4. The template "Inspection and acceptance report (IAR)": RIS No. and Date beside
--       the IAR No. and Date; the lines Requisitioning Office/Dept. and Responsibility
--       Center Code taken off. A new version (the version before stays). An IAR whose
--       text was changed so that these lines are not found is left as it is.
--
--  Nothing is deleted and no amount or posting changes. Forms already prepared keep
--  their text; a draft takes the new format with "Fill again from the records".
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  1. People Accountable Set-up: the users allowed for a function
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brgy_function_users (
  function_code VARCHAR(20)  NOT NULL,          -- sales_rep, preparer, checker, approver, submitter, pm, collector
  user_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (function_code, user_id),
  KEY ix_bfu_user (user_id),
  CONSTRAINT fk_bfu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. The columns of a transaction (each added only when it is not there yet)
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN lines_lookup_inventory TINYINT(1) NOT NULL DEFAULT 0 AFTER bid_endorsed_at',
  'SELECT ''brgy_projects: lines_lookup_inventory already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'lines_lookup_inventory');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN est_gross DECIMAL(15,2) NULL AFTER lines_lookup_inventory',
  'SELECT ''brgy_projects: est_gross already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'est_gross');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN est_wt_rate DECIMAL(5,2) NULL AFTER est_gross',
  'SELECT ''brgy_projects: est_wt_rate already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'est_wt_rate');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN est_profit_rate DECIMAL(5,2) NULL AFTER est_wt_rate',
  'SELECT ''brgy_projects: est_profit_rate already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'est_profit_rate');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN est_receipt_rate DECIMAL(5,2) NULL AFTER est_profit_rate',
  'SELECT ''brgy_projects: est_receipt_rate already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'est_receipt_rate');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN est_sop DECIMAL(15,2) NULL AFTER est_receipt_rate',
  'SELECT ''brgy_projects: est_sop already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'est_sop');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN dv_gross DECIMAL(15,2) NULL AFTER est_sop',
  'SELECT ''brgy_projects: dv_gross already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'dv_gross');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN dv_vat_rate DECIMAL(5,2) NULL AFTER dv_gross',
  'SELECT ''brgy_projects: dv_vat_rate already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'dv_vat_rate');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN dv_ewt_rate DECIMAL(5,2) NULL AFTER dv_vat_rate',
  'SELECT ''brgy_projects: dv_ewt_rate already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'dv_ewt_rate');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE brgy_projects ADD COLUMN cheque_tradename VARCHAR(160) NULL AFTER dv_ewt_rate',
  'SELECT ''brgy_projects: cheque_tradename already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'brgy_projects' AND column_name = 'cheque_tradename');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  3. and 4. The request for quotation and the inspection and acceptance report
-- ---------------------------------------------------------------------
-- RFQ
SET @rfq_body = '<div>{{letterhead}}</div><div class="fx-title" style="text-align:center;margin:12px 0 8px">REQUEST FOR QUOTATION</div><table class="fx-plain" style="width:100%"><tbody><tr><td style="width:56%"></td><td>DATE: <u>{{rfq.date}}</u><br>QUOTATION NO.: <u>{{pr.no}}</u></td></tr></tbody></table><p style="margin:12px 0 0;text-align:justify">Please quote your lowest price on the item/s listed below, subject to the General Conditions stated, stating the shortest time of delivery and submit your quotation duly signed by your representative not later than ____________________, __________ thru email: {{entity.email}} or personally at The Secretariat, Bids and Awards Committee, {{entity.short}} Barangay Hall, {{entity.short}}, {{entity.city}}.</p><div>{{signatories}}</div><div data-section="conditions" title="General conditions"><p style="margin:10px 0 2px">GENERAL CONDITIONS:</p><ol style="margin:0 0 6px;padding-left:2em"><li>All entries must be handwritten / typewritten legibly.</li><li>Approved Budget of the Contract (ABC) is <b>PHP {{project.abc_plain}}</b></li><li>Delivery period is within {{rfq.delivery_words}}.</li><li>Warranty shall be for a period of six (6) months for supplies and materials, one (1) year for equipment, from date of acceptance by the Procuring Entity.</li><li>Price shall be inclusive of VAT.</li><li>Please submit the following documents:<ol type="a" style="margin:0;padding-left:2.6em"><li>PhilGeps Registration Number</li><li>DTI/SEC Registration</li><li>Mayor\'s Permit</li><li>BIR Form No. 2303</li><li>Tax Clearance, if available</li></ol></li></ol></div>{{rfq.items}}<p style="margin:6px 0 0">After having carefully read and accepted your General Conditions, I/We quote you on the item at prices noted above.</p><table class="fx-plain" style="width:100%;margin-top:28px"><tbody><tr><td style="width:56%"></td><td><div style="border-top:1px solid #000;text-align:center">Printed Name / Signature</div></td></tr><tr><td style="padding-top:28px"><div style="border-top:1px solid #000;width:88%">NAME OF ESTABLISHMENT</div></td><td style="padding-top:28px"><div style="border-top:1px solid #000">DATE</div></td></tr></tbody></table>';
SET @rfq_layout = '{"margin_top":10,"margin_right":15,"margin_bottom":10,"margin_left":15,"line_height":1.25,"cell_pad":2,"font_size":10,"table_size":9.5,"title_size":13,"blank_rows":6,"sig_top":6,"sig_space":8,"signatories":[{"lead":"","name":"{{entity.bac_chair_upper}}","pos":"BAC Chairman","extra":"","align":"half","newrow":0,"line":0,"bold":1,"underline":0,"upper":0,"italic":0}],"lh_style":"own","lh_left":"seal","lh_right":"pic_a","lh_lines":"**REPUBLIC OF THE PHILIPPINES**\\nProvince of {{entity.province}}\\n{{entity.lgu_line}}\\n{{entity.title}}\\n**BIDS AND AWARD COMMITTEE**","lh_align":"center","lh_font":"","lh_size":10.5,"lh_big":15,"lh_pic_mm":24,"lh_box":0,"lh_box_bg":"#ffffff","lh_box_fg":"#111111","lh_rule":0,"lh_rule_color":"#111111","lh_space":4,"lh_pic_a":"","lh_pic_b":""}';
SET @rfq_desc = 'The canvass form given to each supplier: the letterhead of the BAC with the seal, the general conditions, the items with blank prices for the supplier to fill in and sign.';
-- IAR
SET @iar_old1 = '<br>Requisitioning Office/Dept.: {{project.end_user}}<br>Responsibility Center Code:';
SET @iar_old2 = '<td>IAR No.: {{iar.no}}<br>Date: {{iar.date}}<br>Invoice No.: {{iar.invoice_no}}<br>Date: {{iar.invoice_date}}</td>';
SET @iar_new2 = '<td><table class="fx-plain" style="width:100%"><tbody><tr><td style="width:55%;padding:0">IAR No.: {{iar.no}}<br>Date: {{iar.date}}</td><td style="padding:0">RIS No.: {{ris.no}}<br>Date: {{ris.date}}</td></tr><tr><td colspan="2" style="padding:0">Invoice No.: {{iar.invoice_no}}<br>Date: {{iar.invoice_date}}</td></tr></tbody></table></td>';

-- the request for quotation: the version before is kept, then the new format (only once)
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Kept before version 34'
  FROM brgy_templates t
 WHERE t.code = 'request_for_quotation' AND INSTR(t.body, '{{rfq.delivery_words}}') = 0
   AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

UPDATE brgy_templates SET body = @rfq_body, layout_json = @rfq_layout, description = @rfq_desc, version_no = version_no + 1, updated_at = NOW()
 WHERE code = 'request_for_quotation' AND INSTR(body, '{{rfq.delivery_words}}') = 0;

INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Version 34: the request for quotation of the barangay (BAC letterhead, general conditions)'
  FROM brgy_templates t
 WHERE t.code = 'request_for_quotation' AND INSTR(t.body, '{{rfq.delivery_words}}') > 0
   AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

-- the inspection and acceptance report: the version before is kept, then the lines changed (only where they are found)
INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Kept before version 34'
  FROM brgy_templates t
 WHERE t.code = 'inspection_acceptance_report' AND (INSTR(t.body, @iar_old1) > 0 OR INSTR(t.body, @iar_old2) > 0)
   AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

UPDATE brgy_templates SET body = REPLACE(REPLACE(body, @iar_old1, ''), @iar_old2, @iar_new2), version_no = version_no + 1, updated_at = NOW()
 WHERE code = 'inspection_acceptance_report' AND (INSTR(body, @iar_old1) > 0 OR INSTR(body, @iar_old2) > 0);

INSERT INTO brgy_template_versions (template_id, version_no, name, body, layout_json, positions_json, change_note)
SELECT t.id, t.version_no, t.name, t.body, t.layout_json, t.positions_json, 'Version 34: RIS No. and Date beside the IAR No.; no requisitioning office, no responsibility center code'
  FROM brgy_templates t
 WHERE t.code = 'inspection_acceptance_report' AND INSTR(t.body, @iar_new2) > 0
   AND NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id AND v.version_no = t.version_no);

-- ---------------------------------------------------------------------
--  The audit trail
-- ---------------------------------------------------------------------
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'People accountable by user, estimates, DV, RFQ, IAR',
        'Migration 2026_11_21: users allowed per function, estimates of the transaction form, DV amounts, cheque tradename, request for quotation and IAR formats');
