<?php

namespace App\Http\Controllers;

use App\Models\QrCode;
use App\Models\QrScan;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\User;
use App\Services\Qr\QrCodeService;
use App\Services\Qr\QrSettings;
use App\Support\Qr\QrRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/**
 * QR codes of records. Scanning a code only ever leads to the record's own page, and only for a signed-in person who may open it:
 * the code is a random token, it carries no database id and no record data, and the page it opens does its own permission checks.
 */
class QrCodeController extends Controller
{
    public function __construct(private QrCodeService $qr)
    {
        $this->setAbilities([]);
    }

    /** The scanner page: the camera, a photo, or a pasted link. */
    public function scanner(Request $request)
    {
        abort_unless($request->user()->can('scan-qr-codes'), 403);

        return Inertia::render('Qr/Scanner', [
            'can' => ['manage' => $request->user()->can('manage-qr-codes'), 'history' => $request->user()->can('read-qr-scans')],
            'recent' => $this->recentScans($request),
        ]);
    }

    /** /q/<token>: the same checks as the scanner's pre-check, then (when allowed) the scan is counted and the person is sent to the record. */
    public function scan(Request $request, string $token)
    {
        $user = $request->user();
        $r = $this->qr->resolveScan($user, $token);
        $this->qr->record($user, $r, $request->query('via') === 'scanner' ? 'scanner' : 'link', $token);

        if ($r['status'] !== 'allowed') {
            $kind = $r['status'] === 'invalid' ? null : __($r['entry']['label'] ?? '');

            return Inertia::render('Qr/Unavailable', ['status' => $r['status'], 'kind' => $kind])->toResponse($request)->setStatusCode($r['status'] === 'no_permission' ? 403 : 404);
        }
        $entry = $r['entry'];

        return redirect()->route($entry['route'], [$entry['param'] => $r['record']->getKey()]);
    }

    /** The scanner asks BEFORE opening: may this person open what the code points to? Refusals are logged here; an allowed scan is logged when it opens. */
    public function check(Request $request, string $token)
    {
        $user = $request->user();
        abort_unless($user->can('scan-qr-codes'), 403);
        $r = $this->qr->resolveScan($user, $token);
        if ($r['status'] !== 'allowed') {
            $this->qr->record($user, $r, 'scanner', $token);
        }
        $kind = in_array($r['status'], ['no_permission', 'disabled', 'unavailable'], true) ? __($r['entry']['label']) : null;

        return response()->json(['status' => $r['status'], 'kind' => $kind, 'go' => $r['status'] === 'allowed' ? route('qr.scan', ['token' => $token, 'via' => 'scanner']) : null]);
    }

    /** JSON for the QR button on a record's page: the code, its address and what it is for. The person must be able to open the record. */
    public function show(Request $request, string $type, int $id)
    {
        [$entry, $record] = $this->authorized($request, $type, $id);
        $code = $this->qr->tokenFor($type, $record, $request->user());
        $url = $this->qr->url($code);
        $s = QrSettings::get();
        $d = QrRegistry::describe($record);

        return response()->json([
            'type' => $type, 'kind' => __($entry['label']), 'reference' => $d['reference'], 'title' => $d['title'],
            'url' => $url, 'image' => $this->qr->dataUri($url, 10),
            'scans' => $code->scans_count, 'last_scanned_at' => optional($code->last_scanned_at)->diffForHumans(),
            'settings' => ['preview_px' => config('qr.preview_px')[$s['preview']], 'print_mm' => $s['print_mm'], 'copies' => $s['copies'], 'caption' => $s['caption']],
            'can' => ['print' => $request->user()->can('print-qr-codes'), 'manage' => $request->user()->can('manage-qr-codes')],
        ]);
    }

    public function download(Request $request, string $type, int $id)
    {
        abort_unless($request->user()->can('print-qr-codes'), 403);
        [$entry, $record] = $this->authorized($request, $type, $id);
        $code = $this->qr->tokenFor($type, $record, $request->user());
        $d = QrRegistry::describe($record);
        log_activity('QR Downloaded', ['kind' => $entry['label'], 'reference' => $d['reference']], $record, 'QR Codes');

        $name = 'QR-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $d['reference']) . '.png';

        return response($this->qr->png($this->qr->url($code), 12), 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }

    /** A printable label sheet: the code, its caption, as many copies as asked, sized in millimetres. */
    public function print(Request $request, string $type, int $id)
    {
        abort_unless($request->user()->can('print-qr-codes'), 403);
        [$entry, $record] = $this->authorized($request, $type, $id);
        $code = $this->qr->tokenFor($type, $record, $request->user());
        $s = QrSettings::get();
        $mm = max(25, min(80, (int) $request->query('mm', $s['print_mm'])));
        $copies = max(1, min(60, (int) $request->query('copies', $s['copies'])));
        $d = QrRegistry::describe($record);
        log_activity('QR Printed', ['kind' => $entry['label'], 'reference' => $d['reference'], 'copies' => $copies, 'mm' => $mm], $record, 'QR Codes');

        return response()->view('qr.label', [
            'image' => $this->qr->dataUri($this->qr->url($code), 12), 'mm' => $mm, 'copies' => $copies, 'columns' => $s['columns'],
            'caption' => $s['caption'], 'frame' => $s['frame'], 'kind' => __($entry['label']), 'reference' => $d['reference'], 'title' => $d['title'],
            'company' => $s['show_company'] ? optional($request->user()->account)->name : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    /** Replace a record's code (a printed copy that went astray): the old code stops working immediately. */
    public function regenerate(Request $request, string $type, int $id)
    {
        abort_unless($request->user()->can('manage-qr-codes'), 403);
        [$entry, $record] = $this->authorized($request, $type, $id);
        $old = $this->qr->tokenFor($type, $record, $request->user());
        $this->qr->revoke($old, $request->user());
        $new = $this->qr->tokenFor($type, $record, $request->user());
        $d = QrRegistry::describe($record);
        log_activity('QR Code Replaced', ['kind' => $entry['label'], 'reference' => $d['reference'], 'old' => '…' . substr($old->token, -4)], $record, 'QR Codes');

        return $this->show($request, $type, $id);
    }

    // ---- QR Scans: the history ------------------------------------------------------------------------------------------

    /** @return array{0: \Illuminate\Database\Eloquent\Builder, 1: array} the visible scans with the page's filters applied (the status filter is left out for the cards) */
    private function scanQuery(Request $request, bool $withStatus = true): array
    {
        $user = $request->user();
        $f = [
            'q' => trim((string) $request->query('q', '')), 'status' => (string) $request->query('status', ''), 'type' => (string) $request->query('type', ''),
            'user_id' => (string) $request->query('user_id', ''), 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''),
        ];
        $all = $user->can('view-all-qr-scans');
        $q = QrScan::query()->where('qr_scans.account_id', $user->account_id)
            ->when(! $all, fn ($w) => $w->where('qr_scans.user_id', $user->id))
            ->when($all && $f['user_id'] !== '' && ctype_digit($f['user_id']), fn ($w) => $w->where('qr_scans.user_id', (int) $f['user_id']))
            ->when($f['type'] !== '' && QrRegistry::get($f['type']), fn ($w) => $w->where('qr_scans.type', $f['type']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($w) => $w->where('qr_scans.created_at', '>=', \Carbon\Carbon::parse($f['from'])->startOfDay()))
            ->when($f['to'] !== '' && strtotime($f['to']), fn ($w) => $w->where('qr_scans.created_at', '<=', \Carbon\Carbon::parse($f['to'])->endOfDay()))
            ->when($f['q'] !== '', function ($w) use ($f, $all) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $f['q']) . '%';
                // anybody may search the references of their own allowed scans; the references of refused ones are searchable only by those who see them
                $w->where(fn ($x) => $x->where('qr_scans.kind', 'like', $like)->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like))
                    ->orWhere(fn ($r) => $r->where('qr_scans.reference', 'like', $like)->when(! $all, fn ($z) => $z->where('qr_scans.status', 'allowed'))));
            });
        $base = clone $q;
        if ($withStatus && isset(QrScan::STATUS[$f['status']])) {
            $q->where('qr_scans.status', $f['status']);
        }

        return [$q, $f, $base, $all];
    }

    /** scan id => the record's own page, for the scans whose record THIS viewer may open (one query per kind of record). */
    private function recordLinks($scans, $viewer): array
    {
        $out = [];
        foreach ($scans->filter(fn ($r) => $r->type && $r->record_id)->groupBy('type') as $type => $group) {
            $entry = QrRegistry::get($type);
            if (! $entry) {
                continue;
            }
            $ok = array_flip(QrRegistry::visibleIds($viewer, $type, $group->pluck('record_id')->unique()->all()));
            foreach ($group as $r) {
                if (isset($ok[(int) $r->record_id])) {
                    $out[$r->id] = route($entry['route'], [$entry['param'] => $r->record_id]);
                }
            }
        }

        return $out;
    }

    /** The code that was scanned, as a small PNG (the history shows it as an icon that zooms on hover). Only for scans the person may see. */
    public function scanImage(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user->can('read-qr-scans') || $user->can('view-all-qr-scans'), 403);
        $scan = QrScan::query()->where('account_id', $user->account_id)->when(! $user->can('view-all-qr-scans'), fn ($w) => $w->where('user_id', $user->id))->findOrFail($id);
        $code = $scan->qr_code_id ? QrCode::query()->find($scan->qr_code_id) : null;
        abort_unless($code, 404);

        return response($this->qr->png($this->qr->url($code), 10), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=86400']);
    }

    private function scanRow(QrScan $r, $viewer, int $no): array
    {
        $reference = $this->shownReference($r, $viewer);

        return [
            'no' => $no, 'id' => $r->id, 'user' => optional($r->user)->name ?: '-', 'date' => $r->created_at->format('d M Y'), 'time' => $r->created_at->format('H:i:s'),
            'module' => $r->kind ? __($r->kind) : '-', 'group' => $r->type ? __(QrRegistry::group($r->type)) : null,
            'document' => $reference ?: ($r->status === 'invalid' ? '-' : null), 'status' => $r->status, 'status_label' => __(QrScan::STATUS[$r->status] ?? $r->status), 'via' => $r->via,
        ];
    }

    public function scans(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('read-qr-scans') || $user->can('view-all-qr-scans'), 403);
        [$q, $f, $base, $all] = $this->scanQuery($request);
        $page = (clone $q)->with('user:id,name')->orderByDesc('qr_scans.created_at')->orderByDesc('qr_scans.id')->paginate(15)->withQueryString();
        $start = ($page->currentPage() - 1) * $page->perPage();
        $links = $this->recordLinks($page->getCollection(), $user);
        $page->setCollection($page->getCollection()->values()->map(fn ($r, $i) => $this->scanRow($r, $user, $start + $i + 1) + ['url' => $links[$r->id] ?? null, 'qr' => $r->qr_code_id ? route('qr-codes.scans.image', $r->id) : null]));

        $c = (clone $base)->toBase()->selectRaw("COUNT(*) as total, COALESCE(SUM(status = 'allowed'),0) as allowed, COALESCE(SUM(status = 'no_permission'),0) as denied, COALESCE(SUM(status IN ('unavailable','disabled','invalid')),0) as other, COUNT(DISTINCT user_id) as people, COALESCE(SUM(created_at >= ?),0) as today", [now()->startOfDay()])->first();
        $types = QrScan::query()->where('account_id', $user->account_id)->when(! $all, fn ($w) => $w->where('user_id', $user->id))->whereNotNull('type')->distinct()->pluck('type')
            ->map(fn ($t) => ['value' => $t, 'label' => __(QrRegistry::get($t)['label'] ?? $t)])->sortBy('label')->values();

        return Inertia::render('Qr/Scans', [
            'rows' => paginated_response($page),
            'stats' => ['total' => (int) $c->total, 'allowed' => (int) $c->allowed, 'denied' => (int) $c->denied, 'other' => (int) $c->other, 'people' => (int) $c->people, 'today' => (int) $c->today],
            'filters' => $f,
            'statuses' => collect(QrScan::STATUS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'types' => $types,
            'users' => $all ? User::query()->where('account_id', $user->account_id)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values() : [],
            'can' => ['all' => $all, 'print' => $user->can('print-qr-scans'), 'scanner' => $user->can('scan-qr-codes')],
        ]);
    }

    /** The scan history as a WIMS report (the same layout the other reports use), for the print page and the PDF. */
    private function scanReport(Request $request, string $format): array
    {
        $user = $request->user();
        abort_unless($user->can('print-qr-scans') && ($user->can('read-qr-scans') || $user->can('view-all-qr-scans')), 403);
        [$q, $f, $base, $all] = $this->scanQuery($request);
        $total = (clone $q)->count();
        $rows = (clone $q)->with('user:id,name')->orderByDesc('qr_scans.created_at')->orderByDesc('qr_scans.id')->limit(2000)->get()->values()->map(function ($r, $i) use ($user) {
            $x = $this->scanRow($r, $user, $i + 1);

            return ['no' => $x['no'], 'user' => $x['user'], 'when' => $x['date'] . ' ' . $x['time'], 'module' => $x['module'], 'document' => $x['document'] ?: '-', 'status' => $x['status_label']];
        })->all();
        $c = (clone $base)->toBase()->selectRaw("COUNT(*) as total, COALESCE(SUM(status = 'allowed'),0) as allowed, COALESCE(SUM(status = 'no_permission'),0) as denied, COALESCE(SUM(status IN ('unavailable','disabled','invalid')),0) as other, COUNT(DISTINCT user_id) as people")->first();
        $filters = array_filter([
            __('Person') => $all ? ($f['user_id'] !== '' ? optional(User::find((int) $f['user_id']))->name : __('Everybody')) : __('Only my scans'),
            __('Module') => $f['type'] !== '' ? __(QrRegistry::get($f['type'])['label'] ?? '') : __('All'),
            __('Status') => isset(QrScan::STATUS[$f['status']]) ? __(QrScan::STATUS[$f['status']]) : __('All'),
            __('From') => $f['from'] ?: __('Start'), __('To') => $f['to'] ?: __('Today'), __('Search') => $f['q'] ?: null,
        ]);
        $reference = 'QRS-' . now()->format('Ymd-His');
        log_activity('QR Scans ' . ($format === 'pdf' ? 'downloaded' : 'printed'), ['reference' => $reference, 'rows' => $total, 'filters' => $filters], null, 'QR Codes');

        return [[
            'type' => 'qr_scans', 'title' => __('QR Scan History'), 'reference' => $reference, 'subtitle' => __('Every scan of a record QR code, with whether the person was allowed to open it.'), 'filters' => $filters,
            'tiles' => [['label' => __('Scans'), 'value' => (int) $c->total], ['label' => __('Allowed'), 'value' => (int) $c->allowed], ['label' => __('No permission'), 'value' => (int) $c->denied], ['label' => __('Other refusals'), 'value' => (int) $c->other], ['label' => __('People'), 'value' => (int) $c->people]],
            'sections' => [], 'table_title' => __('Scans'), 'truncated' => $total > count($rows), 'total_rows' => $total,
            'columns' => [['key' => 'no', 'label' => __('No'), 'align' => 'right'], ['key' => 'user', 'label' => __('User'), 'align' => 'left'], ['key' => 'when', 'label' => __('Date and time'), 'align' => 'left'], ['key' => 'module', 'label' => __('Module'), 'align' => 'left'], ['key' => 'document', 'label' => __('Document scanned'), 'align' => 'left'], ['key' => 'status', 'label' => __('Status'), 'align' => 'left']],
            'rows' => $rows,
            'notes' => [__('Allowed: the person could open the record and was taken to it.'), __('No permission: the person is not allowed to open that kind of record, or the record is outside their projects or confidentiality level. They were told so and nothing opened.'), __('Not available: the record was deleted. Module off: QR codes for that module are switched off. Invalid code: the code is unknown or was replaced.'), __('Only the scans you are allowed to see are listed.')],
        ], $user];
    }

    public function scansPrint(Request $request)
    {
        [$report, $user] = $this->scanReport($request, 'print');

        return view('signatories.report-print', ['report' => $report, 'landscape' => false, 'isPdf' => false, 'autoPrint' => true, 'companyName' => $this->company($user), 'printedBy' => $user->name, 'printedAt' => now()->format('d M Y, H:i')]);
    }

    public function scansPdf(Request $request)
    {
        [$report, $user] = $this->scanReport($request, 'pdf');
        $pdf = Pdf::loadView('signatories.report-print', ['report' => $report, 'landscape' => false, 'isPdf' => true, 'autoPrint' => false, 'companyName' => $this->company($user), 'printedBy' => $user->name, 'printedAt' => now()->format('d M Y, H:i')])->setPaper('a4', 'portrait');
        $pdf->render();
        $d = $pdf->getDomPDF();
        $c = $d->getCanvas();
        $c->page_text($c->get_width() - 95, $c->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('Helvetica'), 8.5, [0.61, 0.64, 0.69]);

        return $pdf->download('qr-scans-' . now()->format('Ymd-His') . '.pdf');
    }

    private function company($user): string
    {
        return preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional($user->account)->name);
    }

    // ---- QR Activation: which modules carry QR codes -------------------------------------------------------------------------

    public function activation(Request $request)
    {
        abort_unless($request->user()->can('activate-qr-codes'), 403);
        $off = QrSettings::get()['disabled_kinds'];
        $codes = QrCode::query()->whereNull('revoked_at')->selectRaw('type, COUNT(*) as n, COALESCE(SUM(scans_count),0) as scans')->groupBy('type')->get()->keyBy('type');
        $groups = [];
        foreach (QrRegistry::GROUPS as $name => $types) {
            $groups[] = ['name' => __($name), 'items' => collect($types)->filter(fn ($t) => QrRegistry::get($t))->map(fn ($t) => [
                'type' => $t, 'icon' => QrRegistry::ICONS[$t] ?? 'qr-code', 'label' => __(QrRegistry::get($t)['label']), 'active' => ! in_array($t, $off, true),
                'codes' => (int) ($codes[$t]->n ?? 0), 'scans' => (int) ($codes[$t]->scans ?? 0),
            ])->values()->all()];
        }
        $total = count(QrRegistry::all());

        return Inertia::render('Qr/Activation', ['groups' => $groups, 'stats' => ['kinds' => $total, 'on' => $total - count($off), 'off' => count($off)]]);
    }

    /** Switch one kind, one group, or everything on / off. Body: kinds (list of types) and active (bool). */
    public function activationUpdate(Request $request)
    {
        abort_unless($request->user()->can('activate-qr-codes'), 403);
        $data = $request->validate(['kinds' => 'required|array|min:1', 'kinds.*' => 'string', 'active' => 'required|boolean']);
        $kinds = array_values(array_intersect($data['kinds'], array_keys(QrRegistry::all())));
        abort_if($kinds === [], 422);
        $off = QrSettings::get()['disabled_kinds'];
        $new = $data['active'] ? array_values(array_diff($off, $kinds)) : array_values(array_unique(array_merge($off, $kinds)));
        QrSettings::save(['disabled_kinds' => $new]);
        log_activity('QR Activation Changed', ['active' => (bool) $data['active'], 'kinds' => $kinds], null, 'QR Codes');

        return back()->with('message', $data['active'] ? __('QR codes switched on.') : __('QR codes switched off.'));
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()->can('manage-qr-codes'), 403);
        $byType = QrCode::query()->whereNull('revoked_at')->selectRaw('type, COUNT(*) as codes, COALESCE(SUM(scans_count), 0) as scans, MAX(last_scanned_at) as last_at')->groupBy('type')->get()
            ->map(fn ($r) => ['type' => $r->type, 'label' => __(QrRegistry::get($r->type)['label'] ?? $r->type), 'codes' => (int) $r->codes, 'scans' => (int) $r->scans, 'last' => $r->last_at ? \Carbon\Carbon::parse($r->last_at)->diffForHumans() : null])
            ->sortByDesc('scans')->values();

        return Inertia::render('Qr/Settings', [
            // NOT called `settings`: that name is the app-wide shared prop (sidebar style, base URL...) and would replace it on this page
            'qr' => QrSettings::get(),
            'stats' => ['codes' => QrCode::query()->whereNull('revoked_at')->count(), 'replaced' => QrCode::query()->whereNotNull('revoked_at')->count(), 'scans' => (int) QrCode::query()->sum('scans_count'), 'kinds' => count(QrRegistry::all())],
            'byType' => $byType,
            'recent' => $this->recentScans($request, 12, true),
            'preview' => $this->qr->dataUri(url('/q/preview-sample-code'), 8),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->can('manage-qr-codes'), 403);
        $data = $request->validate([
            'enabled' => 'required|boolean', 'on_prints' => 'required|boolean', 'caption' => 'required|boolean', 'show_company' => 'required|boolean', 'frame' => 'required|boolean',
            'preview' => 'required|in:small,medium,large', 'print_mm' => 'required|integer|min:25|max:80', 'copies' => 'required|integer|min:1|max:60', 'columns' => 'required|integer|min:1|max:6',
            'error_correction' => 'required|in:L,M,Q,H', 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $before = QrSettings::get();
        QrSettings::save($data);
        log_activity('QR Settings Changed', ['changed' => array_keys(array_diff_assoc($data, $before))], null, 'QR Codes');

        return back()->with('message', __('QR code settings saved.'));
    }

    /** The record and its kind, for a person who may open that record: otherwise a plain 404 (never a hint that it exists). */
    private function authorized(Request $request, string $type, int $id): array
    {
        $entry = QrRegistry::get($type);
        abort_unless($entry && QrCodeService::enabled() && QrRegistry::active($type), 404);
        abort_unless($request->user()->can('scan-qr-codes') && QrRegistry::canOpen($request->user(), $entry), 403);
        $record = QrRegistry::findFor($request->user(), $type, $id);
        abort_unless($record, 404);

        return [$entry, $record];
    }

    /** The person's own latest scans (everyone's for an administrator), from the scan history. */
    private function recentScans(Request $request, int $limit = 6, bool $all = false): array
    {
        $q = QrScan::query()->with('user:id,name')->where('account_id', $request->user()->account_id)->orderByDesc('created_at')->orderByDesc('id')->limit($limit);
        if (! $all) {
            $q->where('user_id', $request->user()->id);
        }

        return $q->get()->map(fn ($r) => [
            'kind' => $r->kind ? __($r->kind) : '', 'reference' => $this->shownReference($r, $request->user()), 'status' => $r->status, 'status_label' => __(QrScan::STATUS[$r->status] ?? $r->status),
            'at' => $r->created_at->diffForHumans(), 'by' => $all ? optional($r->user)->name : null,
        ])->all();
    }

    /** The reference of a scanned record, hidden from anybody who could not open that record unless they may see everybody's scans. */
    private function shownReference(QrScan $r, $viewer): ?string
    {
        if ($r->status === 'allowed' || $r->status === 'invalid' || $viewer->can('view-all-qr-scans')) {
            return $r->reference;
        }

        return null;
    }
}
