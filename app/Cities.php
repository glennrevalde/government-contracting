<?php
declare(strict_types=1);

/**
 * v38: the City/Municipality List (Customers > Lists > City/Municipality List), chosen on the customer form.
 * A city or municipality is deleted only when no customer uses it.
 */
final class Cities
{
    /** the 2026-11-25 migration has been run (the table and the customer's column exist) */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(c.city_id) + COUNT(t.id) FROM customers c, cities t WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** the active ones, by name (with $keep even when inactive: the one a customer already has) */
    public static function active(?int $keep = null): array
    {
        if (!self::ready()) {
            return [];
        }
        return DB::all('SELECT id, name, province, zip_code, is_active FROM cities WHERE is_active = 1' . ($keep ? ' OR id = :k' : '') . ' ORDER BY name, province',
            $keep ? ['k' => $keep] : []);
    }

    public static function get(int $id): ?array
    {
        return self::ready() ? (DB::row('SELECT * FROM cities WHERE id = :id', ['id' => $id]) ?: null) : null;
    }

    /** "Lapu-Lapu City, Cebu" */
    public static function label(array $c): string
    {
        return trim((string)$c['name']) . (trim((string)($c['province'] ?? '')) !== '' ? ', ' . trim((string)$c['province']) : '');
    }

    /** the city or municipality of that name (and province, when given), or null */
    public static function find(string $name, string $province = ''): ?int
    {
        if (!self::ready() || trim($name) === '') {
            return null;
        }
        $id = DB::value('SELECT id FROM cities WHERE name = :n' . ($province !== '' ? ' AND province = :p' : '') . ' ORDER BY is_active DESC, id LIMIT 1',
            ['n' => trim($name)] + ($province !== '' ? ['p' => trim($province)] : []));
        return $id ? (int)$id : null;
    }
}
