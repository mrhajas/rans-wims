<?php

namespace App\Http\Controllers\HolidayGreetings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HolidayGreetings\Concerns\Guards;
use App\Models\HolidayBundle;
use App\Models\HolidayContact;
use App\Models\HolidayDay;
use App\Services\HolidayGreetings\HolidaySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Greeting List Bundles: named groups of the people on the greeting list (Staff, Clients, Directors...), so a holiday's greeting can go to one bundle instead of everybody. */
class BundleController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->need('read-holiday-contacts');
        $me = $request->user();
        $members = DB::table('holiday_bundle_contacts')->get()->groupBy('bundle_id');
        $people = HolidayContact::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'title', 'name', 'email', 'mobile', 'source', 'source_id', 'group_label']);
        // what each person is outside the greeting list: the roles of a user, the position of a staff member, the category of a contact (the filters of the picker)
        $roles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('model_has_roles.model_type', \App\Models\User::class)
            ->get(['model_has_roles.model_id as uid', 'roles.name'])->groupBy('uid')->map(fn ($g) => $g->pluck('name')->values()->all());
        $positions = \App\Models\Staff::withoutGlobalScopes()->whereIn('id', $people->where('source', 'staff')->pluck('source_id')->filter())->pluck('position', 'id');
        $byId = $people->keyBy('id');
        $rows = HolidayBundle::query()->orderBy('name')->get()->map(function ($b) use ($members, $byId) {
            $ids = collect($members[$b->id] ?? [])->pluck('contact_id')->map(fn ($i) => (int) $i)->filter(fn ($i) => $byId->has($i))->values();
            $in = $ids->map(fn ($i) => $byId[$i]);

            return ['id' => $b->id, 'name' => $b->name, 'note' => $b->note, 'ids' => $ids->all(), 'people' => $ids->count(), 'emails' => $in->whereNotNull('email')->count(), 'mobiles' => $in->whereNotNull('mobile')->count(),
                'updated' => optional($b->updated_at)->toIso8601String()];
        })->values();

        return Inertia::render('HolidayGreetings/Bundles', [
            'bundles' => $rows,
            'people' => $people->map(fn ($c) => ['id' => $c->id, 'full' => $c->fullName(), 'email' => $c->email, 'mobile' => $c->mobile, 'source' => $c->source, 'group' => $c->group_label,
                'roles' => $c->source === 'user' ? ($roles[$c->source_id] ?? []) : [],
                'cat' => match ($c->source) { 'contact' => $c->group_label ? __('Contact') . ': ' . $c->group_label : null, 'staff' => trim((string) ($positions[$c->source_id] ?? '')) ? __('Staff') . ': ' . trim($positions[$c->source_id]) : null, default => null }])->values(),
            'roleOptions' => $roles->flatten()->unique()->sort()->values(),
            'catOptions' => $people->map(fn ($c) => match ($c->source) { 'contact' => $c->group_label ? __('Contact') . ': ' . $c->group_label : null, 'staff' => trim((string) ($positions[$c->source_id] ?? '')) ? __('Staff') . ': ' . trim($positions[$c->source_id]) : null, default => null })->filter()->unique()->sort()->values(),
            'sources' => collect(HolidayContact::SOURCES)->map(fn ($l) => __($l))->all(),
            'autoDefault' => HolidaySettings::autoDefaults()['bundle_id'],
            'can' => ['manage' => $me->can('manage-holiday-contacts')],
        ]);
    }

    public function store(Request $request)
    {
        $this->need('manage-holiday-contacts');
        [$name, $note, $ids] = $this->input($request);
        $b = HolidayBundle::create(['account_id' => (int) $request->user()->account_id, 'name' => $name, 'note' => $note, 'created_by_user_id' => $request->user()->id]);
        $this->sync($b, $ids);
        log_activity('Holiday greeting bundle created', ['name' => $b->name, 'people' => count($ids)], $b, 'Holiday Greetings');

        return back()->with('message', __(':n was created with :p people.', ['n' => $b->name, 'p' => count($ids)]));
    }

    public function update(Request $request, int $bundle)
    {
        $this->need('manage-holiday-contacts');
        $b = HolidayBundle::query()->findOrFail($bundle);
        [$name, $note, $ids] = $this->input($request, $b->id);
        $before = ['name' => $b->name, 'people' => DB::table('holiday_bundle_contacts')->where('bundle_id', $b->id)->count()];
        $b->update(['name' => $name, 'note' => $note]);
        $this->sync($b, $ids);
        log_activity('Holiday greeting bundle changed', ['before' => $before, 'after' => ['name' => $b->name, 'people' => count($ids)]], $b, 'Holiday Greetings');

        return back()->with('message', __('Saved.'));
    }

    public function destroy(Request $request, int $bundle)
    {
        $this->need('manage-holiday-contacts');
        $b = HolidayBundle::query()->findOrFail($bundle);
        $inUse = HolidayDay::query()->whereNull('send_started_at')->get()->filter(fn ($h) => $h->autoPlan()['enabled'] && $h->autoPlan()['bundle_id'] === $b->id)->pluck('name');
        if ($inUse->isNotEmpty()) {
            return back()->withErrors(['bundle' => __(':n is chosen in the schedule of :h. Choose another list there first.', ['n' => $b->name, 'h' => $inUse->take(3)->implode(', ')])]);
        }
        log_activity('Holiday greeting bundle removed', ['name' => $b->name], $b, 'Holiday Greetings');
        DB::table('holiday_bundle_contacts')->where('bundle_id', $b->id)->delete();
        $b->delete();

        return back()->with('message', __(':n was removed. The people stay on the greeting list.', ['n' => $b->name]));
    }

    private function input(Request $request, ?int $ignore = null): array
    {
        $d = $request->validate(['name' => 'required|string|max:120', 'note' => 'nullable|string|max:300', 'ids' => 'nullable|array|max:20000', 'ids.*' => 'integer'],
            ['name.required' => __('Enter a name for the bundle, for example Staff or Clients.')]);
        $name = trim($d['name']);
        $dup = HolidayBundle::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists();
        if ($dup) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => __('A bundle with this name already exists.')]);
        }
        $ids = HolidayContact::query()->whereIn('id', array_map('intval', $d['ids'] ?? []))->pluck('id')->map(fn ($i) => (int) $i)->all();

        return [$name, filled($d['note'] ?? null) ? trim($d['note']) : null, $ids];
    }

    private function sync(HolidayBundle $b, array $ids): void
    {
        DB::transaction(function () use ($b, $ids) {
            DB::table('holiday_bundle_contacts')->where('bundle_id', $b->id)->delete();
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('holiday_bundle_contacts')->insert(array_map(fn ($i) => ['bundle_id' => $b->id, 'contact_id' => $i], $chunk));
            }
        });
    }
}
