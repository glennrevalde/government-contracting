<?php
declare(strict_types=1);

/**
 * Barangay Contracts: the format of the forms (Proforma & Form Templates > Format and Positions).
 *
 * Every template has two ways of printing:
 *  - layout:    the text of the template, with its format: paper (A4, Letter, Folio, Legal, A5, half letter or a size
 *               of your own), margins, font, sizes, the lines of the tables, the columns of the item tables (shown,
 *               title, width, alignment), the parts that are hidden, blank rows and "nothing follows", copies with
 *               their labels, a footer and a print offset for printers that do not print where they should;
 *  - positions: only the values, each placed in millimetres on the page, for forms already printed on paper
 *               (the barangay's own pads). A scan of the paper can be put behind the fields while placing them,
 *               and a test print with a millimetre grid shows where the printer puts them.
 * The format is kept on the template (layout_json, positions_json) and used by every form made from it.
 */
final class BrgyLayout
{
    /** paper => [width mm, height mm, label] */
    public const PAPERS = [
        'A4' => [210, 297, 'A4 (210 x 297 mm)'], 'Letter' => [215.9, 279.4, 'Letter (8.5 x 11 in)'], 'Folio' => [215.9, 330.2, 'Folio / long (8.5 x 13 in)'],
        'Legal' => [215.9, 355.6, 'Legal (8.5 x 14 in)'], 'A5' => [148, 210, 'A5 (148 x 210 mm)'], 'HalfLetter' => [139.7, 215.9, 'Half letter (5.5 x 8.5 in)'],
        'Custom' => [0, 0, 'A size of your own'],
    ];

    public const FONTS = [
        'Arial' => 'Arial, "Liberation Sans", Helvetica, sans-serif', 'Calibri' => 'Calibri, Carlito, Arial, sans-serif',
        'CG Omega' => '"CG Omega", "Segoe UI", Inter, Calibri, Arial, sans-serif',
        'Times New Roman' => '"Times New Roman", "Liberation Serif", Times, serif', 'Cambria' => 'Cambria, Caladea, Georgia, serif',
        'Tahoma' => 'Tahoma, Verdana, "DejaVu Sans", sans-serif', 'Century Gothic' => '"Century Gothic", "URW Gothic", Arial, sans-serif',
        'Courier New' => '"Courier New", "Liberation Mono", Courier, monospace',
    ];

    /** Pictures a letterhead can show, left or right */
    public const LH_PICS = ['none' => 'Nothing', 'logo' => 'Company logo', 'pic_a' => 'Picture A', 'pic_b' => 'Picture B', 'lgu_seal' => 'City / municipal seal', 'seal' => 'Barangay seal'];

    /** Where the letterhead of a template comes from */
    public const LH_STYLES = ['company' => 'Company letterhead (shared)', 'barangay' => 'Barangay letterhead with the seals (shared)', 'own' => 'Its own letterhead (this template only)',
        'none' => 'No letterhead'];

    /** The letterhead settings (kept on the template, or shared in Contracting Settings for the company and barangay letterheads) */
    public const LH_KEYS = ['lh_left', 'lh_right', 'lh_lines', 'lh_align', 'lh_font', 'lh_size', 'lh_big', 'lh_pic_mm', 'lh_box', 'lh_box_bg', 'lh_box_fg', 'lh_rule', 'lh_rule_color',
        'lh_space', 'lh_pic_a', 'lh_pic_b'];

    /** A new shared letterhead: the company's, and the barangay's (each barangay's own seals and names) */
    public const LH_SHARED = [
        'company' => ['lh_left' => 'logo', 'lh_right' => 'none', 'lh_lines' => "# {{company.name_upper}}\n{{company.address_upper}}\nTEL. NO. {{company.phone}}", 'lh_align' => 'center',
            'lh_font' => 'Cambria', 'lh_size' => 8.5, 'lh_big' => 20, 'lh_pic_mm' => 26, 'lh_box' => 1, 'lh_box_bg' => '#b8963e', 'lh_box_fg' => '#1b1b1b', 'lh_rule' => 1, 'lh_rule_color' => '#222222',
            'lh_space' => 6, 'lh_pic_a' => '', 'lh_pic_b' => ''],
        'barangay' => ['lh_left' => 'lgu_seal', 'lh_right' => 'seal', 'lh_lines' => "Republic of the Philippines\nProvince of {{entity.province}}\n{{entity.lgu_line}}\n**{{entity.title_upper}}**", 'lh_align' => 'center',
            'lh_font' => '', 'lh_size' => 10.5, 'lh_big' => 15, 'lh_pic_mm' => 25, 'lh_box' => 0, 'lh_box_bg' => '#ffffff', 'lh_box_fg' => '#111111', 'lh_rule' => 2, 'lh_rule_color' => '#111111',
            'lh_space' => 5, 'lh_pic_a' => '', 'lh_pic_b' => ''],
    ];

    /** Where a signature stands: half = centred on the right half of the page (the usual place of a letter's signature) */
    public const SIG_ALIGN = ['left' => 'Left', 'center' => 'Center', 'right' => 'Right', 'half' => 'Right half'];

    /** A signatory block of a template's {{signatories}} */
    public const SIG_KEYS = ['lead', 'name', 'pos', 'extra', 'align', 'newrow', 'line', 'bold', 'underline', 'upper', 'italic'];

    /** The signatures printed when a template sets none: the company's authorized representative */
    public const SIG_DEFAULT = [['lead' => 'Very truly yours,', 'name' => '{{signatory.name}}', 'pos' => '{{signatory.position}}', 'extra' => '{{company.name}}', 'align' => 'left',
        'newrow' => 0, 'line' => 0, 'bold' => 1, 'underline' => 0, 'upper' => 0, 'italic' => 0]];

    /** Ready-made signatories for the Format tab: label => block */
    public const SIG_PRESETS = [
        'Company representative' => ['lead' => 'Respectfully yours,', 'name' => '{{signatory.name}}', 'pos' => '{{signatory.position}}', 'extra' => '{{company.name}}'],
        'Dealer\'s signature (with the company)' => ['name' => '{{signatory.name}}', 'pos' => 'Dealer\'s Signature', 'extra' => "**{{company.name_upper}}**\n{{company.address}}", 'underline' => 1],
        'Conforme of the company' => ['lead' => 'Conforme:', 'name' => '{{signatory.name}}', 'pos' => '{{company.name_upper}}', 'extra' => 'Date: ____________', 'underline' => 1],
        'Received by (the company)' => ['lead' => 'Received by:', 'name' => '{{signatory.name}}', 'pos' => 'PRINTED NAME AND SIGNATURE', 'underline' => 1, 'italic' => 1],
        'Punong Barangay' => ['lead' => 'Very truly yours,', 'name' => 'HON. {{entity.head_name_upper}}', 'pos' => 'Barangay Chairman', 'extra' => '{{entity.location}}', 'underline' => 1],
        'BAC Chairman' => ['lead' => '', 'name' => 'HON. {{entity.bac_chair_upper}}', 'pos' => 'BAC Chairman', 'underline' => 1],
        'BAC Chairman (letter)' => ['lead' => 'Very truly yours,', 'name' => 'HON. {{entity.bac_chair_upper}}', 'pos' => 'BAC CHAIRMAN', 'underline' => 1, 'italic' => 1],
        'BAC member 1' => ['name' => 'HON. {{entity.bac_member_1_upper}}', 'pos' => 'BAC Member', 'underline' => 1],
        'BAC member 2' => ['name' => 'HON. {{entity.bac_member_2_upper}}', 'pos' => 'BAC Member', 'underline' => 1],
        'BAC member 3' => ['name' => 'HON. {{entity.bac_member_3_upper}}', 'pos' => 'BAC Member', 'underline' => 1],
        'BAC member 4' => ['name' => 'HON. {{entity.bac_member_4_upper}}', 'pos' => 'BAC Member', 'underline' => 1],
        'BAC member 5' => ['name' => 'HON. {{entity.bac_member_5_upper}}', 'pos' => 'BAC Member', 'underline' => 1],
        'Barangay Treasurer' => ['name' => '{{entity.treasurer_upper}}', 'pos' => 'Barangay Treasurer', 'underline' => 1],
        'Inspector' => ['lead' => 'Inspected by:', 'name' => '{{project.inspector_upper}}', 'pos' => 'Inspector', 'underline' => 1],
        'Blank (a name to write)' => ['lead' => 'Received by:', 'name' => '', 'pos' => 'Signature over Printed Name', 'line' => 1],
    ];

    public const DEFAULTS = [
        'paper' => '', 'orientation' => '', 'width_mm' => '', 'height_mm' => '',
        'margin_top' => 15, 'margin_right' => 14, 'margin_bottom' => 15, 'margin_left' => 14,
        'font' => 'Arial', 'font_size' => 10.5, 'line_height' => 1.35, 'title_size' => 14, 'table_size' => 9.5,
        'border' => 1, 'cell_pad' => 3, 'blank_rows' => 3, 'nothing_follows' => 1, 'show_specs' => 1, 'th_bg' => '', 'comply_text' => '"comply"', 'date_style' => 'dmy',
        'copies' => 1, 'copy_labels' => '', 'offset_x' => 0, 'offset_y' => 0,
        'footer' => '', 'footer_align' => 'right', 'footer_bold' => 0, 'footer_size' => 7.5, 'footer_pos' => 'end',
        'hidden' => [], 'columns' => [],
        // the letterhead ({{letterhead}}): shared company or barangay letterhead, the template's own, or none
        'lh_style' => 'company',
        'lh_left' => 'logo', 'lh_right' => 'none', 'lh_lines' => '', 'lh_align' => 'left', 'lh_font' => '', 'lh_size' => 9.5, 'lh_big' => 17, 'lh_pic_mm' => 24,
        'lh_box' => 0, 'lh_box_bg' => '#b8963e', 'lh_box_fg' => '#1b1b1b', 'lh_rule' => 1, 'lh_rule_color' => '#222222', 'lh_space' => 5, 'lh_pic_a' => '', 'lh_pic_b' => '',
        // the signatures ({{signatories}}): the blocks, how many side by side, the room to sign
        'signatories' => [], 'sig_cols' => 2, 'sig_space' => 12, 'sig_gap' => 7, 'sig_top' => 6,
    ];

    /**
     * The item tables a template can hold: placeholder => [name, columns, quantity (bid / contract), total column, total label].
     * A column: key => [title, width % (0 = the rest), alignment].
     */
    public const TABLES = [
        'pr.items' => ['Purchase request items', ['no' => ['No.', 6, 'center'], 'qty' => ['Qty.', 8, 'center'], 'unit' => ['Unit', 8, 'center'], 'description' => ['Item Description', 0, 'left'],
            'est_unit' => ["Estimated\nUnit Cost", 14, 'right'], 'est_amount' => ["Estimated\nAmount", 16, 'right']], 'bid', 'est_amount', 'TOTAL AMOUNT'],
        'po.items' => ['Purchase order items', ['unit' => ['Unit', 8, 'center'], 'description' => ['Particulars', 0, 'left'], 'qty' => ['Quantity', 10, 'center'],
            'unit_price' => ['Unit Cost', 15, 'right'], 'amount' => ['Amount', 17, 'right']], 'contract', 'amount', ''],
        'ris.items' => ['Requisition and issue slip items', ['unit' => ['Unit', 11, 'center'], 'description' => ['Particulars', 0, 'left'], 'qty' => ['Quantity', 14, 'center'],
            'issued_qty' => ['Qty', 9, 'center'], 'remarks' => ['Remarks', 20, 'left']], 'contract', '', ''],
        'iar.items' => ['Inspection and acceptance report items', ['no' => ["Stock/\nProperty No.", 12, 'center'], 'description' => ['Description', 0, 'left'], 'unit' => ['Unit', 10, 'center'],
            'qty' => ['Quantity', 12, 'center']], 'contract', '', ''],
        // v34: the barangay's request for quotation: UNIT | ITEM DESCRIPTION | QUANTITY | UNIT PRICE | TOTAL (the prices left for the supplier)
        'rfq.items' => ['Request for quotation items', ['unit' => ['UNIT', 9, 'center'], 'description' => ['ITEM DESCRIPTION', 0, 'left'], 'qty' => ['QUANTITY', 13, 'center'],
            'blank_price' => ['UNIT PRICE', 13, 'right'], 'blank_total' => ['TOTAL', 14, 'right']], 'bid', 'blank_total', 'TOTAL'],
        'lines.table' => ['Price schedule (bid quantities)', ['no' => ['Item no.', 6, 'center'], 'description' => ['Description / specifications', 0, 'left'], 'unit' => ['Unit', 9, 'center'],
            'qty' => ['Qty', 10, 'right'], 'unit_price' => ['Unit price', 15, 'right'], 'amount' => ['Total price', 16, 'right']], 'bid', 'amount', 'TOTAL'],
        'lines.contract_table' => ['Contract lines (with change orders)', ['no' => ['Item no.', 6, 'center'], 'description' => ['Description / specifications', 0, 'left'], 'unit' => ['Unit', 9, 'center'],
            'qty' => ['Qty', 10, 'right'], 'unit_price' => ['Unit price', 15, 'right'], 'amount' => ['Total price', 16, 'right']], 'contract', 'amount', 'TOTAL'],
        // the bid's technical specifications (Section VII) and schedule of requirements (Section VI): each specification line of an item on its own line
        'specs.items' => ['Technical specifications with the statement of compliance', ['qty' => ['Qty', 8, 'center'], 'unit' => ['Unit', 8, 'center'], 'description' => ['Description', 0, 'left'],
            'comply' => ["Statement of\nCompliance", 18, 'center']], 'bid', '', ''],
        'sched.items' => ['Schedule of requirements', ['no' => ["ITEM\nNO.", 8, 'center'], 'description' => ['DESCRIPTION', 0, 'left'], 'qty' => ['QUANTITY', 7, 'center'], 'unit' => ['', 9, 'center'],
            'delivery' => ["DELIVERED\nWEEKS/MONTH", 16, 'center']], 'bid', '', ''],
    ];

    /** v39: the item tables whose rows can be added, changed and taken away on a form (Fill in the blanks > Items) */
    public const GRID_TABLES = ['pr.items', 'po.items', 'ris.items', 'iar.items', 'rfq.items'];

    /**
     * v39: the attributes that let a form's page work an item table out again as rows are added or changed: the columns
     * shown and their alignment, the blank rows and "nothing follows" of the layout, the total column and how it is written
     */
    public static function gridAttrs(string $table, array $cols, array $L): string
    {
        if (!in_array($table, self::GRID_TABLES, true)) {
            return '';
        }
        $tot = self::TABLES[$table][3];
        return ' data-cols="' . h(implode(',', array_keys($cols))) . '" data-aligns="' . h(implode(',', array_column($cols, 'align'))) . '" data-blank="' . max(0, (int)$L['blank_rows'])
            . '" data-nf="' . ($L['nothing_follows'] ? 1 : 0) . '" data-total="' . h($tot !== '' && isset($cols[$tot]) ? $tot : '') . '" data-prefix="' . h($table === 'pr.items' ? 'PHP ' : ($table === 'po.items' ? 'Php' : '')) . '"';
    }

    /** Column groups printed over the headings (the RIS: Requisition | Issuance) */
    private const GROUPS = ['ris.items' => [['Requisition', ['unit', 'description', 'qty']], ['Issuance', ['issued_qty', 'remarks']]]];

    // ================================================================== the layout settings

    /** The layout of a template (or a form's template), with every setting filled */
    public static function layout(?array $tpl): array
    {
        $L = self::DEFAULTS;
        $saved = $tpl && !empty($tpl['layout_json']) ? json_decode((string)$tpl['layout_json'], true) : null;
        if (is_array($saved)) {
            foreach ($saved as $k => $v) {
                if (array_key_exists($k, $L)) {
                    $L[$k] = $v;
                }
            }
        }
        if ($L['paper'] === '' || !isset(self::PAPERS[$L['paper']])) {
            $L['paper'] = isset(self::PAPERS[$tpl['paper'] ?? '']) ? $tpl['paper'] : 'A4';
        }
        if (!in_array($L['orientation'], ['portrait', 'landscape'], true)) {
            $L['orientation'] = ($tpl['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait';
        }
        $L['hidden'] = array_values(array_filter((array)$L['hidden'], 'is_string'));
        $L['columns'] = is_array($L['columns']) ? $L['columns'] : [];
        // signatories never set on the template: the company's representative; set (even to none): as set
        $L['signatories'] = is_array($saved) && array_key_exists('signatories', $saved)
            ? array_map([self::class, 'sigBlock'], array_values(array_filter((array)$L['signatories'], 'is_array'))) : self::SIG_DEFAULT;
        if (!isset(self::LH_STYLES[$L['lh_style']])) {
            $L['lh_style'] = 'company';
        }
        // a shared letterhead comes from Contracting Settings
        if (in_array($L['lh_style'], ['company', 'barangay'], true)) {
            $L = array_merge($L, self::sharedLetterhead($L['lh_style']));
        }
        return $L;
    }

    /** The company or barangay letterhead shared by the templates (Contracting Settings, changed from any template's Format tab) */
    public static function sharedLetterhead(string $style): array
    {
        $base = self::LH_SHARED[$style] ?? self::LH_SHARED['company'];
        $saved = class_exists('Settings') ? json_decode((string)Settings::get('brgy_lh_' . $style, ''), true) : null;
        if (is_array($saved)) {
            foreach (self::LH_KEYS as $k) {
                if (array_key_exists($k, $saved)) {
                    $base[$k] = $saved[$k];
                }
            }
        }
        return $base;
    }

    /** Keeps a shared letterhead (only its own keys) */
    public static function saveSharedLetterhead(string $style, array $L): array
    {
        $v = array_intersect_key($L, array_flip(self::LH_KEYS));
        $old = self::sharedLetterhead($style);
        if ($v != array_intersect_key($old, $v)) {
            Settings::set('brgy_lh_' . $style, json_encode($v, JSON_UNESCAPED_UNICODE));
            return [$old, $v];
        }
        return [];
    }

    private static function num($v, float $min, float $max, float $default): float
    {
        if (!is_numeric($v)) {
            return $default;
        }
        return max($min, min($max, round((float)$v, 2)));
    }

    /** A colour as #rrggbb (or the default) */
    public static function color($v, string $default): string
    {
        $v = strtolower(trim((string)$v));
        if (preg_match('/^#[0-9a-f]{3}$/', $v)) {
            $v = '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : $default;
    }

    /** One signatory block, cleaned */
    public static function sigBlock(array $s): array
    {
        $t = fn(string $k, int $len) => mb_substr(trim(str_replace("\r", '', (string)($s[$k] ?? ''))), 0, $len);
        return ['lead' => $t('lead', 300), 'name' => $t('name', 120), 'pos' => $t('pos', 160), 'extra' => $t('extra', 300),
            'align' => isset(self::SIG_ALIGN[$s['align'] ?? '']) ? $s['align'] : 'left',
            'newrow' => !empty($s['newrow']) ? 1 : 0, 'line' => !empty($s['line']) ? 1 : 0, 'bold' => array_key_exists('bold', $s) ? (!empty($s['bold']) ? 1 : 0) : 1,
            'underline' => !empty($s['underline']) ? 1 : 0, 'upper' => !empty($s['upper']) ? 1 : 0, 'italic' => !empty($s['italic']) ? 1 : 0];
    }

    /** The layout settings from the Format tab (only what is known, every number kept within sensible bounds) */
    public static function fromPost(array $in): array
    {
        $d = self::DEFAULTS;
        $L = [];
        $L['paper'] = isset(self::PAPERS[$in['paper'] ?? '']) ? $in['paper'] : 'A4';
        $L['orientation'] = ($in['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait';
        $L['width_mm'] = $L['paper'] === 'Custom' ? self::num($in['width_mm'] ?? '', 50, 600, 210) : '';
        $L['height_mm'] = $L['paper'] === 'Custom' ? self::num($in['height_mm'] ?? '', 50, 900, 297) : '';
        foreach (['margin_top', 'margin_right', 'margin_bottom', 'margin_left'] as $k) {
            $L[$k] = self::num($in[$k] ?? '', 0, 60, $d[$k]);
        }
        $L['font'] = isset(self::FONTS[$in['font'] ?? '']) ? $in['font'] : 'Arial';
        $L['font_size'] = self::num($in['font_size'] ?? '', 6, 20, $d['font_size']);
        $L['line_height'] = self::num($in['line_height'] ?? '', 0.9, 3, $d['line_height']);
        $L['title_size'] = self::num($in['title_size'] ?? '', 8, 36, $d['title_size']);
        $L['table_size'] = self::num($in['table_size'] ?? '', 6, 18, $d['table_size']);
        $L['border'] = self::num($in['border'] ?? '', 0, 4, $d['border']);
        $L['cell_pad'] = self::num($in['cell_pad'] ?? '', 0, 12, $d['cell_pad']);
        $L['blank_rows'] = (int)self::num($in['blank_rows'] ?? '', 0, 40, $d['blank_rows']);
        $L['nothing_follows'] = !empty($in['nothing_follows']) ? 1 : 0;
        $L['show_specs'] = !empty($in['show_specs']) ? 1 : 0;
        $L['copies'] = (int)self::num($in['copies'] ?? '', 1, 6, 1);
        $L['copy_labels'] = mb_substr(trim(str_replace("\r", '', (string)($in['copy_labels'] ?? ''))), 0, 300);
        $L['offset_x'] = self::num($in['offset_x'] ?? '', -30, 30, 0);
        $L['offset_y'] = self::num($in['offset_y'] ?? '', -30, 30, 0);
        $L['footer'] = mb_substr(trim((string)($in['footer'] ?? '')), 0, 200);
        $L['footer_align'] = in_array($in['footer_align'] ?? '', ['left', 'center', 'right'], true) ? $in['footer_align'] : 'right';
        $L['footer_bold'] = !empty($in['footer_bold']) ? 1 : 0;
        $L['footer_size'] = self::num($in['footer_size'] ?? '', 5, 20, $d['footer_size']);
        $L['footer_pos'] = ($in['footer_pos'] ?? '') === 'bottom' ? 'bottom' : 'end';
        $L['th_bg'] = trim((string)($in['th_bg'] ?? '')) === '' ? '' : self::color($in['th_bg'], '');
        $L['comply_text'] = mb_substr(trim((string)($in['comply_text'] ?? $d['comply_text'])), 0, 40);
        $L['date_style'] = ($in['date_style'] ?? '') === 'mdy' ? 'mdy' : 'dmy';
        // the letterhead
        $L['lh_style'] = isset(self::LH_STYLES[$in['lh_style'] ?? '']) ? $in['lh_style'] : 'company';
        $base = self::LH_SHARED[$L['lh_style']] ?? self::LH_SHARED['company'];
        $L['lh_left'] = isset(self::LH_PICS[$in['lh_left'] ?? '']) ? $in['lh_left'] : $base['lh_left'];
        $L['lh_right'] = isset(self::LH_PICS[$in['lh_right'] ?? '']) ? $in['lh_right'] : $base['lh_right'];
        $L['lh_lines'] = mb_substr(trim(str_replace("\r", '', (string)($in['lh_lines'] ?? $base['lh_lines']))), 0, 800);
        $L['lh_align'] = in_array($in['lh_align'] ?? '', ['left', 'center', 'right'], true) ? $in['lh_align'] : $base['lh_align'];
        $L['lh_font'] = isset(self::FONTS[$in['lh_font'] ?? '']) ? $in['lh_font'] : '';
        $L['lh_size'] = self::num($in['lh_size'] ?? '', 6, 24, (float)$base['lh_size']);
        $L['lh_big'] = self::num($in['lh_big'] ?? '', 8, 40, (float)$base['lh_big']);
        $L['lh_pic_mm'] = self::num($in['lh_pic_mm'] ?? '', 8, 60, (float)$base['lh_pic_mm']);
        $L['lh_box'] = !empty($in['lh_box']) ? 1 : 0;
        $L['lh_box_bg'] = self::color($in['lh_box_bg'] ?? '', $base['lh_box_bg']);
        $L['lh_box_fg'] = self::color($in['lh_box_fg'] ?? '', $base['lh_box_fg']);
        $L['lh_rule'] = self::num($in['lh_rule'] ?? '', 0, 6, (float)$base['lh_rule']);
        $L['lh_rule_color'] = self::color($in['lh_rule_color'] ?? '', $base['lh_rule_color']);
        $L['lh_space'] = self::num($in['lh_space'] ?? '', 0, 30, (float)$base['lh_space']);
        foreach (['lh_pic_a', 'lh_pic_b'] as $k) {
            $L[$k] = preg_match('/^lhpic_[a-z0-9_]+\.(png|jpg)$/', (string)($in[$k] ?? '')) ? (string)$in[$k] : '';
        }
        // the signatories
        $sig = [];
        foreach ((array)($in['sig'] ?? []) as $s) {
            if (is_array($s) && (trim((string)($s['name'] ?? '')) !== '' || trim((string)($s['lead'] ?? '')) !== '' || trim((string)($s['pos'] ?? '')) !== '')) {
                $sig[] = self::sigBlock($s);
            }
        }
        $L['signatories'] = array_slice($sig, 0, 16);
        $L['sig_cols'] = (int)self::num($in['sig_cols'] ?? '', 1, 4, $d['sig_cols']);
        $L['sig_space'] = self::num($in['sig_space'] ?? '', 0, 40, $d['sig_space']);
        $L['sig_gap'] = self::num($in['sig_gap'] ?? '', 0, 40, $d['sig_gap']);
        $L['sig_top'] = self::num($in['sig_top'] ?? '', 0, 60, $d['sig_top']);
        $L['hidden'] = array_values(array_filter(array_map(fn($s) => preg_replace('/[^a-z0-9_-]/i', '', (string)$s), (array)($in['hidden'] ?? []))));
        $cols = [];
        foreach ((array)($in['col'] ?? []) as $table => $set) {
            if (!isset(self::TABLES[$table]) || !is_array($set)) {
                continue;
            }
            foreach (self::TABLES[$table][1] as $ck => [$title, $w, $al]) {
                $c = (array)($set[$ck] ?? []);
                $cols[$table][$ck] = [
                    'show' => !empty($c['show']) ? 1 : 0,
                    'label' => mb_substr(trim(str_replace("\r", '', (string)($c['label'] ?? $title))), 0, 60) ?: $title,
                    'width' => (int)self::num($c['width'] ?? $w, 0, 90, (float)$w),
                    'align' => in_array($c['align'] ?? '', ['left', 'center', 'right'], true) ? $c['align'] : $al,
                ];
            }
        }
        $L['columns'] = $cols;
        return $L;
    }

    /** Width and height of the paper in millimetres, turned for landscape */
    public static function size(array $L): array
    {
        [$w, $h] = self::PAPERS[$L['paper']] ?? self::PAPERS['A4'];
        if ($L['paper'] === 'Custom') {
            $w = (float)($L['width_mm'] ?: 210);
            $h = (float)($L['height_mm'] ?: 297);
        }
        if (($L['orientation'] ?? 'portrait') === 'landscape') {
            [$w, $h] = [$h, $w];
        }
        return [(float)$w, (float)$h];
    }

    public static function paperLabel(array $L): string
    {
        [$w, $h] = self::size($L);
        $name = $L['paper'] === 'Custom' ? 'Custom ' . rtrim(rtrim(number_format($w, 1), '0'), '.') . ' x ' . rtrim(rtrim(number_format($h, 1), '0'), '.') . ' mm' : $L['paper'];
        return $name . ', ' . $L['orientation'];
    }

    /** The CSS variables of a form on screen and on paper (put on the .formdoc element) */
    public static function styleAttr(array $L, bool $withPadding = true): string
    {
        [$w, $h] = self::size($L);
        $s = '--paper-w:' . $w . 'mm;--paper-h:' . $h . 'mm;--m-v:' . ((float)$L['margin_top'] + (float)$L['margin_bottom']) . 'mm;--f-font:' . self::FONTS[$L['font']] . ';--f-size:' . $L['font_size'] . 'pt;--f-line:' . $L['line_height'] . ';--f-title:' . $L['title_size'] . 'pt;'
            . '--f-table:' . $L['table_size'] . 'pt;--f-border:' . $L['border'] . 'px;--f-pad:' . $L['cell_pad'] . 'px;' . (($L['th_bg'] ?? '') !== '' ? '--f-th:' . $L['th_bg'] . ';' : '');
        if ($withPadding) {
            $s .= 'padding:' . $L['margin_top'] . 'mm ' . $L['margin_right'] . 'mm ' . $L['margin_bottom'] . 'mm ' . $L['margin_left'] . 'mm;';
        }
        return $s;
    }

    /** Extra classes of the .formdoc (the footer kept at the bottom of the page) */
    public static function docClass(array $L): string
    {
        return ($L['footer'] ?? '') !== '' && ($L['footer_pos'] ?? 'end') === 'bottom' ? ' has-foot-bottom' : '';
    }

    /** The footer of each page (or each copy) */
    public static function footerHtml(array $L): string
    {
        if (($L['footer'] ?? '') === '') {
            return '';
        }
        return '<div class="fx-footer" style="text-align:' . h($L['footer_align'] ?? 'right') . ';font-size:' . (float)($L['footer_size'] ?? 7.5) . 'pt' . (!empty($L['footer_bold']) ? ';font-weight:700;color:#111' : '') . '">'
            . self::inline((string)$L['footer']) . '</div>';
    }

    /** **bold** in a short text typed in the Format tab (everything else escaped) */
    public static function inline(string $text): string
    {
        return (string)preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', h($text));
    }

    // ================================================================== letterhead and signatories

    /**
     * A short text of the letterhead or of a signature with its fields filled: a field with no value leaves its place empty, and a
     * text whose fields are all empty gives '' (the line is left out). $render fills the fields (escaped text in, HTML out).
     */
    public static function fillQuiet(string $text, callable $render): string
    {
        $n = preg_match_all('/\{\{\s*[a-z0-9_.]+\s*\}\}/i', $text);
        $html = (string)$render(h($text));
        $gap = '/<span class="ph-missing"[^>]*>.*?<\/span>/';
        if ($n > 0 && preg_match_all($gap, $html) >= $n) {
            return '';
        }
        return trim((string)preg_replace(['/' . substr($gap, 1, -1) . '/', '/\*\*(.+?)\*\*/u'], ['', '<b>$1</b>'], $html));
    }

    /** The address of a letterhead picture (relative to index.php, so a prepared form finds it wherever the program is) */
    public static function picUrl(string $file): string
    {
        return preg_match('/^lhpic_[a-z0-9_]+\.(png|jpg)$/', $file) && is_file(BrgyDocs::dir() . '/' . $file)
            ? 'index.php?' . http_build_query(['p' => 'file.lhpic', 'f' => $file, 'v' => substr(md5((string)filemtime(BrgyDocs::dir() . '/' . $file)), 0, 6)]) : '';
    }

    /**
     * The letterhead of a form ({{letterhead}}): a picture left and/or right, and the lines of text between them (each line may hold
     * fields; "# " starts a big line, **...** is bold), optionally in a coloured banner, with a rule under it.
     * $pics: the addresses of the pictures that depend on the project (logo, seal, lgu_seal); $render: fills the fields of a line.
     */
    public static function letterheadHtml(array $L, array $pics, callable $render): string
    {
        if (($L['lh_style'] ?? 'company') === 'none') {
            return '';
        }
        $mm = (float)$L['lh_pic_mm'];
        $pic = function (string $which) use ($L, $pics, $mm): string {
            $src = match ($which) {
                'pic_a' => self::picUrl((string)$L['lh_pic_a']), 'pic_b' => self::picUrl((string)$L['lh_pic_b']),
                'logo', 'seal', 'lgu_seal' => (string)($pics[$which] ?? ''), default => '',
            };
            return $src === '' ? '' : '<img src="' . h($src) . '" alt="" style="height:' . $mm . 'mm;max-width:' . round($mm * 3.2, 1) . 'mm;object-fit:contain">';
        };
        $left = $L['lh_left'] !== 'none' ? $pic((string)$L['lh_left']) : '';
        $right = $L['lh_right'] !== 'none' ? $pic((string)$L['lh_right']) : '';
        $lines = '';
        foreach (preg_split('/\n/', (string)$L['lh_lines']) as $line) {
            $big = str_starts_with($line, '# ');
            $line = $big ? substr($line, 2) : $line;
            $html = self::fillQuiet($line, $render);
            if ($html === '' && trim($line) !== '') {
                continue;     // a line of fields with no value: left out
            }
            $lines .= '<div class="lh-line' . ($big ? ' lh-big' : '') . '"' . ($big ? ' style="font-size:' . (float)$L['lh_big'] . 'pt;font-weight:700;line-height:1.1"' : '') . '>' . ($html === '' ? '&nbsp;' : $html) . '</div>';
        }
        $font = ($L['lh_font'] ?? '') !== '' && isset(self::FONTS[$L['lh_font']]) ? 'font-family:' . self::FONTS[$L['lh_font']] . ';' : '';
        $text = '<div class="lh-text" style="' . $font . 'font-size:' . (float)$L['lh_size'] . 'pt;line-height:1.3;text-align:' . h($L['lh_align'])
            . (!empty($L['lh_box']) ? ';background-color:' . h($L['lh_box_bg']) . ';color:' . h($L['lh_box_fg']) . ';border:1px solid #222;padding:2mm 4mm' : '') . '">' . $lines . '</div>';
        $cellW = round($mm * 1.25 + 2, 1);
        $leftW = $left !== '' ? max($cellW, 1) : 0;
        $rightW = $right !== '' ? max($cellW, 1) : 0;
        // centred text stays in the middle of the page: an empty cell balances a single picture (not with a banner, which fills the row)
        if ($L['lh_align'] === 'center' && empty($L['lh_box']) && ($left === '') !== ($right === '')) {
            $leftW = $rightW = $cellW;
        }
        $html = '<table class="fx-plain fx-lh" style="width:100%;border-collapse:collapse"><tbody><tr>'
            . ($leftW ? '<td style="width:' . $leftW . 'mm;vertical-align:middle;text-align:left;padding:0 2mm 0 0">' . $left . '</td>' : '')
            . '<td style="vertical-align:middle;padding:0">' . $text . '</td>'
            . ($rightW ? '<td style="width:' . $rightW . 'mm;vertical-align:middle;text-align:right;padding:0 0 0 2mm">' . $right . '</td>' : '')
            . '</tr></tbody></table>';
        if ((float)$L['lh_rule'] > 0) {
            $html .= '<div class="lh-rule" style="border-top:' . (float)$L['lh_rule'] . 'px solid ' . h($L['lh_rule_color']) . ';margin-top:2mm"></div>';
        }
        return '<div class="fx-letterhead" style="margin-bottom:' . (float)$L['lh_space'] . 'mm">' . $html . '</div>';
    }

    /**
     * The signatures of a form ({{signatories}}): the blocks of the template side by side ($L['sig_cols'] a row), each with its lead
     * ("Very truly yours,"), room to sign, the name, the position and more lines; a block may start a new row.
     */
    public static function signatoriesHtml(array $L, callable $render): string
    {
        $blocks = $L['signatories'] ?? self::SIG_DEFAULT;
        if (!$blocks) {
            return '';
        }
        $cols = max(1, min(4, (int)($L['sig_cols'] ?? 2)));
        $rows = [];
        $row = [];
        foreach ($blocks as $b) {
            $b = self::sigBlock($b);
            if ($row && ($b['newrow'] || count($row) >= $cols)) {
                $rows[] = $row;
                $row = [];
            }
            $row[] = $b;
        }
        if ($row) {
            $rows[] = $row;
        }
        $fill = fn(string $t) => self::fillQuiet($t, $render);
        $out = '<div class="fx-sigs" style="margin-top:' . (float)($L['sig_top'] ?? 6) . 'mm">';
        foreach ($rows as $i => $r) {
            $alone = count($r) === 1 && in_array($r[0]['align'], ['center', 'half'], true);
            $out .= '<table class="fx-plain fx-sigrow" style="width:100%;border-collapse:collapse' . ($i > 0 ? ';margin-top:' . (float)($L['sig_gap'] ?? 7) . 'mm' : '') . '"><tbody><tr>';
            $cells = $alone ? $r : array_pad($r, $cols, null);
            if ($alone && $r[0]['align'] === 'half') {
                $out .= '<td style="width:52%"></td>';
            }
            foreach ($cells as $b) {
                $w = $alone ? ($b['align'] === 'half' ? 48 : 100) : round(100 / $cols, 2);
                if ($b === null) {
                    $out .= '<td style="width:' . $w . '%"></td>';
                    continue;
                }
                $align = $b['align'] === 'half' ? 'center' : $b['align'];
                $b['align'] = $align;
                $name = $fill($b['name']);
                $nameStyle = ($b['bold'] ? 'font-weight:700;' : '') . ($b['underline'] ? 'text-decoration:underline;' : '') . ($b['upper'] ? 'text-transform:uppercase;' : '');
                $out .= '<td style="width:' . $w . '%;vertical-align:top;text-align:' . $align . ';padding:0 3mm 0 0">'
                    . ($b['lead'] !== '' ? '<div class="sg-lead">' . nl2br($fill($b['lead'])) . '</div>' : '')
                    . '<div style="height:' . (float)($L['sig_space'] ?? 12) . 'mm"></div>'
                    . ($b['line'] || $name === '' ? '<div class="sg-line" style="border-top:1px solid #222;width:62mm;max-width:100%;' . ($b['align'] === 'center' ? 'margin:0 auto' : ($b['align'] === 'right' ? 'margin-left:auto' : '')) . '"></div>' : '')
                    . ($name !== '' ? '<div class="sg-nm" style="' . $nameStyle . '">' . $name . '</div>' : '')
                    . ($b['pos'] !== '' ? '<div class="sg-ps"' . ($b['italic'] ? ' style="font-style:italic"' : '') . '>' . nl2br($fill($b['pos'])) . '</div>' : '')
                    . ($b['extra'] !== '' ? '<div class="sg-ex">' . nl2br($fill($b['extra'])) . '</div>' : '')
                    . '</td>';
            }
            $out .= '</tr></tbody></table>';
        }
        return $out . '</div>';
    }

    /** CSS for the hidden parts (and, on paper, the offset) of forms within $scope */
    public static function css(array $L, string $scope = '', bool $print = true): string
    {
        $out = '';
        foreach ($L['hidden'] as $sec) {
            $out .= $scope . ' .formdoc [data-section="' . $sec . '"] { display: none !important; }';
        }
        if ($print && ((float)$L['offset_x'] != 0.0 || (float)$L['offset_y'] != 0.0)) {
            $out .= '@media print { ' . $scope . ' .formdoc { transform: translate(' . (float)$L['offset_x'] . 'mm, ' . (float)$L['offset_y'] . 'mm); } }';
        }
        return $out;
    }

    /** The text of a form without its hidden parts (Word cannot read the CSS that hides them) */
    public static function stripHidden(string $html, array $L): string
    {
        if (!$L['hidden'] || !class_exists('DOMDocument') || trim($html) === '') {
            return $html;
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="__r">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);
        foreach ($L['hidden'] as $sec) {
            foreach (iterator_to_array($xp->query('//*[@data-section="' . preg_replace('/[^a-z0-9_-]/i', '', $sec) . '"]')) as $n) {
                $n->parentNode->removeChild($n);
            }
        }
        $root = $doc->getElementById('__r');
        if (!$root) {
            return $html;
        }
        $out = '';
        foreach ($root->childNodes as $n) {
            $out .= $doc->saveHTML($n);
        }
        return $out;
    }

    /** The parts of a template that can be hidden: data-section key => title */
    public static function sections(string $body): array
    {
        $out = [];
        if (preg_match_all('/<[a-z0-9]+[^>]*\bdata-section="([a-z0-9_-]+)"[^>]*>/i', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $title = preg_match('/\btitle="([^"]*)"/i', $x[0], $t) ? html_entity_decode($t[1], ENT_QUOTES) : ucfirst(str_replace(['_', '-'], ' ', $x[1]));
                $out[$x[1]] = $title;
            }
        }
        if (preg_match('/\{\{\s*lgu\.header\s*\}\}/i', $body)) {
            $out['seals'] = 'Seals in the letterhead';
        }
        return $out;
    }

    /** The item tables a template holds */
    public static function tablesIn(string $body): array
    {
        $out = [];
        foreach (self::TABLES as $k => $t) {
            if (preg_match('/\{\{\s*' . preg_quote($k, '/') . '\s*\}\}/i', $body)) {
                $out[$k] = $t[0];
            }
        }
        return $out;
    }

    /** The columns of an item table as the layout says: key => [show, label, width, align] */
    public static function columns(string $table, array $L): array
    {
        $out = [];
        foreach (self::TABLES[$table][1] as $ck => [$title, $w, $al]) {
            $c = $L['columns'][$table][$ck] ?? [];
            $out[$ck] = ['show' => (int)($c['show'] ?? 1), 'label' => (string)($c['label'] ?? $title), 'width' => (int)($c['width'] ?? $w), 'align' => (string)($c['align'] ?? $al)];
        }
        return $out;
    }

    // ================================================================== item tables

    /** The rows of an item table as plain values (also kept on the form for printing on pre-printed paper) */
    public static function rows(string $table, array $lines, array $L): array
    {
        $qtyOf = self::TABLES[$table][2];
        $out = [];
        $i = 0;
        foreach ($lines as $l) {
            $q = $qtyOf === 'bid' ? (float)$l['bid_qty'] : (float)$l['qty'];
            if ($q == 0.0 && !empty($l['co_id']) && $qtyOf === 'bid') {
                continue;
            }
            $price = (float)$l['unit_price'];
            $est = $l['est_unit_price'] !== null && $l['est_unit_price'] !== '' ? (float)$l['est_unit_price'] : $price;
            $desc = (string)$l['description'];
            $out[] = [
                'no' => (string)(++$i), 'qty' => qty($q), 'unit' => (string)$l['unit'], 'description' => $desc,
                'specs' => $L['show_specs'] && in_array($table, ['lines.table', 'lines.contract_table'], true) ? (string)$l['specs'] : '',
                'unit_price' => number_format($price, 2), 'amount' => number_format(r2($q * $price), 2),
                'est_unit' => number_format($est, 2), 'est_amount' => number_format(r2($q * $est), 2),
                'issued_qty' => '', 'remarks' => '', 'blank_price' => '', 'blank_total' => '', 'comply' => (string)($L['comply_text'] ?? '"comply"'), 'delivery' => '',
                'spec_lines' => in_array($table, ['specs.items', 'sched.items'], true) && ($sp = trim(implode("\n", array_map('rtrim', preg_split('/\R/', (string)$l['specs']))), "\n")) !== '' ? explode("\n", $sp) : [],
                '_amount' => r2($q * $price), '_est' => r2($q * $est),
            ];
        }
        return $out;
    }

    /** Total of the money column of an item table */
    public static function total(string $table, array $rows): float
    {
        $col = self::TABLES[$table][3];
        if ($col === 'est_amount') {
            return r2(array_sum(array_column($rows, '_est')));
        }
        return $col === 'amount' ? r2(array_sum(array_column($rows, '_amount'))) : 0.0;
    }

    /** A line of an item's specifications: a heading in capitals, or "Label:" alone, prints bold; **...** too */
    public static function specLine(string $line): string
    {
        $t = trim($line);
        $plain = preg_replace('/\*\*/', '', $t);
        $bold = (mb_strtoupper($plain) === $plain && preg_match('/[A-Z]{3}/', $plain) && mb_strlen($plain) <= 48) || preg_match('/^[^:]{2,40}:$/u', $plain);
        $html = (string)preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', h($t));
        return $bold ? '<b>' . strip_tags($html) . '</b>' : $html;
    }

    /** The heading cells of an item table: a column with no title joins the one before it (QUANTITY over quantity and unit) */
    private static function headCells(array $cols): string
    {
        $out = '';
        $keys = array_keys($cols);
        foreach ($keys as $i => $ck) {
            if ($cols[$ck]['label'] === '' && $i > 0) {
                continue;
            }
            $span = 1;
            while (isset($keys[$i + $span]) && $cols[$keys[$i + $span]]['label'] === '') {
                $span++;
            }
            $out .= '<th style="text-align:center"' . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>' . nl2br(h($cols[$ck]['label'])) . '</th>';
        }
        return $out;
    }

    /** An item table, with the columns, blank rows and "nothing follows" of the layout ($extra: delivery = the text of the delivery column) */
    public static function itemsTable(string $table, array $lines, array $L, array $extra = []): string
    {
        $cols = array_filter(self::columns($table, $L), fn($c) => $c['show']);
        if (!$cols) {
            return '';
        }
        $rows = self::rows($table, $lines, $L);
        if ($table === 'specs.items' || $table === 'sched.items') {
            return self::specsTable($table, $cols, $rows, $L, $extra);
        }
        $html = '<table class="fx fx-items" data-table="' . h($table) . '"' . self::gridAttrs($table, $cols, $L) . '><colgroup>';
        foreach ($cols as $c) {
            $html .= '<col' . ($c['width'] > 0 ? ' style="width:' . $c['width'] . '%"' : '') . '>';
        }
        $html .= '</colgroup><thead>';
        if (isset(self::GROUPS[$table])) {
            $html .= '<tr>';
            foreach (self::GROUPS[$table] as [$label, $keys]) {
                $n = count(array_intersect($keys, array_keys($cols)));
                if ($n) {
                    $html .= '<th colspan="' . $n . '">' . h($label) . '</th>';
                }
            }
            $html .= '</tr>';
        }
        $html .= '<tr>';
        foreach ($cols as $c) {
            $html .= '<th style="text-align:center">' . nl2br(h($c['label'])) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr>';
            foreach ($cols as $ck => $c) {
                $v = h($r[$ck] ?? '');
                if ($ck === 'description' && $r['specs'] !== '') {
                    $v .= '<br><small>' . nl2br(h($r['specs'])) . '</small>';
                }
                $html .= '<td style="text-align:' . $c['align'] . '">' . $v . '</td>';
            }
            $html .= '</tr>';
        }
        $empty = '<tr>' . str_repeat('<td>&nbsp;</td>', count($cols)) . '</tr>';
        if ($L['nothing_follows'] && $rows) {
            $html .= '<tr class="fx-nf">';
            foreach ($cols as $ck => $c) {
                $html .= $ck === 'description' ? '<td style="text-align:center;font-weight:bold">***nothing follows***</td>' : '<td></td>';
            }
            $html .= '</tr>';
        }
        $html .= str_repeat(str_replace('<tr>', '<tr class="fx-blank">', $empty), max(0, (int)$L['blank_rows']));
        $html .= '</tbody>';
        $totCol = self::TABLES[$table][3];
        if ($totCol !== '' && isset($cols[$totCol])) {
            $keys = array_keys($cols);
            $at = array_search($totCol, $keys, true);
            $label = self::TABLES[$table][4];
            if ($table === 'lines.table' || $table === 'lines.contract_table') {
                $label .= ' (' . (Brgy::on('brgy_prices_vat_inclusive') ? 'VAT inclusive' : 'VAT exclusive') . ')';
            }
            $sum = self::total($table, $rows);
            $value = $totCol === 'blank_total' ? '' : ($table === 'pr.items' ? 'PHP ' : ($table === 'po.items' ? 'Php' : '')) . number_format($sum, 2);
            $html .= '<tfoot><tr>' . ($at > 0 ? '<td colspan="' . $at . '" style="text-align:right;font-weight:bold">' . h($label) . '</td>' : '')
                . '<td class="fx-tot" style="text-align:right;font-weight:bold">' . h($value) . '</td>' . str_repeat('<td></td>', count($keys) - $at - 1) . '</tr></tfoot>';
        }
        return $html . '</table>';
    }

    /**
     * The technical specifications (no lines, the statement of compliance beside the first item) and the schedule of requirements
     * (each specification on its own row, the delivery period in one cell down the side), as the bid forms print them.
     */
    private static function specsTable(string $table, array $cols, array $rows, array $L, array $extra): string
    {
        $sched = $table === 'sched.items';
        $html = '<table class="' . ($sched ? 'fx fx-items fx-sched' : 'fx-plain fx-specs') . '" data-table="' . h($table) . '" style="width:100%"><colgroup>';
        foreach ($cols as $c) {
            $html .= '<col' . ($c['width'] > 0 ? ' style="width:' . $c['width'] . '%"' : '') . '>';
        }
        $html .= '</colgroup><thead><tr>' . self::headCells($cols) . '</tr></thead><tbody>';
        $total = 0;
        foreach ($rows as $r) {
            $total += 1 + ($sched ? count($r['spec_lines']) : 0);
        }
        $total = max(1, $total + ($sched ? (int)$L['blank_rows'] : 0));
        $first = true;
        $deliveryCell = function () use (&$first, $total, $cols, $extra): string {
            if (!isset($cols['delivery'])) {
                return '';
            }
            if (!$first) {
                return '';
            }
            $first = false;
            return '<td rowspan="' . $total . '" style="text-align:' . $cols['delivery']['align'] . ';vertical-align:middle">' . ($extra['delivery'] ?? '') . '</td>';
        };
        foreach ($rows as $n => $r) {
            if ($sched) {
                $html .= '<tr>';
                foreach ($cols as $ck => $c) {
                    if ($ck === 'delivery') {
                        $html .= $deliveryCell();
                        continue;
                    }
                    $v = $ck === 'description' ? '<b>' . h($r['description']) . '</b>' : h((string)($r[$ck] ?? ''));
                    $html .= '<td style="text-align:' . $c['align'] . '">' . $v . '</td>';
                }
                $html .= '</tr>';
                foreach ($r['spec_lines'] as $sl) {
                    $html .= '<tr>';
                    foreach ($cols as $ck => $c) {
                        if ($ck === 'delivery') {
                            continue;
                        }
                        $html .= '<td style="text-align:' . $c['align'] . '">' . ($ck === 'description' ? (trim($sl) === '' ? '&nbsp;' : self::specLine($sl)) : '') . '</td>';
                    }
                    $html .= '</tr>';
                }
                continue;
            }
            $html .= '<tr>';
            foreach ($cols as $ck => $c) {
                if ($ck === 'description') {
                    $v = '<b>' . h($r['description']) . '</b>' . implode('', array_map(fn($sl) => '<br>' . self::specLine($sl), $r['spec_lines']));
                } elseif ($ck === 'comply') {
                    $v = $n === 0 || $r['comply'] !== '' ? '<b><i>' . h($r['comply']) . '</i></b>' : '';
                } else {
                    $v = h((string)($r[$ck] ?? ''));
                }
                $html .= '<td style="text-align:' . $c['align'] . ';vertical-align:top' . ($n > 0 ? ';padding-top:7px' : '') . ($ck === 'qty' || $ck === 'unit' ? ';font-weight:700' : '') . '">' . $v . '</td>';
            }
            $html .= '</tr>';
        }
        if ($sched) {
            for ($i = 0; $i < (int)$L['blank_rows']; $i++) {
                $html .= '<tr>';
                foreach ($cols as $ck => $c) {
                    if ($ck === 'delivery') {
                        $html .= $deliveryCell();
                        continue;
                    }
                    $html .= '<td>&nbsp;</td>';
                }
                $html .= '</tr>';
            }
            if (!$rows && !(int)$L['blank_rows']) {
                $html .= '<tr>' . implode('', array_map(fn($ck) => $ck === 'delivery' ? $deliveryCell() : '<td>&nbsp;</td>', array_keys($cols))) . '</tr>';
            }
        }
        return $html . '</tbody></table>';
    }

    // ================================================================== printing

    /** The paper, margins, font and copies of a printed form; ends the request */
    public static function printPage(string $title, string $body, array $L, array $opt = []): never
    {
        [$w, $h] = self::size($L);
        $draft = !empty($opt['draft']);
        $clean = !empty($opt['clean']);
        layout_start($title, ['bare' => true, 'no_title' => true, 'body_class' => 'print-doc brgy-page']);
        echo '<style>@page { size: ' . $w . 'mm ' . $h . 'mm; margin: ' . $L['margin_top'] . 'mm ' . $L['margin_right'] . 'mm ' . $L['margin_bottom'] . 'mm ' . $L['margin_left'] . 'mm; }' . self::css($L) . '</style>';
        echo '<div class="doc-tools no-print"><div class="d-flex flex-wrap gap-2 align-items-center">'
            . (!empty($opt['back']) ? '<a class="btn btn-sm btn-outline-secondary" href="' . h($opt['back']) . '"><i class="bi bi-arrow-left"></i> Back</a>' : '')
            . '<button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>' . ($opt['tools'] ?? '')
            . '<span class="small text-muted ms-auto">' . h(self::paperLabel($L)) . ((int)$L['copies'] > 1 ? ', ' . (int)$L['copies'] . ' copies' : '') . ($draft ? ', printed with the DRAFT mark' : '')
            . '. In the print dialog choose the same paper, scale 100% (actual size), margins "Default" or "None".</span></div></div>';
        echo '<div class="doc-stage">';
        $labels = array_values(array_filter(array_map('trim', explode("\n", (string)$L['copy_labels']))));
        for ($i = 0; $i < max(1, (int)$L['copies']); $i++) {
            if ($i > 0) {
                echo '<div class="copy-break"></div>';
            }
            echo '<div class="formdoc paper-sheet' . ($draft ? ' is-draft' : '') . ($clean ? ' is-clean' : '') . self::docClass($L) . '" style="' . h(self::styleAttr($L)) . '">'
                . (isset($labels[$i]) ? '<div class="fx-copy">' . h($labels[$i]) . '</div>' : '') . $body . self::footerHtml($L) . '</div>';
        }
        echo '</div>';
        layout_end();
        exit;
    }

    // ================================================================== printing on pre-printed paper

    public const POS_DEFAULTS = ['paper' => '', 'orientation' => '', 'width_mm' => '', 'height_mm' => '', 'offset_x' => 0, 'offset_y' => 0, 'font' => 'Arial', 'font_size' => 10,
        'fields' => [], 'tables' => []];

    public static function positions(?array $tpl): array
    {
        $P = self::POS_DEFAULTS;
        $saved = $tpl && !empty($tpl['positions_json']) ? json_decode((string)$tpl['positions_json'], true) : null;
        if (is_array($saved)) {
            foreach ($saved as $k => $v) {
                if (array_key_exists($k, $P)) {
                    $P[$k] = $v;
                }
            }
        }
        if (!isset(self::PAPERS[$P['paper']])) {
            $P['paper'] = isset(self::PAPERS[$tpl['paper'] ?? '']) ? $tpl['paper'] : 'A4';
        }
        if (!in_array($P['orientation'], ['portrait', 'landscape'], true)) {
            $P['orientation'] = ($tpl['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait';
        }
        $P['fields'] = array_values(array_filter((array)$P['fields'], 'is_array'));
        $P['tables'] = array_values(array_filter((array)$P['tables'], 'is_array'));
        return $P;
    }

    /** The placed fields from the designer (sent as JSON), cleaned */
    public static function positionsFromPost(array $in, string $json): array
    {
        $P = ['paper' => isset(self::PAPERS[$in['paper'] ?? '']) ? $in['paper'] : 'A4', 'orientation' => ($in['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait'];
        $P['width_mm'] = $P['paper'] === 'Custom' ? self::num($in['width_mm'] ?? '', 50, 600, 210) : '';
        $P['height_mm'] = $P['paper'] === 'Custom' ? self::num($in['height_mm'] ?? '', 50, 900, 297) : '';
        $P['offset_x'] = self::num($in['offset_x'] ?? '', -30, 30, 0);
        $P['offset_y'] = self::num($in['offset_y'] ?? '', -30, 30, 0);
        $P['font'] = isset(self::FONTS[$in['font'] ?? '']) ? $in['font'] : 'Arial';
        $P['font_size'] = self::num($in['font_size'] ?? '', 5, 30, 10);
        [$pw, $ph] = self::size($P);
        $d = json_decode($json, true);
        $known = self::fieldList();
        $P['fields'] = [];
        foreach ((array)($d['fields'] ?? []) as $f) {
            $key = strtolower((string)($f['key'] ?? ''));
            if (!isset($known[$key]) && !preg_match('/^txn\.mark_[a-z0-9_]+$/', $key) && !preg_match('/^text:/', $key)) {
                continue;
            }
            $P['fields'][] = ['key' => $key, 'text' => str_starts_with($key, 'text:') ? mb_substr((string)($f['text'] ?? ''), 0, 120) : '',
                'x' => self::num($f['x'] ?? 0, 0, $pw, 0), 'y' => self::num($f['y'] ?? 0, 0, $ph, 0), 'w' => self::num($f['w'] ?? 40, 2, $pw, 40),
                'size' => self::num($f['size'] ?? 0, 0, 30, 0), 'bold' => !empty($f['bold']) ? 1 : 0, 'align' => in_array($f['align'] ?? '', ['left', 'center', 'right'], true) ? $f['align'] : 'left',
                'upper' => !empty($f['upper']) ? 1 : 0];
        }
        $P['tables'] = [];
        foreach ((array)($d['tables'] ?? []) as $t) {
            $key = (string)($t['key'] ?? '');
            if (!isset(self::TABLES[$key])) {
                continue;
            }
            $cols = [];
            foreach ((array)($t['cols'] ?? []) as $c) {
                $ck = (string)($c['col'] ?? '');
                if (!isset(self::TABLES[$key][1][$ck]) && $ck !== 'specs') {
                    continue;
                }
                $cols[] = ['col' => $ck, 'x' => self::num($c['x'] ?? 0, 0, $pw, 0), 'w' => self::num($c['w'] ?? 20, 2, $pw, 20),
                    'align' => in_array($c['align'] ?? '', ['left', 'center', 'right'], true) ? $c['align'] : 'left'];
            }
            $P['tables'][] = ['key' => $key, 'y' => self::num($t['y'] ?? 0, 0, $ph, 0), 'row_h' => self::num($t['row_h'] ?? 5, 2, 40, 5),
                'max_rows' => (int)self::num($t['max_rows'] ?? 15, 1, 80, 15), 'size' => self::num($t['size'] ?? 0, 0, 30, 0), 'cols' => $cols];
        }
        return $P;
    }

    /** Every field that can be placed: key => label (the text fields of the templates, plus the type marks of the transaction form) */
    public static function fieldList(): array
    {
        $out = [];
        foreach (BrgyDocs::FIELDS as $group => $fields) {
            foreach ($fields as $k => $label) {
                if (!in_array($k, BrgyDocs::HTML_FIELDS, true) && !isset(self::TABLES[$k])) {
                    $out[$k] = $label . ' (' . $group . ')';
                }
            }
        }
        foreach (BrgySop::txnTypes() as $code => $label) {
            $out['txn.mark_' . $code] = 'X mark: type ' . $label . ' (Transaction form)';
        }
        return $out;
    }

    /** The values of a form for printing on pre-printed paper: plain text fields and the rows of the item tables */
    public static function values(array $ctx, array $lines, array $L): array
    {
        $f = [];
        foreach ($ctx as $k => $v) {
            if (!is_array($v)) {
                $f[$k] = (string)$v;
            } elseif (isset($v['text'])) {
                $f[$k] = (string)$v['text'];
            }
        }
        $t = [];
        foreach (self::TABLES as $k => $def) {
            $t[$k] = array_map(fn($r) => array_diff_key($r, ['_amount' => 1, '_est' => 1]), self::rows($k, $lines, $L));
        }
        return ['f' => $f, 't' => $t];
    }

    /**
     * One page of fields placed in millimetres. $mode: print (values only), test (labels, boxes and a millimetre grid),
     * design (the same as test, on screen, with the scan of the paper behind).
     */
    public static function renderPositions(array $P, array $vals, string $mode = 'print', string $bgUrl = ''): string
    {
        [$w, $h] = self::size($P);
        $font = self::FONTS[$P['font']] ?? self::FONTS['Arial'];
        $labels = self::fieldList();
        $test = $mode !== 'print';
        $html = '<div class="pos-page' . ($test ? ' is-test' : '') . '" style="width:' . $w . 'mm;height:' . $h . 'mm;font-family:' . h($font) . ';font-size:' . (float)$P['font_size'] . 'pt">';
        if ($bgUrl !== '' && $mode === 'design') {
            $html .= '<img class="pos-bg" src="' . h($bgUrl) . '" alt="">';
        }
        if ($test) {
            $html .= '<svg class="pos-grid" width="' . $w . 'mm" height="' . $h . 'mm" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none">';
            for ($x = 10; $x < $w; $x += 10) {
                $html .= '<line x1="' . $x . '" y1="0" x2="' . $x . '" y2="' . $h . '" class="' . ($x % 50 === 0 ? 'g5' : 'g1') . '"/><text x="' . ($x + 0.6) . '" y="3">' . $x . '</text>';
            }
            for ($y = 10; $y < $h; $y += 10) {
                $html .= '<line x1="0" y1="' . $y . '" x2="' . $w . '" y2="' . $y . '" class="' . ($y % 50 === 0 ? 'g5' : 'g1') . '"/><text x="0.6" y="' . ($y - 0.6) . '">' . $y . '</text>';
            }
            $html .= '</svg>';
        }
        foreach ($P['fields'] as $fd) {
            $key = (string)$fd['key'];
            if (str_starts_with($key, 'text:')) {
                $text = (string)($fd['text'] ?? '');
            } elseif ($test) {
                $text = $vals['f'][$key] ?? '';
                $text = $text !== '' ? $text : (str_starts_with($key, 'txn.mark_') ? 'X' : preg_replace('/ \(.*\)$/', '', $labels[$key] ?? $key));
            } else {
                $text = (string)($vals['f'][$key] ?? '');
            }
            if (!empty($fd['upper'])) {
                $text = mb_strtoupper($text);
            }
            $html .= '<div class="pos-f" style="left:' . (float)$fd['x'] . 'mm;top:' . (float)$fd['y'] . 'mm;width:' . (float)$fd['w'] . 'mm;text-align:' . h($fd['align'])
                . ((float)$fd['size'] > 0 ? ';font-size:' . (float)$fd['size'] . 'pt' : '') . (!empty($fd['bold']) ? ';font-weight:700' : '') . '">' . nl2br(h($text)) . '</div>';
        }
        foreach ($P['tables'] as $t) {
            $rows = $vals['t'][$t['key']] ?? [];
            $n = $test ? max(min(count($rows), (int)$t['max_rows']), min(3, (int)$t['max_rows'])) : min(count($rows), (int)$t['max_rows']);
            for ($i = 0; $i < $n; $i++) {
                $top = (float)$t['y'] + $i * (float)$t['row_h'];
                foreach ($t['cols'] as $c) {
                    $v = $rows[$i][$c['col']] ?? '';
                    if ($test && $v === '') {
                        $v = $i === 0 ? (self::TABLES[$t['key']][1][$c['col']][0] ?? $c['col']) : '';
                    }
                    $html .= '<div class="pos-f pos-cell" style="left:' . (float)$c['x'] . 'mm;top:' . $top . 'mm;width:' . (float)$c['w'] . 'mm;height:' . (float)$t['row_h'] . 'mm;text-align:' . h($c['align'])
                        . ((float)$t['size'] > 0 ? ';font-size:' . (float)$t['size'] . 'pt' : '') . '">' . h(str_replace("\n", ' ', $v)) . '</div>';
                }
            }
            if (count($rows) > (int)$t['max_rows'] && !$test) {
                $html .= '<div class="pos-f" style="left:' . (float)($t['cols'][0]['x'] ?? 10) . 'mm;top:' . ((float)$t['y'] + $n * (float)$t['row_h']) . 'mm;width:80mm;font-style:italic">'
                    . h((count($rows) - $n) . ' more line(s): see the attached list') . '</div>';
            }
        }
        return $html . '</div>';
    }

    /** Prints fields placed on pre-printed paper (or the test page); ends the request */
    public static function printPositions(string $title, array $P, array $vals, array $opt = []): never
    {
        [$w, $h] = self::size($P);
        $mode = !empty($opt['test']) ? 'test' : 'print';
        layout_start($title, ['bare' => true, 'no_title' => true, 'body_class' => 'print-doc brgy-page']);
        echo '<style>@page { size: ' . $w . 'mm ' . $h . 'mm; margin: 0; } @media print { .pos-page { transform: translate(' . (float)$P['offset_x'] . 'mm, ' . (float)$P['offset_y'] . 'mm); } }</style>';
        echo '<div class="doc-tools no-print"><div class="d-flex flex-wrap gap-2 align-items-center">'
            . (!empty($opt['back']) ? '<a class="btn btn-sm btn-outline-secondary" href="' . h($opt['back']) . '"><i class="bi bi-arrow-left"></i> Back</a>' : '')
            . '<button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> ' . ($mode === 'test' ? 'Print the test page' : 'Print') . '</button>' . ($opt['tools'] ?? '')
            . '<span class="small text-muted ms-auto">' . h(self::paperLabel($P)) . ', on pre-printed paper. Scale 100% (actual size), margins "None".'
            . ($mode === 'test' ? ' Print on the pre-printed paper: each value should fall in its blank. Move them in the designer, or set the offset when the whole page is off.' : '') . '</span></div></div>';
        echo '<div class="doc-stage pos-stage">' . self::renderPositions($P, $vals, $mode) . '</div>';
        layout_end();
        exit;
    }
}
