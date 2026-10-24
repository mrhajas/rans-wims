<?php

namespace App\Http\Controllers\Travels;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Staff;
use App\Models\Travel;
use App\Models\TravelItem;
use App\Models\TravelPackage;
use App\Models\TravelPackageAudit;
use App\Models\TravelTraveller;
use App\Models\User;
use App\Services\Travels\TravelNotifier;
use App\Support\ReferenceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Travel Packages: ready-made travels (Unguja to Pemba) with what they cost for one person. Managed with their own permissions; every change of a package is kept
 * in its history. A person with "use" asks for a Travel Request from a package: how many people, who, when. The request waits for approval, where the amounts
 * can still be changed.
 */
class PackageController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]); // every action is checked below, by name
    }

    private function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    private function row(TravelPackage $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'trip_type' => $p->trip_type, 'trip_label' => __(Travel::TRIP_TYPES[$p->trip_type] ?? $p->trip_type), 'from_place' => $p->from_place, 'from_country' => $p->from_country,
            'to_place' => $p->to_place, 'to_country' => $p->to_country, 'route' => $p->routeLabel(), 'currency' => $p->currency, 'exchange_rate' => (string) $p->exchange_rate,
            'ticket_amount' => (string) $p->ticket_amount, 'accommodation_amount' => (string) $p->accommodation_amount, 'per_diem_amount' => (string) $p->per_diem_amount, 'transport_amount' => (string) $p->transport_amount,
            'total' => number_format($p->totalPerPerson(), 2, '.', ''), 'ticket_agent_id' => $p->ticket_agent_contact_id, 'ticket_agent' => optional($p->ticketAgent)->name, 'notes' => $p->notes, 'is_active' => (bool) $p->is_active,
            'updated_at' => optional($p->updated_at)->toDateTimeString(), 'created_by' => optional($p->creator)->name,
        ];
    }

    private function issuers()
    {
        return Contact::query()->whereIn('category', [Contact::TICKET_AGENT_CATEGORY, Contact::VISA_AGENT_CATEGORY])->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ($c->phone ? ' (' . $c->phone . ')' : '')])->values();
    }

    // ---------------------------------------------------------------------------------------------------- the packages

    public function index(Request $request)
    {
        $this->need('read-travel-packages');
        $f = ['search' => trim((string) $request->query('search', '')), 'status' => (string) $request->query('status', '')];
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $rows = TravelPackage::query()->with(['ticketAgent:id,name', 'creator:id,name'])
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like($f['search']))->orWhere('from_place', 'like', $like($f['search']))->orWhere('to_place', 'like', $like($f['search']))->orWhere('to_country', 'like', $like($f['search']))))
            ->when($f['status'] === 'active', fn ($q) => $q->where('is_active', true))->when($f['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('is_active')->orderBy('name')->paginate(18)->withQueryString();
        $ids = $rows->getCollection()->pluck('id')->all();
        $uses = TravelPackageAudit::query()->whereIn('travel_package_id', $ids)->where('action', 'requested')->selectRaw('travel_package_id, COUNT(*) c')->groupBy('travel_package_id')->pluck('c', 'travel_package_id');
        $changes = TravelPackageAudit::query()->whereIn('travel_package_id', $ids)->selectRaw('travel_package_id, COUNT(*) c')->groupBy('travel_package_id')->pluck('c', 'travel_package_id');
        $rows->through(fn ($p) => $this->row($p) + ['requests' => (int) ($uses[$p->id] ?? 0), 'history' => (int) ($changes[$p->id] ?? 0)]);
        $me = $request->user();

        return Inertia::render('Travels/Packages/Index', [
            'rows' => $rows, 'filters' => $f, 'issuers' => $this->issuers(), 'currencies' => Travel::currencies(),
            'stats' => ['total' => TravelPackage::count(), 'active' => TravelPackage::where('is_active', true)->count(), 'inactive' => TravelPackage::where('is_active', false)->count()],
            'can' => ['create' => $me->can('create-travel-packages'), 'update' => $me->can('update-travel-packages'), 'delete' => $me->can('delete-travel-packages'), 'use' => $me->can('use-travel-packages') && $me->can('create-travels'), 'audit' => $me->can('audit-travel-packages')],
        ]);
    }

    private function validated(Request $request, ?TravelPackage $existing): array
    {
        $d = $request->validate([
            'name' => 'required|string|max:150', 'trip_type' => 'required|in:return,one_way', 'from_place' => 'nullable|string|max:150', 'from_country' => 'nullable|string|max:100', 'to_place' => 'required|string|max:150', 'to_country' => 'nullable|string|max:100',
            'currency' => 'required|string|size:3', 'exchange_rate' => 'nullable|numeric|gt:0|max:99999999',
            'ticket_amount' => 'nullable|numeric|min:0|max:99999999999', 'accommodation_amount' => 'nullable|numeric|min:0|max:99999999999', 'per_diem_amount' => 'nullable|numeric|min:0|max:99999999999', 'transport_amount' => 'nullable|numeric|min:0|max:99999999999',
            'ticket_agent_contact_id' => 'nullable|integer', 'notes' => 'nullable|string|max:2000',
        ], ['name.required' => __('Give the package a name, for example Unguja to Pemba.'), 'to_place.required' => __('Where does this package go?')]);
        $errors = [];
        $currency = strtoupper($d['currency']);
        $rate = $currency === 'TZS' ? 1.0 : (float) ($d['exchange_rate'] ?? 0);
        if ($currency !== 'TZS' && $rate <= 0) {
            $errors['exchange_rate'] = __('Enter the exchange rate: how many TZS one :c is worth.', ['c' => $currency]);
        }
        $amounts = [];
        foreach (array_keys(TravelPackage::AMOUNTS) as $k) {
            $amounts[$k] = round((float) ($d[$k] ?? 0), 2);
        }
        if (array_sum($amounts) <= 0) {
            $errors['ticket_amount'] = __('Enter at least one amount: the ticket, the accommodation, the per diem or the inside transport.');
        }
        $name = trim($d['name']);
        if (TravelPackage::query()->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            $errors['name'] = __('There is already a package with this name.');
        }
        $agent = ! empty($d['ticket_agent_contact_id']) ? (int) $d['ticket_agent_contact_id'] : null;
        if ($agent && ! $this->issuers()->pluck('value')->contains($agent) && ! ($existing && (int) $existing->ticket_agent_contact_id === $agent)) {
            $errors['ticket_agent_contact_id'] = __('Choose the ticket agent from the list (a contact in the Ticket Agent category).');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $clean = fn ($v) => ($v = is_string($v) ? trim($v) : $v) === '' ? null : $v;

        return ['name' => $name, 'trip_type' => $d['trip_type'], 'from_place' => $clean($d['from_place'] ?? null), 'from_country' => $clean($d['from_country'] ?? null), 'to_place' => trim($d['to_place']), 'to_country' => $clean($d['to_country'] ?? null),
            'currency' => $currency, 'exchange_rate' => $rate, 'ticket_agent_contact_id' => $agent, 'notes' => $clean($d['notes'] ?? null)] + $amounts;
    }

    /** What changed between two versions of a package, field by field, in words a person reads in the history. */
    private function diff(array $before, array $after): array
    {
        $labels = ['name' => 'Name', 'trip_type' => 'Trip', 'from_place' => 'From', 'from_country' => 'From country', 'to_place' => 'To', 'to_country' => 'To country', 'currency' => 'Currency', 'exchange_rate' => 'Exchange rate',
            'ticket_amount' => 'Ticket', 'accommodation_amount' => 'Accommodation', 'per_diem_amount' => 'Expenses / Per Diem', 'transport_amount' => 'Inside Transport', 'ticket_agent_contact_id' => 'Ticket agent', 'notes' => 'Notes'];
        $out = [];
        foreach ($labels as $k => $label) {
            $a = $before[$k] ?? null;
            $b = $after[$k] ?? null;
            $same = is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 0.0000001 : (string) ($a ?? '') === (string) ($b ?? '');
            if (! $same) {
                $out[$label] = [$k === 'ticket_agent_contact_id' ? ($a ? optional(Contact::withTrashed()->find($a))->name : null) : $a, $k === 'ticket_agent_contact_id' ? ($b ? optional(Contact::withTrashed()->find($b))->name : null) : $b];
            }
        }

        return $out;
    }

    public function store(Request $request)
    {
        $this->need('create-travel-packages');
        $d = $this->validated($request, null);
        $p = TravelPackage::create($d + ['is_active' => true, 'created_by_user_id' => $request->user()->id]);
        TravelPackageAudit::record($p, 'created');
        log_activity('Travel package ' . $p->name . ' created', ['total_per_person' => $p->totalPerPerson(), 'currency' => $p->currency], $p, 'Travels');

        return back()->with('message', __('Package :n saved.', ['n' => $p->name]));
    }

    public function update(Request $request, TravelPackage $package)
    {
        $this->need('update-travel-packages');
        $d = $this->validated($request, $package);
        $before = $package->only(array_keys($d));
        $package->update($d);
        $changes = $this->diff($before, $package->fresh()->only(array_keys($d)));
        if ($changes) {
            TravelPackageAudit::record($package->fresh(), 'updated', $changes);
            log_activity('Travel package ' . $package->name . ' updated', ['changes' => $changes], $package, 'Travels');
        }

        return back()->with('message', $changes ? __('Package :n changed. The change is in its history.', ['n' => $package->name]) : __('Nothing was changed.'));
    }

    public function toggle(Request $request, TravelPackage $package)
    {
        $this->need('update-travel-packages');
        $package->update(['is_active' => ! $package->is_active]);
        TravelPackageAudit::record($package->fresh(), $package->is_active ? 'activated' : 'deactivated');
        log_activity('Travel package ' . $package->name . ($package->is_active ? ' switched on' : ' switched off'), [], $package, 'Travels');

        return back()->with('message', $package->is_active ? __('Package :n is on: it can be used for Travel Requests.', ['n' => $package->name]) : __('Package :n is off: it cannot be used until it is switched on.', ['n' => $package->name]));
    }

    public function destroy(Request $request, TravelPackage $package)
    {
        $this->need('delete-travel-packages');
        TravelPackageAudit::record($package, 'deleted');
        $package->delete();
        log_activity('Travel package ' . $package->name . ' deleted', [], $package, 'Travels');

        return back()->with('message', __('Package :n deleted. Travels made from it stay as they are.', ['n' => $package->name]));
    }

    /** The history of one package: each line is the package as it was that day, with what changed. JSON for the history window. */
    public function history(Request $request, TravelPackage $package)
    {
        $this->need('audit-travel-packages');
        $rows = $package->audits()->paginate(20)->withQueryString();
        $prev = null;

        return response()->json([
            'package' => ['id' => $package->id, 'name' => $package->name, 'route' => $package->routeLabel(), 'currency' => $package->currency, 'total' => number_format($package->totalPerPerson(), 2, '.', '')],
            'data' => collect($rows->items())->map(fn ($a) => [
                'id' => $a->id, 'action' => $a->action, 'at' => optional($a->created_at)->toIso8601String(), 'by' => $a->user_name, 'ip' => $a->ip, 'name' => $a->name, 'route' => $a->route, 'currency' => $a->currency, 'exchange_rate' => $a->exchange_rate,
                'ticket_amount' => $a->ticket_amount, 'accommodation_amount' => $a->accommodation_amount, 'per_diem_amount' => $a->per_diem_amount, 'transport_amount' => $a->transport_amount, 'total_per_person' => $a->total_per_person,
                'is_active' => $a->is_active, 'changes' => $a->changes ?: new \stdClass(), 'details' => $a->details ?: new \stdClass(),
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'from' => $rows->firstItem(), 'to' => $rows->lastItem()],
        ]);
    }

    // ---------------------------------------------------------------------------------------------------- a Travel Request from a package

    public function requestForm(TravelPackage $package)
    {
        $this->need('use-travel-packages');
        $this->need('create-travels');
        abort_unless($package->is_active, 404);
        $package->loadMissing('ticketAgent:id,name');

        return Inertia::render('Travels/Packages/Request', [
            'package' => $this->row($package), 'types' => Travel::travelTypes(), 'agents' => $this->issuers(),
            'staff' => Staff::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Terminated'))->orderBy('name')->get(['id', 'name', 'staff_number', 'mobile_no', 'user_id'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->name . ($s->staff_number ? ' (' . $s->staff_number . ')' : ''), 'name' => $s->name, 'phone' => $s->mobile_no]),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name, 'name' => $u->name]),
            'today' => now()->toDateString(),
        ]);
    }

    public function submitRequest(Request $request, TravelPackage $package)
    {
        $this->need('use-travel-packages');
        $this->need('create-travels');
        abort_unless($package->is_active, 404);
        $d = $request->validate([
            'people' => 'required|array|min:1|max:30', 'people.*.traveller_type' => 'required|in:staff,user,other', 'people.*.staff_id' => 'nullable|integer', 'people.*.user_id' => 'nullable|integer', 'people.*.name' => 'nullable|string|max:150', 'people.*.phone' => 'nullable|string|max:60',
            'depart_date' => 'required|date', 'return_date' => 'nullable|date|after_or_equal:depart_date', 'travel_type' => 'nullable|string|max:60', 'purpose' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:2000', 'ticket_agent_contact_id' => 'nullable|integer',
        ], ['people.required' => __('Add at least one traveller.'), 'people.min' => __('Add at least one traveller.'), 'depart_date.required' => __('When do they leave?')]);
        $errors = [];
        $rows = [];
        $seen = [];
        foreach ($d['people'] as $i => $p) {
            $type = $p['traveller_type'];
            $staff = $type === 'staff' && ! empty($p['staff_id']) ? Staff::query()->find((int) $p['staff_id']) : null;
            $user = $type === 'user' && ! empty($p['user_id']) ? User::query()->find((int) $p['user_id']) : null;
            if ($type === 'staff' && ! $staff) {
                $errors["people.$i.staff_id"] = __('Choose the staff member.');
            }
            if ($type === 'user' && ! $user) {
                $errors["people.$i.user_id"] = __('Choose the user.');
            }
            $name = $staff ? $staff->name : ($user ? $user->name : trim((string) ($p['name'] ?? '')));
            if ($type === 'other' && $name === '') {
                $errors["people.$i.name"] = __('Type the name of the traveller.');
            }
            $key = $staff ? 's' . $staff->id : ($user ? 'u' . $user->id : 'n' . mb_strtolower($name));
            if (isset($seen[$key]) && $name !== '') {
                $errors["people.$i." . ($staff ? 'staff_id' : ($user ? 'user_id' : 'name'))] = __(':n is already on this request.', ['n' => $name]);
            }
            $seen[$key] = true;
            $rows[] = ['traveller_type' => $type, 'staff_id' => $staff?->id, 'user_id' => $user ? $user->id : $staff?->user_id, 'name' => $name, 'phone' => ($p['phone'] ?? null) ?: ($staff?->mobile_no), 'ticket_number' => null,
                'ticket_amount' => (float) $package->ticket_amount, 'visa_amount' => 0, 'sort_order' => $i];
        }
        if ($package->trip_type === 'return' && empty($d['return_date'])) {
            $errors['return_date'] = __('This package is a return trip: when do they come back?');
        }
        $agent = ! empty($d['ticket_agent_contact_id']) ? (int) $d['ticket_agent_contact_id'] : $package->ticket_agent_contact_id;
        if ($agent && ! $this->issuers()->pluck('value')->contains($agent) && (int) $agent !== (int) $package->ticket_agent_contact_id) {
            $errors['ticket_agent_contact_id'] = __('Choose the ticket agent from the list.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $n = count($rows);
        $rate = (float) $package->exchange_rate ?: 1.0;
        $line = function (string $category, float $each) use ($n, $package, $rate) {
            $amount = round($each * $n, 2);

            return $amount > 0 ? ['category' => $category, 'description' => $n . ' x ' . number_format($each, 2) . ' (' . $package->name . ')', 'contact_id' => null, 'currency' => $package->currency, 'exchange_rate' => $rate, 'amount' => $amount, 'amount_tzs' => TravelItem::toTzs($amount, $rate)] : null;
        };
        $items = array_values(array_filter([$line('Accommodation', (float) $package->accommodation_amount), $line('Expenses / Per Diem', (float) $package->per_diem_amount), $line('Inside Transport', (float) $package->transport_amount)]));
        foreach ($items as $i => &$it) {
            $it['sort_order'] = $i;
        }
        unset($it);
        $ticket = round((float) $package->ticket_amount * $n, 2);
        $clean = fn ($v) => ($v = is_string($v) ? trim($v) : $v) === '' ? null : $v;

        $t = ReferenceLock::run('travels', fn () => DB::transaction(function () use ($package, $d, $rows, $items, $ticket, $agent, $rate, $clean) {
            $t = Travel::create([
                'reference' => Travel::nextReference(), 'status' => Travel::PENDING, 'travel_package_id' => $package->id, 'package_name' => $package->name, 'trip_type' => $package->trip_type, 'travel_type' => $clean($d['travel_type'] ?? null), 'purpose' => $clean($d['purpose'] ?? null),
                'from_place' => $package->from_place, 'from_country' => $package->from_country, 'to_place' => $package->to_place, 'to_country' => $package->to_country, 'scope' => Travel::scopeOfCountry($package->to_country),
                'depart_date' => $d['depart_date'], 'return_date' => $package->trip_type === 'return' ? ($d['return_date'] ?? null) : null, 'booking_date' => now()->toDateString(),
                'ticket_agent_contact_id' => $ticket > 0 ? $agent : null, 'currency' => $package->currency, 'exchange_rate' => $rate, 'ticket_amount' => $ticket, 'has_visa' => false, 'visa_amount' => 0, 'total_amount' => $ticket, 'notes' => $clean($d['notes'] ?? null),
            ]);
            foreach ($rows as $r) {
                TravelTraveller::create($r + ['travel_id' => $t->id]);
            }
            foreach ($items as $it) {
                TravelItem::create($it + ['travel_id' => $t->id]);
            }

            return $t;
        }));
        $n = count($rows);
        TravelPackageAudit::record($package, 'requested', [], ['travel' => $t->reference, 'travel_id' => $t->id, 'people' => $n, 'total' => round($package->totalPerPerson() * $n, 2), 'depart_date' => $t->depart_date->toDateString()]);
        log_activity('Travel Request ' . $t->reference . ' made from package ' . $package->name, ['people' => $n, 'total' => round($package->totalPerPerson() * $n, 2), 'currency' => $package->currency], $t, 'Travels');
        TravelNotifier::fire('approval_needed', $t, $request->user());

        return redirect()->route('travels.show', $t->id)->with('message', __(':r is a Travel Request now. It waits for approval, where the amounts can still be changed.', ['r' => $t->reference]));
    }
}
