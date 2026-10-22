<?php

namespace App\Services\VisitorLogs;

use App\Models\Setting;
use App\Models\User;

/** The Visitor Logs settings, soft-coded: thresholds, which optional visitor fields are used, who can be visited, the reception location and the retention note. */
class VisitorSettings
{
    /** Optional visitor fields a company may switch on. Off by default: only collect what is needed. */
    public const OPTIONAL_FIELDS = [
        'id_type' => 'Identification document type', 'id_number' => 'Identification number', 'nationality' => 'Nationality', 'address' => 'Address', 'vehicle_registration' => 'Vehicle registration', 'emergency_notes' => 'Emergency / contact notes',
    ];

    public const DEFAULTS = ['visitor_overstay_hours' => '8', 'visitor_collection_days' => '3', 'visitor_retention_months' => '', 'visitor_reception_location' => '', 'visitor_fields' => 'vehicle_registration', 'visitor_host_roles' => '', 'visitor_require_phone' => '0', 'visitor_default_warehouse' => ''];

    public static function get(string $key): string
    {
        $v = get_settings($key);

        return ($v === null || $v === '') ? (self::DEFAULTS[$key] ?? '') : (string) $v;
    }

    /** Hours a visitor can stay checked in before an informational alert goes out (0 = never). */
    public static function overstayHours(): int
    {
        return max(0, min(72, (int) self::get('visitor_overstay_hours')));
    }

    /** Days an item may wait for its recipient before it counts as waiting too long (0 = never). */
    public static function collectionDays(): int
    {
        return max(0, min(365, (int) self::get('visitor_collection_days')));
    }

    public static function retentionMonths(): ?int
    {
        $v = self::get('visitor_retention_months');

        return $v === '' ? null : max(1, (int) $v);
    }

    public static function location(): string
    {
        return trim(self::get('visitor_reception_location'));
    }

    public static function requirePhone(): bool
    {
        return self::get('visitor_require_phone') === '1';
    }

    /** @return string[] the optional fields in use */
    public static function fields(): array
    {
        return array_values(array_intersect(array_keys(self::OPTIONAL_FIELDS), self::list(self::get('visitor_fields'))));
    }

    public static function fieldOn(string $f): bool
    {
        return in_array($f, self::fields(), true);
    }

    /** Roles whose people can be chosen as the person visited (empty = every active user). */
    public static function hostRoles(): array
    {
        return self::list(self::get('visitor_host_roles'));
    }

    public static function list(string $v): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\n]+/', $v) ?: []), fn ($x) => $x !== '')));
    }

    public static function save(array $values, int $accountId): void
    {
        foreach ($values as $k => $v) {
            Setting::updateOrCreate(['tec_key' => $k, 'account_id' => $accountId], ['tec_value' => (string) $v]);
        }
    }

    /** Users that can be chosen as the person visited / the recipient of an item: active, and (when set) holding one of the allowed roles. */
    public static function peopleQuery()
    {
        $roles = self::hostRoles();

        return User::query()->where('is_active', true)->whereNull('disabled_at')
            ->when($roles, fn ($q) => $q->whereHas('roles', fn ($r) => $r->whereIn('name', $roles)));
    }

    /** The Project / Warehouse ids a person may attend from: the ones assigned to them (a person with none and no admin role keeps their own warehouse). null = every project (Super Admin / Admin with no assignment). */
    public static function accessibleWarehouseIds(User $u): ?array
    {
        $assigned = $u->warehouses()->pluck('warehouses.id')->map(fn ($i) => (int) $i)->all();
        if ($assigned) {
            return $assigned;
        }
        if ($u->hasRole(['Super Admin', 'Admin'])) {
            return null;
        }

        return $u->warehouse_id ? [(int) $u->warehouse_id] : [];
    }

    /** The projects offered in "Attended from": [{id, name, code}] */
    public static function warehouseChoices(User $u): array
    {
        $ids = self::accessibleWarehouseIds($u);

        return \App\Models\Warehouse::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name', 'code'])
            ->map(fn ($w) => ['id' => (int) $w->id, 'name' => $w->name, 'code' => $w->code])->all();
    }

    /** The project reception works from (setting; HQ OFFICE when none is set). */
    public static function receptionWarehouseId(): ?int
    {
        $v = self::get('visitor_default_warehouse');
        if ($v !== '' && ctype_digit($v)) {
            return (int) $v;
        }
        $hq = \App\Models\Warehouse::query()->whereRaw('LOWER(name) = ?', ['hq office'])->value('id') ?: \App\Models\Warehouse::query()->where('name', 'like', 'HQ%OFFICE%')->value('id');

        return $hq ? (int) $hq : null;
    }

    /** What "Attended from" starts with: the only project a person has, else the reception project when they may use it, else nothing (they choose). */
    public static function defaultWarehouseId(array $choices): ?int
    {
        if (count($choices) === 1) {
            return $choices[0]['id'];
        }
        $hq = self::receptionWarehouseId();

        return $hq && collect($choices)->contains('id', $hq) ? $hq : null;
    }
}
