<?php
declare(strict_types=1);

/**
 * Knows which upgrade scripts (database/migrations) the program files need and which of them
 * have not been run on this database yet. index.php stops with instructions while any is pending,
 * so nobody works on a half-upgraded system.
 */
final class Schema
{
    private const FLAG = 'schema_ok_2026_11_23';

    /**
     * file => one cheap probe.
     *   A string probe is pending when the query fails: "WHERE 1 = 0" reads no rows, but MySQL still
     *   checks that every table and column it names exists.
     *   An [sql, expected] probe is pending when the query fails OR does not give back what is expected;
     *   that is how a migration which only adds a row of data is recognised.
     */
    private const MIGRATIONS = [
        'database/migrations/2026_09_19_classes_cash_voucher.sql' =>
            'SELECT (SELECT COUNT(*) FROM classes WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM other_names WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM transactions WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM transaction_lines WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM journal_lines WHERE 1 = 0)
                  + (SELECT COUNT(show_in_pos) FROM payment_methods WHERE 1 = 0)
                  + (SELECT COUNT(class_id) FROM pos_terminals WHERE 1 = 0)',
        'database/migrations/2026_09_21_asset_maintenance_debit_memo_logo.sql' =>
            'SELECT (SELECT COUNT(*) FROM asset_locations WHERE 1 = 0)
                  + (SELECT COUNT(location_id) + COUNT(class_id) + COUNT(current_meter) FROM fixed_assets WHERE 1 = 0)
                  + (SELECT COUNT(txn_id) + COUNT(schedule_id) FROM asset_maintenance WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_parts WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_labor WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_photos WHERE 1 = 0)
                  + (SELECT COUNT(next_due_meter) FROM asset_pm_schedules WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_pm_log WHERE 1 = 0)',
        'database/migrations/2026_09_22_memorized_budgets_imports.sql' =>
            'SELECT (SELECT COUNT(*) FROM memorized_txns WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM budgets WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM budget_lines WHERE 1 = 0)
                  + (SELECT COUNT(options) FROM report_layouts WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM import_batches WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM backup_log WHERE 1 = 0)',
        'database/migrations/2026_09_23_item_search_per_transaction.sql' =>
            ['SELECT COUNT(*) FROM settings WHERE setting_key = \'item_search_by_page\'', 1],
        // the column has to exist (the query fails without it) and both report pages have to be there
        'database/migrations/2026_09_24_subsidiary_ledger.sql' =>
            ['SELECT 1 + (SELECT COUNT(with_sl) FROM accounts WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.subsidiary_ledger\', \'rpt.schedule_of_accounts\'))', 3],
        // settings only: all six rows of the Bluetooth receipt printer have to be there
        'database/migrations/2026_09_25_bluetooth_receipt_printer.sql' =>
            ['SELECT COUNT(*) FROM settings WHERE setting_key IN
                (\'bt_printer_enabled\', \'bt_printer_width\', \'bt_printer_name\', \'bt_printer_feed\', \'bt_printer_cut\', \'bt_printer_autoprint\')', 6],
        // the two columns have to be there (the query fails without them)
        'database/migrations/2026_09_26_pos_default_site_class.sql' =>
            'SELECT COUNT(pos_default_site_id) + COUNT(pos_default_class_id) FROM users WHERE 1 = 0',
        // data only: both Find pages have to be there
        'database/migrations/2026_09_27_find_report_design.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key IN (\'home.find\', \'rpt.find\')', 2],
        // the approval columns of users have to be there, and the four company pages
        'database/migrations/2026_09_28_multi_company.sql' =>
            ['SELECT 4 + (SELECT COUNT(approval_status) + COUNT(created_via) + COUNT(approved_by) + COUNT(approved_at) + COUNT(rejected_reason) + COUNT(is_platform) FROM users WHERE 1 = 0)
                       - (SELECT COUNT(*) FROM pages WHERE page_key IN (\'company.profile\', \'company.users\', \'company.join_requests\', \'admin.multi_company\'))', 0],
        // the saved service charge and interest of a reconciliation (the query fails without the columns)
        'database/migrations/2026_09_29_reconcile_saved_charges.sql' =>
            'SELECT COUNT(service_charge) + COUNT(service_date) + COUNT(service_account_id) + COUNT(interest_earned)
                  + COUNT(interest_date) + COUNT(interest_account_id) + COUNT(class_id) FROM bank_reconciliations WHERE 1 = 0',
        // the approval_codes table (the query fails without it) and its page have to be there
        'database/migrations/2026_10_01_price_levels_approval_codes.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key = \'pos.approval_codes\') + (SELECT COUNT(*) FROM approval_codes WHERE 1 = 0)', 1],
        // the Category List and the five reports have to be there, and the two shift columns (the query fails without them)
        'database/migrations/2026_10_03_categories_reports_shift_corrections.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'inv.categories\', \'rpt.income_customer_summary\', \'rpt.income_customer_detail\', \'rpt.expenses_vendor_summary\', \'rpt.expenses_vendor_detail\', \'rpt.inv_no_movement\'))
                       + (SELECT COUNT(corrected_by) + COUNT(corrected_at) FROM pos_shifts WHERE 1 = 0)', 6],
        // the Customized Inventory Report has to be there, and the POS shift of a deposit line (the query fails without it)
        'database/migrations/2026_10_08_custom_report_deposit_edit.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key = \'rpt.inv_custom\') + (SELECT COUNT(pos_shift_id) FROM transaction_lines WHERE 1 = 0)', 1],
        // the BIR Reports module pages, the advances page, the withholding tables, the wht_ids columns and the default accounts
        'database/migrations/2026_10_15_withholding_bir_advances.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'bir.wht_table\', \'bir.form_2307\', \'vend.advances\', \'rpt.bir_2550q\'))
                       + (SELECT COUNT(*) FROM default_accounts WHERE setting_key IN (\'wht_payable\', \'vat_withheld_payable\', \'cvat_withheld\', \'vendor_advances\') AND account_id IS NOT NULL)
                       + (SELECT COUNT(*) FROM withholding_taxes WHERE 1 = 0) + (SELECT COUNT(*) FROM payment_withholdings WHERE 1 = 0)
                       + (SELECT COUNT(v.wht_ids) + COUNT(c.wht_ids) FROM vendors v, customers c WHERE 1 = 0)', 8],
        // the inventory movement reports and their drill-down page
        'database/migrations/2026_10_22_inventory_movement_reports.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.inv_movement_summary\', \'rpt.inv_movement_detail\', \'rpt.inv_movement_txns\', \'rpt.sales_inv_movement\')', 4],
        // Barangay Contracts: the module's pages, its tables (the query fails without them) and its default accounts
        'database/migrations/2026_10_29_barangay_contracting.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'brgy.dashboard\', \'brgy.projects\', \'brgy.settings\', \'rpt.brgy_reconciliation\'))
                       + (SELECT COUNT(*) FROM brgy_postings WHERE 1 = 0) + (SELECT COUNT(*) FROM brgy_templates WHERE 1 = 0) + (SELECT COUNT(*) FROM brgy_collections WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM default_accounts WHERE setting_key IN (\'retention_receivable\', \'security_deposits\') AND account_id IS NOT NULL)', 6],
        // the SOP of the company and the configurable forms: the new pages, the new columns and tables (the query fails without them) and the first step
        'database/migrations/2026_11_03_barangay_sop_forms.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'brgy.process\', \'rpt.brgy_monitoring\'))
                       + (SELECT COUNT(process) + COUNT(payment_status) + COUNT(txn_form_no) FROM brgy_projects WHERE 1 = 0)
                       + (SELECT COUNT(layout_json) + COUNT(positions_json) + COUNT(print_mode) FROM brgy_templates WHERE 1 = 0)
                       + (SELECT COUNT(values_json) + COUNT(needs_approval) FROM brgy_forms WHERE 1 = 0) + (SELECT COUNT(est_unit_price) FROM brgy_project_lines WHERE 1 = 0)
                       + (SELECT COUNT(seal_file) + COUNT(extra_bid_papers) FROM brgy_entities WHERE 1 = 0) + (SELECT COUNT(layout_json) FROM brgy_template_versions WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM brgy_project_steps WHERE 1 = 0) + (SELECT COUNT(*) FROM brgy_project_papers WHERE 1 = 0) + (SELECT COUNT(*) FROM brgy_canvass WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM brgy_sop_papers WHERE code = \'txn_form\') + (SELECT COUNT(*) FROM brgy_sop_steps WHERE code = \'svp1\')', 4],
        // letterheads, logos and signatories of the forms, the bid forms of steps 8 and 13, Cash Out and Kaliwaan: the type columns (the query fails without them) and the new rows
        'database/migrations/2026_11_10_letterheads_bid_forms_types.sql' =>
            ['SELECT (SELECT COUNT(txn_types) FROM brgy_sop_steps WHERE 1 = 0) + (SELECT COUNT(txn_types) FROM brgy_sop_papers WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM brgy_sop_papers WHERE code = \'post_qual_notice\') + (SELECT COUNT(*) FROM brgy_templates WHERE code = \'notice_of_post_qualification\')', 2],
        // the accounting updates: the new columns and tables (the query fails without them), the seven new report pages
        // and the module default account of the item group price variance
        'database/migrations/2026_11_19_accounting_updates.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.sales_item_group\', \'rpt.cmp_is_month\', \'rpt.cmp_bs_custom\', \'rpt.trial_balance_class\', \'rpt.cash_flow_class\', \'rpt.avg_cost_changes\'))
                       + (SELECT COUNT(*) FROM default_accounts WHERE setting_key = \'group_price_variance\')
                       + (SELECT COUNT(group_variance_account_id) FROM items WHERE 1 = 0) + (SELECT COUNT(group_item_id) + COUNT(group_role) FROM transaction_lines WHERE 1 = 0)
                       + (SELECT COUNT(received_via) FROM transactions WHERE 1 = 0) + (SELECT COUNT(*) FROM item_group_sales WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM clearing_settlements WHERE 1 = 0) + (SELECT COUNT(*) FROM txn_attachments WHERE 1 = 0) + (SELECT COUNT(*) FROM txn_attachment_chunks WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM voucher_sign_requests WHERE 1 = 0) + (SELECT COUNT(*) FROM voucher_signatures WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM voucher_documents WHERE 1 = 0) + (SELECT COUNT(*) FROM voucher_document_chunks WHERE 1 = 0)', 7],
        // tradenames, people accountable and the Report Center menu: the two tables (the query fails without them),
        // the two pages, the Report Center module and the Report Center page moved into it
        'database/migrations/2026_11_20_tradenames_people_report_center.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'brgy.tradenames\', \'brgy.accountable\'))
                       + (SELECT COUNT(*) FROM modules WHERE code = \'rc\')
                       + (SELECT COUNT(*) FROM pages p JOIN modules m ON m.id = p.module_id WHERE p.page_key = \'home.reports\' AND m.code = \'rc\')
                       + (SELECT COUNT(*) FROM brgy_tradenames WHERE 1 = 0) + (SELECT COUNT(*) FROM brgy_function_roles WHERE 1 = 0)', 4],
        // v34: the users allowed for a function, the columns of the estimates, the DV and the cheque's tradename (the query fails without them)
        'database/migrations/2026_11_21_people_users_estimates_dv_rfq_iar.sql' =>
            'SELECT (SELECT COUNT(*) FROM brgy_function_users WHERE 1 = 0)
                  + (SELECT COUNT(lines_lookup_inventory) + COUNT(est_gross) + COUNT(est_wt_rate) + COUNT(est_profit_rate) + COUNT(est_receipt_rate) + COUNT(est_sop)
                          + COUNT(dv_gross) + COUNT(dv_vat_rate) + COUNT(dv_ewt_rate) + COUNT(cheque_tradename) FROM brgy_projects WHERE 1 = 0)',
        // v35: the zip code and the treasurer name of a customer
        'database/migrations/2026_11_23_customer_zip_treasurer.sql' =>
            'SELECT COUNT(zip_code) + COUNT(treasurer_name) FROM customers WHERE 1 = 0',
    ];

    private static ?array $pending = null;

    public static function flush(): void
    {
        self::$pending = null;
    }

    /** Upgrade scripts still to be run on this request's database, oldest first (empty = up to date) */
    public static function pending(): array
    {
        if (self::$pending !== null) {
            return self::$pending;
        }
        // remembered per database: a Super Admin moves between companies in one session
        $flag = self::FLAG . ':' . DB::databaseName();
        $inSession = session_status() === PHP_SESSION_ACTIVE;
        if ($inSession && !empty($_SESSION[$flag])) {
            return self::$pending = [];
        }
        $out = self::pendingWith(fn(string $sql) => DB::value($sql));
        if (!$out && $inSession) {
            $_SESSION[$flag] = 1;
        }
        return self::$pending = $out;
    }

    /** Upgrade scripts still to be run on another company's database (the platform's update page) */
    public static function pendingOn(Sql $db): array
    {
        return self::pendingWith(fn(string $sql) => $db->value($sql));
    }

    /** @return string[] every upgrade script the program knows, oldest first */
    public static function files(): array
    {
        return array_keys(self::MIGRATIONS);
    }

    private static function pendingWith(callable $value): array
    {
        $out = [];
        foreach (self::MIGRATIONS as $file => $probe) {
            [$sql, $want] = is_array($probe) ? $probe : [$probe, null];
            try {
                $got = $value($sql);
                if ($want !== null && (int)$got !== (int)$want) {
                    $out[] = $file;
                }
            } catch (Throwable $e) {
                $out[] = $file;
            }
        }
        return $out;
    }

    public static function ready(): bool
    {
        return !self::pending();
    }
}
