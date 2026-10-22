<?php

namespace App\Http\Controllers\VisitorLogs;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VisitorLogs\Concerns\Guards;
use App\Models\Visitor;
use App\Models\VisitorLogEvent;
use App\Models\VisitorOption;
use App\Models\VisitorVisit;
use App\Services\VisitorLogs\VisitorSettings;
use App\Support\ReferenceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The Visitor Directory: the reusable record of each visitor, so a returning visitor is chosen instead of typed again. Identification, nationality, address and emergency
 * notes are sensitive: only people with the sensitive right see or change them, and the identification number is encrypted in the database.
 */
class VisitorController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->need('read-visitors');
        $me = $request->user();
        $f = ['search' => mb_substr(trim((string) $request->query('search', '')), 0, 80), 'category' => (string) $request->query('category', ''), 'state' => in_array($request->query('state'), ['active', 'inactive'], true) ? $request->query('state') : ''];
        $rows = Visitor::query()
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'like', $this->like($f['search']))->orWhere('company', 'like', $this->like($f['search']))->orWhere('phone', 'like', $this->like($f['search']))
                ->orWhere('reference', 'like', $this->like($f['search']))->orWhere('email', 'like', $this->like($f['search']))->when(Visitor::phoneKey($f['search']), fn ($x, $k) => $x->orWhere('phone_key', $k))))
            ->when($f['category'] !== '', fn ($q) => $q->where('category', $f['category']))
            ->when($f['state'] === 'active', fn ($q) => $q->where('is_active', true))->when($f['state'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('full_name')->paginate(20)->withQueryString();
        $history = $me->can('view-visitor-history');
        $counts = $history ? VisitorVisit::query()->whereIn('visitor_id', $rows->pluck('id'))->whereIn('status', [VisitorVisit::IN, VisitorVisit::OUT])->selectRaw('visitor_id, COUNT(*) n, MAX(check_in_at) last')->groupBy('visitor_id')->get()->keyBy('visitor_id') : collect();
        $rows->through(fn ($v) => $v->summary($me) + ['visits' => $history ? (int) ($counts[$v->id]->n ?? 0) : null, 'last_visit_at' => $history && isset($counts[$v->id]) ? \Carbon\Carbon::parse($counts[$v->id]->last)->toIso8601String() : null, 'created_at' => optional($v->created_at)->toIso8601String()]);
        $all = Visitor::query()->selectRaw('COUNT(*) total, SUM(is_active) active')->first();

        return Inertia::render('VisitorLogs/Visitors/Index', [
            'rows' => $rows, 'filters' => $f, 'categories' => VisitorOption::names('visitor_category'), 'fields' => VisitorSettings::fields(), 'stats' => ['total' => (int) $all->total, 'active' => (int) $all->active, 'inactive' => (int) $all->total - (int) $all->active],
            'can' => $this->can($me), 'requirePhone' => VisitorSettings::requirePhone(),
        ]);
    }

    private function can($me): array
    {
        return ['create' => $me->can('create-visitors'), 'update' => $me->can('update-visitors'), 'deactivate' => $me->can('deactivate-visitors'), 'sensitive' => $me->can('view-visitor-sensitive'), 'history' => $me->can('view-visitor-history'),
            'duplicates' => $me->can('create-duplicate-visitors'), 'newVisit' => $me->can('create-visitor-logs')];
    }

    public function show(Request $request, Visitor $visitor)
    {
        $this->need('read-visitors');
        $me = $request->user();
        $can = $this->can($me);
        $data = $visitor->summary($me, $can['history']) + ['remarks' => $visitor->remarks, 'created_by' => optional($visitor->createdBy)->name, 'created_at' => optional($visitor->created_at)->toIso8601String(),
            'deactivated_at' => optional($visitor->deactivated_at)->toIso8601String()];
        if ($can['sensitive']) {
            $data += ['id_type' => $visitor->id_type, 'id_number' => $visitor->id_number, 'nationality' => $visitor->nationality, 'address' => $visitor->address, 'emergency_notes' => $visitor->emergency_notes];
            log_activity('Visitor sensitive details viewed', ['visitor' => $visitor->reference], $visitor, 'Visitor Logs');
        }
        $visits = [];
        if ($can['history']) {
            $visits = VisitorVisit::query()->where('visitor_id', $visitor->id)->visibleTo($me)->with('host:id,name')->orderByDesc('check_in_at')->limit(30)->get()
                ->map(fn ($v) => ['id' => $v->id, 'reference' => $v->reference, 'status' => $v->status, 'check_in_at' => $v->check_in_at->toIso8601String(), 'check_out_at' => optional($v->check_out_at)->toIso8601String(), 'duration' => $v->check_out_at ? VisitorVisit::durationText((int) $v->duration_minutes) : null,
                    'host' => $v->host_name ?: optional($v->host)->name, 'purpose' => $v->purpose_other ?: $v->purpose, 'can_open' => $me->can('read-visitor-logs') || (int) $v->host_user_id === $me->id]);
        }

        return Inertia::render('VisitorLogs/Visitors/Show', ['visitor' => $data, 'visits' => $visits, 'fields' => VisitorSettings::fields(), 'categories' => VisitorOption::names('visitor_category'), 'can' => $can, 'requirePhone' => VisitorSettings::requirePhone()]);
    }

    /** The fields a person may fill in: the optional fields in use, and the sensitive ones only for people who may see them. */
    private function validated(Request $request, ?Visitor $visitor): array
    {
        $me = $request->user();
        $d = $request->validate([
            'full_name' => 'required|string|max:190', 'company' => 'nullable|string|max:190', 'phone' => [VisitorSettings::requirePhone() ? 'required' : 'nullable', 'string', 'max:40'], 'email' => 'nullable|email|max:190',
            'category' => 'nullable|string|max:80', 'remarks' => 'nullable|string|max:2000', 'vehicle_registration' => 'nullable|string|max:40', 'id_type' => 'nullable|string|max:60', 'id_number' => 'nullable|string|max:80',
            'nationality' => 'nullable|string|max:100', 'address' => 'nullable|string|max:500', 'emergency_notes' => 'nullable|string|max:1000',
        ], ['full_name.required' => __('Type the visitor\'s full name.'), 'phone.required' => __('Type the visitor\'s phone number.'), 'email.email' => __('That email address is not valid.')]);
        $out = ['full_name' => trim($d['full_name']), 'company' => trim((string) ($d['company'] ?? '')) ?: null, 'email' => trim((string) ($d['email'] ?? '')) ?: null, 'category' => trim((string) ($d['category'] ?? '')) ?: null, 'remarks' => trim((string) ($d['remarks'] ?? '')) ?: null];
        $out['phone'] = Visitor::cleanPhone($d['phone'] ?? null);
        $out['phone_key'] = Visitor::phoneKey($out['phone']);
        foreach (VisitorSettings::fields() as $field) {
            $sensitive = in_array($field, Visitor::SENSITIVE, true);
            if ($sensitive && ! $me->can('view-visitor-sensitive')) {
                continue;
            }
            $out[$field] = trim((string) ($d[$field] ?? '')) ?: null;
        }

        return $out;
    }

    /** Works for the page and for the "+ Add New Visitor" window of the visit form (JSON): 201 with the saved visitor, or 409 with the people who look the same. */
    public function store(Request $request)
    {
        $this->need('create-visitors');
        $d = $this->validated($request, null);
        $matches = Visitor::likelyMatches($d['full_name'], $d['company'] ?? null, $d['phone'] ?? null);
        if ($matches->isNotEmpty() && ! ($request->boolean('force') && $request->user()->can('create-duplicate-visitors'))) {
            $payload = ['message' => __('This looks like a visitor who is already in the directory.'), 'duplicates' => $matches->map(fn ($m) => $m->summary($request->user(), $request->user()->can('view-visitor-history')))->values(), 'can_force' => $request->user()->can('create-duplicate-visitors')];

            return $request->wantsJson() ? response()->json($payload, 409) : back()->withErrors(['full_name' => $payload['message']]);
        }
        $v = ReferenceLock::run('visitors', fn () => Visitor::create($d + ['reference' => Visitor::nextReference(), 'is_active' => true, 'created_by_user_id' => $request->user()->id]));
        \App\Services\VisitorLogs\VisitorContacts::link($v);
        VisitorLogEvent::record('visitor', $v, 'created', [], ['name' => $v->full_name, 'company' => $v->company, 'duplicate_override' => $matches->isNotEmpty()]);
        log_activity('Visitor ' . $v->reference . ' added', ['name' => $v->full_name, 'company' => $v->company, 'phone' => $v->phone, 'duplicate_override' => $matches->isNotEmpty()], $v, 'Visitor Logs');

        return $request->wantsJson() ? response()->json(['visitor' => $v->summary($request->user(), false)], 201) : redirect()->route('visitor-logs.visitors.show', $v->id)->with('message', __(':n added to the directory.', ['n' => $v->full_name]));
    }

    public function update(Request $request, Visitor $visitor)
    {
        $this->need('update-visitors');
        $d = $this->validated($request, $visitor);
        $visitor->fill($d);
        $changes = collect($visitor->getDirty())->except(['phone_key'])->all();
        $old = collect($changes)->map(fn ($v, $k) => $k === 'id_number' ? '***' : $visitor->getOriginal($k))->all();
        $new = collect($changes)->map(fn ($v, $k) => $k === 'id_number' ? '***' : $v)->all();
        $visitor->save();
        \App\Services\VisitorLogs\VisitorContacts::follow($visitor);
        if ($changes) {
            VisitorLogEvent::record('visitor', $visitor, 'updated', $old, $new);
            log_activity('Visitor ' . $visitor->reference . ' updated', ['changed' => array_keys($changes)], $visitor, 'Visitor Logs');
        }

        return $request->wantsJson() ? response()->json(['visitor' => $visitor->fresh()->summary($request->user(), false)]) : back()->with('message', __('Visitor saved.'));
    }

    public function toggle(Request $request, Visitor $visitor)
    {
        $this->need('deactivate-visitors');
        $on = ! $visitor->is_active;
        $visitor->update(['is_active' => $on, 'deactivated_by_user_id' => $on ? null : $request->user()->id, 'deactivated_at' => $on ? null : now()]);
        VisitorLogEvent::record('visitor', $visitor, $on ? 'reactivated' : 'deactivated', ['is_active' => ! $on], ['is_active' => $on], mb_substr((string) $request->input('reason', ''), 0, 500) ?: null);
        log_activity('Visitor ' . $visitor->reference . ($on ? ' reactivated' : ' deactivated'), ['name' => $visitor->full_name], $visitor, 'Visitor Logs');

        return back()->with('message', $on ? __('Visitor reactivated.') : __('Visitor deactivated. They stay in the history but cannot be chosen for new visits.'));
    }
}
