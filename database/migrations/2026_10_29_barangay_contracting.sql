-- =====================================================================
--  G-SMaRT Integrated Accounting and Government Contracting System - migration: Barangay Contracts
--  (government contracting with barangays and other procuring entities)
--
--  What it changes
--    1. A new module, Barangay Contracts, between BIR Reports and System Administration, with:
--         Contracting Dashboard, Projects & Contracts, Approval Queue, Deliveries & Acceptance,
--         Billing Packs, Collections & Retention, Forms & Proformas, Document Vault,
--         Barangays & Procuring Entities, Proforma & Form Templates, Compliance Requirement Library,
--         Procurement Modes, Regulatory References, Contracting Settings, and 18 reports.
--    2. 28 new tables, all named brgy_... (nothing else is altered).
--    3. Six roles for the contracting staff (Bid & Compliance Officer, Approver, Project Manager,
--       Warehouse & Delivery, Billing Officer, Collection Officer) with their page rights; the role
--       named Accountant (when there is one) may view and print the module and review in the
--       Approval Queue. Administrators see everything as before.
--    4. Accounts: Retention Receivable, Bid and Performance Security Deposits, Contract Revenue - Works,
--       Liquidated Damages and Loss on Forfeited Security (an account of that name is used when there is
--       one; otherwise it is added with the first free code of a short list), and seven module default
--       accounts (Chart of Accounts > Module Default Accounts > Barangay Contracts). Revenue on goods and
--       services goes to your default sales / service income accounts.
--    5. A site "Delivered, awaiting acceptance" (BRGY-DLV) for goods at the procuring entity not yet
--       accepted, the customer type Government, number series BCP-, BDR-, BBL-, BCL-, BCO-, BFM-, BSC-,
--       and the module settings (company details on the forms are left blank: Contracting Settings).
--    6. The set-up lists: kinds of documents, procurement modes under RA 12009 (and RA 9184 for
--       procurements started before it), regulatory references, the compliance requirement library and
--       31 proforma / form templates (quotations, proposals, contracts, letters, receipts, reports).
--
--  Nothing is deleted and no amount is changed.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  With several companies, run it on every company database (the Super Admin's
--  Companies > Database updates > Update all companies does that).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click your database (government_contracting_system) > Import > choose this file > Go
--    Navicat:    right-click your database (government_contracting_system) > Execute SQL File
--    or:         mysql -u root -p government_contracting_system < 2026_10_29_barangay_contracting.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The tables
-- ---------------------------------------------------------------------

-- Procurement modes: configuration with effective dates, never constants in the program
CREATE TABLE IF NOT EXISTS brgy_proc_modes (
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
CREATE TABLE IF NOT EXISTS brgy_references (
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
CREATE TABLE IF NOT EXISTS brgy_doc_types (
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
CREATE TABLE IF NOT EXISTS brgy_entities (
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
CREATE TABLE IF NOT EXISTS brgy_contacts (
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
CREATE TABLE IF NOT EXISTS brgy_projects (
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
  lock_version           INT UNSIGNED NOT NULL DEFAULT 0,
  created_by             INT UNSIGNED NULL,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by             INT UNSIGNED NULL,
  updated_at             DATETIME     NULL,
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
CREATE TABLE IF NOT EXISTS brgy_project_lines (
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
CREATE TABLE IF NOT EXISTS brgy_cost_versions (
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
CREATE TABLE IF NOT EXISTS brgy_requirements (
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
CREATE TABLE IF NOT EXISTS brgy_documents (
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
CREATE TABLE IF NOT EXISTS brgy_project_reqs (
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
CREATE TABLE IF NOT EXISTS brgy_templates (
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

CREATE TABLE IF NOT EXISTS brgy_template_versions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  version_no  INT          NOT NULL,
  name        VARCHAR(160) NOT NULL,
  body        MEDIUMTEXT   NOT NULL,
  change_note VARCHAR(255) NULL,
  changed_by  INT UNSIGNED NULL,
  changed_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_btv (template_id, version_no),
  CONSTRAINT fk_btv_template FOREIGN KEY (template_id) REFERENCES brgy_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents made from the templates for a project (letters, schedules, contracts, receipts ...)
CREATE TABLE IF NOT EXISTS brgy_forms (
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
CREATE TABLE IF NOT EXISTS brgy_workflow (
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
CREATE TABLE IF NOT EXISTS brgy_delegations (
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
CREATE TABLE IF NOT EXISTS brgy_submissions (
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
CREATE TABLE IF NOT EXISTS brgy_events (
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
CREATE TABLE IF NOT EXISTS brgy_change_orders (
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

CREATE TABLE IF NOT EXISTS brgy_co_lines (
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
CREATE TABLE IF NOT EXISTS brgy_deliveries (
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

CREATE TABLE IF NOT EXISTS brgy_delivery_lines (
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
CREATE TABLE IF NOT EXISTS brgy_billings (
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

CREATE TABLE IF NOT EXISTS brgy_billing_lines (
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
CREATE TABLE IF NOT EXISTS brgy_collections (
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
CREATE TABLE IF NOT EXISTS brgy_securities (
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
CREATE TABLE IF NOT EXISTS brgy_tasks (
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
CREATE TABLE IF NOT EXISTS brgy_postings (
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

-- ---------------------------------------------------------------------
--  2. The module and its pages
-- ---------------------------------------------------------------------

INSERT IGNORE INTO modules (code, name, icon, sort_order) VALUES ('brgy', 'Barangay Contracts', 'briefcase', 10);
UPDATE modules SET sort_order = 11 WHERE code = 'admin';
UPDATE modules SET sort_order = 12 WHERE code = 'company';

SET @brgy = (SELECT id FROM modules WHERE code = 'brgy' LIMIT 1);
SET @brgy_sort = (SELECT COALESCE(MAX(sort_order), 900) FROM pages WHERE page_key NOT LIKE 'brgy.%' AND page_key NOT LIKE 'rpt.brgy%');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('brgy.dashboard', @brgy, 'Contracting Dashboard', 'center', 'Transactions', @brgy_sort + 10, 1),
  ('brgy.projects', @brgy, 'Projects & Contracts', 'center', 'Transactions', @brgy_sort + 20, 1),
  ('brgy.approvals', @brgy, 'Approval Queue', 'center', 'Transactions', @brgy_sort + 30, 1),
  ('brgy.deliveries', @brgy, 'Deliveries & Acceptance', 'transaction', 'Transactions', @brgy_sort + 40, 1),
  ('brgy.billing', @brgy, 'Billing Packs', 'transaction', 'Transactions', @brgy_sort + 50, 1),
  ('brgy.collections', @brgy, 'Collections & Retention', 'transaction', 'Transactions', @brgy_sort + 60, 1),
  ('brgy.forms', @brgy, 'Forms & Proformas', 'transaction', 'Transactions', @brgy_sort + 70, 1),
  ('brgy.vault', @brgy, 'Document Vault', 'center', 'Transactions', @brgy_sort + 80, 1),
  ('brgy.entities', @brgy, 'Barangays & Procuring Entities', 'list', 'Lists', @brgy_sort + 90, 1),
  ('brgy.templates', @brgy, 'Proforma & Form Templates', 'setup', 'Setup', @brgy_sort + 100, 1),
  ('brgy.requirements', @brgy, 'Compliance Requirement Library', 'setup', 'Setup', @brgy_sort + 110, 1),
  ('brgy.modes', @brgy, 'Procurement Modes', 'setup', 'Setup', @brgy_sort + 120, 1),
  ('brgy.references', @brgy, 'Regulatory References', 'setup', 'Setup', @brgy_sort + 130, 1),
  ('brgy.settings', @brgy, 'Contracting Settings', 'setup', 'Setup', @brgy_sort + 140, 1),
  ('rpt.brgy_bid_register', @brgy, 'Bid Register', 'report', 'Barangay Contract Reports', @brgy_sort + 150, 1),
  ('rpt.brgy_win_loss', @brgy, 'Won and Lost Opportunities', 'report', 'Barangay Contract Reports', @brgy_sort + 160, 1),
  ('rpt.brgy_eligibility', @brgy, 'Eligibility and Compliance Matrix', 'report', 'Barangay Contract Reports', @brgy_sort + 170, 1),
  ('rpt.brgy_submissions', @brgy, 'Tender and Document Submission Log', 'report', 'Barangay Contract Reports', @brgy_sort + 180, 1),
  ('rpt.brgy_contract_status', @brgy, 'Contract Status: Awarded, Delivered, Billed, Collected', 'report', 'Barangay Contract Reports', @brgy_sort + 190, 1),
  ('rpt.brgy_delivery_variance', @brgy, 'Delivery and Implementation Variance', 'report', 'Barangay Contract Reports', @brgy_sort + 200, 1),
  ('rpt.brgy_inspection', @brgy, 'Inspection and Acceptance Register', 'report', 'Barangay Contract Reports', @brgy_sort + 210, 1),
  ('rpt.brgy_billing_pack', @brgy, 'Billing Pack Completeness', 'report', 'Barangay Contract Reports', @brgy_sort + 220, 1),
  ('rpt.brgy_ar_aging', @brgy, 'A/R Aging by Barangay', 'report', 'Barangay Contract Reports', @brgy_sort + 230, 1),
  ('rpt.brgy_collection_perf', @brgy, 'Collection Performance by Barangay', 'report', 'Barangay Contract Reports', @brgy_sort + 240, 1),
  ('rpt.brgy_change_orders', @brgy, 'Change Order Register', 'report', 'Barangay Contract Reports', @brgy_sort + 250, 1),
  ('rpt.brgy_retention', @brgy, 'Retention and Warranty Tracker', 'report', 'Barangay Contract Reports', @brgy_sort + 260, 1),
  ('rpt.brgy_profitability', @brgy, 'Project Profitability', 'report', 'Barangay Contract Reports', @brgy_sort + 270, 1),
  ('rpt.brgy_wht_credits', @brgy, 'Taxes Withheld by Barangays (2307 Tracker)', 'report', 'Barangay Contract Reports', @brgy_sort + 280, 1),
  ('rpt.brgy_doc_expiry', @brgy, 'Document Expiry and Missing Papers', 'report', 'Barangay Contract Reports', @brgy_sort + 290, 1),
  ('rpt.brgy_cash_forecast', @brgy, 'Cash Collection Forecast', 'report', 'Barangay Contract Reports', @brgy_sort + 300, 1),
  ('rpt.brgy_reconciliation', @brgy, 'Contracts-to-Ledger Reconciliation', 'report', 'Barangay Contract Reports', @brgy_sort + 310, 1),
  ('rpt.brgy_approval_log', @brgy, 'Approval Log', 'report', 'Barangay Contract Reports', @brgy_sort + 320, 1);

-- ---------------------------------------------------------------------
--  3. Roles of the contracting staff, and what the Accountant may see
-- ---------------------------------------------------------------------

INSERT IGNORE INTO roles (name, description, is_admin, is_system, all_sites) VALUES
  ('Contracts - Bid & Compliance Officer', 'Barangay Contracts: finds opportunities, keeps the directory and the document vault, prepares bids, checklists and forms, and records submissions.', 0, 1, 1),
  ('Contracts - Approver (Management)', 'Barangay Contracts: approves bids, billing packs, change orders and forms within the approval limit, waives requirements, closes out and reopens contracts, and keeps the set-up lists.', 0, 1, 1),
  ('Contracts - Project Manager', 'Barangay Contracts: mobilizes awarded contracts, records deliveries and the entity\'s inspection, prepares change orders, tasks and progress reports.', 0, 1, 1),
  ('Contracts - Warehouse & Delivery', 'Barangay Contracts: records deliveries from the warehouse and files the signed delivery receipts.', 0, 1, 1),
  ('Contracts - Billing Officer', 'Barangay Contracts: prepares billing packs for accepted deliveries, issues the invoices once approved, submits the packs and follows up their acknowledgment.', 0, 1, 1),
  ('Contracts - Collection Officer', 'Barangay Contracts: records collections with the taxes withheld and deductions, returned checks, retention releases and the BIR Forms 2307 received.', 0, 1, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, x.al, x.cc, x.ce, x.cd, x.cp, x.ca FROM (
  SELECT 'Accountant' AS rn, 'brgy.dashboard' AS pk, 1 AS al, 0 AS cc, 0 AS ce, 0 AS cd, 1 AS cp, 0 AS ca
  UNION ALL SELECT 'Accountant', 'brgy.projects', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'brgy.approvals', 1, 0, 1, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'brgy.deliveries', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'brgy.billing', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'brgy.collections', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'brgy.forms', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'brgy.vault', 1, 0, 1, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'brgy.entities', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'brgy.references', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_bid_register', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_win_loss', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_eligibility', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_submissions', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_contract_status', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_delivery_variance', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_inspection', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_billing_pack', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_ar_aging', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_collection_perf', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_change_orders', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_retention', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_profitability', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_wht_credits', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_doc_expiry', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_cash_forecast', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_reconciliation', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Accountant', 'rpt.brgy_approval_log', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'home.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'home.reports', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'home.memorized', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'home.report_layouts', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'home.search', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'home.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'rpt.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'inv.items', 1, 0, 0, 0, 0, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.projects', 1, 1, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.approvals', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.forms', 1, 1, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.vault', 1, 1, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.entities', 1, 1, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.templates', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.requirements', 1, 1, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.modes', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'brgy.references', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'rpt.brgy_bid_register', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'rpt.brgy_win_loss', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'rpt.brgy_eligibility', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'rpt.brgy_submissions', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'rpt.brgy_doc_expiry', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'home.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'home.reports', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'home.memorized', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'home.report_layouts', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'home.search', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'home.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.income_statement', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'cust.center', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.ar_aging_summary', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.projects', 1, 0, 1, 0, 1, 1
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.approvals', 1, 1, 1, 0, 1, 1
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.deliveries', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.billing', 1, 0, 0, 0, 1, 1
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.collections', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.forms', 1, 0, 0, 0, 1, 1
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.vault', 1, 0, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.entities', 1, 0, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.templates', 1, 1, 1, 1, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.requirements', 1, 1, 1, 1, 1, 1
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.modes', 1, 1, 1, 1, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.references', 1, 1, 1, 1, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy.settings', 1, 0, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_bid_register', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_win_loss', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_eligibility', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_submissions', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_contract_status', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_delivery_variance', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_inspection', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_billing_pack', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_ar_aging', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_collection_perf', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_change_orders', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_retention', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_profitability', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_wht_credits', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_doc_expiry', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_cash_forecast', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_reconciliation', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Approver (Management)', 'rpt.brgy_approval_log', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'home.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'home.reports', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'home.memorized', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'home.report_layouts', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'home.search', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'home.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'rpt.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'inv.items', 1, 0, 0, 0, 0, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'rpt.qoh_by_site', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'brgy.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'brgy.projects', 1, 0, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'brgy.approvals', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'brgy.deliveries', 1, 1, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'brgy.forms', 1, 1, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'brgy.vault', 1, 1, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'brgy.entities', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'rpt.brgy_contract_status', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'rpt.brgy_delivery_variance', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'rpt.brgy_inspection', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Project Manager', 'rpt.brgy_change_orders', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Warehouse & Delivery', 'home.dashboard', 1, 0, 0, 0, 0, 0
  UNION ALL SELECT 'Contracts - Warehouse & Delivery', 'rpt.qoh_by_site', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Warehouse & Delivery', 'brgy.projects', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Warehouse & Delivery', 'brgy.deliveries', 1, 1, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Warehouse & Delivery', 'brgy.forms', 1, 1, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Warehouse & Delivery', 'brgy.vault', 1, 1, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'home.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'home.reports', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'home.memorized', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'home.report_layouts', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'home.search', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'home.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'rpt.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'cust.center', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'cust.invoices', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'brgy.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'brgy.projects', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'brgy.approvals', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'brgy.billing', 1, 1, 1, 1, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'brgy.forms', 1, 1, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'brgy.vault', 1, 1, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'rpt.brgy_contract_status', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'rpt.brgy_billing_pack', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Billing Officer', 'rpt.brgy_ar_aging', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'home.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'home.reports', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'home.memorized', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'home.report_layouts', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'home.search', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'home.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'rpt.find', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'cust.center', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'cust.payments', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'brgy.dashboard', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'brgy.projects', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'brgy.billing', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'brgy.collections', 1, 1, 1, 1, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'brgy.forms', 1, 1, 1, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'brgy.vault', 1, 1, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'rpt.brgy_ar_aging', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'rpt.brgy_collection_perf', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'rpt.brgy_retention', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'rpt.brgy_wht_credits', 1, 0, 0, 0, 1, 0
  UNION ALL SELECT 'Contracts - Collection Officer', 'rpt.brgy_cash_forecast', 1, 0, 0, 0, 1, 0
) x JOIN roles r ON r.name = x.rn JOIN pages p ON p.page_key = x.pk;

INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT r.id, x.pk, x.pv FROM (
  SELECT 'Contracts - Bid & Compliance Officer' AS rn, 'brgy_approval_limit' AS pk, '0' AS pv
  UNION ALL SELECT 'Contracts - Bid & Compliance Officer', 'view_costs', '1'
  UNION ALL SELECT 'Contracts - Approver (Management)', 'brgy_approval_limit', ''
  UNION ALL SELECT 'Contracts - Approver (Management)', 'view_costs', '1'
  UNION ALL SELECT 'Contracts - Project Manager', 'brgy_approval_limit', '0'
  UNION ALL SELECT 'Contracts - Project Manager', 'view_costs', '0'
  UNION ALL SELECT 'Contracts - Warehouse & Delivery', 'view_costs', '0'
  UNION ALL SELECT 'Contracts - Billing Officer', 'brgy_approval_limit', '0'
  UNION ALL SELECT 'Contracts - Billing Officer', 'view_costs', '0'
  UNION ALL SELECT 'Contracts - Collection Officer', 'view_costs', '0'
) x JOIN roles r ON r.name = x.rn;

-- ---------------------------------------------------------------------
--  4. Accounts and module default accounts (only when not set yet)
--     An account of the same name is used when there is one; otherwise the account is added
--     with the first free code of a short list, so no account of yours is ever taken over.
-- ---------------------------------------------------------------------

-- Retention Receivable (Asset)
SET @a = (SELECT id FROM accounts WHERE name = 'Retention Receivable' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '1160' AS c, 1 AS o UNION ALL SELECT '1161', 2 UNION ALL SELECT '1165', 3 UNION ALL SELECT '1169', 4 UNION ALL SELECT '1190', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Retention Receivable', 'Other Current Asset', 'Asset', 'Debit', 'Retention deducted by procuring entities, released after the warranty'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Retention Receivable' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('retention_receivable', 'Retention Receivable', 'brgy', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'retention_receivable' AND account_id IS NULL;

-- Bid and Performance Security Deposits (Asset)
SET @a = (SELECT id FROM accounts WHERE name = 'Bid and Performance Security Deposits' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '1420' AS c, 1 AS o UNION ALL SELECT '1421', 2 UNION ALL SELECT '1425', 3 UNION ALL SELECT '1429', 4 UNION ALL SELECT '1491', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Bid and Performance Security Deposits', 'Other Current Asset', 'Asset', 'Debit', 'Bid, performance and warranty securities paid in cash or by check'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Bid and Performance Security Deposits' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('security_deposits', 'Bid and Performance Security Deposits', 'brgy', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'security_deposits' AND account_id IS NULL;

-- Contract Revenue - Works (Income)
SET @a = (SELECT id FROM accounts WHERE name = 'Contract Revenue - Works' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '4015' AS c, 1 AS o UNION ALL SELECT '4016', 2 UNION ALL SELECT '4018', 3 UNION ALL SELECT '4019', 4 UNION ALL SELECT '4095', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Contract Revenue - Works', 'Income', 'Income', 'Credit', 'Infrastructure works billed to procuring entities'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Contract Revenue - Works' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('gov_works_income', 'Contract Revenue - Works', 'brgy', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'gov_works_income' AND account_id IS NULL;

-- Liquidated Damages (Expense)
SET @a = (SELECT id FROM accounts WHERE name = 'Liquidated Damages' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '6085' AS c, 1 AS o UNION ALL SELECT '6086', 2 UNION ALL SELECT '6087', 3 UNION ALL SELECT '6089', 4 UNION ALL SELECT '6095', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Liquidated Damages', 'Expense', 'Expense', 'Debit', 'Deducted by procuring entities for late delivery or completion'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Liquidated Damages' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('liquidated_damages', 'Liquidated Damages', 'brgy', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'liquidated_damages' AND account_id IS NULL;

-- Loss on Forfeited Security (Expense)
SET @a = (SELECT id FROM accounts WHERE name = 'Loss on Forfeited Security' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '7010' AS c, 1 AS o UNION ALL SELECT '7011', 2 UNION ALL SELECT '7015', 3 UNION ALL SELECT '7019', 4 UNION ALL SELECT '7090', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Loss on Forfeited Security', 'Other Expense', 'Expense', 'Debit', 'Bid or performance security forfeited by a procuring entity'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Loss on Forfeited Security' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('forfeited_security', 'Loss on Forfeited Security', 'brgy', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'forfeited_security' AND account_id IS NULL;

-- revenue on goods: the default sales income account; on services: an account named Service Income, or the same sales account
SET @a = (SELECT account_id FROM default_accounts WHERE setting_key = 'sales_income' LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('gov_goods_income', 'Contract Revenue - Goods', 'brgy', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'gov_goods_income' AND account_id IS NULL;
SET @a = COALESCE((SELECT id FROM accounts WHERE name = 'Service Income' AND account_type = 'Income' ORDER BY id LIMIT 1), @a);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('gov_services_income', 'Contract Revenue - Services', 'brgy', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'gov_services_income' AND account_id IS NULL;

-- ---------------------------------------------------------------------
--  5. Site, customer type, numbering and settings
-- ---------------------------------------------------------------------

INSERT IGNORE INTO sites (code, name, site_type, address) VALUES ('BRGY-DLV', 'Delivered, awaiting acceptance', 'other', 'Goods at the procuring entity, not yet accepted');
INSERT INTO customer_types (name) SELECT 'Government' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM customer_types WHERE name = 'Government');

INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES
  ('brgy_project', 'BCP-', 1, 5),
  ('brgy_delivery', 'BDR-', 1, 5),
  ('brgy_billing', 'BBL-', 1, 5),
  ('brgy_collection', 'BCL-', 1, 5),
  ('brgy_co', 'BCO-', 1, 5),
  ('brgy_form', 'BFM-', 1, 5),
  ('brgy_security', 'BSC-', 1, 5);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('brgy_company_reg_no', ''),
  ('brgy_philgeps_no', ''),
  ('brgy_philgeps_class', ''),
  ('brgy_bir_status', 'vat'),
  ('brgy_signatory_name', ''),
  ('brgy_signatory_position', ''),
  ('brgy_bank_details', ''),
  ('brgy_prices_vat_inclusive', '1'),
  ('brgy_sod_strict', '1'),
  ('brgy_block_over_abc', '1'),
  ('brgy_gate_documents', '1'),
  ('brgy_gate_deadline', '1'),
  ('brgy_require_acceptance_doc', '1'),
  ('brgy_require_pack_complete', '1'),
  ('brgy_require_perf_security', '0'),
  ('brgy_create_class', '1'),
  ('brgy_default_retention_pct', '0'),
  ('brgy_default_ld_rate', '0'),
  ('brgy_wht_goods', 'WT-GOODS'),
  ('brgy_wht_services', 'WT-SERVICES'),
  ('brgy_wht_vat', 'WT-VAT'),
  ('brgy_billing_pack', 'invoice|Sales invoice (BIR-registered)|1\nsoa|Statement of account|1\ndr|Delivery receipt, signed by the entity|1\niar|Inspection and acceptance report|1\npo|Purchase order / contract copy|1\naccomplishment|Accomplishment report (services and works)|0\nphoto|Photos of the delivery / work|0'),
  ('brgy_alert_days', '30'),
  ('brgy_bid_alert_days', '7'),
  ('brgy_accept_alert_days', '7'),
  ('brgy_ack_alert_days', '15'),
  ('brgy_upload_max_mb', '15'),
  ('brgy_scan_command', ''),
  ('brgy_alert_email', '');

INSERT IGNORE INTO settings (setting_key, setting_value) SELECT 'brgy_delivered_site_id', id FROM sites WHERE code = 'BRGY-DLV';
UPDATE settings s JOIN sites t ON t.code = 'BRGY-DLV' SET s.setting_value = t.id WHERE s.setting_key = 'brgy_delivered_site_id' AND s.setting_value = '';

-- ---------------------------------------------------------------------
--  6. The set-up lists: kinds of documents, procurement modes, references, requirements, templates
-- ---------------------------------------------------------------------

INSERT IGNORE INTO brgy_doc_types (code, name, doc_group, is_company, needs_validity, validity_months, sort_order, is_active) VALUES
  ('accomplishment', 'Accomplishment / service report', 'delivery', 0, 0, NULL, 380, 1),
  ('afs', 'Audited financial statements', 'financial', 1, 1, 12, 110, 1),
  ('authority', 'Secretary\'s certificate / special power of attorney', 'legal', 1, 0, NULL, 70, 1),
  ('bid_security', 'Bid security / bid securing declaration', 'bid', 0, 1, NULL, 270, 1),
  ('billing_letter', 'Billing cover letter / request for payment', 'billing', 0, 0, NULL, 420, 1),
  ('bir_2303', 'BIR Certificate of Registration (Form 2303)', 'legal', 1, 0, NULL, 40, 1),
  ('brochure', 'Brochures and technical data sheets', 'technical', 0, 0, NULL, 240, 1),
  ('bulletin', 'Supplemental bid bulletin', 'bid', 0, 0, NULL, 160, 1),
  ('business_tax_return', 'Business tax returns (VAT / percentage tax)', 'financial', 1, 0, NULL, 100, 1),
  ('change_order', 'Approved variation / change order', 'contract', 0, 0, NULL, 350, 1),
  ('closeout', 'Completion / closeout papers', 'contract', 0, 0, NULL, 480, 1),
  ('company_profile', 'Company profile', 'technical', 1, 0, NULL, 140, 1),
  ('contract', 'Contract agreement', 'contract', 0, 0, NULL, 320, 1),
  ('correspondence', 'Letter / correspondence', 'other', 0, 0, NULL, 290, 1),
  ('cover_letter', 'Cover letter', 'bid', 0, 0, NULL, 250, 1),
  ('dr', 'Delivery receipt (signed)', 'delivery', 0, 0, NULL, 360, 1),
  ('followup_letter', 'Collection follow-up letter', 'collection', 0, 0, NULL, 470, 1),
  ('form_2307', 'BIR Form 2307 from the entity', 'collection', 0, 0, NULL, 460, 1),
  ('iar', 'Inspection and acceptance report', 'delivery', 0, 0, NULL, 370, 1),
  ('internal', 'Internal sheet (approval, review, reconciliation)', 'other', 0, 0, NULL, 500, 1),
  ('invoice', 'Sales invoice', 'billing', 0, 0, NULL, 400, 1),
  ('itr', 'Latest income tax return', 'financial', 1, 0, NULL, 90, 1),
  ('mayors_permit', 'Mayor\'s / business permit', 'legal', 1, 1, 12, 20, 1),
  ('method_statement', 'Method statement', 'technical', 0, 0, NULL, 210, 1),
  ('nfcc', 'NFCC computation / committed line of credit', 'financial', 1, 0, NULL, 120, 1);

INSERT IGNORE INTO brgy_doc_types (code, name, doc_group, is_company, needs_validity, validity_months, sort_order, is_active) VALUES
  ('noa', 'Notice of award', 'contract', 0, 0, NULL, 300, 1),
  ('ntp', 'Notice to proceed', 'contract', 0, 0, NULL, 330, 1),
  ('omnibus', 'Omnibus sworn statement', 'bid', 0, 0, NULL, 260, 1),
  ('or_ar', 'Acknowledgment / collection receipt', 'collection', 0, 0, NULL, 450, 1),
  ('other', 'Other document', 'other', 0, 0, NULL, 510, 1),
  ('pcab_license', 'PCAB contractor license', 'legal', 1, 1, 12, 60, 1),
  ('perf_security', 'Performance security', 'contract', 0, 1, NULL, 340, 1),
  ('philgeps_cert', 'PhilGEPS registration certificate (Platinum)', 'legal', 1, 1, 12, 10, 1),
  ('photo', 'Photos of the delivery / work', 'delivery', 0, 0, NULL, 390, 1),
  ('po', 'Purchase order / job order', 'contract', 0, 0, NULL, 310, 1),
  ('price_schedule', 'Price schedule / bill of quantities', 'bid', 0, 0, NULL, 180, 1),
  ('prof_license', 'Professional licenses of key personnel', 'legal', 1, 1, 36, 80, 1),
  ('proposal', 'Supply / services / scope-of-work proposal', 'technical', 0, 0, NULL, 230, 1),
  ('quotation', 'Price quotation / bid form', 'bid', 0, 0, NULL, 190, 1),
  ('receiving_copy', 'Receiving copy / acknowledgment of the entity', 'billing', 0, 0, NULL, 440, 1),
  ('rfq', 'RFQ / invitation to bid / notice', 'bid', 0, 0, NULL, 150, 1),
  ('schedule', 'Implementation / delivery schedule', 'technical', 0, 0, NULL, 220, 1),
  ('sec_dti', 'SEC / DTI / CDA registration', 'legal', 1, 0, NULL, 30, 1),
  ('slcc', 'Statement of ongoing and completed contracts', 'technical', 1, 0, NULL, 130, 1),
  ('soa', 'Statement of account', 'billing', 0, 0, NULL, 410, 1),
  ('specs', 'Technical specifications / terms of reference', 'technical', 0, 0, NULL, 170, 1),
  ('submission_proof', 'Proof of submission / receiving copy', 'bid', 0, 0, NULL, 280, 1),
  ('supplier_request', 'Request to a supplier / subcontractor', 'other', 0, 0, NULL, 490, 1),
  ('tax_clearance', 'Tax clearance', 'legal', 1, 1, 12, 50, 1),
  ('technical_proposal', 'Technical proposal / statement of compliance', 'technical', 0, 0, NULL, 200, 1);

INSERT IGNORE INTO brgy_doc_types (code, name, doc_group, is_company, needs_validity, validity_months, sort_order, is_active) VALUES
  ('transmittal', 'Transmittal letter', 'billing', 0, 0, NULL, 430, 1);

INSERT IGNORE INTO brgy_proc_modes (code, name, regime, legal_basis, source_url, max_abc, needs_bid_security, needs_perf_security, default_retention_pct, effective_from, effective_to, notes, sort_order, is_active) VALUES
  ('competitive_bidding', 'Competitive Bidding', 'ra12009', 'RA 12009 and its IRR (default mode)', 'https://www.gppb.gov.ph/', NULL, 1, 1, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 10, 1),
  ('limited_source', 'Limited Source Bidding', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 1, 1, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 20, 1),
  ('direct_contracting', 'Direct Contracting', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 0, 1, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 30, 1),
  ('direct_acquisition', 'Direct Acquisition', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 0, 0, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 40, 1),
  ('repeat_order', 'Repeat Order', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 0, 1, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 50, 1),
  ('small_value', 'Small Value Procurement', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 0, 0, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 60, 1),
  ('negotiated_emergency', 'Negotiated Procurement (Emergency Cases)', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 0, 0, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 70, 1),
  ('negotiated_failure', 'Negotiated Procurement (Two Failed Biddings)', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 0, 1, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 80, 1),
  ('negotiated_other', 'Negotiated Procurement (Other Cases)', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 0, 0, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 90, 1),
  ('direct_sales', 'Direct Sales', 'ra12009', 'RA 12009 and its IRR', 'https://www.gppb.gov.ph/', NULL, 0, 0, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 100, 1),
  ('public_bidding_9184', 'Public Bidding', 'ra9184', 'RA 9184 and its 2016 revised IRR (procurement started before the RA 12009 rules applied)', 'https://www.gppb.gov.ph/', NULL, 1, 1, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 110, 1),
  ('shopping_9184', 'Shopping', 'ra9184', 'RA 9184 and its 2016 revised IRR (transition cases)', 'https://www.gppb.gov.ph/', NULL, 0, 0, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 120, 1),
  ('svp_9184', 'Small Value Procurement', 'ra9184', 'RA 9184 and its 2016 revised IRR (transition cases)', 'https://www.gppb.gov.ph/', NULL, 0, 0, NULL, NULL, NULL, 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.', 130, 1);

INSERT IGNORE INTO brgy_references (code, title, issuer, ref_type, issued_date, published_date, effective_date, url, applies_to, notes, is_active) VALUES
  ('RA-12009', 'Republic Act No. 12009, New Government Procurement Act', 'Congress of the Philippines', 'law', '2024-07-20', NULL, '2024-08-13', 'https://www.gppb.gov.ph/', 'All procurement of goods, infrastructure projects and consulting services by government entities, barangays included', 'Signed 20 July 2024; took effect 13 August 2024. Repealed RA 9184. Procurement started before the new rules applied follows the transitory provisions (see GPPB Resolution No. 05-2025).', 1),
  ('IRR-RA-12009', 'Implementing Rules and Regulations of RA 12009', 'Government Procurement Policy Board', 'irr', NULL, NULL, NULL, 'https://www.gppb.gov.ph/', 'Procedures, procurement modes, documentary requirements, securities, contract implementation', 'Approved by the GPPB in February 2025 and published in the same month. Enter the effectivity date after checking the official copy.', 1),
  ('GPPB-RES-05-2025', 'GPPB Resolution No. 05-2025 approving Circular No. 01-2025: guidance on Section 113 (Transitory Provision) in relation to Section 115 (Repealing Clause) of RA 12009 and its IRR', 'Government Procurement Policy Board', 'resolution', '2025-09-18', NULL, NULL, 'https://www.gppb.gov.ph/wp-content/uploads/2025/10/GPPB-Resolution-No.-05-2025.pdf', 'Which law and forms govern a procurement during the transition', 'Covers whether RA 9184 still applies to ongoing procurement, the use of RA 9184 forms, when the new standard procurement forms become mandatory, and a three-year transitory period counted from 14 May 2025.', 1),
  ('GPPB-FORMS', 'GPPB Philippine Bidding Documents and standard procurement forms', 'Government Procurement Policy Board', 'other', NULL, NULL, NULL, 'https://www.gppb.gov.ph/', 'Bid forms, omnibus sworn statement, bid / performance securing declarations, contract forms, price schedules', 'Use the version the procuring entity issued with its bidding documents or RFQ. The program keeps these as prescribed-form shells: paste the official text, do not reword it.', 1),
  ('COA-2023-004', 'COA Circular No. 2023-004, updated documentary requirements for common government transactions', 'Commission on Audit', 'circular', '2023-06-14', NULL, NULL, 'https://www.coa.gov.ph/', 'Supporting papers the entity needs to pay a supplier or contractor', 'Amends COA Circular No. 2012-001. Use it to build the billing pack the entity\'s accountant and auditor will look for.', 1),
  ('BIR-RR-7-2024', 'Revenue Regulations No. 7-2024 (invoicing under the Ease of Paying Taxes Act)', 'Bureau of Internal Revenue', 'regulation', NULL, '2024-04-12', '2024-04-27', 'https://www.bir.gov.ph/', 'Sales invoice as the primary document for sales of goods and services', 'Official receipts may still be used as supplementary documents marked \"This document is not valid for claim of input tax.\" A document printed by this program is not by itself a BIR-registered invoice.', 1),
  ('BIR-RR-11-2024', 'Revenue Regulations No. 11-2024 (amending RR 7-2024: registration and invoicing transition)', 'Bureau of Internal Revenue', 'regulation', '2024-06-13', NULL, NULL, 'https://www.bir.gov.ph/', 'Transition for invoices and receipts', 'Read with RR 7-2024 and the later BIR issuances, including e-invoicing guidance.', 1),
  ('BIR-RR-11-2018', 'Revenue Regulations No. 11-2018 (withholding tax rates, including government payors)', 'Bureau of Internal Revenue', 'regulation', NULL, NULL, NULL, 'https://www.bir.gov.ph/', 'Income tax and VAT withheld by government buyers (BIR Reports > Withholding Tax Table)', 'Rates are kept on the Withholding Tax Table, never in the program; check them against the current rules and the entity\'s Form 2307.', 1),
  ('RA-9184', 'Republic Act No. 9184, Government Procurement Reform Act (repealed)', 'Congress of the Philippines', 'law', '2003-01-10', NULL, NULL, 'https://www.gppb.gov.ph/', 'Procurement started before the RA 12009 rules applied', 'Repealed by RA 12009; still governs transition cases as the transitory provisions allow.', 1),
  ('RA-10173', 'Republic Act No. 10173, Data Privacy Act of 2012', 'Congress of the Philippines', 'law', '2012-08-15', NULL, NULL, 'https://www.privacy.gov.ph/', 'Personal information of officials and contact persons, documents kept in the vault', 'Keep only what is needed, limit access (confidential documents), and log every download.', 1);

INSERT IGNORE INTO brgy_requirements (code, name, description, doc_group, stage, requirement_type, regimes, categories, mode_codes, min_abc, max_abc, needs_validity, doc_type, template_code, is_prescribed, legal_basis, source_url, entity_id, effective_from, effective_to, status, sort_order, is_active) VALUES
  ('REQ-PHILGEPS', 'PhilGEPS registration certificate (Platinum membership where required)', NULL, 'legal', 'eligibility', 'mandatory', NULL, NULL, NULL, NULL, NULL, 1, 'philgeps_cert', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 10, 1),
  ('REQ-MAYORS', 'Mayor\'s / business permit (current)', NULL, 'legal', 'eligibility', 'mandatory', NULL, NULL, NULL, NULL, NULL, 1, 'mayors_permit', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 20, 1),
  ('REQ-REG', 'SEC / DTI / CDA registration', NULL, 'legal', 'eligibility', 'mandatory', NULL, NULL, 'competitive_bidding,limited_source,public_bidding_9184', NULL, NULL, 0, 'sec_dti', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 30, 1),
  ('REQ-BIR2303', 'BIR Certificate of Registration (Form 2303)', 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.', 'legal', 'eligibility', 'conditional', NULL, NULL, NULL, NULL, NULL, 0, 'bir_2303', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 40, 1),
  ('REQ-TAXCLEAR', 'Tax clearance', NULL, 'legal', 'eligibility', 'mandatory', NULL, NULL, 'competitive_bidding,limited_source,public_bidding_9184', NULL, NULL, 1, 'tax_clearance', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 50, 1),
  ('REQ-PCAB', 'PCAB contractor license (category and size range for the project)', NULL, 'legal', 'eligibility', 'mandatory', NULL, 'works', NULL, NULL, NULL, 1, 'pcab_license', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 60, 1),
  ('REQ-PROF', 'Professional licenses of the key personnel', 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.', 'legal', 'eligibility', 'conditional', NULL, 'consultancy,works', NULL, NULL, NULL, 1, 'prof_license', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 70, 1),
  ('REQ-AUTH', 'Authority of the signatory (secretary\'s certificate / special power of attorney)', 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.', 'legal', 'eligibility', 'conditional', NULL, NULL, NULL, NULL, NULL, 0, 'authority', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 80, 1),
  ('REQ-ITR', 'Latest income tax return / business tax returns', 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.', 'financial', 'eligibility', 'conditional', NULL, NULL, NULL, NULL, NULL, 0, 'itr', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 90, 1),
  ('REQ-SLCC', 'Statement of all ongoing and completed government and private contracts', NULL, 'technical', 'eligibility', 'mandatory', NULL, NULL, 'competitive_bidding,limited_source,public_bidding_9184', NULL, NULL, 0, 'slcc', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 100, 1),
  ('REQ-AFS', 'Audited financial statements', NULL, 'financial', 'eligibility', 'mandatory', NULL, NULL, 'competitive_bidding,limited_source,public_bidding_9184', NULL, NULL, 1, 'afs', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 110, 1),
  ('REQ-NFCC', 'Net financial contracting capacity (NFCC) or committed line of credit', NULL, 'financial', 'eligibility', 'mandatory', NULL, NULL, 'competitive_bidding,limited_source,public_bidding_9184', NULL, NULL, 0, 'nfcc', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 120, 1),
  ('REQ-OSS', 'Omnibus sworn statement (prescribed form)', NULL, 'bid', 'bid', 'mandatory', NULL, NULL, NULL, NULL, NULL, 0, 'omnibus', 'omnibus_sworn_statement', 1, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 130, 1),
  ('REQ-BIDSEC', 'Bid security or bid securing declaration', NULL, 'bid', 'bid', 'mandatory', NULL, NULL, 'competitive_bidding,limited_source,public_bidding_9184', NULL, NULL, 1, 'bid_security', 'bid_securing_declaration', 1, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 140, 1),
  ('REQ-QUOTE', 'Signed price quotation / bid form (in the form the entity issued)', NULL, 'bid', 'bid', 'mandatory', NULL, NULL, NULL, NULL, NULL, 0, 'quotation', 'bid_form', 1, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 150, 1),
  ('REQ-PRICESCHED', 'Price schedule / bill of quantities', NULL, 'bid', 'bid', 'mandatory', NULL, NULL, NULL, NULL, NULL, 0, 'price_schedule', 'price_schedule', 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 160, 1),
  ('REQ-SPECS', 'Technical proposal / statement of compliance with the specifications', NULL, 'technical', 'bid', 'mandatory', NULL, NULL, NULL, NULL, NULL, 0, 'technical_proposal', 'technical_proposal', 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 170, 1),
  ('REQ-BROCHURE', 'Brochures and technical data sheets of the products offered', NULL, 'technical', 'bid', 'optional', NULL, 'goods', NULL, NULL, NULL, 0, 'brochure', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 180, 1),
  ('REQ-SCHED', 'Delivery / implementation schedule', 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.', 'technical', 'bid', 'conditional', NULL, 'services,works', NULL, NULL, NULL, 0, 'schedule', 'implementation_schedule', 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 190, 1),
  ('REQ-METHOD', 'Method statement', 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.', 'technical', 'bid', 'conditional', NULL, 'works', NULL, NULL, NULL, 0, 'method_statement', 'method_statement', 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 200, 1),
  ('REQ-PERFSEC', 'Performance security', 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.', 'contract', 'contract', 'conditional', NULL, NULL, NULL, NULL, NULL, 1, 'perf_security', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 210, 1),
  ('REQ-CONTRACT', 'Signed contract / purchase order', NULL, 'contract', 'contract', 'mandatory', NULL, NULL, NULL, NULL, NULL, 0, 'contract', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 220, 1),
  ('REQ-NTP', 'Notice to proceed', 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.', 'contract', 'contract', 'conditional', NULL, 'services,works', NULL, NULL, NULL, 0, 'ntp', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 230, 1),
  ('REQ-DR', 'Delivery receipt signed by the entity', NULL, 'delivery', 'delivery', 'mandatory', NULL, 'goods', NULL, NULL, NULL, 0, 'dr', 'delivery_receipt', 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 240, 1),
  ('REQ-IAR', 'Inspection and acceptance report', NULL, 'delivery', 'delivery', 'mandatory', NULL, NULL, NULL, NULL, NULL, 0, 'iar', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 250, 1);

INSERT IGNORE INTO brgy_requirements (code, name, description, doc_group, stage, requirement_type, regimes, categories, mode_codes, min_abc, max_abc, needs_validity, doc_type, template_code, is_prescribed, legal_basis, source_url, entity_id, effective_from, effective_to, status, sort_order, is_active) VALUES
  ('REQ-ACCOMP', 'Accomplishment / service completion report', NULL, 'delivery', 'delivery', 'mandatory', NULL, 'services,works', NULL, NULL, NULL, 0, 'accomplishment', 'accomplishment_report', 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 260, 1),
  ('REQ-SI', 'Sales invoice (BIR-registered)', NULL, 'billing', 'billing', 'mandatory', NULL, NULL, NULL, NULL, NULL, 0, 'invoice', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 270, 1),
  ('REQ-SOA', 'Statement of account', NULL, 'billing', 'billing', 'mandatory', NULL, NULL, NULL, NULL, NULL, 0, 'soa', 'statement_of_account', 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 280, 1),
  ('REQ-2307', 'BIR Form 2307 issued by the entity for the taxes it withheld', NULL, 'billing', 'billing', 'optional', NULL, NULL, NULL, NULL, NULL, 0, 'form_2307', NULL, 0, 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity', 'https://www.gppb.gov.ph/', NULL, NULL, NULL, 'approved', 290, 1);

UPDATE brgy_requirements SET approved_at = NOW() WHERE status = 'approved' AND approved_at IS NULL;

INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('cover_letter', 'Cover letter: submission of bid / quotation', 'letter', 'bid', 'cover_letter', 'Covering letter of the bid or quotation, listing what is enclosed.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\"></h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Submission of bid / quotation for {{project.title}} ({{project.reference_no}})</b></p><p>Dear Sir / Madam:</p><p>In response to your invitation, {{company.name}} respectfully submits its bid / quotation for <b>{{project.title}}</b> in the total amount of <b>{{project.bid_amount}}</b> ({{project.bid_amount_words}}), inclusive of all applicable taxes, under the terms and specifications of your request.</p><p>Enclosed are the documents your request asks for, as listed in our checklist:</p>{{requirements.table}}<p>Our offer is valid for the period stated in your bidding documents. For clarifications, please contact the undersigned at {{company.phone}} or {{company.email}}.</p><p style=\"margin-top:28px\">Very truly yours,</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'signatory.name,entity.name', 1, 10, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('company_profile', 'Company profile', 'proforma', 'bid', 'company_profile', 'Short profile of the company for bid and eligibility envelopes.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">COMPANY PROFILE</h2><table class=\"ft\"><tbody><tr><td style=\"width:34%\">Registered name</td><td><b>{{company.name}}</b></td></tr><tr><td>Business address</td><td>{{company.address}}</td></tr><tr><td>Registration</td><td>{{company.reg_no}}</td></tr><tr><td>TIN / tax status</td><td>{{company.tin}}   ({{company.vat_status}})</td></tr><tr><td>PhilGEPS registration</td><td>{{company.philgeps_no}}   {{company.philgeps_class}}</td></tr><tr><td>Contact</td><td>{{company.phone}}   {{company.email}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr></tbody></table><h3>Lines of business</h3><p>Supply and delivery of office, school, medical and household supplies, IT equipment and furniture; general services (janitorial, maintenance, repairs); and, through its licensed construction arm, minor infrastructure works for barangays and local government units.</p><h3>Facilities and people</h3><p>[Describe the warehouse, delivery vehicles, technical staff and the after-sales service offered.]</p><h3>Major government clients</h3><p>[List recent contracts: entity, project, amount, date completed.]</p>', 'A4', 'portrait', 0, NULL, NULL, 'company.name', 1, 20, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('quotation', 'Price quotation', 'proforma', 'bid', 'quotation', 'Company price quotation with the price schedule (when the entity has no form of its own).', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">PRICE QUOTATION</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>We offer to supply the following under the terms of your request for quotation:</p>{{lines.table}}<p><b>Total amount: {{lines.total}}</b> ({{lines.total_words}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Delivery period</td><td>{{project.delivery_days}} from receipt of the purchase order / notice to proceed</td></tr><tr><td>Delivery site</td><td>{{project.delivery_site}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr><tr><td>Payment terms</td><td>{{project.payment_terms}}</td></tr><tr><td>Validity of the offer</td><td>As stated in the RFQ</td></tr></tbody></table><p>Prices include all applicable taxes, delivery and handling. We confirm that the items offered comply with the specifications stated in your request.</p><p style=\"margin-top:28px\">Submitted by:</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'signatory.name,project.title', 1, 30, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('price_schedule', 'Price schedule / bill of quantities', 'proforma', 'bid', 'price_schedule', 'Price schedule from the project lines (use the entity\'s own schedule form when its documents prescribe one).', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">PRICE SCHEDULE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p></p>{{lines.table}}<p><b>Total bid price: {{lines.total}}</b><br>({{lines.total_words}})</p><p>Name of bidder: <b>{{company.name}}</b><br>Signature of the authorized representative:</p><p style=\"margin-top:28px\"></p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'project.title', 1, 40, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('technical_proposal', 'Technical proposal and statement of compliance', 'proforma', 'bid', 'technical_proposal', 'Item-by-item statement of compliance with the technical specifications.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">TECHNICAL PROPOSAL AND STATEMENT OF COMPLIANCE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>We state below, item by item, our compliance with the technical specifications. Where \"Comply\" is stated, the offer meets or exceeds the requirement; supporting brochures and data sheets are attached.</p><table class=\"ft\"><thead><tr><th style=\"width:6%\">Item</th><th>Specification required</th><th style=\"width:30%\">Offer (brand, model, specification)</th><th style=\"width:12%\">Statement</th></tr></thead><tbody><tr><td>1</td><td>{{project.specs}}</td><td>[Brand / model offered]</td><td>Comply</td></tr></tbody></table><p><b>Delivery:</b> {{project.delivery_days}} at {{project.delivery_site}}. <b>Warranty:</b> {{project.warranty_months}}.</p><p style=\"margin-top:28px\">Certified correct:</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'project.title', 1, 50, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('method_statement', 'Method statement (works / services)', 'proforma', 'bid', 'method_statement', 'How the works or services will be carried out: sequence, resources, safety and quality control.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">METHOD STATEMENT</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><h3>1. Scope</h3><p>{{project.specs}}</p><h3>2. Mobilization</h3><p>[Site set-up, permits, coordination with the barangay and the end-user ({{project.end_user}}).]</p><h3>3. Sequence of work</h3><ol><li>[Activity]</li><li>[Activity]</li><li>[Activity]</li></ol><h3>4. Manpower and equipment</h3><p>[List of key personnel and equipment.]</p><h3>5. Quality control and testing</h3><p>[Tests, inspections and hold points; acceptance by the entity\'s inspection committee.]</p><h3>6. Safety and environment</h3><p>[Safety plan, signage, housekeeping, disposal.]</p><h3>7. Schedule</h3><p>Completion within {{project.delivery_days}} from the notice to proceed.</p><p style=\"margin-top:28px\">Prepared by:</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'project.title', 1, 60, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('implementation_schedule', 'Implementation / delivery schedule', 'proforma', 'bid', 'schedule', 'Schedule of deliveries or activities over the contract period.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">IMPLEMENTATION / DELIVERY SCHEDULE</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><table class=\"ft\"><thead><tr><th style=\"width:6%\">No.</th><th>Activity / delivery</th><th style=\"width:18%\">Start</th><th style=\"width:18%\">Finish</th><th style=\"width:16%\">Weight %</th></tr></thead><tbody><tr><td>1</td><td>Mobilization / receipt of PO or NTP</td><td>Day 1</td><td>Day 3</td><td>5</td></tr><tr><td>2</td><td>[Delivery / work package]</td><td></td><td></td><td></td></tr><tr><td>3</td><td>Inspection and acceptance</td><td></td><td></td><td></td></tr></tbody></table><p>Total contract period: {{project.delivery_days}}.</p><p style=\"margin-top:28px\">Prepared by:</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'project.title', 1, 70, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('supply_proposal', 'Supply and services proposal', 'proforma', 'bid', 'proposal', 'Narrative proposal for supply and related services (training, installation, after-sales).', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">SUPPLY AND SERVICES PROPOSAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>{{company.name}} proposes to supply and deliver the items below, together with the related services described, for <b>{{lines.total}}</b>.</p>{{lines.table}}<h3>Related services</h3><ul><li>Delivery, unloading and installation at {{project.delivery_site}}</li><li>Orientation of the end-users</li><li>Warranty service: {{project.warranty_months}}</li></ul><p style=\"margin-top:28px\">Very truly yours,</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'project.title', 1, 80, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('scope_of_work', 'Scope-of-work proposal', 'proforma', 'bid', 'proposal', 'Proposal for services or works: scope, deliverables, exclusions and price.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">SCOPE-OF-WORK PROPOSAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><h3>Scope of work</h3><p>{{project.specs}}</p><h3>Deliverables</h3><ul><li>[Deliverable]</li><li>[Deliverable]</li></ul><h3>Exclusions</h3><p>[Items not included.]</p><h3>Price</h3>{{lines.table}}<p>Duration: {{project.delivery_days}}. Payment: {{project.payment_terms}}.</p><p style=\"margin-top:28px\">Very truly yours,</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'project.title', 1, 90, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('supplier_request', 'Request for quotation to a supplier / subcontractor', 'internal', 'internal', 'supplier_request', 'Asks suppliers and subcontractors for prices and their certifications (kept apart from the sale to the barangay).', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">REQUEST FOR QUOTATION (SUPPLIER / SUBCONTRACTOR)</h2><p>To: [Supplier / subcontractor]<br>Address: [Address]</p><p>We are preparing an offer for <b>{{project.title}}</b> for {{entity.name}}. Please quote your best price and delivery for the following, valid for at least 30 days:</p>{{lines.table}}<p>Please include with your quotation: your business permit, BIR registration, product certifications or licenses, warranty terms and delivery lead time. Prices to us are separate from our prices to the procuring entity; this request does not disclose the entity\'s budget.</p><p>Reply to {{project.preparer}} at {{company.email}} by [date].</p><p style=\"margin-top:28px\">Requested by:</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'project.title', 1, 100, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('bid_approval_sheet', 'Internal bid approval sheet', 'internal', 'internal', 'internal', 'Go / no-go, costing and margin, compliance, and the sign-off of the preparer, checker and approver before submission.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">INTERNAL BID APPROVAL SHEET</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project no.</td><td>{{project.no}}</td></tr><tr><td>Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode / law</td><td>{{project.mode}}, {{project.regime}}</td></tr><tr><td>ABC</td><td>{{project.abc}}</td></tr><tr><td>Bid amount</td><td><b>{{project.bid_amount}}</b></td></tr><tr><td>Bid deadline</td><td>{{project.bid_deadline}}</td></tr></tbody></table><h3>Costing and margin (confidential)</h3>{{costing.table}}<h3>Compliance checklist</h3>{{requirements.table}}<h3>Remarks</h3><p>[Risks, assumptions, supplier quotations relied on, payment record of the entity.]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.preparer}}</div><div>Prepared by</div></td><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.checker}}</div><div>Checked by</div></td><td style=\"width:33%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'project.no', 1, 110, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('contract_review_sheet', 'Contract review sheet', 'internal', 'award', 'internal', 'Review of the notice of award, purchase order or contract against the bid before signing.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">CONTRACT REVIEW SHEET</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b> ({{project.no}})</td></tr><tr><td>Notice of award</td><td>{{project.noa_ref}}, {{project.noa_date}}</td></tr><tr><td>Contract / PO no.</td><td>{{project.contract_ref}}</td></tr><tr><td>Amount awarded</td><td>{{project.contract_amount}}</td></tr><tr><td>Delivery / completion</td><td>{{project.delivery_days}}; due {{project.due_date}}</td></tr><tr><td>Retention</td><td>{{project.retention_pct}}</td></tr><tr><td>Liquidated damages</td><td>{{project.ld_rate}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr><tr><td>Payment terms</td><td>{{project.payment_terms}}</td></tr></tbody></table><h3>Checks</h3><table class=\"ft\"><thead><tr><th>Point</th><th style=\"width:16%\">OK</th><th style=\"width:36%\">Remarks</th></tr></thead><tbody><tr><td>Items, quantities and prices agree with the approved bid</td><td></td><td></td></tr><tr><td>Delivery period and site can be met</td><td></td><td></td></tr><tr><td>Performance security / warranty requirements understood</td><td></td><td></td></tr><tr><td>Billing documents and payment terms understood</td><td></td><td></td></tr><tr><td>Taxes to be withheld by the entity noted</td><td></td><td></td></tr></tbody></table><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.checker}}</div><div>Reviewed by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'project.no', 1, 120, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('handover_memo', 'Project handover memo (sales to operations)', 'internal', 'award', 'internal', 'Hands the awarded contract to the project manager and warehouse with what was promised.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">PROJECT HANDOVER MEMO</h2><p><b>To:</b> {{project.pm}}, Project Manager; Warehouse and Delivery<br><b>From:</b> {{project.preparer}}<br><b>Re:</b> {{project.title}} ({{project.no}})</p><p>The contract below was awarded to the company by {{entity.name}} (notice of award {{project.noa_ref}}, {{project.noa_date}}). Please mobilize as follows:</p>{{lines.contract_table}}<table class=\"ft\"><tbody><tr><td style=\"width:32%\">Delivery site</td><td>{{project.delivery_site}}</td></tr><tr><td>Due</td><td>{{project.due_date}}</td></tr><tr><td>End-user</td><td>{{project.end_user}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr></tbody></table><p>Deliveries are recorded on Deliveries &amp; Acceptance; the signed delivery receipt and the inspection and acceptance report are filed in the Document Vault, as billing depends on them.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.preparer}}</div><div>Handed over by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Received by</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'project.no', 1, 130, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('delivery_receipt', 'Delivery receipt', 'proforma', 'delivery', 'dr', 'Delivery receipt of the goods delivered, for the entity\'s signature.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">DELIVERY RECEIPT</h2><table class=\"ft\"><tbody><tr><td style=\"width:24%\">DR no.</td><td><b>{{delivery.no}}</b></td><td style=\"width:20%\">Date</td><td>{{delivery.date}}</td></tr><tr><td>Delivered to</td><td colspan=\"3\">{{entity.name}}, {{delivery.to}}</td></tr><tr><td>Project</td><td colspan=\"3\">{{project.title}} (contract / PO {{project.contract_ref}})</td></tr></tbody></table><p></p>{{delivery.table}}<p>Received the above items in good order and condition, subject to inspection and acceptance by the entity.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Delivered by ({{company.name}})</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{delivery.received_by}} {{delivery.received_position}}</div><div>Received by (name, position, date)</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'delivery.no,entity.name', 1, 140, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('accomplishment_report', 'Service / accomplishment report', 'proforma', 'delivery', 'accomplishment', 'Report of services rendered or work accomplished for the period, for the end-user\'s certification.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">SERVICE / ACCOMPLISHMENT REPORT</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr><tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table><p>Period covered: [from] to [to]</p>{{delivery.table}}<h3>Work done</h3><p>[Describe the services rendered / work accomplished, with dates.]</p><h3>Issues and actions</h3><p>[None]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Submitted by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.end_user}}</div><div>Certified by the end-user</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'project.title', 1, 150, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('transmittal', 'Transmittal letter', 'letter', 'billing', 'transmittal', 'Transmits documents to the entity and asks for the receiving copy.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">TRANSMITTAL</h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Re: {{project.title}}</b></p><p>We transmit the following documents for your appropriate action:</p><table class=\"ft\"><thead><tr><th style=\"width:6%\">No.</th><th>Document</th><th style=\"width:16%\">Copies</th></tr></thead><tbody><tr><td>1</td><td>[Document]</td><td>1</td></tr><tr><td>2</td><td>[Document]</td><td>1</td></tr></tbody></table><p>Kindly acknowledge receipt by signing below.</p><p style=\"margin-top:28px\">Very truly yours,</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Received by (name, position)</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Date and time received</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'entity.name', 1, 160, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('billing_cover_letter', 'Billing cover letter / request for payment', 'letter', 'billing', 'billing_letter', 'Requests payment of a billing, listing the pack enclosed.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\"></h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Request for payment, {{billing.type}} {{billing.no}} for {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>We respectfully request payment of <b>{{billing.gross}}</b> ({{billing.gross_words}}) for the deliveries / services accepted under {{project.contract_ref}}, as detailed below:</p>{{billing.table}}<p>Enclosed are our sales invoice no. {{billing.si_no}}, the statement of account, and the signed delivery and inspection and acceptance documents.</p><p>Payment may be made by check payable to <b>{{company.name}}</b> or by credit to: {{company.bank_details}}. Kindly furnish us BIR Form 2307 for the taxes withheld.</p><p style=\"margin-top:28px\">Very truly yours,</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'billing.no,signatory.name', 1, 170, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('statement_of_account', 'Statement of account', 'proforma', 'billing', 'soa', 'Billings, payments (with the taxes withheld and deductions) and the balance of the contract.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">STATEMENT OF ACCOUNT</h2><table class=\"ft\"><tbody><tr><td style=\"width:24%\">Bill to</td><td><b>{{entity.name}}</b><br>{{entity.address}}</td></tr><tr><td>Project</td><td>{{project.title}} ({{project.no}})</td></tr><tr><td>Contract / PO</td><td>{{project.contract_ref}}, {{project.contract_amount}}</td></tr></tbody></table><p></p>{{soa.table}}<p>Please make payment to {{company.name}}: {{company.bank_details}}.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>Noted by</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'entity.name', 1, 180, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('collection_followup', 'Collection follow-up letter', 'letter', 'collection', 'followup_letter', 'Courteous follow-up of a billing that is due.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\"></h2><p><b>{{entity.treasurer}}</b><br>Treasurer<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Subject: Follow-up of payment, {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>May we respectfully follow up the payment of our billing for the above project, which has been accepted and is now due. Our records show the following:</p>{{soa.table}}<p>Should any document be lacking for the processing of the payment, please let us know so we can provide it at once.</p><p style=\"margin-top:28px\">Very truly yours,</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'entity.name', 1, 190, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('acknowledgment_receipt', 'Acknowledgment receipt (supplementary)', 'receipt', 'collection', 'or_ar', 'Acknowledges a payment received. A supplementary document only: the sales invoice remains the primary document.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">ACKNOWLEDGMENT RECEIPT</h2><table class=\"ft\"><tbody><tr><td style=\"width:26%\">No.</td><td>{{collection.no}}</td><td style=\"width:16%\">Date</td><td>{{collection.date}}</td></tr></tbody></table><p>Received from <b>{{entity.name}}</b> the amount of <b>{{collection.amount_words}} ({{collection.amount}})</b> by {{collection.reference}}, in payment of billing {{billing.no}} (sales invoice no. {{billing.si_no}}) for {{project.title}}.</p>{{collection.table}}<p style=\"margin-top:14px;font-weight:bold;text-align:center;border:1px solid #000;padding:6px\">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:100%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Received by (cashier / collector)</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'collection.no', 1, 200, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('reconciliation_sheet', 'Contract reconciliation sheet', 'internal', 'collection', 'internal', 'Reconciles the contract, deliveries, billings, collections, taxes, retention and the ledger.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">CONTRACT RECONCILIATION SHEET</h2><p><b>{{project.title}}</b> ({{project.no}}), {{entity.name}}</p>{{closeout.table}}<h3>Statement of account</h3>{{soa.table}}<h3>Differences and actions</h3><p>[None]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Checked by (accounting)</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'project.no', 1, 210, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('closeout_summary', 'Project completion and closeout summary', 'internal', 'closeout', 'closeout', 'Final figures, papers on file, warranty and lessons learned at closeout.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\">PROJECT COMPLETION AND CLOSEOUT SUMMARY</h2><table class=\"ft\"><tbody><tr><td style=\"width:32%\">Project</td><td><b>{{project.title}}</b> ({{project.no}})</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Contract</td><td>{{project.contract_ref}}, NOA {{project.noa_ref}}</td></tr><tr><td>Warranty until</td><td>{{project.warranty_until}}</td></tr></tbody></table><h3>Final figures</h3>{{closeout.table}}<h3>Papers on file</h3>{{requirements.all_table}}<h3>Lessons learned</h3><p>[What went well, what to change next time.]</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.pm}}</div><div>Prepared by</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{project.approver}}</div><div>Approved by</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'project.no', 1, 220, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('contract_goods', 'Contract agreement: supply and delivery of goods (proforma)', 'contract', 'contract', 'contract', 'Reusable contract for goods: parties, contract documents, scope, price, delivery, inspection, payment, retention, warranty, liquidated damages, changes, termination.', '<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">Supply and Delivery of Goods</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall supply and deliver the goods as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The SUPPLIER shall deliver the goods to {{project.delivery_site}} within {{project.delivery_days}} from receipt of the purchase order / notice to proceed, on or before {{project.due_date}}.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. Performance security.</b> Where required by the contract documents, the SUPPLIER shall post the performance security in the form and amount stated there, valid until final acceptance.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>', 'Folio', 'portrait', 0, NULL, NULL, 'entity.head_name,signatory.name,project.contract_amount', 1, 230, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('contract_services', 'Contract agreement: general services (proforma)', 'contract', 'contract', 'contract', 'Reusable contract for janitorial, maintenance, repair, catering and other general services.', '<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">General Services</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall render the services as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The services shall be rendered at {{project.delivery_site}} from {{project.start_date}} for {{project.delivery_days}}, under the schedule in the terms of reference. Each period\'s services are certified by the end-user ({{project.end_user}}) through the accomplishment report.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. Personnel.</b> The SUPPLIER employs and pays its personnel, complies with labor, social security and safety laws, and remains solely responsible for them; no employer-employee relationship arises between the ENTITY and the SUPPLIER\'s personnel.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>', 'Folio', 'portrait', 0, NULL, NULL, 'entity.head_name,signatory.name,project.contract_amount', 1, 240, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('contract_works', 'Contract agreement: infrastructure works (proforma)', 'contract', 'contract', 'contract', 'Reusable contract for minor infrastructure works. The contractor must hold the PCAB license the project calls for.', '<h2 style=\"text-align:center\">CONTRACT AGREEMENT<br><span style=\"font-size:12pt\">Infrastructure Works</span></h2><p style=\"font-size:9pt;border:1px solid #999;padding:6px\"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p><p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p><p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the \"ENTITY\"); and</p><p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the \"SUPPLIER\");</p><p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p><p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p><p><b>NOW, THEREFORE</b>, the parties agree as follows:</p><p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p><p><b>2. Scope.</b> The SUPPLIER shall carry out and complete the works as listed below:</p>{{lines.contract_table}}<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p><p><b>4. Period and place.</b> The works at {{project.delivery_site}} shall start upon the notice to proceed dated {{project.ntp_date}} and be completed within {{project.delivery_days}}, on or before {{project.due_date}}. Progress billings follow the accomplishment certified by the ENTITY\'s engineer.</p><p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p><p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p><p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p><p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p><p><b>9. License, securities and insurance.</b> The CONTRACTOR (the SUPPLIER) holds a valid PCAB license for the category and size of this project, posts the performance security required by the contract documents, and keeps the works and its workers insured as the contract documents require. It is liable for defects of the works as provided by law.</p><p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p><p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p><p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p><p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p><p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{entity.head_name}}</div><div>For the ENTITY: {{entity.head_position}}</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the SUPPLIER: {{signatory.position}}</div></td></tr></table><p style=\"margin-top:14px\">Signed in the presence of:</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>Witness</div></td></tr></table><h3 style=\"margin-top:20px\">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>', 'Folio', 'portrait', 0, NULL, NULL, 'entity.head_name,signatory.name,project.contract_amount', 1, 250, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('subcontract', 'Subcontract agreement (company and subcontractor)', 'contract', 'internal', 'contract', 'Back-to-back subcontract for part of a barangay contract; the company stays responsible to the entity.', '<h2 style=\"text-align:center\">SUBCONTRACT AGREEMENT</h2><p>This Agreement is made on {{date.today}} between <b>{{company.name}}</b>, represented by {{signatory.name}}, {{signatory.position}} (the \"CONTRACTOR\"), and <b>[Subcontractor]</b>, [address, TIN], represented by [name, position] (the \"SUBCONTRACTOR\").</p><p><b>1. Main contract.</b> The CONTRACTOR holds the contract for <b>{{project.title}}</b> with {{entity.name}} (NOA {{project.noa_ref}}). Subcontracting is allowed only as the main contract and the procurement rules allow, and the CONTRACTOR remains responsible to the entity for the whole contract.</p><p><b>2. Scope.</b> [Part of the work subcontracted, with quantities.]</p><p><b>3. Price and payment.</b> [Amount], paid within [days] after the entity accepts and pays the corresponding portion, less the same retention and deductions the entity applies to that portion.</p><p><b>4. Schedule and quality.</b> The SUBCONTRACTOR follows the specifications, schedule and inspection of the main contract and corrects rejected work at its cost.</p><p><b>5. Permits, licenses and personnel.</b> The SUBCONTRACTOR holds the licenses its work requires and is solely responsible for its personnel and their compliance with labor and safety laws.</p><p><b>6. Liquidated damages.</b> Delay caused by the SUBCONTRACTOR is charged to it at the rate the entity applies ({{project.ld_rate}}).</p><p><b>7. Confidentiality.</b> Prices and terms of the main contract are confidential.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the CONTRACTOR</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>For the SUBCONTRACTOR</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'signatory.name', 1, 260, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('supply_agreement', 'Supply agreement with a supplier (back-to-back)', 'contract', 'internal', 'contract', 'Commits a supplier to the items, prices and delivery dates the company promised the entity.', '<h2 style=\"text-align:center\">SUPPLY AGREEMENT</h2><p>Between <b>{{company.name}}</b> (the \"BUYER\"), represented by {{signatory.name}}, and <b>[Supplier]</b> (the \"SELLER\"), dated {{date.today}}.</p><p><b>1. Goods.</b> The SELLER supplies the following for the BUYER\'s contract {{project.no}} ({{project.title}}):</p><p>[Items, quantities and prices to the BUYER]</p><p><b>2. Delivery.</b> To [warehouse / site] on or before [date], allowing the BUYER to deliver to {{entity.name}} by {{project.due_date}}.</p><p><b>3. Quality and warranty.</b> The goods meet the specifications of the main contract; the SELLER replaces goods the entity rejects and honors a warranty of at least {{project.warranty_months}}.</p><p><b>4. Payment.</b> [Terms]. The BUYER withholds the taxes required by law.</p><table class=\"sig-table\" style=\"width:100%;border-collapse:collapse;margin-top:26px\"><tr><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\">{{signatory.name}}</div><div>For the BUYER</div></td><td style=\"width:50%;vertical-align:top;padding:28px 10px 0 0\"><div style=\"border-bottom:1px solid #000;min-height:22px\"> </div><div>For the SELLER</div></td></tr></table>', 'A4', 'portrait', 0, NULL, NULL, 'signatory.name', 1, 270, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('po_conforme', 'Purchase order acceptance / conforme letter', 'letter', 'award', 'correspondence', 'Acknowledges the notice of award or purchase order and confirms the delivery commitment.', '<table style=\"width:100%;border-collapse:collapse\"><tr><td style=\"width:70px;vertical-align:top\">{{company.logo}}</td><td style=\"vertical-align:top\"><div style=\"font-size:15pt;font-weight:bold\">{{company.name}}</div><div>{{company.address}}</div><div>TIN {{company.tin}}   Tel. {{company.phone}}   {{company.email}}</div></td><td style=\"text-align:right;vertical-align:top;width:34%\"><div>{{doc.no}}</div><div>{{date.today}}</div></td></tr></table><hr><h2 style=\"text-align:center;margin:10px 0 14px\"></h2><p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p><p><b>Attention:</b> The Chairperson, Bids and Awards Committee  ({{entity.bac_chair}})</p><p><b>Subject: Acceptance of Purchase Order / Notice of Award, {{project.title}}</b></p><p>Dear Sir / Madam:</p><p>We acknowledge receipt of your Notice of Award {{project.noa_ref}} dated {{project.noa_date}} and Purchase Order {{project.po_no}} for <b>{{project.contract_amount}}</b>, and confirm our acceptance (conforme) of their terms.</p><p>We will deliver to {{project.delivery_site}} within {{project.delivery_days}}, on or before {{project.due_date}}. Our contact person for this contract is {{project.pm}}.</p><p style=\"margin-top:28px\">Conforme:</p><p style=\"margin-top:42px\"><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>', 'A4', 'portrait', 0, NULL, NULL, 'project.po_no', 1, 280, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('omnibus_sworn_statement', 'Omnibus sworn statement (prescribed form)', 'prescribed', 'bid', 'omnibus', 'Shell for the prescribed omnibus sworn statement: the official text is pasted in, the program fills its blanks and keeps its review.', '<h2 style=\"text-align:center\">OMNIBUS SWORN STATEMENT</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr></tbody></table>', 'A4', 'portrait', 1, 'https://www.gppb.gov.ph/ (and the bidding documents of the procuring entity)', NULL, 'company.name,signatory.name', 1, 290, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('bid_securing_declaration', 'Bid securing declaration (prescribed form)', 'prescribed', 'bid', 'bid_security', 'Shell for the prescribed bid securing declaration.', '<h2 style=\"text-align:center\">BID SECURING DECLARATION</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr><tr><td>ABC</td><td>{{project.abc}}</td></tr></tbody></table>', 'A4', 'portrait', 1, 'https://www.gppb.gov.ph/ (and the bidding documents of the procuring entity)', NULL, 'company.name,signatory.name', 1, 300, 1);
INSERT IGNORE INTO brgy_templates (code, name, category, stage, doc_type, description, body, paper, orientation, is_prescribed, official_source, official_version, required_fields, version_no, sort_order, is_active) VALUES ('bid_form', 'Bid form / price quotation form (prescribed by the entity)', 'prescribed', 'bid', 'quotation', 'Shell for the bid or quotation form the entity issued; the price schedule of the project is below to copy the figures.', '<h2 style=\"text-align:center\">BID FORM / PRICE QUOTATION FORM</h2><div style=\"border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed\"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div><p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class=\"ft\"><tbody><tr><td style=\"width:36%\">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr><tr><td>Total bid</td><td>{{project.bid_amount}} ({{project.bid_amount_words}})</td></tr></tbody></table>{{lines.table}}', 'A4', 'portrait', 1, 'https://www.gppb.gov.ph/ (and the bidding documents of the procuring entity)', NULL, 'company.name,signatory.name', 1, 310, 1);

INSERT INTO brgy_template_versions (template_id, version_no, name, body, change_note)
SELECT t.id, t.version_no, t.name, t.body, 'First version' FROM brgy_templates t
 WHERE NOT EXISTS (SELECT 1 FROM brgy_template_versions v WHERE v.template_id = t.id);

-- ---------------------------------------------------------------------
--  7. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Barangay Contracts module',
        'Migration 2026_10_29: Barangay Contracts (projects, compliance, bids, awards, deliveries, billing packs, collections, retention, forms and proformas, document vault, reports)');

-- Done. Next steps in the application:
--   Barangay Contracts > Contracting Settings: the company's registration, PhilGEPS no., signatory and bank details;
--     check the withholding tax types and the site for delivered goods.
--   Chart of Accounts > Module Default Accounts > Barangay Contracts: check the seven accounts.
--   Barangay Contracts > Procurement Modes and Compliance Requirement Library: enter the ceilings and
--     requirements you verified against the current IRR and GPPB issuances.
--   Barangay Contracts > Document Vault > Company papers: file the permits, registrations and clearances once.
--   System Administration > Users: give the contracting staff their Contracts roles.
