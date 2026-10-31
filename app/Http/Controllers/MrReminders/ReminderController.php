<?php

namespace App\Http\Controllers\MrReminders;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestReminder;
use App\Models\MaterialRequestReminderDelivery;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MrReminders\ReminderSender;
use App\Services\MrReminders\ReminderSettings;
use App\Support\MaterialRequestVisibility;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/** Reminders on approved Material Requests that have not reached their final status: send one, see the history of a request, and the page / report of every reminder and who was told. */
class ReminderController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    /** The window of one request: its details, who would be told, and the history of reminders. */
    public function form(Request $request, MaterialRequest $material_request)
    {
        $this->need('read-mr-reminders');
        $this->authorize('view', $material_request);
        $me = $request->user();
        $mr = $material_request->loadMissing(['warehouse:id,name', 'user:id,name', 'items']);
        $audience = ReminderSender::audience((int) $mr->account_id ?: (int) $me->account_id, null, false, $mr);

        return response()->json([
            'request' => ['id' => $mr->id, 'reference' => $mr->reference, 'status' => MaterialRequest::statusLabel($mr->status), 'project' => optional($mr->warehouse)->name, 'requester' => optional($mr->user)->name,
                'date' => optional($mr->date_requested)->format('d M Y'), 'items' => $mr->items->count()],
            'eligible' => ReminderSender::eligible($mr), 'canSend' => ReminderSender::canSend($me, $mr), 'approvalChase' => ReminderSender::isApprovalChase($mr),
            'audience' => $audience->map(fn ($u) => ['name' => $u->name, 'me' => $u->id === $me->id])->values(),
            'channels' => ['email' => ReminderSettings::email(), 'sms' => ReminderSettings::sms(), 'app' => ReminderSettings::app()],
            'limits' => ['files' => ReminderSender::MAX_FILES, 'mb' => ReminderSender::MAX_KB / 1024],
            'history' => $mr->reminders()->with(['sender:id,name', 'attachments'])->latest('id')->get()->map(fn ($r) => ReminderSender::present($r))->values(),
        ]);
    }

    public function store(Request $request, MaterialRequest $material_request)
    {
        $me = $request->user();
        $this->need('send-mr-reminders');
        $this->authorize('view', $material_request);
        $d = $request->validate([
            'note' => 'nullable|string|max:2000', 'leave_me_out' => 'nullable|boolean', 'files' => 'nullable|array|max:' . ReminderSender::MAX_FILES, 'files.*' => 'file|mimes:pdf,doc,docx,jpg,jpeg,png|max:' . ReminderSender::MAX_KB,
        ], [
            'files.max' => __('A reminder holds up to :max files.', ['max' => ReminderSender::MAX_FILES]), 'files.*.max' => __('Each file can be up to 5 MB.'),
            'files.*.mimes' => __('Only PDF, DOC, DOCX, JPG or PNG files can be attached.'), 'files.*.uploaded' => __('A file could not be uploaded. Keep each file under 5 MB.'),
        ]);
        if (! ReminderSender::canSend($me, $material_request)) {
            return response()->json(['message' => __('A reminder can only be sent for a request that is waiting for approval, or approved but not yet at its final status (Cleared).')], 422);
        }
        $leaveOut = $request->boolean('leave_me_out');
        if (ReminderSender::audience((int) $material_request->account_id ?: (int) $me->account_id, $me, $leaveOut, $material_request)->isEmpty()) {
            return response()->json(['message' => ReminderSender::isApprovalChase($material_request)
                ? __('Nobody else can approve this request, so there is nobody to remind.')
                : __('Nobody is set to receive the reminders. A Super Admin chooses them on the All Requests Reminders page.')], 422);
        }
        $r = ReminderSender::send($material_request, $me, $d['note'] ?? null, $request->file('files', []), $leaveOut);
        $c = $r['counts'];

        return response()->json(['message' => __('Reminder sent to :n people: :e emails, :s SMS and :a app alerts.', ['n' => $c['people'], 'e' => $c['email']['sent'], 's' => $c['sms']['sent'], 'a' => $c['app']['sent']]),
            'reminder' => ReminderSender::present($r['reminder']), 'counts' => $c]);
    }

    /** All Requests Reminders. */
    public function index(Request $request)
    {
        $this->need('read-mr-reminders');
        $me = $request->user();
        $f = $this->filters($request);
        $query = $this->query($me, $f)->with(['sender:id,name', 'attachments', 'materialRequest.warehouse:id,name'])->latest('id');
        $rows = $query->paginate(15)->withQueryString();
        $ids = $rows->getCollection()->pluck('id');
        $dels = MaterialRequestReminderDelivery::query()->whereIn('reminder_id', $ids)->orderBy('name')->orderBy('channel')->get()->groupBy('reminder_id');
        $base = $this->query($me, []);
        $stats = [
            'total' => (clone $base)->count(), 'requests' => (clone $base)->distinct()->count('material_request_id'),
            'week' => (clone $base)->where('created_at', '>=', now()->subDays(7))->count(),
            'failed' => (clone $base)->whereHas('deliveries', fn ($q) => $q->where('status', 'Failed'))->count(),
        ];
        $account = (int) $me->account_id;

        return Inertia::render('MrReminders/Index', [
            'rows' => ['data' => $rows->getCollection()->map(function ($r) use ($dels) {
                $mr = $r->materialRequest;
                $o = ReminderSender::present($r, false);
                $d = $dels[$r->id] ?? collect();
                $o['counts'] = $this->countsOf($d);
                $o['people'] = $d->map(fn ($x) => ['name' => $x->name, 'channel' => $x->channel, 'address' => $x->address, 'status' => $x->status, 'error' => $x->error])->values();

                return $o + ['request_id' => $r->material_request_id, 'project' => optional(optional($mr)->warehouse)->name, 'current_status' => $mr ? MaterialRequest::statusLabel($mr->status) : null, 'current_raw' => $mr ? $mr->status : null, 'cleared' => $mr ? $mr->isFinal() : false];
            })->values(),
                'links' => $rows->linkCollection()->map(fn ($l) => ['url' => $l['url'], 'label' => $l['label'], 'active' => $l['active']])->values(), 'from' => $rows->firstItem(), 'to' => $rows->lastItem(), 'total' => $rows->total()],
            'filters' => $f, 'stats' => $stats,
            // with the category and the finished date so the list reads in sections (Main Warehouses, Projects, Others) like every other project dropdown
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name, 'status' => $w->status, 'completed_at' => $w->completed_at])->values(),
            'senders' => User::withoutGlobalScope('account')->whereIn('id', MaterialRequestReminder::query()->distinct()->pluck('created_by_user_id'))->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values(),
            'recipients' => User::withoutGlobalScope('account')->whereIn('id', MaterialRequestReminderDelivery::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values(),
            'statuses' => collect(ReminderSender::eligibleStatuses())->push(MaterialRequest::STATUS_CLEARED)->map(fn ($s) => ['value' => $s, 'label' => MaterialRequest::statusLabel($s)])->values(),
            'mrSettings' => $me->can('manage-mr-reminder-settings') ? ReminderSettings::forPage($account) + ['audience' => ReminderSettings::recipients($account)->map(fn ($u) => $u->name)->values()] : null,
            'can' => ['print' => $me->can('print-mr-reminders'), 'settings' => $me->can('manage-mr-reminder-settings')],
        ]);
    }

    public function settings(Request $request)
    {
        $this->need('manage-mr-reminder-settings');
        $account = (int) $request->user()->account_id;
        $d = $request->validate(['roles' => 'nullable|array', 'roles.*' => 'string|max:120', 'users' => 'nullable|array', 'users.*' => 'integer', 'email' => 'required|boolean', 'sms' => 'required|boolean', 'app' => 'required|boolean']);
        $roles = \Spatie\Permission\Models\Role::query()->whereIn('name', $d['roles'] ?? [])->pluck('name')->values()->all();
        $users = User::withoutGlobalScope('account')->where('account_id', $account)->whereIn('id', $d['users'] ?? [])->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        if (! $roles && ! $users) {
            return back()->withErrors(['roles' => __('Choose at least one role or one user to receive the reminders.')]);
        }
        $before = ['roles' => ReminderSettings::roles(), 'users' => ReminderSettings::userIds(), 'email' => ReminderSettings::email(), 'sms' => ReminderSettings::sms(), 'app' => ReminderSettings::app()];
        ReminderSettings::save(['mrr_roles' => json_encode($roles), 'mrr_users' => json_encode($users), 'mrr_email' => $d['email'] ? '1' : '0', 'mrr_sms' => $d['sms'] ? '1' : '0', 'mrr_app' => $d['app'] ? '1' : '0'], $account);
        log_activity('Material request reminder recipients changed', ['before' => $before, 'after' => ['roles' => $roles, 'users' => $users, 'email' => (bool) $d['email'], 'sms' => (bool) $d['sms'], 'app' => (bool) $d['app']]], null, 'MR Reminders');

        return back()->with('message', __('The reminder recipients were saved.'));
    }

    public function print(Request $request)
    {
        return $this->pdfOf($this->report($request, 'print'))->stream($this->file('pdf'));
    }

    public function pdf(Request $request)
    {
        return $this->pdfOf($this->report($request, 'pdf'))->download($this->file('pdf'));
    }

    public function csv(Request $request)
    {
        $r = $this->report($request, 'csv');
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;

        return response()->streamDownload(function () use ($r, $safe) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, array_column($r['columns'], 'label'));
            foreach ($r['raw'] as $row) {
                fputcsv($o, array_map(fn ($c) => $safe($row[$c['key']] ?? ''), $r['columns']));
            }
            fclose($o);
        }, $this->file('csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function file(string $ext): string
    {
        return 'material-request-reminders-' . now()->format('Ymd-His') . '.' . $ext;
    }

    private function filters(Request $request): array
    {
        $f = [];
        foreach (['q', 'warehouse_id', 'status', 'sender', 'recipient', 'channel', 'result', 'from', 'to'] as $k) {
            $v = trim((string) $request->query($k, ''));
            if ($v !== '') {
                $f[$k] = $v;
            }
        }

        return $f;
    }

    private function query(User $me, array $f)
    {
        $visible = MaterialRequestVisibility::scopeQueryFor(MaterialRequest::query(), $me)->select('material_requests.id');
        $like = fn ($v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';

        return MaterialRequestReminder::query()->whereIn('material_request_id', $visible)
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('reference', 'like', $like($v))->orWhere('note', 'like', $like($v))))
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->whereIn('material_request_id', MaterialRequest::query()->where('warehouse_id', (int) $v)->select('id')))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->whereIn('material_request_id', MaterialRequest::query()->where('status', $v)->select('id')))
            ->when($f['sender'] ?? null, fn ($q, $v) => $q->where('created_by_user_id', (int) $v))
            ->when(($f['recipient'] ?? null) || ($f['channel'] ?? null) || ($f['result'] ?? null), fn ($q) => $q->whereHas('deliveries', fn ($d) => $d
                ->when($f['recipient'] ?? null, fn ($x, $v) => $x->where('user_id', (int) $v))->when($f['channel'] ?? null, fn ($x, $v) => $x->where('channel', $v))->when($f['result'] ?? null, fn ($x, $v) => $x->where('status', $v))))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v, config('app.timezone'))->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v, config('app.timezone'))->endOfDay()));
    }

    private function countsOf($d): array
    {
        $o = ['people' => $d->pluck('user_id')->unique()->count()];
        foreach (['email', 'sms', 'app'] as $ch) {
            $g = $d->where('channel', $ch);
            $o[$ch] = ['sent' => $g->where('status', 'Sent')->count(), 'failed' => $g->where('status', 'Failed')->count(), 'skipped' => $g->where('status', 'Skipped')->count(), 'total' => $g->count()];
        }

        return $o;
    }

    /** One line per person and channel of every reminder that matches the filters. */
    private function report(Request $request, string $format): array
    {
        $this->need('print-mr-reminders');
        $me = $request->user();
        $f = $this->filters($request);
        $reminders = $this->query($me, $f)->with(['sender:id,name', 'materialRequest.warehouse:id,name'])->latest('id')->limit(2000)->get();
        $dels = MaterialRequestReminderDelivery::query()->whereIn('reminder_id', $reminders->pluck('id'))
            ->when($f['recipient'] ?? null, fn ($q, $v) => $q->where('user_id', (int) $v))->when($f['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))->when($f['result'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name')->orderBy('channel')->get()->groupBy('reminder_id');
        $label = ['email' => __('Email'), 'sms' => __('SMS'), 'app' => __('App alert')];
        $tz = config('app.timezone');
        $rows = [];
        foreach ($reminders as $r) {
            $mr = $r->materialRequest;
            $base = ['date' => $r->created_at->timezone($tz)->format('d M Y H:i'), 'reference' => $r->reference, 'project' => optional(optional($mr)->warehouse)->name, 'status' => MaterialRequest::statusLabel($r->status_at_send),
                'by' => optional($r->sender)->name, 'note' => (string) $r->note];
            foreach ($dels[$r->id] ?? [] as $d) {
                $rows[] = $base + ['person' => $d->name, 'channel' => $label[$d->channel] ?? $d->channel, 'address' => (string) $d->address, 'result' => $d->status, 'problem' => (string) $d->error];
            }
        }
        $cols = [['key' => 'date', 'label' => __('Date'), 'align' => 'left'], ['key' => 'reference', 'label' => __('Request'), 'align' => 'left'], ['key' => 'project', 'label' => __('Project / Site'), 'align' => 'left'],
            ['key' => 'status', 'label' => __('Status then'), 'align' => 'left'], ['key' => 'by', 'label' => __('Reminder from'), 'align' => 'left'], ['key' => 'person', 'label' => __('Told'), 'align' => 'left'],
            ['key' => 'channel', 'label' => __('How'), 'align' => 'left'], ['key' => 'address', 'label' => __('Email / Mobile'), 'align' => 'left'], ['key' => 'result', 'label' => __('Result'), 'align' => 'left'],
            ['key' => 'note', 'label' => __('Notes & Remarks'), 'align' => 'left'], ['key' => 'problem', 'label' => __('Problem'), 'align' => 'left']];
        $names = ['warehouse_id' => __('Project / Site'), 'status' => __('Request status'), 'sender' => __('Reminder from'), 'recipient' => __('Told'), 'channel' => __('How'), 'result' => __('Result'), 'from' => __('From'), 'to' => __('To'), 'q' => __('Search')];
        $shown = [];
        foreach ($f as $k => $v) {
            $shown[$names[$k]] = match ($k) {
                'warehouse_id' => Warehouse::query()->whereKey((int) $v)->value('name') ?? $v, 'sender', 'recipient' => User::withoutGlobalScope('account')->whereKey((int) $v)->value('name') ?? $v,
                'channel' => $label[$v] ?? $v, default => $v,
            };
        }
        $sent = collect($rows)->where('result', 'Sent')->count();
        $r = [
            'type' => 'mr_reminders', 'title' => __('Material Request Reminders'), 'reference' => 'MRR-' . now()->format('Ymd-His'), 'subtitle' => __('Who was reminded about which approved Material Request, and how.'), 'table_title' => __('Reminders and who was told'),
            'filters' => $shown, 'tiles' => [['label' => __('Reminders'), 'value' => $reminders->count()], ['label' => __('Requests'), 'value' => $reminders->pluck('material_request_id')->unique()->count()], ['label' => __('Messages sent'), 'value' => $sent . ' / ' . count($rows)],
                ['label' => __('Failed'), 'value' => collect($rows)->where('result', 'Failed')->count()]],
            'columns' => $cols, 'rows' => $rows, 'raw' => $rows, 'total_rows' => count($rows), 'truncated' => $reminders->count() >= 2000, 'sections' => [],
            'notes' => [__('"Sent" means the mail server, the SMS gateway or the WIMS app alert accepted the message. WIMS does not receive a read receipt.'), __('A person without an email address or mobile number is shown as Skipped.')],
        ];
        log_activity('Material request reminders exported', ['format' => $format, 'rows' => count($rows), 'filters' => $f, 'reference' => $r['reference']], null, 'MR Reminders');

        return $r;
    }

    private function pdfOf(array $r)
    {
        $pdf = Pdf::loadView('signatories.report-print', ['report' => $r, 'landscape' => true, 'isPdf' => true, 'autoPrint' => false, 'signatures' => ['enabled' => false],
            'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional(auth()->user()->account)->name), 'printedBy' => auth()->user()->name, 'printedAt' => now()->format('d M Y, H:i')])
            ->setPaper('a4', 'landscape');
        $pdf->render();
        $d = $pdf->getDomPDF();
        $c = $d->getCanvas();
        $c->page_text($c->get_width() - 95, $c->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('Helvetica'), 8.5, [0.61, 0.64, 0.69]);

        return $pdf;
    }
}
