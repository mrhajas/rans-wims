<?php

namespace App\Services\Subcontracts;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Soft-coded fields of the Subcontracts module. For each kind of record (agreement, work item, variation, payment request):
 *  - the OPTIONAL built-in fields can be switched off (hidden everywhere and ignored on save) or made required;
 *  - the people running the module can add their own fields (text, long text, number, date, list, yes/no). Their values are kept in the record's
 *    `extra_attributes` JSON under the key "custom", so no table changes are needed when a field is added or removed. Removing a definition hides the
 *    field; the stored values stay untouched, so adding it back brings them back.
 * The core fields (who, what, how much, which currency) cannot be switched off: the money and the approval flow depend on them.
 */
class SubcontractFields
{
    public const TYPES = ['text' => 'Short text', 'textarea' => 'Long text', 'number' => 'Number', 'date' => 'Date', 'select' => 'Choose from a list', 'yesno' => 'Yes / No'];

    public const MAX_CUSTOM = 20;

    /** Entities and their optional built-in fields (key => label). */
    public const OPTIONAL = [
        'agreement' => ['warehouse_id' => 'Project(s) / Warehouse(s)', 'site' => 'Site / building / phase', 'administrator_user_id' => 'Administrator', 'agreement_date' => 'Agreement date', 'start_date' => 'Start date',
            'expected_completion' => 'Expected completion', 'scope' => 'Scope of work', 'payment_terms' => 'Payment terms', 'advance_terms' => 'Advance payment terms', 'retention_percent' => 'Retention %', 'vat_treatment' => 'VAT / tax treatment', 'remarks' => 'Remarks'],
        'work_item' => ['category' => 'Work category', 'beneficiary_name' => 'Beneficiary', 'unit' => 'Unit', 'remarks' => 'Remarks'],
        'variation' => ['reason' => 'Reason', 'effective_date' => 'Effective date'],
        'request' => ['description' => 'Description'],
    ];

    public const ENTITY_LABELS = ['agreement' => 'Agreements', 'work_item' => 'Work items', 'variation' => 'Variations', 'request' => 'Payment requests'];

    public static function entities(): array
    {
        return array_keys(self::OPTIONAL);
    }

    /** @return array{builtin: array<string,array{label:string,on:bool,required:bool}>, custom: array<int,array>} */
    public static function config(string $entity): array
    {
        abort_unless(isset(self::OPTIONAL[$entity]), 500);
        $saved = SubcontractSettings::get()['fields'][$entity] ?? [];
        $builtin = [];
        foreach (self::OPTIONAL[$entity] as $key => $label) {
            $s = $saved['builtin'][$key] ?? [];
            $on = array_key_exists('on', $s) ? (bool) $s['on'] : true;
            $builtin[$key] = ['label' => $label, 'on' => $on, 'required' => $on && ! empty($s['required'])];
        }
        $custom = [];
        foreach ((array) ($saved['custom'] ?? []) as $c) {
            if (is_array($c) && ! empty($c['key']) && ! empty($c['label']) && isset(self::TYPES[$c['type'] ?? ''])) {
                $custom[] = ['key' => (string) $c['key'], 'label' => (string) $c['label'], 'type' => $c['type'], 'options' => array_values((array) ($c['options'] ?? [])), 'required' => ! empty($c['required']) && $c['type'] !== 'yesno',
                    'in_list' => ! empty($c['in_list']), 'help' => (string) ($c['help'] ?? '')];
            }
        }

        return ['builtin' => $builtin, 'custom' => $custom];
    }

    /** The custom fields that are shown as extra columns in lists and reports. */
    public static function listFields(string $entity): array
    {
        return array_values(array_filter(self::config($entity)['custom'], fn ($c) => $c['in_list']));
    }

    /** What the pages need: built-in switches and the custom definitions. */
    public static function forPage(string $entity): array
    {
        return self::config($entity);
    }

    /** Validates the request with the base rules of the controller, adjusted to the field settings, plus the custom fields. Returns the validated data. */
    public static function validate(Request $request, string $entity, array $rules, $existingExtra = null): array
    {
        $existingExtra = self::asArray($existingExtra);
        $cfg = self::config($entity);
        $off = [];
        foreach ($cfg['builtin'] as $key => $b) {
            if (! $b['on']) {
                unset($rules[$key]);
                $off[] = $key;
            } elseif ($b['required'] && isset($rules[$key])) {
                $rules[$key] = is_array($rules[$key]) ? $rules[$key] : explode('|', (string) $rules[$key]);
                $rules[$key] = array_values(array_filter($rules[$key], fn ($r) => $r !== 'nullable'));
                array_unshift($rules[$key], 'required');
            }
        }
        $names = [];
        foreach ($cfg['custom'] as $c) {
            $k = 'custom.' . $c['key'];
            $names[$k] = $c['label'];
            $r = match ($c['type']) {
                'number' => ['numeric', 'min:-99999999999', 'max:99999999999'],
                'date' => ['date_format:Y-m-d'],
                'select' => [Rule::in($c['options'])],
                'yesno' => ['boolean'],
                'textarea' => ['string', 'max:3000'],
                default => ['string', 'max:190'],
            };
            $rules[$k] = array_merge([$c['required'] ? 'required' : 'nullable'], $r);
        }
        $d = $request->validate($rules, [], $names);
        $d = array_diff_key($d, array_flip($off));
        $d['extra_attributes'] = self::merge($cfg['custom'], (array) ($d['custom'] ?? []), $existingExtra);
        unset($d['custom']);
        if ($d['extra_attributes'] === null && $existingExtra === null) {
            unset($d['extra_attributes']);
        }

        return $d;
    }

    /** extra_attributes may be an array, a JSON string or a schemaless-attributes object. */
    private static function asArray($v): ?array
    {
        if ($v === null) {
            return null;
        }
        if (is_object($v) && method_exists($v, 'toArray')) {
            return $v->toArray();
        }
        if (is_string($v)) {
            return json_decode($v, true) ?: [];
        }

        return is_array($v) ? $v : [];
    }

    private static function merge(array $defs, array $values, ?array $existing): ?array
    {
        if (! $defs && $existing === null) {
            return null;
        }
        $out = $existing ?? [];
        $custom = (array) ($out['custom'] ?? []);
        foreach ($defs as $c) {
            $v = $values[$c['key']] ?? null;
            $v = match ($c['type']) {
                'yesno' => $v === null || $v === '' ? null : (filter_var($v, FILTER_VALIDATE_BOOLEAN) ? true : false),
                'number' => $v === null || $v === '' ? null : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'),
                default => is_string($v) && trim($v) !== '' ? trim($v) : null,
            };
            if ($v === null) {
                unset($custom[$c['key']]);
            } else {
                $custom[$c['key']] = $v;
            }
        }
        if ($custom) {
            $out['custom'] = $custom;
        } else {
            unset($out['custom']);
        }

        return $out ?: null;
    }

    /** Values of the custom fields that are still defined: [key => value] ready to show (yes/no as words, dates as typed). */
    public static function values(string $entity, $extra): array
    {
        $extra = self::asArray($extra) ?? [];
        $out = [];
        foreach (self::config($entity)['custom'] as $c) {
            $v = $extra['custom'][$c['key']] ?? null;
            $out[$c['key']] = $v;
        }

        return $out;
    }

    /** Cleans the definitions typed on the settings page and checks them. */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::OPTIONAL as $entity => $fields) {
            $in = $input[$entity] ?? [];
            $builtin = [];
            foreach ($fields as $key => $label) {
                $b = $in['builtin'][$key] ?? [];
                $on = ! array_key_exists('on', $b) || filter_var($b['on'], FILTER_VALIDATE_BOOLEAN);
                $builtin[$key] = ['on' => $on, 'required' => $on && filter_var($b['required'] ?? false, FILTER_VALIDATE_BOOLEAN)];
            }
            $custom = [];
            $keys = [];
            $labels = [];
            foreach (array_slice((array) ($in['custom'] ?? []), 0, self::MAX_CUSTOM) as $i => $c) {
                $label = trim(preg_replace('/\s+/', ' ', (string) ($c['label'] ?? '')));
                if ($label === '') {
                    continue;
                }
                if (mb_strlen($label) > 60) {
                    throw ValidationException::withMessages(["fields.$entity.custom.$i.label" => __('A field name can have at most 60 characters.')]);
                }
                $type = (string) ($c['type'] ?? 'text');
                if (! isset(self::TYPES[$type])) {
                    throw ValidationException::withMessages(["fields.$entity.custom.$i.type" => __('Choose the kind of field.')]);
                }
                if (in_array(mb_strtolower($label), $labels, true) || in_array(mb_strtolower($label), array_map('mb_strtolower', array_map(fn ($l) => __($l), $fields)), true)) {
                    throw ValidationException::withMessages(["fields.$entity.custom.$i.label" => __('There is already a field with this name.')]);
                }
                $labels[] = mb_strtolower($label);
                $key = ! empty($c['key']) && preg_match('/^cf_[a-z0-9_]{1,40}$/', (string) $c['key']) ? (string) $c['key'] : 'cf_' . substr(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)), 0, 30) . '_' . substr(md5($label . microtime(true) . $i), 0, 4);
                if (in_array($key, $keys, true)) {
                    $key .= '_' . $i;
                }
                $keys[] = $key;
                $options = [];
                if ($type === 'select') {
                    $raw = is_array($c['options'] ?? null) ? $c['options'] : preg_split('/\r?\n/', (string) ($c['options'] ?? ''));
                    $options = array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), $raw), fn ($o) => $o !== '' && mb_strlen($o) <= 80)));
                    if (count($options) < 2) {
                        throw ValidationException::withMessages(["fields.$entity.custom.$i.options" => __('Give at least two choices, one per line.')]);
                    }
                }
                $custom[] = ['key' => $key, 'label' => $label, 'type' => $type, 'options' => $options, 'required' => $type !== 'yesno' && filter_var($c['required'] ?? false, FILTER_VALIDATE_BOOLEAN), 'in_list' => filter_var($c['in_list'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'help' => mb_substr(trim((string) ($c['help'] ?? '')), 0, 160)];
            }
            $out[$entity] = ['builtin' => $builtin, 'custom' => $custom];
        }

        return $out;
    }
}
