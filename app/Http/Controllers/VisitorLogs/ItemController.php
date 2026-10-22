<?php

namespace App\Http\Controllers\VisitorLogs;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Travels\Concerns\ManagesFiles;
use App\Http\Controllers\VisitorLogs\Concerns\Guards;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorIncomingItem;
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
 * Incoming Mail & Deliveries: letters, documents, packages and parcels received at reception, for one person. A delivery needs no visit. The status moves through the
 * Super Admin's list; handing an item over is its own act (who got it, when, who released it), never a side effect of a notification. Every change is kept in the item's history.
 */
class ItemController extends Controller
{
    use Guards, ManagesFiles;

    private const SORTS = ['received_at' => 'received_at', 'reference' => 'reference', 'status' => 'status', 'status_changed_at' => 'status_changed_at', 'recipient' => 'recipient_name', 'sender' => 'sender_name'];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function find(int $id): VisitorIncomingItem
    {
        $i = VisitorIncomingItem::query()->findOrFail($id);
        abort_unless($i->canBeSeenBy(auth()->user()), 403);

        return $i;
    }

    private function scoped()
    {
        return VisitorIncomingItem::query()->visibleTo(auth()->user());
    }

    private function can($me): array
    {
        return ['create' => $me->can('create-incoming-items'), 'update' => $me->can('update-incoming-items'), 'status' => $me->can('update-incoming-item-status'), 'handover' => $me->can('handover-incoming-items'), 'correct' => $me->can('correct-incoming-items'),
            'confidential' => $me->can('view-confidential-incoming-items'), 'files' => $me->can('attach-incoming-items'), 'history' => $me->can('view-incoming-item-history'), 'reports' => $me->can('read-visitor-reports'), 'print' => $me->can('print-visitor-reports')];
    }

    /** One row for lists: the sender and the subject of a confidential item are hidden from people who may not see them. */
    private function row(VisitorIncomingItem $i, User $me): array
    {
        $see = $i->canSeeDetails($me);

        return ['id' => $i->id, 'reference' => $i->reference, 'received_at' => $i->received_at->toIso8601String(), 'type' => $i->item_type, 'status' => $i->status, 'flag' => $i->status_flag, 'confidential' => (bool) $i->is_confidential, 'masked' => ! $see,
            'sender' => $see ? trim(($i->sender_name ?: '') . ($i->sender_company ? ' (' . $i->sender_company . ')' : '')) : null, 'subject' => $see ? $i->subject : null, 'courier' => $see ? $i->courier : null, 'recipient' => $i->recipient_name, 'department' => $i->department,
            'received_by' => optional($i->receivedBy)->name, 'status_changed_at' => optional($i->status_changed_at)->toIso8601String(), 'count' => (int) $i->item_count, 'open' => $i->isOpen(),
            'waiting_days' => $i->isOpen() ? (int) $i->received_at->diffInDays(now()) : null, 'warehouse_id' => $i->warehouse_id, 'project' => optional($i->warehouse)->name];
    }

    // ---------------------------------------------------------------------------------------------------- the register

    public function index(Request $request)
    {
        $this->need('read-incoming-items');
        $me = $request->user();
        $f = ['search' => mb_substr(trim((string) $request->query('search', '')), 0, 80), 'status' => mb_substr((string) $request->query('status', ''), 0, 80), 'type' => mb_substr((string) $request->query('type', ''), 0, 80), 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''),
            'recipient_id' => ctype_digit((string) $request->query('recipient_id', '')) ? (string) $request->query('recipient_id') : '', 'warehouse_id' => ctype_digit((string) $request->query('warehouse_id', '')) ? (string) $request->query('warehouse_id') : '', 'when' => in_array($request->query('when'), ['open', 'closed', 'overdue'], true) ? $request->query('when') : '',
            'sort' => array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'received_at', 'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc'];
        $days = VisitorSettings::collectionDays();
        $base = $this->scoped()
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))->when($f['type'] !== '', fn ($q) => $q->where('item_type', $f['type']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('received_at', '>=', date('Y-m-d', strtotime($f['from']))))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('received_at', '<=', date('Y-m-d', strtotime($f['to']))))
            ->when($f['recipient_id'] !== '', fn ($q) => $q->where('recipient_user_id', (int) $f['recipient_id']))->when($f['warehouse_id'] !== '', fn ($q) => $q->where('warehouse_id', (int) $f['warehouse_id']))
            ->when($f['when'] === 'open', fn ($q) => $q->open())->when($f['when'] === 'closed', fn ($q) => $q->whereIn('status_flag', ['handed', 'closed']))
            ->when($f['when'] === 'overdue' && $days > 0, fn ($q) => $q->open()->where('received_at', '<', now()->subDays($days)))
            ->when($f['search'] !== '', fn ($q) => $q->where(function ($w) use ($f, $me) {
                $w->where('reference', 'like', $this->like($f['search']))->orWhere('recipient_name', 'like', $this->like($f['search']))->orWhere('tracking_number', 'like', $this->like($f['search']));
                // sender and subject are searched only where the person may read them (never leak a confidential item through search)
                $see = fn ($x) => $x->where(fn ($y) => $y->where('sender_name', 'like', $this->like($f['search']))->orWhere('sender_company', 'like', $this->like($f['search']))->orWhere('subject', 'like', $this->like($f['search']))->orWhere('courier', 'like', $this->like($f['search'])));
                $w->orWhere(fn ($x) => $me->hasRole('Super Admin') || $me->can('view-confidential-incoming-items') ? $see($x) : $see($x)->where(fn ($c) => $c->where('is_confidential', false)->orWhere('recipient_user_id', $me->id)->orWhere('received_by_user_id', $me->id)));
            }));
        $rows = (clone $base)->with(['receivedBy:id,name', 'warehouse:id,name,code'])->orderBy(self::SORTS[$f['sort']], $f['dir'])->orderByDesc('id')->paginate(20)->withQueryString();
        $rows->through(fn ($i) => $this->row($i, $me));
        $open = $this->scoped()->open()->count();

        return Inertia::render('VisitorLogs/Items/Index', [
            'rows' => $rows, 'filters' => $f, 'statuses' => VisitorOption::names('item_status', false), 'types' => VisitorOption::names('item_type', false), 'recipients' => $this->scoped()->whereNotNull('recipient_user_id')->distinct()->pluck('recipient_name', 'recipient_user_id')->map(fn ($n, $id) => ['value' => (string) $id, 'label' => $n])->sortBy('label')->values(),
            'stats' => ['total' => $this->scoped()->count(), 'open' => $open, 'closed' => $this->scoped()->count() - $open, 'overdue' => $days > 0 ? $this->scoped()->open()->where('received_at', '<', now()->subDays($days))->count() : 0], 'warehouses' => VisitorSettings::warehouseChoices($me), 'collectionDays' => $days, 'readyStatus' => VisitorOption::firstWithFlag('item_status', 'ready'), 'can' => $this->can($me),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------- new item

    private function formProps(?VisitorIncomingItem $i, Request $request): array
    {
        $me = $request->user();
        $visit = null;
        if (! $i && ctype_digit((string) $request->query('visit_id', ''))) {
            $v = VisitorVisit::query()->visibleTo($me)->with('visitor:id,full_name,company,phone,reference')->find((int) $request->query('visit_id'));
            $visit = $v ? ['id' => $v->id, 'warehouse_id' => $v->warehouse_id, 'reference' => $v->reference, 'visitor_id' => $v->visitor_id, 'visitor' => $v->visitor ? $v->visitor->summary($me, false) : null, 'host_user_id' => $v->host_user_id, 'host_name' => $v->host_name] : null;
        }

        return ['item' => $i ? [
            'id' => $i->id, 'reference' => $i->reference, 'received_at' => $i->received_at->toIso8601String(), 'item_type' => $i->item_type, 'sender_name' => $i->sender_name, 'sender_company' => $i->sender_company, 'sender_contact' => $i->sender_contact, 'courier' => $i->courier, 'tracking_number' => $i->tracking_number,
            'recipient_user_id' => $i->recipient_user_id, 'recipient_name' => $i->recipient_name, 'department' => $i->department, 'subject' => $i->subject, 'is_confidential' => (bool) $i->is_confidential, 'item_count' => (int) $i->item_count, 'item_condition' => $i->item_condition,
            'visit_id' => $i->visit_id, 'visitor_id' => $i->visitor_id, 'remarks' => $i->remarks, 'warehouse_id' => $i->warehouse_id, 'sender_contact_id' => $i->sender_contact_id] : null,
            'senderContact' => $i && $i->senderContact ? \App\Services\VisitorLogs\VisitorContacts::card($i->senderContact) : null,
            'visitor' => $i && $i->visitor ? $i->visitor->summary($me, false) : null, 'fromVisit' => $visit, 'warehouses' => $wh = VisitorSettings::warehouseChoices($me), 'defaultWarehouse' => $i ? $i->warehouse_id : ($visit && ! empty($visit['warehouse_id']) ? $visit['warehouse_id'] : VisitorSettings::defaultWarehouseId($wh)), 'types' => VisitorOption::names('item_type'), 'departments' => VisitorOption::names('department'), 'now' => now()->toIso8601String(), 'timezone' => config('app.timezone'),
            'canCorrectTime' => $me->can('correct-incoming-items'), 'can' => $this->can($me) + ['visitors' => $me->can('read-visitors') || $me->can('create-visitor-logs')]];
    }

    public function create(Request $request)
    {
        $this->need('create-incoming-items');

        return Inertia::render('VisitorLogs/Items/Form', $this->formProps(null, $request));
    }

    /** A type or a department the receptionist typed is added to the lists now that the item is going to be saved. */
    private function addNewEntries(array &$f): void
    {
        foreach ((array) ($f['_new'] ?? []) as [$kind, $name]) {
            $f[$kind === 'item_type' ? 'item_type' : 'department'] = VisitorOption::ensure($kind, $name);
        }
        unset($f['_new']);
    }

    private function validated(Request $request, ?VisitorIncomingItem $item): array
    {
        $me = $request->user();
        $d = $request->validate([
            'item_type' => 'required|string|max:80', 'warehouse_id' => 'nullable|integer', 'sender_contact_id' => 'nullable|integer', 'recipient_user_id' => 'required|integer', 'sender_name' => 'nullable|string|max:190', 'sender_company' => 'nullable|string|max:190', 'sender_contact' => 'nullable|string|max:190', 'courier' => 'nullable|string|max:120',
            'tracking_number' => 'nullable|string|max:120', 'department' => 'nullable|string|max:120', 'subject' => 'nullable|string|max:255', 'is_confidential' => 'nullable|boolean', 'item_count' => 'nullable|integer|min:1|max:999', 'item_condition' => 'nullable|string|max:160',
            'visit_id' => 'nullable|integer', 'visitor_id' => 'nullable|integer', 'remarks' => 'nullable|string|max:2000',
        ], ['item_type.required' => __('Choose the type of item.'), 'recipient_user_id.required' => __('Choose who the item is for.')]);
        $new = [];
        $type = trim(preg_replace('/\s+/', ' ', $d['item_type']));
        $existing = collect(VisitorOption::names('item_type', false))->first(fn ($n) => mb_strtolower($n) === mb_strtolower($type));
        if ($existing) {
            $type = $existing;
        } else {
            $new[] = ['item_type', $type]; // a type typed by the receptionist: added to the list when the item is saved
        }
        $rec = User::query()->find((int) $d['recipient_user_id']);
        if (! $rec || (! VisitorSettings::peopleQuery()->whereKey($rec->id)->exists() && ! ($item && (int) $item->recipient_user_id === $rec->id))) {
            throw ValidationException::withMessages(['recipient_user_id' => __('Choose the recipient from the list.')]);
        }
        $dept = trim(preg_replace('/\s+/', ' ', (string) ($d['department'] ?? '')));
        $existing = collect(VisitorOption::names('department', false))->first(fn ($n) => mb_strtolower($n) === mb_strtolower($dept));
        if ($dept !== '' && ! $existing) {
            $new[] = ['department', $dept];
        } elseif ($existing) {
            $dept = $existing;
        }
        $choices = VisitorSettings::warehouseChoices($me);
        $wid = ! empty($d['warehouse_id']) ? (int) $d['warehouse_id'] : null;
        if ($wid && ! collect($choices)->contains('id', $wid) && ! ($item && (int) $item->warehouse_id === $wid)) {
            throw ValidationException::withMessages(['warehouse_id' => __('Choose a project you work in.')]);
        }
        if (! $wid && $choices) {
            throw ValidationException::withMessages(['warehouse_id' => __('Choose the project this item was received for.')]);
        }
        $visitId = null;
        if (! empty($d['visit_id'])) {
            $visitId = VisitorVisit::query()->visibleTo($me)->whereKey((int) $d['visit_id'])->value('id') ?: ($item && (int) $item->visit_id === (int) $d['visit_id'] ? (int) $d['visit_id'] : null);
            if (! $visitId) {
                throw ValidationException::withMessages(['visit_id' => __('That visit was not found.')]);
            }
        }
        $visitorId = ! empty($d['visitor_id']) ? Visitor::query()->whereKey((int) $d['visitor_id'])->value('id') : null;
        if (! empty($d['visitor_id']) && ! $visitorId) {
            throw ValidationException::withMessages(['visitor_id' => __('That visitor was not found.')]);
        }
        // only people allowed to see confidential items can set the flag (a receptionist without the right cannot make an item invisible to colleagues)
        $conf = $me->can('view-confidential-incoming-items') ? (bool) ($d['is_confidential'] ?? false) : ($item ? (bool) $item->is_confidential : false);
        $clean = fn ($k) => trim((string) ($d[$k] ?? '')) ?: null;
        // the sender is a Contact (category Visitors when it was added here); its name and phone are kept on the item as they were on the day
        $sender = null;
        if (! empty($d['sender_contact_id'])) {
            $sender = \App\Models\Contact::query()->find((int) $d['sender_contact_id']);
            if (! $sender) {
                throw ValidationException::withMessages(['sender_contact_id' => __('That contact was not found.')]);
            }
        }

        return ['_new' => $new, 'warehouse_id' => $wid, 'item_type' => $type, 'recipient_user_id' => $rec->id, 'recipient_name' => $rec->name, 'sender_contact_id' => $sender ? $sender->id : null, 'sender_name' => $sender ? $sender->name : ($clean('sender_name') ?: ($item ? $item->sender_name : null)), 'sender_company' => $clean('sender_company'), 'sender_contact' => $sender ? ($sender->phone ?: $sender->email) : ($clean('sender_contact') ?: ($item ? $item->sender_contact : null)), 'courier' => $clean('courier'), 'tracking_number' => $clean('tracking_number'),
            'department' => $dept ?: null, 'subject' => $clean('subject'), 'is_confidential' => $conf, 'item_count' => (int) ($d['item_count'] ?? 1) ?: 1, 'item_condition' => $clean('item_condition'), 'visit_id' => $visitId, 'visitor_id' => $visitorId, 'remarks' => $clean('remarks')];
    }

    public function store(Request $request)
    {
        $this->need('create-incoming-items');
        $me = $request->user();
        $f = $this->validated($request, null);
        $this->addNewEntries($f);
        $at = now();
        $reason = null;
        if ($request->filled('received_at') && $me->can('correct-incoming-items')) {
            $given = $this->parseMoment($request->input('received_at'));
            if (! $given || $given->gt(now()->addMinute())) {
                throw ValidationException::withMessages(['received_at' => __('The time received must be a valid date and time that is not in the future.')]);
            }
            $reason = trim((string) $request->input('time_reason', ''));
            if ($reason === '') {
                throw ValidationException::withMessages(['time_reason' => __('Give the reason for setting the time yourself.')]);
            }
            $at = $given;
        }
        $first = VisitorOption::firstWithFlag('item_status', 'initial') ?: (VisitorOption::names('item_status')[0] ?? __('Received at Reception'));
        $item = ReferenceLock::run('visitor_items', fn () => VisitorIncomingItem::create($f + ['reference' => VisitorIncomingItem::nextReference(), 'received_at' => $at, 'received_by_user_id' => $me->id, 'status' => $first,
            'status_flag' => VisitorOption::flagOf('item_status', $first), 'status_changed_at' => $at]));
        $files = 0;
        if ($request->hasFile('attachments') && $me->can('attach-incoming-items')) {
            $request->validate($this->fileRules());
            $files = $this->saveFiles($item, $request, 'visitor-items');
        }
        VisitorLogEvent::record('item', $item, 'received', [], ['status' => $first, 'recipient' => $item->recipient_name, 'type' => $item->item_type, 'confidential' => $item->is_confidential, 'time_set_by_hand' => $reason !== null], $reason);
        log_activity('Incoming item received: ' . $item->reference, ['type' => $item->item_type, 'recipient' => $item->recipient_name, 'confidential' => (bool) $item->is_confidential, 'visit_id' => $item->visit_id, 'files' => $files], $item, 'Visitor Logs');
        VisitorNotifier::item('item_received', $item, $me);

        return redirect()->route('visitor-logs.items.show', $item->id)->with('message', __(':r registered. The recipient was told.', ['r' => $item->reference]));
    }

    // ---------------------------------------------------------------------------------------------------- one item

    public function show(Request $request, int $item)
    {
        $i = $this->find($item);
        $me = $request->user();
        $i->load(['receivedBy:id,name', 'releasedBy:id,name', 'visit:id,reference', 'visitor']);
        $can = $this->can($me);
        $see = $i->canSeeDetails($me);
        $data = $this->row($i, $me) + ['sender_contact' => $see ? $i->sender_contact : null, 'tracking_number' => $see ? $i->tracking_number : null, 'condition' => $see ? $i->item_condition : null, 'remarks' => $see ? $i->remarks : null,
            'recipient_user_id' => $i->recipient_user_id, 'visit' => $i->visit ? ['id' => $i->visit->id, 'reference' => $i->visit->reference] : null, 'visitor' => $i->visitor && $see ? ['id' => $i->visitor->id, 'name' => $i->visitor->full_name] : null,
            'handed_to' => $i->handed_to, 'handed_at' => optional($i->handed_at)->toIso8601String(), 'released_by' => optional($i->releasedBy)->name, 'handover_remarks' => $i->handover_remarks];
        $events = [];
        if ($can['history'] || (int) $i->recipient_user_id === $me->id) {
            $events = VisitorLogEvent::query()->where('subject_type', 'item')->where('subject_id', $i->id)->with('user:id,name')->orderBy('id')->get()
                ->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'by' => optional($e->user)->name, 'at' => $e->created_at->toIso8601String(), 'reason' => $e->reason, 'old' => $e->old_value, 'new' => $e->new_value]);
        }
        $files = $see && ($can['files'] || (int) $i->recipient_user_id === $me->id) ? $this->filesOf($i) : [];
        $statuses = collect(VisitorOption::listOf('item_status'))->map(fn ($s) => ['name' => $s['name'], 'flag' => $s['flag']])->values();

        return Inertia::render('VisitorLogs/Items/Show', ['item' => $data, 'events' => $events, 'files' => $files, 'statuses' => $statuses, 'can' => $can + ['seeFiles' => $see && ($can['files'] || (int) $i->recipient_user_id === $me->id)], 'now' => now()->toIso8601String()]);
    }

    public function edit(Request $request, int $item)
    {
        $this->need('update-incoming-items');
        $i = $this->find($item);
        abort_unless($i->canSeeDetails($request->user()), 403);
        abort_unless($i->isOpen() || $request->user()->can('correct-incoming-items'), 422, __('An item that was handed over can only be changed through a correction.'));

        return Inertia::render('VisitorLogs/Items/Form', $this->formProps($i, $request));
    }

    public function update(Request $request, int $item)
    {
        $this->need('update-incoming-items');
        $i = $this->find($item);
        abort_unless($i->canSeeDetails($request->user()), 403);
        abort_unless($i->isOpen() || $request->user()->can('correct-incoming-items'), 422, __('An item that was handed over can only be changed through a correction.'));
        $f = $this->validated($request, $i);
        $this->addNewEntries($f);
        $i->fill($f);
        $changes = $i->getDirty();
        $old = collect($changes)->map(fn ($x, $k) => $i->getOriginal($k))->all();
        $i->save();
        if ($changes) {
            VisitorLogEvent::record('item', $i, 'edited', $old, $changes);
            log_activity('Incoming item ' . $i->reference . ' changed', ['changed' => array_keys($changes)], $i, 'Visitor Logs');
        }

        return redirect()->route('visitor-logs.items.show', $i->id)->with('message', __('Item saved.'));
    }

    /** A status other than "handed over": notified, ready for collection, returned to sender, or any other the list holds. */
    public function status(Request $request, int $item)
    {
        $this->need('update-incoming-item-status');
        $found = $this->find($item);
        abort_unless($found->canSeeDetails($request->user()) || (int) $found->received_by_user_id === $request->user()->id, 403);
        $d = $request->validate(['status' => 'required|string|max:80', 'remarks' => 'nullable|string|max:1000']);
        $target = collect(VisitorOption::listOf('item_status'))->firstWhere('name', $d['status']);
        if (! $target) {
            throw ValidationException::withMessages(['status' => __('Choose a status from the list.')]);
        }
        if ($target['flag'] === 'handed') {
            throw ValidationException::withMessages(['status' => __('Use "Hand over" to record who received the item. A status alone does not mean it was delivered.')]);
        }
        if ($target['flag'] === 'closed' && trim((string) ($d['remarks'] ?? '')) === '') {
            throw ValidationException::withMessages(['remarks' => __('Say why, for example to whom it was returned.')]);
        }
        $i = DB::transaction(function () use ($found, $target, $d, $request) {
            $i = VisitorIncomingItem::query()->whereKey($found->id)->lockForUpdate()->first();
            if (! $i->isOpen()) {
                throw ValidationException::withMessages(['status' => __('This item is already :s. Use a correction to change a finished record.', ['s' => mb_strtolower($i->status)])]);
            }
            $old = ['status' => $i->status];
            $i->update(['status' => $target['name'], 'status_flag' => $target['flag'], 'status_changed_at' => now(), 'overdue_alerted_at' => null]);
            VisitorLogEvent::record('item', $i, 'status_changed', $old, ['status' => $target['name']], trim((string) ($d['remarks'] ?? '')) ?: null);

            return $i;
        });
        log_activity('Incoming item ' . $i->reference . ' status changed', ['from' => $found->status, 'to' => $i->status, 'remarks' => $d['remarks'] ?? null], $i, 'Visitor Logs');
        if ($target['flag'] === 'ready') {
            VisitorNotifier::item('item_ready', $i, $request->user());
        }

        return back()->with('message', __('Status changed to :s.', ['s' => $i->status]));
    }

    public function handover(Request $request, int $item)
    {
        $this->need('handover-incoming-items');
        $found = $this->find($item);
        $me = $request->user();
        abort_unless($found->canSeeDetails($me) || (int) $found->received_by_user_id === $me->id, 403);
        $d = $request->validate(['handed_to' => 'required|string|max:190', 'status' => 'nullable|string|max:80', 'remarks' => 'nullable|string|max:1000', 'handed_at' => 'nullable|string', 'time_reason' => 'nullable|string|max:1000'], ['handed_to.required' => __('Type who received the item: the recipient or the authorized collector.')]);
        $handed = collect(VisitorOption::listOf('item_status'))->where('flag', 'handed')->values();
        $target = ! empty($d['status']) ? $handed->firstWhere('name', $d['status']) : $handed->first();
        if (! $target) {
            throw ValidationException::withMessages(['status' => __('Choose how it was handed over from the list.')]);
        }
        $at = now();
        if (filled($d['handed_at'] ?? null) && $me->can('correct-incoming-items')) {
            $given = $this->parseMoment($d['handed_at']);
            if (! $given || $given->gt(now()->addMinute())) {
                throw ValidationException::withMessages(['handed_at' => __('The hand-over time must be a valid date and time that is not in the future.')]);
            }
            if (trim((string) ($d['time_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['time_reason' => __('Give the reason for setting the time yourself.')]);
            }
            $at = $given;
        }
        $i = DB::transaction(function () use ($found, $target, $d, $me, $at) {
            $i = VisitorIncomingItem::query()->whereKey($found->id)->lockForUpdate()->first();
            if (! $i->isOpen()) {
                throw ValidationException::withMessages(['handed_to' => __('This item was already :s.', ['s' => mb_strtolower($i->status)])]);
            }
            $old = ['status' => $i->status];
            $i->update(['status' => $target['name'], 'status_flag' => 'handed', 'status_changed_at' => $at, 'handed_to' => trim($d['handed_to']), 'handed_at' => $at, 'released_by_user_id' => $me->id, 'handover_remarks' => trim((string) ($d['remarks'] ?? '')) ?: null]);
            VisitorLogEvent::record('item', $i, 'handed_over', $old, ['status' => $target['name'], 'handed_to' => $i->handed_to, 'handed_at' => $at->toDateTimeString(), 'released_by' => $me->name], trim((string) ($d['time_reason'] ?? '')) ?: null);

            return $i;
        });
        log_activity('Incoming item ' . $i->reference . ' handed over', ['to' => $i->handed_to, 'at' => $at->toDateTimeString(), 'status' => $i->status], $i, 'Visitor Logs');
        VisitorNotifier::item('item_handed_over', $i, $me);

        return back()->with('message', __(':r was handed over to :n.', ['r' => $i->reference, 'n' => $i->handed_to]));
    }

    /** Fixes a finished delivery record (who got it, when, the status), always with a reason. */
    public function correct(Request $request, int $item)
    {
        $this->need('correct-incoming-items');
        $i = $this->find($item);
        abort_unless($i->canSeeDetails($request->user()), 403);
        $d = $request->validate(['handed_to' => 'required|string|max:190', 'handed_at' => 'nullable|string', 'status' => 'nullable|string|max:80', 'remarks' => 'nullable|string|max:1000', 'reason' => 'required|string|max:1000'], ['reason.required' => __('Give the reason for the correction.'), 'handed_to.required' => __('Type who received the item.')]);
        abort_if($i->isOpen(), 422, __('Only a finished delivery record is corrected here. Use the status or the hand-over for an open item.'));
        $at = filled($d['handed_at'] ?? null) ? $this->parseMoment($d['handed_at']) : $i->handed_at;
        if (! $at || $at->gt(now()->addMinute())) {
            throw ValidationException::withMessages(['handed_at' => __('The hand-over time must be a valid date and time that is not in the future.')]);
        }
        $status = $i->status;
        $flag = $i->status_flag;
        if (! empty($d['status'])) {
            $t = collect(VisitorOption::listOf('item_status'))->whereIn('flag', ['handed', 'closed'])->firstWhere('name', $d['status']);
            if (! $t) {
                throw ValidationException::withMessages(['status' => __('Choose a finished status from the list.')]);
            }
            $status = $t['name'];
            $flag = $t['flag'];
        }
        $old = ['status' => $i->status, 'handed_to' => $i->handed_to, 'handed_at' => optional($i->handed_at)->toDateTimeString(), 'remarks' => $i->handover_remarks];
        $i->update(['status' => $status, 'status_flag' => $flag, 'handed_to' => trim($d['handed_to']), 'handed_at' => $at, 'handover_remarks' => trim((string) ($d['remarks'] ?? '')) ?: $i->handover_remarks]);
        $new = ['status' => $i->status, 'handed_to' => $i->handed_to, 'handed_at' => $at->toDateTimeString(), 'remarks' => $i->handover_remarks];
        VisitorLogEvent::record('item', $i, 'corrected', $old, $new, $d['reason']);
        log_activity('Incoming item ' . $i->reference . ' delivery corrected', ['before' => $old, 'after' => $new, 'reason' => $d['reason']], $i, 'Visitor Logs');

        return back()->with('message', __('The delivery record was corrected and the correction was recorded.'));
    }

    // ---------------------------------------------------------------------------------------------------- files

    public function attach(Request $request, int $item)
    {
        $this->need('attach-incoming-items');
        $i = $this->find($item);
        abort_unless($i->canSeeDetails($request->user()), 403);
        $request->validate($this->fileRules());
        $n = $this->saveFiles($i, $request, 'visitor-items');
        VisitorLogEvent::record('item', $i, 'file_added', [], ['files' => $n]);
        log_activity('Files added to item ' . $i->reference, ['files' => $n], $i, 'Visitor Logs');

        return back()->with('message', __(':n file(s) added.', ['n' => $n]));
    }

    public function download(Request $request, int $item, int $file)
    {
        $i = $this->find($item);
        $me = $request->user();
        abort_unless($i->canSeeDetails($me) && ($me->can('attach-incoming-items') || (int) $i->recipient_user_id === $me->id), 403);
        $a = $this->fileOf($i, $file);
        VisitorLogEvent::record('item', $i, 'file_opened', [], ['file' => $a->title]);
        log_activity('File of item ' . $i->reference . ' opened', ['file' => $a->title, 'confidential' => (bool) $i->is_confidential], $i, 'Visitor Logs');

        return $this->sendFile($a, $request->boolean('inline'));
    }
}
