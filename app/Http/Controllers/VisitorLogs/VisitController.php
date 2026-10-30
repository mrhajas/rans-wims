<?php

namespace App\Http\Controllers\VisitorLogs;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Travels\Concerns\ManagesFiles;
use App\Http\Controllers\VisitorLogs\Concerns\Guards;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorLogEvent;
use App\Models\VisitorOption;
use App\Models\VisitorVisit;
use App\Services\VisitorLogs\VisitorNotifier;
use App\Services\VisitorLogs\VisitorSettings;
use App\Support\ReferenceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The visit register: check a visitor in (the time is the server's, never the visitor's device), check them out (once), and, for people who are allowed, correct a time
 * or cancel a visit, always with a reason. The check-in time is never overwritten by the check-out; every change is written to the visit's history.
 */
class VisitController extends Controller
{
    use Guards, ManagesFiles;

    private const SORTS = ['check_in_at' => 'check_in_at', 'check_out_at' => 'check_out_at', 'reference' => 'reference', 'status' => 'status', 'host' => 'host_name', 'purpose' => 'purpose'];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function visible()
    {
        return VisitorVisit::query()->visibleTo(auth()->user());
    }

    private function find(int $id): VisitorVisit
    {
        $v = VisitorVisit::query()->findOrFail($id);
        abort_unless($v->canBeSeenBy(auth()->user()), 403);

        return $v;
    }

    private function can($me): array
    {
        return ['create' => $me->can('create-visitor-logs'), 'update' => $me->can('update-visitor-logs'), 'checkout' => $me->can('checkout-visitor-logs'), 'correct' => $me->can('correct-visitor-logs'), 'cancel' => $me->can('cancel-visitor-logs'),
            'history' => $me->can('view-visitor-history'), 'reports' => $me->can('read-visitor-reports'), 'print' => $me->can('print-visitor-reports'), 'visitors' => $me->can('read-visitors'), 'viewAll' => $me->hasRole('Super Admin') || $me->can('view-all-visits')];
    }

    // ---------------------------------------------------------------------------------------------------- the register

    public function index(Request $request)
    {
        $this->need('read-visitor-logs');
        $me = $request->user();
        $f = ['search' => mb_substr(trim((string) $request->query('search', '')), 0, 80), 'status' => in_array($request->query('status'), VisitorVisit::STATUSES, true) ? $request->query('status') : '', 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''),
            'host_id' => ctype_digit((string) $request->query('host_id', '')) ? (string) $request->query('host_id') : '', 'department' => mb_substr((string) $request->query('department', ''), 0, 120), 'purpose' => mb_substr((string) $request->query('purpose', ''), 0, 160), 'warehouse_id' => ctype_digit((string) $request->query('warehouse_id', '')) ? (string) $request->query('warehouse_id') : '',
            'sort' => array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'check_in_at', 'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc'];
        $base = $this->visible()
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('visit_date', '>=', date('Y-m-d', strtotime($f['from']))))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('visit_date', '<=', date('Y-m-d', strtotime($f['to']))))
            ->when($f['warehouse_id'] !== '', fn ($q) => $q->where('warehouse_id', (int) $f['warehouse_id']))->when($f['host_id'] !== '', fn ($q) => $q->where('host_user_id', (int) $f['host_id']))->when($f['department'] !== '', fn ($q) => $q->where('department', $f['department']))->when($f['purpose'] !== '', fn ($q) => $q->where('purpose', $f['purpose']))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $this->like($f['search']))->orWhere('host_name', 'like', $this->like($f['search']))->orWhere('purpose', 'like', $this->like($f['search']))->orWhere('purpose_other', 'like', $this->like($f['search']))
                ->orWhere('appointment_ref', 'like', $this->like($f['search']))->orWhereHas('visitor', fn ($v) => $v->where('full_name', 'like', $this->like($f['search']))->orWhere('company', 'like', $this->like($f['search']))->orWhere('phone', 'like', $this->like($f['search'])))));
        $rows = (clone $base)->with(['visitor:id,full_name,company,phone,reference', 'registeredBy:id,name', 'warehouse:id,name,code'])->orderBy(self::SORTS[$f['sort']], $f['dir'])->orderByDesc('id')->paginate(20)->withQueryString();
        $rows->through(fn ($v) => $this->row($v));
        $counts = $this->visible()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('VisitorLogs/Visits/Index', [
            'rows' => $rows, 'filters' => $f, 'statuses' => VisitorVisit::STATUSES, 'purposes' => VisitorOption::names('purpose', false), 'departments' => VisitorOption::names('department', false), 'warehouses' => VisitorSettings::warehouseChoices($me),
            'hosts' => $this->visible()->whereNotNull('host_user_id')->distinct()->pluck('host_name', 'host_user_id')->map(fn ($n, $id) => ['value' => (string) $id, 'label' => $n])->sortBy('label')->values(),
            'stats' => ['total' => (int) $counts->sum(), 'in' => (int) ($counts[VisitorVisit::IN] ?? 0), 'out' => (int) ($counts[VisitorVisit::OUT] ?? 0), 'cancelled' => (int) ($counts[VisitorVisit::CANCELLED] ?? 0)], 'can' => $this->can($me), 'overstayHours' => VisitorSettings::overstayHours(),
        ]);
    }

    private function row(VisitorVisit $v): array
    {
        return ['id' => $v->id, 'reference' => $v->reference, 'status' => $v->status, 'visitor_id' => $v->visitor_id, 'visitor' => optional($v->visitor)->full_name, 'company' => optional($v->visitor)->company, 'phone' => optional($v->visitor)->phone,
            'host' => $v->host_name, 'department' => $v->department, 'purpose' => $v->purpose_other ? $v->purpose . ': ' . $v->purpose_other : $v->purpose, 'check_in_at' => $v->check_in_at->toIso8601String(), 'check_out_at' => optional($v->check_out_at)->toIso8601String(),
            'minutes' => $v->check_out_at ? (int) $v->duration_minutes : ($v->status === VisitorVisit::IN ? $v->minutesOnPremises() : null), 'duration' => $v->check_out_at ? VisitorVisit::durationText((int) $v->duration_minutes) : ($v->status === VisitorVisit::IN ? VisitorVisit::durationText($v->minutesOnPremises()) : null),
            'registered_by' => optional($v->registeredBy)->name, 'companions' => (int) $v->companions, 'warehouse_id' => $v->warehouse_id, 'project' => optional($v->warehouse)->name];
    }

    // ---------------------------------------------------------------------------------------------------- new visit

    public function create(Request $request)
    {
        $this->need('create-visitor-logs');
        $me = $request->user();
        $visitor = null;
        if (ctype_digit((string) $request->query('visitor_id', ''))) {
            $visitor = Visitor::query()->where('is_active', true)->find((int) $request->query('visitor_id'));
        }

        return Inertia::render('VisitorLogs/Visits/Form', [
            'idPass' => \App\Services\IdPass\PassAssignments::formProps($me), 'visit' => null, 'visitor' => $visitor ? $visitor->summary($me, $me->can('view-visitor-history')) : null, 'purposes' => VisitorOption::listOf('purpose'), 'departments' => VisitorOption::names('department'), 'categories' => VisitorOption::names('visitor_category'),
            'now' => now()->toIso8601String(), 'timezone' => config('app.timezone'), 'fields' => VisitorSettings::fields(), 'requirePhone' => VisitorSettings::requirePhone(), 'canCorrectTime' => $me->can('correct-visitor-logs'),
            'warehouses' => $wh = VisitorSettings::warehouseChoices($me), 'defaultWarehouse' => VisitorSettings::defaultWarehouseId($wh),
            'can' => ['createVisitor' => $me->can('create-visitors'), 'updateVisitor' => $me->can('update-visitors'), 'duplicates' => $me->can('create-duplicate-visitors'), 'sensitive' => $me->can('view-visitor-sensitive'), 'history' => $me->can('view-visitor-history'), 'visitors' => $me->can('read-visitors')],
        ]);
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'visitor_id' => 'required|integer', 'host_user_id' => 'required|integer', 'purpose' => 'required|string|max:120', 'purpose_other' => 'nullable|string|max:1000', 'department' => 'nullable|string|max:120', 'warehouse_id' => 'nullable|integer', 'appointment_ref' => 'nullable|string|max:80',
            'companions' => 'nullable|integer|min:0|max:500', 'vehicle_registration' => 'nullable|string|max:40', 'remarks' => 'nullable|string|max:2000',
        ], ['visitor_id.required' => __('Choose the visitor, or add a new one.'), 'host_user_id.required' => __('Choose the person being visited.'), 'purpose.required' => __('Choose the purpose of the visit.')]);
    }

    /** Checks the choices against the live lists and returns the fields to save (the host's name and the purpose are kept as they are on the day). */
    private function resolve(array $d, ?VisitorVisit $visit = null): array
    {
        $visitor = Visitor::query()->find((int) $d['visitor_id']);
        if (! $visitor || (! $visitor->is_active && (! $visit || (int) $visit->visitor_id !== $visitor->id))) {
            throw ValidationException::withMessages(['visitor_id' => __('Choose an active visitor from the directory.')]);
        }
        $host = User::query()->find((int) $d['host_user_id']);
        $allowed = $host && (VisitorSettings::peopleQuery()->whereKey($host->id)->exists() || ($visit && (int) $visit->host_user_id === $host->id));
        if (! $allowed) {
            throw ValidationException::withMessages(['host_user_id' => __('Choose the person being visited from the list.')]);
        }
        $new = [];
        $purposes = collect(VisitorOption::listOf('purpose'));
        $purpose = trim(preg_replace('/\s+/', ' ', $d['purpose']));
        $known = $purposes->first(fn ($p) => mb_strtolower($p['name']) === mb_strtolower($purpose));
        $purpose = $known['name'] ?? $purpose;
        if (! $known && ! ($visit && $visit->purpose === $purpose)) {
            $new[] = ['purpose', $purpose]; // a purpose typed by the receptionist: added to the list when the visit is saved
        }
        $other = ($known['flag'] ?? VisitorOption::flagOf('purpose', $purpose)) === 'other';
        if ($other && trim((string) ($d['purpose_other'] ?? '')) === '') {
            throw ValidationException::withMessages(['purpose_other' => __('Describe the purpose of the visit.')]);
        }
        $dept = trim(preg_replace('/\s+/', ' ', (string) ($d['department'] ?? '')));
        $existing = collect(VisitorOption::names('department', false))->first(fn ($n) => mb_strtolower($n) === mb_strtolower($dept));
        if ($dept !== '' && ! $existing) {
            $new[] = ['department', $dept];
        } elseif ($existing) {
            $dept = $existing;
        }
        // "Attended from": one of the projects the person works in (they must choose when they have several and no default applies)
        $choices = VisitorSettings::warehouseChoices(auth()->user());
        $wid = ! empty($d['warehouse_id']) ? (int) $d['warehouse_id'] : null;
        if ($wid && ! collect($choices)->contains('id', $wid) && ! ($visit && (int) $visit->warehouse_id === $wid)) {
            throw ValidationException::withMessages(['warehouse_id' => __('Choose a project you work in.')]);
        }
        if (! $wid && $choices) {
            throw ValidationException::withMessages(['warehouse_id' => __('Choose the project this visit is attended from.')]);
        }

        return ['visitor_id' => $visitor->id, 'host_user_id' => $host->id, 'host_name' => $host->name, 'purpose' => $purpose, 'purpose_other' => $other ? trim((string) $d['purpose_other']) : null, 'department' => $dept ?: null,
            'appointment_ref' => trim((string) ($d['appointment_ref'] ?? '')) ?: null, 'companions' => (int) ($d['companions'] ?? 0), 'vehicle_registration' => trim((string) ($d['vehicle_registration'] ?? '')) ?: null, 'remarks' => trim((string) ($d['remarks'] ?? '')) ?: null,
            'warehouse_id' => $wid, '_new' => $new];
    }

    /** A purpose or department the receptionist typed is added to the lists now that the visit is going to be saved. */
    private function addNewEntries(array &$fields): void
    {
        foreach ((array) ($fields['_new'] ?? []) as [$kind, $name]) {
            $fields[$kind] = VisitorOption::ensure($kind, $name);
        }
        unset($fields['_new']);
    }

    public function store(Request $request)
    {
        $this->need('create-visitor-logs');
        $me = $request->user();
        $d = $this->rules($request);
        $fields = $this->resolve($d);
        // the time is the server's. Only a person who may correct times can set an earlier check-in, and then a reason is required and recorded
        $at = now();
        $reason = null;
        if ($request->filled('check_in_at') && $me->can('correct-visitor-logs')) {
            $given = $this->parseMoment($request->input('check_in_at'));
            if (! $given || $given->gt(now()->addMinute())) {
                throw ValidationException::withMessages(['check_in_at' => __('The check-in time must be a valid date and time that is not in the future.')]);
            }
            $reason = trim((string) $request->input('time_reason', ''));
            if ($reason === '') {
                throw ValidationException::withMessages(['time_reason' => __('Give the reason for setting the check-in time yourself.')]);
            }
            $at = $given;
        }
        $open = VisitorVisit::query()->where('visitor_id', $fields['visitor_id'])->where('status', VisitorVisit::IN)->first();
        if ($open) {
            throw ValidationException::withMessages(['visitor_id' => __('This visitor is already checked in (:r, since :t). Check them out first.', ['r' => $open->reference, 't' => $open->check_in_at->format('d M Y, H:i')])]);
        }
        $passId = \App\Services\IdPass\PassAssignments::enabledFor($me) ? (int) $request->input('id_pass_id', 0) : 0;
        if ($passId && ! \App\Services\IdPass\PassAssignments::free(500)->contains('id', $passId)) {
            throw ValidationException::withMessages(['id_pass_id' => __('That visitor pass is not free. Choose another one.')]);
        }
        $this->addNewEntries($fields);
        $visit = ReferenceLock::run('visitor_visits', fn () => VisitorVisit::create($fields + ['reference' => VisitorVisit::nextReference(), 'status' => VisitorVisit::IN, 'check_in_at' => $at, 'visit_date' => $at->toDateString(), 'registered_by_user_id' => $me->id]));
        if ($passId) {
            try {
                $given = \App\Services\IdPass\PassAssignments::assignToVisit($visit, $passId, $me);
            } catch (\Throwable $e) {
                \Log::warning('Visitor pass not given at check-in', ['visit' => $visit->id, 'error' => $e->getMessage()]);
            }
        }
        $files = $this->saveFiles($visit, $request, 'visitor-visits');
        VisitorLogEvent::record('visit', $visit, 'checked_in', [], ['check_in_at' => $at->toDateTimeString(), 'host' => $visit->host_name, 'purpose' => $visit->purpose, 'time_set_by_hand' => $reason !== null], $reason);
        log_activity('Visitor checked in: ' . $visit->reference, ['visitor_id' => $visit->visitor_id, 'host' => $visit->host_name, 'purpose' => $visit->purpose, 'check_in_at' => $at->toDateTimeString(), 'time_set_by_hand' => $reason !== null, 'files' => $files], $visit, 'Visitor Logs');
        VisitorNotifier::visit('visitor_checked_in', $visit, $me);

        return redirect()->route('visitor-logs.visits.show', $visit->id)->with('message', __(':v checked in.', ['v' => optional($visit->visitor)->full_name ?: $visit->reference]));
    }

    // ---------------------------------------------------------------------------------------------------- one visit

    public function show(Request $request, int $visit)
    {
        $v = $this->find($visit);
        $me = $request->user();
        $v->load(['visitor', 'registeredBy:id,name', 'checkedOutBy:id,name', 'items:id,visit_id,reference,item_type,status,is_confidential']);
        $events = VisitorLogEvent::query()->where('subject_type', 'visit')->where('subject_id', $v->id)->with('user:id,name')->orderBy('id')->get()
            ->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'by' => optional($e->user)->name, 'at' => $e->created_at->toIso8601String(), 'reason' => $e->reason, 'old' => $e->old_value, 'new' => $e->new_value]);
        $visitor = $v->visitor;
        $card = $visitor ? $visitor->summary($me, $me->can('view-visitor-history')) : null;
        $data = $this->row($v) + ['visit_date' => $v->visit_date->toDateString(), 'purpose_other' => $v->purpose_other, 'purpose_name' => $v->purpose, 'appointment_ref' => $v->appointment_ref, 'vehicle' => $v->vehicle_registration, 'remarks' => $v->remarks,
            'host_user_id' => $v->host_user_id, 'checked_out_by' => optional($v->checkedOutBy)->name, 'cancelled_at' => optional($v->cancelled_at)->toIso8601String(), 'cancel_reason' => $v->cancel_reason,
            'items' => $me->can('read-incoming-items') ? $v->items->map(fn ($i) => ['id' => $i->id, 'reference' => $i->reference, 'type' => $i->item_type, 'status' => $i->status])->values() : []];

        $idPass = \App\Services\IdPass\PassAssignments::forVisit($v->id, $me);
        $idPassForm = ! $idPass && $v->status === VisitorVisit::IN ? \App\Services\IdPass\PassAssignments::formProps($me) : ['enabled' => false, 'free' => []];

        return Inertia::render('VisitorLogs/Visits/Show', ['idPass' => $idPass, 'idPassForm' => $idPassForm, 'visit' => $data, 'visitor' => $card, 'events' => $events, 'files' => $this->filesOf($v), 'can' => $this->can($me) + ['visitorPage' => $me->can('read-visitors'), 'itemCreate' => $me->can('create-incoming-items')], 'overstayHours' => VisitorSettings::overstayHours(),
            'purposes' => VisitorOption::listOf('purpose'), 'departments' => VisitorOption::names('department')]);
    }

    public function edit(Request $request, int $visit)
    {
        $this->need('update-visitor-logs');
        $v = $this->find($visit);
        abort_if($v->status === VisitorVisit::CANCELLED, 422, __('A cancelled visit cannot be changed.'));
        $me = $request->user();

        return Inertia::render('VisitorLogs/Visits/Form', [
            'visit' => ['id' => $v->id, 'reference' => $v->reference, 'visitor_id' => $v->visitor_id, 'host_user_id' => $v->host_user_id, 'host_name' => $v->host_name, 'purpose' => $v->purpose, 'purpose_other' => $v->purpose_other, 'department' => $v->department, 'appointment_ref' => $v->appointment_ref,
                'companions' => (int) $v->companions, 'vehicle_registration' => $v->vehicle_registration, 'remarks' => $v->remarks, 'warehouse_id' => $v->warehouse_id, 'check_in_at' => $v->check_in_at->toIso8601String()],
            'visitor' => optional($v->visitor)->summary($me, false), 'purposes' => VisitorOption::listOf('purpose'), 'departments' => VisitorOption::names('department'), 'categories' => VisitorOption::names('visitor_category'),
            'now' => now()->toIso8601String(), 'timezone' => config('app.timezone'), 'fields' => VisitorSettings::fields(), 'requirePhone' => VisitorSettings::requirePhone(), 'canCorrectTime' => false,
            'warehouses' => $wh = VisitorSettings::warehouseChoices($me), 'defaultWarehouse' => $v->warehouse_id,
            'can' => ['createVisitor' => false, 'updateVisitor' => false, 'duplicates' => false, 'sensitive' => false, 'history' => false, 'visitors' => false],
        ]);
    }

    public function update(Request $request, int $visit)
    {
        $this->need('update-visitor-logs');
        $v = $this->find($visit);
        abort_if($v->status === VisitorVisit::CANCELLED, 422, __('A cancelled visit cannot be changed.'));
        $d = $this->rules($request);
        $fields = $this->resolve($d, $v);
        if ((int) $fields['visitor_id'] !== (int) $v->visitor_id) {
            throw ValidationException::withMessages(['visitor_id' => __('The visitor of a visit cannot be changed. Cancel it and register a new visit.')]);
        }
        $this->addNewEntries($fields);
        $v->fill($fields);
        $changes = $v->getDirty();
        $old = collect($changes)->map(fn ($x, $k) => $v->getOriginal($k))->all();
        $v->save();
        if ($changes) {
            VisitorLogEvent::record('visit', $v, 'edited', $old, $changes);
            log_activity('Visit ' . $v->reference . ' changed', ['changed' => array_keys($changes), 'before' => $old, 'after' => $changes], $v, 'Visitor Logs');
        }

        return redirect()->route('visitor-logs.visits.show', $v->id)->with('message', __('Visit saved.'));
    }

    // ---------------------------------------------------------------------------------------------------- check out, correct, cancel

    public function checkout(Request $request, int $visit)
    {
        $this->need('checkout-visitor-logs');
        $found = $this->find($visit);
        $result = DB::transaction(function () use ($found, $request) {
            $v = VisitorVisit::query()->whereKey($found->id)->lockForUpdate()->first();
            if ($v->status !== VisitorVisit::IN) {
                return ['error' => $v->status === VisitorVisit::OUT ? __('This visitor was already checked out at :t.', ['t' => $v->check_out_at->format('d M Y, H:i')]) : __('This visit is cancelled, so it cannot be checked out.')];
            }
            $out = now();
            $v->update(['status' => VisitorVisit::OUT, 'check_out_at' => $out, 'duration_minutes' => max(0, (int) $v->check_in_at->diffInMinutes($out)), 'checked_out_by_user_id' => $request->user()->id]);
            VisitorLogEvent::record('visit', $v, 'checked_out', ['status' => VisitorVisit::IN], ['status' => VisitorVisit::OUT, 'check_out_at' => $out->toDateTimeString(), 'minutes' => $v->duration_minutes], mb_substr((string) $request->input('remarks', ''), 0, 500) ?: null);

            return ['visit' => $v];
        });
        if (isset($result['error'])) {
            return $request->wantsJson() ? response()->json(['message' => $result['error']], 409) : back()->withErrors(['checkout' => $result['error']]);
        }
        $v = $result['visit'];
        \App\Services\IdPass\PassAssignments::releaseVisit($v, $request->user(), __('Checked out'));
        log_activity('Visitor checked out: ' . $v->reference, ['check_in_at' => $v->check_in_at->toDateTimeString(), 'check_out_at' => $v->check_out_at->toDateTimeString(), 'minutes' => $v->duration_minutes], $v, 'Visitor Logs');
        VisitorNotifier::visit('visitor_checked_out', $v, $request->user());
        $msg = __(':v checked out. Time on premises: :d.', ['v' => optional($v->visitor)->full_name ?: $v->reference, 'd' => VisitorVisit::durationText((int) $v->duration_minutes)]);

        return $request->wantsJson() ? response()->json(['message' => $msg, 'visit' => $this->row($v->load('visitor'))]) : back()->with('message', $msg);
    }

    public function correct(Request $request, int $visit)
    {
        $this->need('correct-visitor-logs');
        $v = $this->find($visit);
        abort_if($v->status === VisitorVisit::CANCELLED, 422, __('A cancelled visit cannot be corrected.'));
        $d = $request->validate(['check_in_at' => 'required|string', 'check_out_at' => 'nullable|string', 'reason' => 'required|string|max:1000'], ['reason.required' => __('Give the reason for the correction.'), 'check_in_at.required' => __('Give the check-in time.')]);
        $in = $this->parseMoment($d['check_in_at']);
        $out = filled($d['check_out_at'] ?? null) ? $this->parseMoment($d['check_out_at']) : null;
        if (! $in || $in->gt(now()->addMinute())) {
            throw ValidationException::withMessages(['check_in_at' => __('The check-in time must be a valid date and time that is not in the future.')]);
        }
        if (filled($d['check_out_at'] ?? null) && (! $out || $out->gt(now()->addMinute()))) {
            throw ValidationException::withMessages(['check_out_at' => __('The check-out time must be a valid date and time that is not in the future.')]);
        }
        if ($v->status === VisitorVisit::OUT && ! $out) {
            throw ValidationException::withMessages(['check_out_at' => __('This visitor was checked out, so a check-out time is needed. Cancel the visit instead if it should not exist.')]);
        }
        if ($out && $out->lt($in)) {
            throw ValidationException::withMessages(['check_out_at' => __('The check-out time cannot be before the check-in time.')]);
        }
        $old = ['check_in_at' => $v->check_in_at->toDateTimeString(), 'check_out_at' => optional($v->check_out_at)->toDateTimeString(), 'status' => $v->status];
        $upd = ['check_in_at' => $in, 'visit_date' => $in->toDateString()];
        if ($out) {
            $upd += ['check_out_at' => $out, 'status' => VisitorVisit::OUT, 'duration_minutes' => max(0, (int) $in->diffInMinutes($out)), 'checked_out_by_user_id' => $v->checked_out_by_user_id ?: $request->user()->id, 'long_stay_alerted_at' => null];
        }
        $v->update($upd);
        if (($upd['status'] ?? null) === VisitorVisit::OUT) {
            \App\Services\IdPass\PassAssignments::releaseVisit($v, $request->user(), __('Check-out corrected'));
        }
        $new = ['check_in_at' => $in->toDateTimeString(), 'check_out_at' => optional($out)->toDateTimeString(), 'status' => $v->status];
        VisitorLogEvent::record('visit', $v, 'corrected', $old, $new, $d['reason']);
        log_activity('Visit ' . $v->reference . ' times corrected', ['before' => $old, 'after' => $new, 'reason' => $d['reason']], $v, 'Visitor Logs');

        return back()->with('message', __('The times were corrected and the correction was recorded.'));
    }

    public function cancel(Request $request, int $visit)
    {
        $this->need('cancel-visitor-logs');
        $v = $this->find($visit);
        $d = $request->validate(['reason' => 'required|string|max:1000'], ['reason.required' => __('Give the reason for cancelling this visit.')]);
        abort_if($v->status === VisitorVisit::CANCELLED, 422, __('This visit is already cancelled.'));
        $old = ['status' => $v->status];
        $v->update(['status' => VisitorVisit::CANCELLED, 'cancelled_by_user_id' => $request->user()->id, 'cancelled_at' => now(), 'cancel_reason' => $d['reason']]);
        \App\Services\IdPass\PassAssignments::releaseVisit($v, $request->user(), __('Visit cancelled'));
        VisitorLogEvent::record('visit', $v, 'cancelled', $old, ['status' => VisitorVisit::CANCELLED], $d['reason']);
        log_activity('Visit ' . $v->reference . ' cancelled', ['reason' => $d['reason'], 'was' => $old['status']], $v, 'Visitor Logs');

        return back()->with('message', __('The visit was cancelled. It stays in the register and the history.'));
    }

    // ---------------------------------------------------------------------------------------------------- files

    public function attach(Request $request, int $visit)
    {
        $this->needAny(['update-visitor-logs', 'create-visitor-logs']);
        $v = $this->find($visit);
        abort_if($v->status === VisitorVisit::CANCELLED, 422);
        $request->validate($this->fileRules());
        $n = $this->saveFiles($v, $request, 'visitor-visits');
        VisitorLogEvent::record('visit', $v, 'file_added', [], ['files' => $n]);
        log_activity('Files added to visit ' . $v->reference, ['files' => $n], $v, 'Visitor Logs');

        return back()->with('message', __(':n file(s) added.', ['n' => $n]));
    }

    public function download(Request $request, int $visit, int $file)
    {
        $v = $this->find($visit);
        $a = $this->fileOf($v, $file);
        log_activity('File of visit ' . $v->reference . ' opened', ['file' => $a->title], $v, 'Visitor Logs');

        return $this->sendFile($a, $request->boolean('inline'));
    }
}
