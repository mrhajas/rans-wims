<?php

namespace App\Http\Controllers;

use App\Events\CaseTicketAssigned;
use App\Events\CaseTicketStatusChanged;
use App\Http\Requests\CaseTicketStoreRequest;
use App\Http\Requests\CaseTicketUpdateRequest;
use App\Http\Resources\CaseTicketCollection;
use App\Models\CaseCategory;
use App\Models\CasePriority;
use App\Models\CaseTicket;
use App\Models\User;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

/**
 * Case Tracking's controller-level authorization mirrors Labour/Workforce
 * Agreement exactly: setAbilities([]) opts every action out of Authorizable's
 * route-name-derived auto-check, and every method below carries its own
 * explicit $this->authorize(...) call against CaseTicketPolicy instead —
 * hiding a button is never the only guard.
 */
class CaseTrackingController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /**
     * The one place ownership scoping is decided — index(), export(),
     * printList() and pdfList() all build their query from here so a plain
     * user's "own/assigned only" restriction can never drift between what
     * they see on screen and what they can print/export.
     */
    protected function scopedQuery(Request $request, User $user): \Illuminate\Database\Eloquent\Builder
    {
        $mine = $request->boolean('mine') || $request->routeIs('case-tracking.mine');

        $query = CaseTicket::query()->with(['category:id,name', 'priority:id,name,color', 'warehouse:id,name', 'createdBy:id,name', 'assignedTo:id,name']);

        // "My Cases" (the ?mine=1 link) always scopes to the current user,
        // even for someone who holds read-all-case-tracking — the whole
        // point of that view is "just mine", not "everyone's, filtered".
        if ($mine || (! $user->hasRole(['Super Admin', 'Admin']) && ! $user->can('read-all-case-tracking'))) {
            $query->where(function ($q) use ($user) {
                $q->where('created_by_user_id', $user->id)->orWhere('assigned_to_user_id', $user->id);
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', CaseTicket::class);
        $user = $request->user();

        $filters = $request->only(['search', 'status', 'case_category_id', 'case_priority_id', 'warehouse_id', 'assigned_to_user_id', 'created_by_user_id', 'date_from', 'date_to']);
        $mine = $request->boolean('mine') || $request->routeIs('case-tracking.mine');

        $cases = $this->scopedQuery($request, $user)->filter($filters)->orderByDesc('id')->paginate()->withQueryString();
        $cases->getCollection()->transform(fn (CaseTicket $c) => $this->presentRow($c));

        return Inertia::render('CaseTracking/Index', [
            'filters' => $filters + ['mine' => $mine],
            'statuses' => CaseTicket::statuses(),
            'categories' => CaseCategory::active()->ordered()->get(['id', 'name']),
            'priorities' => CasePriority::active()->ordered()->get(['id', 'name', 'color']),
            'warehouses' => $this->projectWarehouses(),
            'cases' => new CaseTicketCollection($cases),
            'cards' => $this->summaryCards($user),
            'stats' => $this->listStats($request, $user),
            'can' => [
                'create' => $user->can('create', CaseTicket::class),
                'viewAnalytics' => $user->can('viewAnalytics', CaseTicket::class),
                'export' => $user->can('export', CaseTicket::class),
                'manageSettings' => $user->can('manageSettings', CaseTicket::class),
            ],
        ]);
    }

    /**
     * Header tiles for the list. Built on scopedQuery() so the numbers follow
     * exactly the same visibility rules as the rows (including the "My Cases"
     * own-only scope); list filters are intentionally ignored. One aggregate
     * query, no eager loads.
     */
    protected function listStats(Request $request, User $user): array
    {
        $closedStatuses = [CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED];
        $openIn = implode(',', array_fill(0, count($closedStatuses), '?'));
        $highIds = CasePriority::where('level', '>=', 3)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $highSql = $highIds ? 'case_priority_id IN ('.implode(',', $highIds).')' : '0 = 1';
        $cutoff = now()->subDays(14)->toDateTimeString();

        $row = $this->scopedQuery($request, $user)->toBase()->selectRaw(
            'COUNT(*) as total,'
            ."COALESCE(SUM(CASE WHEN status NOT IN ({$openIn}) THEN 1 ELSE 0 END), 0) as open_count,"
            .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as in_progress,'
            ."COALESCE(SUM(CASE WHEN status IN ({$openIn}) THEN 1 ELSE 0 END), 0) as done,"
            ."COALESCE(SUM(CASE WHEN status NOT IN ({$openIn}) AND {$highSql} THEN 1 ELSE 0 END), 0) as high_prio,"
            ."COALESCE(SUM(CASE WHEN status NOT IN ({$openIn}) AND created_at < ? THEN 1 ELSE 0 END), 0) as long_running",
            array_merge($closedStatuses, [CaseTicket::STATUS_IN_PROGRESS], $closedStatuses, $closedStatuses, $closedStatuses, [$cutoff])
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'open' => (int) ($row->open_count ?? 0),
            'in_progress' => (int) ($row->in_progress ?? 0),
            'done' => (int) ($row->done ?? 0),
            'high_priority' => (int) ($row->high_prio ?? 0),
            'long_running' => (int) ($row->long_running ?? 0),
        ];
    }

    protected function summaryCards(User $user): array
    {
        $base = CaseTicket::query();
        if (! $user->hasRole(['Super Admin', 'Admin']) && ! $user->can('read-all-case-tracking')) {
            $base->where(function ($q) use ($user) {
                $q->where('created_by_user_id', $user->id)->orWhere('assigned_to_user_id', $user->id);
            });
        }

        $rows = (clone $base)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $highPriorityId = CasePriority::where('level', '>=', 3)->pluck('id');

        return [
            'total' => (int) $rows->sum(),
            'open' => (int) $rows->except([CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED])->sum(),
            'in_review' => (int) ($rows[CaseTicket::STATUS_IN_REVIEW] ?? 0),
            'in_progress' => (int) ($rows[CaseTicket::STATUS_IN_PROGRESS] ?? 0),
            'resolved' => (int) ($rows[CaseTicket::STATUS_RESOLVED] ?? 0),
            'closed' => (int) ($rows[CaseTicket::STATUS_CLOSED] ?? 0),
            'high_priority' => (int) (clone $base)->whereIn('case_priority_id', $highPriorityId)
                ->whereNotIn('status', [CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED])->count(),
        ];
    }

    protected function presentRow(CaseTicket $c): array
    {
        return [
            'id' => $c->id,
            'case_number' => $c->case_number,
            'subject' => $c->subject,
            'status' => $c->status,
            'category_name' => $c->category?->name,
            'priority_name' => $c->priority?->name,
            'priority_color' => $c->priority?->color,
            'warehouse_name' => $c->warehouse?->name,
            'created_by_name' => $c->createdBy?->name,
            'assigned_to_name' => $c->assignedTo?->name,
            'created_at' => optional($c->created_at)->toDateTimeString(),
            'updated_at' => optional($c->updated_at)->toDateTimeString(),
            'is_long_running' => $c->isLongRunning(),
        ];
    }

    public function create(Request $request)
    {
        $this->authorize('create', CaseTicket::class);

        // Prefill support for the floating Quick Access Menu's "New Case"
        // shortcut, which links here with the page the user was on encoded
        // as module_reference/external_url. module_reference is only
        // accepted if it's an exact match against the same fixed list the
        // AutoComplete itself offers — the field is deliberately non-freeform,
        // so an unrecognized value would otherwise show a Module/Reference
        // the picker can't actually represent.
        $moduleReference = $request->query('module_reference');
        if (! in_array($moduleReference, CaseTicket::wimsModules(), true)) {
            $moduleReference = null;
        }

        $externalUrl = $request->query('external_url');
        if (! is_string($externalUrl) || strlen($externalUrl) > 2048 || ! filter_var($externalUrl, FILTER_VALIDATE_URL)) {
            $externalUrl = null;
        }

        return Inertia::render('CaseTracking/Form', [
            'edit' => null,
            'categories' => CaseCategory::active()->ordered()->get(['id', 'name']),
            'priorities' => CasePriority::active()->ordered()->get(['id', 'name']),
            'warehouses' => $this->projectWarehouses(),
            'wimsModules' => CaseTicket::wimsModules(),
            'prefillModuleReference' => $moduleReference,
            'prefillExternalUrl' => $externalUrl,
        ]);
    }

    /**
     * Inline "type a new one" create for the Category field on the ticket
     * form — gated by the same ability as creating a case itself
     * (create-case-tracking), deliberately not CaseSettingsController's
     * manageSettings-gated storeCategory(), since any case-creator should be
     * able to add a category while filing a ticket, not just admins.
     * firstOrCreate so two people typing the same new name in close
     * succession land on one row instead of a unique-constraint error.
     */
    public function quickCreateCategory(Request $request)
    {
        $this->authorize('create', CaseTicket::class);

        $data = $request->validate(['name' => 'required|string|max:100']);
        $name = trim($data['name']);

        $category = CaseCategory::firstOrCreate(
            ['name' => $name],
            ['sort_order' => (CaseCategory::max('sort_order') ?? 0) + 1]
        );

        if ($category->wasRecentlyCreated) {
            log_activity(__('Case category ":name" created.', ['name' => $category->name]), [], $category, 'Case Tracking');
        }

        return response()->json(['id' => $category->id, 'name' => $category->name]);
    }

    /** Same as quickCreateCategory() above, for the Priority field. New priorities default to the lowest shelf (level 1, slate) — a creator picking "type a new one" is naming a category of work, not pre-judging its urgency. */
    public function quickCreatePriority(Request $request)
    {
        $this->authorize('create', CaseTicket::class);

        $data = $request->validate(['name' => 'required|string|max:100']);
        $name = trim($data['name']);

        $priority = CasePriority::firstOrCreate(
            ['name' => $name],
            ['level' => 1, 'color' => 'slate']
        );

        if ($priority->wasRecentlyCreated) {
            log_activity(__('Case priority ":name" created.', ['name' => $priority->name]), [], $priority, 'Case Tracking');
        }

        return response()->json(['id' => $priority->id, 'name' => $priority->name]);
    }

    public function store(CaseTicketStoreRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();

        // Retries on a case_number collision rather than locking a counter —
        // same convention as TransportRecordController::createPaymentVoucher()'s
        // nextVoucherReference() retry — two people submitting a case at
        // nearly the same instant could otherwise both compute the same
        // MAX(...)+1 suffix, and the loser would crash with a raw
        // QueryException on the unique constraint instead of just getting
        // the next available number.
        $case = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $case = CaseTicket::create([
                    'case_number' => $this->nextCaseNumber(),
                    'subject' => $data['subject'],
                    'description' => $data['description'],
                    'case_category_id' => $data['case_category_id'] ?? null,
                    'case_priority_id' => $data['case_priority_id'] ?? null,
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'module_reference' => $data['module_reference'] ?? null,
                    'external_url' => $data['external_url'] ?? null,
                    'status' => CaseTicket::STATUS_DRAFT,
                    'created_by_user_id' => $user->id,
                ]);
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                $isDuplicate = (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'case_tickets_case_number_unique');
                if (! $isDuplicate || $attempt === 2) {
                    throw $e;
                }
            }
        }

        $case->saveAttachments($request->file('attachments'), 'case-tracking');
        log_activity(__('Case :number created.', ['number' => $case->case_number]), $this->withRequestMeta(['subject' => $case->subject]), $case, 'Case Tracking');

        \App\Services\CaseWorkflowNotifier::fire('created', $case, $user->id, __('Case Created'), $case->case_number . ' - ' . $case->subject . ' was created by ' . $user->name . '.');

        if ($request->boolean('should_submit') && $user->can('submit', $case)) {
            $this->doSubmit($case, $user);
        }

        return redirect()->route('case-tracking.show', $case->id)->with('message', __('Case created.'));
    }

    public function show(Request $request, CaseTicket $case_ticket)
    {
        $this->authorize('view', $case_ticket);
        $case_ticket->load(['category', 'priority', 'warehouse:id,name', 'createdBy:id,name', 'assignedTo:id,name', 'resolvedBy:id,name', 'closedBy:id,name', 'attachments', 'comments.user:id,name']);

        $user = $request->user();

        return Inertia::render('CaseTracking/Show', [
            'case' => $this->presentDetail($case_ticket),
            'timeline' => $this->timeline($case_ticket),
            'assignableUsers' => $user->can('assign', $case_ticket) ? User::ofAccount()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'allowedStatuses' => app(\App\Policies\CaseTicketPolicy::class)->allowedTargetStatuses($user, $case_ticket),
            'can' => [
                'update' => $user->can('update', $case_ticket),
                'submit' => $user->can('submit', $case_ticket),
                'delete' => $user->can('delete', $case_ticket),
                'comment' => $user->can('comment', $case_ticket),
                'attach' => $user->can('attach', $case_ticket),
                'assign' => $user->can('assign', $case_ticket),
                'print' => $user->can('print', $case_ticket),
            ],
        ]);
    }

    protected function presentDetail(CaseTicket $c): array
    {
        return [
            'id' => $c->id,
            'case_number' => $c->case_number,
            'subject' => $c->subject,
            'description' => $c->description,
            'status' => $c->status,
            'category' => $c->category ? ['id' => $c->category->id, 'name' => $c->category->name] : null,
            'priority' => $c->priority ? ['id' => $c->priority->id, 'name' => $c->priority->name, 'color' => $c->priority->color] : null,
            'warehouse' => $c->warehouse ? ['id' => $c->warehouse->id, 'name' => $c->warehouse->name] : null,
            'module_reference' => $c->module_reference,
            'external_url' => $c->external_url,
            'created_by' => $c->createdBy ? ['id' => $c->createdBy->id, 'name' => $c->createdBy->name] : null,
            'assigned_to' => $c->assignedTo ? ['id' => $c->assignedTo->id, 'name' => $c->assignedTo->name] : null,
            'created_at' => optional($c->created_at)->toDateTimeString(),
            'updated_at' => optional($c->updated_at)->toDateTimeString(),
            'submitted_at' => optional($c->submitted_at)->toDateTimeString(),
            'return_reason' => $c->return_reason,
            'resolution' => $c->resolution,
            'resolved_by_name' => $c->resolvedBy?->name,
            'resolved_at' => optional($c->resolved_at)->toDateTimeString(),
            'closed_by_name' => $c->closedBy?->name,
            'closed_at' => optional($c->closed_at)->toDateTimeString(),
            'reopened_count' => $c->reopened_count,
            'attachments' => $c->attachments->map(fn (Attachment $a) => [
                'id' => $a->id,
                'name' => $a->title ?: $a->filename,
                'is_image' => str_starts_with((string) $a->filetype, 'image/'),
                'download_url' => route('case-tracking.attachments.download', [$c->id, $a->id]),
                'delete_url' => route('case-tracking.attachments.destroy', [$c->id, $a->id]),
            ])->values(),
            'comments' => $c->comments->map(fn ($cm) => [
                'id' => $cm->id,
                'body' => $cm->body,
                'user_name' => $cm->user?->name,
                'user_id' => $cm->user_id,
                'created_at' => optional($cm->created_at)->toDateTimeString(),
            ])->values(),
        ];
    }

    /** Every logged action on this case, newest first — the "Activity Timeline" from the spec, built entirely from the existing Spatie activity log, no new table. */
    protected function timeline(CaseTicket $c): array
    {
        return \Spatie\Activitylog\Models\Activity::forSubject($c)
            ->with('causer:id,name')
            ->latest()
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'description' => $a->description,
                'causer_name' => $a->causer?->name ?? __('System'),
                'properties' => $a->properties,
                'created_at' => optional($a->created_at)->toDateTimeString(),
            ])->values()->all();
    }

    public function edit(Request $request, CaseTicket $case_ticket)
    {
        $this->authorize('update', $case_ticket);
        $case_ticket->load(['attachments']);

        return Inertia::render('CaseTracking/Form', [
            'edit' => $this->presentDetail($case_ticket),
            'categories' => CaseCategory::active()->ordered()->get(['id', 'name']),
            'priorities' => CasePriority::active()->ordered()->get(['id', 'name']),
            'warehouses' => $this->projectWarehouses(),
            'wimsModules' => CaseTicket::wimsModules(),
        ]);
    }

    public function update(CaseTicketUpdateRequest $request, CaseTicket $case_ticket)
    {
        $data = $request->validated();
        $before = $case_ticket->only(['subject', 'description', 'case_category_id', 'case_priority_id', 'warehouse_id', 'module_reference', 'external_url']);

        $case_ticket->update([
            'subject' => $data['subject'],
            'description' => $data['description'],
            'case_category_id' => $data['case_category_id'] ?? null,
            'case_priority_id' => $data['case_priority_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'module_reference' => $data['module_reference'] ?? null,
            'external_url' => $data['external_url'] ?? null,
        ]);

        $case_ticket->saveAttachments($request->file('attachments'), 'case-tracking');
        log_activity(__('Case :number edited.', ['number' => $case_ticket->case_number]), $this->withRequestMeta(['before' => $before, 'after' => $data]), $case_ticket, 'Case Tracking');

        if ($request->boolean('should_submit') && $request->user()->can('submit', $case_ticket)) {
            $this->doSubmit($case_ticket, $request->user());
        }

        \App\Services\CaseWorkflowNotifier::fire('updated', $case_ticket->fresh(), $request->user()->id, __('Case Edited'), $case_ticket->case_number . ' - ' . $case_ticket->subject . ' was edited by ' . $request->user()->name . '.');

        return redirect()->route('case-tracking.show', $case_ticket->id)->with('message', __('Case updated.'));
    }

    public function destroy(Request $request, CaseTicket $case_ticket)
    {
        $this->authorize('delete', $case_ticket);
        $number = $case_ticket->case_number;
        $case_ticket->loadMissing(['createdBy', 'assignedTo', 'warehouse']);
        $case_ticket->delete();
        log_activity(__('Case :number deleted.', ['number' => $number]), $this->withRequestMeta([]), $case_ticket, 'Case Tracking');
        \App\Services\CaseWorkflowNotifier::fire('deleted', $case_ticket, $request->user()->id, __('Case Deleted'), $number . ' - ' . $case_ticket->subject . ' was deleted by ' . $request->user()->name . '.');

        return redirect()->route('case-tracking.index')->with('message', __('Case deleted.'));
    }

    public function submit(Request $request, CaseTicket $case_ticket)
    {
        $this->authorize('submit', $case_ticket);
        $this->doSubmit($case_ticket, $request->user());

        return back()->with('message', __('Case submitted.'));
    }

    protected function doSubmit(CaseTicket $case, $user): void
    {
        $case->update(['status' => CaseTicket::STATUS_SUBMITTED, 'submitted_at' => now()]);
        log_activity(__('Case :number submitted.', ['number' => $case->case_number]), $this->withRequestMeta([]), $case, 'Case Tracking');
        event(new CaseTicketStatusChanged($case->fresh(), $user->id));
    }

    /**
     * One endpoint for every forward status move (In Review, In Progress,
     * Returned, Resolved, Closed) plus reopening — mirrors
     * LabourController::updateStatus() exactly, gated by
     * CaseTicketPolicy::changeStatus()/allowedTargetStatuses().
     */
    public function updateStatus(Request $request, CaseTicket $case_ticket)
    {
        $targetStatus = $request->input('status');
        $this->authorize('changeStatus', [$case_ticket, $targetStatus]);

        $data = $request->validate([
            'status' => 'required|in:' . implode(',', CaseTicket::statuses()),
            'note' => 'nullable|string|max:2000',
            'resolution' => 'required_if:status,' . CaseTicket::STATUS_RESOLVED . '|nullable|string|max:5000',
        ]);

        $user = $request->user();

        DB::transaction(function () use ($case_ticket, $targetStatus, $data, $user) {
            // Locked and re-checked here — without this, two concurrent
            // status changes on the same case (e.g. one agent resolving
            // while another returns it) can both pass authorize()'s
            // snapshot check and both write, leaving a case whose
            // resolution/return_reason contradicts its final status and
            // firing two conflicting notifications.
            $locked = CaseTicket::whereKey($case_ticket->id)->lockForUpdate()->firstOrFail();
            abort_unless(Gate::forUser($user)->allows('changeStatus', [$locked, $targetStatus]), 422, __('This case is no longer eligible for that status change.'));

            $before = $locked->status;
            $update = ['status' => $targetStatus];

            if ($targetStatus === CaseTicket::STATUS_RETURNED) {
                $update['return_reason'] = $data['note'] ?? null;
            }

            if ($targetStatus === CaseTicket::STATUS_RESOLVED) {
                $update['resolution'] = $data['resolution'];
                $update['resolved_by_user_id'] = $user->id;
                $update['resolved_at'] = now();
            }

            if ($targetStatus === CaseTicket::STATUS_CLOSED) {
                $update['closed_by_user_id'] = $user->id;
                $update['closed_at'] = now();
            }

            // Reopening — moving out of Resolved/Closed back into review.
            if (in_array($before, [CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED], true) && $targetStatus === CaseTicket::STATUS_IN_REVIEW) {
                $update['reopened_count'] = $locked->reopened_count + 1;
            }

            $locked->update($update);

            log_activity(
                __('Case :number status changed from :before to :after.', ['number' => $locked->case_number, 'before' => $before, 'after' => $targetStatus]),
                $this->withRequestMeta(['from' => $before, 'to' => $targetStatus, 'note' => $data['note'] ?? null]),
                $locked,
                'Case Tracking'
            );

            event(new CaseTicketStatusChanged($locked->fresh(), $user->id, $data['note'] ?? null, $before));
        });

        return back()->with('message', __('Case status updated.'));
    }

    public function assign(Request $request, CaseTicket $case_ticket)
    {
        $this->authorize('assign', $case_ticket);

        $data = $request->validate(['assigned_to_user_id' => 'nullable|exists:users,id']);
        $user = $request->user();

        $result = DB::transaction(function () use ($case_ticket, $data) {
            // Locked and re-checked for the same reason as updateStatus()
            // above — two near-simultaneous reassignments must not both
            // write, which would otherwise log/notify an assignment that no
            // longer matches the ticket's actual final assignee.
            $locked = CaseTicket::whereKey($case_ticket->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->assigned_to_user_id;
            $locked->update(['assigned_to_user_id' => $data['assigned_to_user_id'] ?? null]);

            $newAssignee = $locked->fresh()->assignedTo;
            $verb = $previous ? 'reassigned' : 'assigned';
            log_activity(
                __('Case :number :verb to :name.', ['number' => $locked->case_number, 'verb' => $verb, 'name' => $newAssignee?->name ?? __('Unassigned')]),
                $this->withRequestMeta(['from' => $previous, 'to' => $data['assigned_to_user_id'] ?? null]),
                $locked,
                'Case Tracking'
            );

            return ['ticket' => $locked->fresh(), 'previous' => $previous];
        });

        // Dispatched after the transaction commits — a notification/email
        // failure (e.g. the recipient's mail server rejecting delivery)
        // must never roll back an assignment that already succeeded, and
        // (since AppNotification is queued but QUEUE_CONNECTION=sync runs
        // it inline) must never surface as a 500 to someone who just
        // successfully reassigned a case either. See
        // CaseTicketAssignedListener for the actual notify/email logic.
        if ($data['assigned_to_user_id'] ?? null) {
            try {
                event(new CaseTicketAssigned($result['ticket'], $user->id, $result['previous']));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('message', __('Case assignment updated.'));
    }

    public function downloadAttachment(Request $request, CaseTicket $case_ticket, Attachment $attachment)
    {
        $this->authorize('view', $case_ticket);
        abort_unless((int) $attachment->model_id === (int) $case_ticket->id && $attachment->model_type === CaseTicket::class, 404);

        log_activity(__('Attachment :name downloaded from Case :number.', ['name' => $attachment->title ?: $attachment->filename, 'number' => $case_ticket->case_number]), $this->withRequestMeta([]), $case_ticket, 'Case Tracking');

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title ?: $attachment->filename);
    }

    public function destroyAttachment(Request $request, CaseTicket $case_ticket, Attachment $attachment)
    {
        abort_unless((int) $attachment->model_id === (int) $case_ticket->id && $attachment->model_type === CaseTicket::class, 404);
        $this->authorize('removeAttachment', $case_ticket);

        $name = $attachment->title ?: $attachment->filename;
        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        log_activity(__('Attachment :name removed from Case :number.', ['name' => $name, 'number' => $case_ticket->case_number]), $this->withRequestMeta([]), $case_ticket, 'Case Tracking');

        return back()->with('message', __('Attachment removed.'));
    }

    protected function pdfPayload(CaseTicket $c, string $generatedBy): array
    {
        $c->load(['category', 'priority', 'warehouse:id,name', 'createdBy:id,name', 'assignedTo:id,name', 'comments.user:id,name']);

        return [
            'case' => $this->presentDetail($c),
            'timeline' => $this->timeline($c),
            'qr' => \App\Support\PrintQrCode::dataUri(route('case-tracking.show', $c->id)),
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $generatedBy,
        ];
    }

    public function print(Request $request, CaseTicket $case_ticket)
    {
        $this->authorize('print', $case_ticket);
        log_activity(__('Case :number printed.', ['number' => $case_ticket->case_number]), $this->withRequestMeta([]), $case_ticket, 'Case Tracking');

        return view('case-ticket-print', $this->pdfPayload($case_ticket, $request->user()->name) + ['isPdf' => false]);
    }

    public function pdf(Request $request, CaseTicket $case_ticket)
    {
        $this->authorize('print', $case_ticket);
        log_activity(__('Case :number downloaded as PDF.', ['number' => $case_ticket->case_number]), $this->withRequestMeta([]), $case_ticket, 'Case Tracking');

        $pdf = Pdf::loadView('case-ticket-print', $this->pdfPayload($case_ticket, $request->user()->name) + ['isPdf' => true]);

        return $pdf->download($case_ticket->case_number . '.pdf');
    }

    public function export(Request $request)
    {
        $this->authorize('export', CaseTicket::class);

        $filters = $request->only(['search', 'status', 'case_category_id', 'case_priority_id', 'warehouse_id', 'assigned_to_user_id', 'created_by_user_id', 'date_from', 'date_to']);
        $cases = $this->scopedQuery($request, $request->user())->filter($filters)->orderByDesc('id')->limit(10000)->get();

        // No single case to attach this to (it's a list-level export) —
        // log_activity()'s performedOn() requires a real model, so this
        // bypasses that shared helper and calls Spatie's own activity()
        // directly instead of forcing a fake subject onto one arbitrary case.
        activity('Case Tracking')->withProperties($this->withRequestMeta($filters))->log(__('Case list exported (:count cases).', ['count' => $cases->count()]));

        $filename = 'case-tracking-' . now()->format('Ymd-His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($cases) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Case Number', 'Subject', 'Category', 'Priority', 'Status', 'Project', 'Created By', 'Assigned To', 'Created At']);
            foreach ($cases as $c) {
                fputcsv($out, [
                    $c->case_number, $c->subject, $c->category?->name, $c->priority?->name, $c->status,
                    $c->warehouse?->name, $c->createdBy?->name, $c->assignedTo?->name, optional($c->created_at)->toDateTimeString(),
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Print/PDF of whatever list the user is currently looking at — reuses
     * scopedQuery() so "My Cases" only ever prints/downloads that user's own
     * cases, never everyone's. Gated the same as the list itself (viewAny),
     * not the admin-only export ability — anyone can already see these rows
     * on screen, so a paper copy of the same thing isn't a bigger grant.
     */
    protected function listReportPayload(Request $request): array
    {
        $user = $request->user();
        $filters = $request->only(['search', 'status', 'case_category_id', 'case_priority_id', 'warehouse_id', 'assigned_to_user_id', 'created_by_user_id', 'date_from', 'date_to']);
        $mine = $request->boolean('mine') || $request->routeIs('case-tracking.mine');

        $cases = $this->scopedQuery($request, $user)->filter($filters)->orderByDesc('id')->limit(10000)->get();

        return [
            'title' => $mine ? 'My Cases' : 'Case Tracking - All Cases',
            'cases' => $cases->map(fn (CaseTicket $c) => $this->presentRow($c))->values(),
            'byStatus' => $cases->groupBy('status')->map->count(),
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $user->name,
        ];
    }

    public function printList(Request $request)
    {
        $this->authorize('viewAny', CaseTicket::class);

        return view('case-tracking-list-print', $this->listReportPayload($request) + ['isPdf' => false]);
    }

    public function pdfList(Request $request)
    {
        $this->authorize('viewAny', CaseTicket::class);

        $payload = $this->listReportPayload($request);
        $pdf = Pdf::loadView('case-tracking-list-print', $payload + ['isPdf' => true]);

        return $pdf->download('case-tracking-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function projectWarehouses()
    {
        return Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name']);
    }

    protected function nextCaseNumber(): string
    {
        $maxSuffix = (int) DB::table('case_tickets')
            ->selectRaw('MAX(CAST(SUBSTRING(case_number, 4) AS UNSIGNED)) as max_suffix')
            ->where('case_number', 'like', 'CT-%')
            ->value('max_suffix');

        return 'CT-' . str_pad((string) ($maxSuffix + 1), 6, '0', STR_PAD_LEFT);
    }

    /** IP/user-agent aren't captured by the shared log_activity() helper today — added here specifically since the module spec calls for it, without changing what any other module logs. */
    protected function withRequestMeta(array $properties): array
    {
        return $properties + [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
    }
}
