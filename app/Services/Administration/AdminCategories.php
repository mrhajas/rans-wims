<?php

namespace App\Services\Administration;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * The categories of the Administration items (Fuel, Cleaning, Allowance...) and the link of an item to a staff member. Together they let the reports answer
 * "how much fuel did we pay in October", "how much did we pay for Ali" or "Ali's fuel". The list is soft-coded on the Administration settings page; a category
 * already used on an item or a line is always still offered, so an old record never loses it.
 */
class AdminCategories
{
    public const DEFAULTS = 'Fuel, Allowance, Telephone, Cleaning, Maintenance, Vehicle, Office, Meals, Charity, Administration, Other';

    public static function split(string $v): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($x) => mb_substr(trim($x), 0, 60), preg_split('/[,;\n]+/', $v) ?: []), fn ($x) => $x !== '')));
    }

    /** The categories a person picks from: the settings list plus every category already used. */
    public static function list(): array
    {
        $set = self::split((string) (get_settings('admin_categories') ?: self::DEFAULTS));
        $used = collect(DB::table('admin_items')->whereNotNull('category')->distinct()->pluck('category'))->merge(DB::table('admin_document_items')->whereNotNull('category')->distinct()->pluck('category'))->all();
        $all = $set;
        foreach ($used as $u) {
            if (! in_array(mb_strtolower($u), array_map('mb_strtolower', $all), true)) {
                $all[] = $u;
            }
        }

        return $all;
    }

    /** A first guess of the category from the words of an item, used for the starting template and for items made before categories existed. */
    public static function guess(string $name): string
    {
        $n = mb_strtoupper($name);
        foreach ([
            '/FUEL|PETROL|DIESEL/' => 'Fuel', '/TELEPHONE|AIRTIME|TOP.?UP|CREDIT/' => 'Telephone', '/REPAIR|MAINT|SPARE/' => 'Maintenance', '/CAR WASH|VESPA|VEHICLE|PARKING/' => 'Vehicle',
            '/CLEANING|KITCHEN|GROCER|DETERGENT|SOAP/' => 'Cleaning', '/MEAL|LUNCH|FOOD|TEA\b/' => 'Meals', '/CHARITY|ZAKAT|SADAKA|MASJID/' => 'Charity', '/ALLOWANCE|TRANSPORT/' => 'Allowance',
            '/STATIONAR|NEWS ?PAPER|WATER|PRINT|OFFICE/' => 'Office',
        ] as $re => $cat) {
            if (preg_match($re, $n)) {
                return $cat;
            }
        }

        return 'Administration';
    }

    /** "s:5" (a staff member) or "c:7" (a contact) from a dropdown into the two columns; anything else is nobody. */
    public static function parsePerson(?string $v): array
    {
        if (preg_match('/^s:(\d+)$/', (string) $v, $m)) {
            return ['staff_id' => (int) $m[1], 'contact_id' => null];
        }
        if (preg_match('/^c:(\d+)$/', (string) $v, $m)) {
            return ['staff_id' => null, 'contact_id' => (int) $m[1]];
        }

        return ['staff_id' => null, 'contact_id' => null];
    }

    /** The one dropdown list of people: every staff member in a first section, every contact in a second (values "s:ID" / "c:ID"), each A to Z. */
    public static function peopleOptions(bool $everyone = false): array
    {
        // filters and reports list every staff member (also those who left, their old lines are still there); forms only those on the payroll
        $staff = \App\Models\Staff::query()->when(! $everyone, fn ($q) => $q->whereIn('status', \App\Models\Staff::PAYROLL_STATUSES))->orderBy('name')->get(['id', 'name'])
            ->map(fn ($x) => ['value' => 's:' . $x->id, 'label' => $x->name, 'section' => __('Staff'), 'section_icon' => 'users', 'section_tone' => 'emerald']);
        // the contact names are only listed to people who may read Contacts
        $canContacts = ! auth()->check() || auth()->user()->can('read-contacts');
        $contacts = ($canContacts ? \App\Models\Contact::query()->orderBy('name')->get(['id', 'name']) : collect())
            ->map(fn ($x) => ['value' => 'c:' . $x->id, 'label' => $x->name, 'section' => __('Contacts'), 'section_icon' => 'group', 'section_tone' => 'amber']);

        return $staff->concat($contacts)->values()->all();
    }

    /** The staff member whose name is exactly this text (a person on the payroll), or null. */
    public static function staffByName(?string $name): ?Staff
    {
        $n = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $name)));
        if ($n === '') {
            return null;
        }
        $hits = Staff::query()->whereIn('status', Staff::PAYROLL_STATUSES)->whereRaw('LOWER(TRIM(name)) = ?', [$n])->limit(2)->get();

        return $hits->count() === 1 ? $hits->first() : null; // two people with the same name are never linked by guess
    }

    /** Gives every item without a category a guessed one and links items to the staff member of their usual beneficiary; then copies both onto the lines already made. Safe to repeat. */
    public static function backfill(): array
    {
        $cat = 0;
        $linked = 0;
        foreach (DB::table('admin_items')->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('category')->orWhereNull('staff_id'))->get() as $i) {
            $set = [];
            if ($i->category === null) {
                $set['category'] = self::guess($i->name);
                $cat++;
            }
            if ($i->staff_id === null && ($s = self::staffByName($i->default_beneficiary))) {
                $set['staff_id'] = $s->id;
                $linked++;
            }
            if ($set) {
                DB::table('admin_items')->where('id', $i->id)->update($set);
            }
        }
        DB::statement('UPDATE admin_document_items l JOIN admin_items i ON i.id = l.admin_item_id SET l.category = i.category WHERE l.category IS NULL AND i.category IS NOT NULL');
        DB::statement('UPDATE admin_document_items l JOIN admin_items i ON i.id = l.admin_item_id SET l.staff_id = i.staff_id WHERE l.staff_id IS NULL AND i.staff_id IS NOT NULL');

        return ['categories' => $cat, 'linked' => $linked];
    }
}
