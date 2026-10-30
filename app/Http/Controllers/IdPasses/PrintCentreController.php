<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IdPasses\Concerns\Guards;
use App\Jobs\GenerateIdPassBatch;
use App\Models\IdPassBatch;
use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\IdPassPrint;
use App\Models\User;
use App\Services\IdPass\BatchRunner;
use App\Services\IdPass\CardPdf;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassQuery;
use App\Services\IdPass\IdPassSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The Print Centre: choose categories, filter, tick or take everything that matches, see how many cards and sheets it makes, generate the PDF (A4 sheets or card size), and read the print history. */
class PrintCentreController extends Controller
{
    use Guards;

    /** How many of the selected cards were printed before (a reason is needed to print them again). */
    private int $reprints = 0;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function centre(): void
    {
        $this->module();
        $this->feature('id_print_centre');
        $this->need('access-id-print-centre');
    }

    /** The kinds this person may put in a batch of this action (print or pdf). */
    private function kindsFor(string $action): array
    {
        $u = auth()->user();
        $perm = $action === 'pdf' ? 'download' : 'print';

        return array_values(array_filter(array_keys(IdPassKinds::ALL), fn ($k) => IdPassKinds::can($k, $perm, $u)));
    }

    public function index(Request $request)
    {
        $this->centre();
        $u = $request->user();
        $kinds = IdPassKinds::readable($u);
        $cats = IdPassCategory::query()->whereIn('kind', $kinds ?: ['-'])->orderBy('sort_order')->get()->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'kind' => $c->kind])->values();
        $batches = IdPassBatch::query()->with('user:id,name')->where(fn ($q) => $u->can('read-id-print-history') ? $q : $q->where('user_id', $u->id))->latest('id')->limit(8)->get()->map(fn ($b) => $this->batchRow($b));

        return Inertia::render('IdPasses/PrintCentre', [
            'kinds' => collect($kinds)->map(fn ($k) => ['value' => $k, 'label' => __(IdPassKinds::get($k)['label']), 'tone' => IdPassKinds::get($k)['tone'], 'print' => IdPassKinds::can($k, 'print'), 'download' => IdPassKinds::can($k, 'download')])->values(),
            'categories' => $cats, 'statuses' => collect(IdPassCard::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(), 'batches' => $batches,
            'can' => ['print' => $u->can('batch-print-ids') && \App\Services\FeatureManager::canAccess('id_batch_print', $u), 'pdf' => $u->can('batch-export-ids') && \App\Services\FeatureManager::canAccess('id_batch_print', $u) && \App\Services\FeatureManager::canAccess('id_pdf_export', $u),
                'history' => $u->can('read-id-print-history')],
            'limits' => ['max' => BatchRunner::MAX, 'part' => BatchRunner::PART, 'sync' => IdPassSettings::syncBatch()],
        ]);
    }

    private function batchRow(IdPassBatch $b): array
    {
        return ['id' => $b->id, 'reference' => $b->reference, 'action' => $b->action, 'layout' => $b->layout, 'cards' => (int) $b->cards, 'sheets' => (int) $b->sheets, 'status' => $b->status, 'progress' => (int) $b->progress, 'error' => $b->error,
            'parts' => (int) (($b->filters['parts'] ?? 0)), 'by' => optional($b->user)->name, 'at' => $b->created_at?->timezone(config('app.timezone'))->toIso8601String()];
    }

    /** The cards to choose from (a page of a list with the same filters as the lists). */
    public function cards(Request $request)
    {
        $this->centre();
        $action = $request->query('action') === 'pdf' ? 'pdf' : 'print';
        $kinds = $this->kindsFor($action);
        $f = IdPassQuery::filters($request->query());
        $q = IdPassCard::query()->with('category');
        IdPassQuery::apply($q, $f, $kinds);
        IdPassQuery::sort($q, 'category', 'asc');
        $p = $q->orderBy('number')->paginate(30)->withQueryString();

        return response()->json(['data' => $p->getCollection()->map(fn ($c) => IdPassQuery::row($c))->values(), 'total' => $p->total(), 'page' => $p->currentPage(), 'last' => $p->lastPage()]);
    }

    /** The ids of a request: the ticked ones, or everything the filters match; never more than the person may print, never more than the limit. */
    private function selection(Request $request, string $action): array
    {
        $d = $request->validate(['mode' => 'required|in:ids,filter', 'ids' => 'nullable|array|max:2000', 'ids.*' => 'integer', 'filters' => 'nullable|array', 'layout' => 'required|in:card,a4_side,a4_duplex', 'orientation' => 'nullable|in:portrait,landscape']);
        $kinds = $this->kindsFor($action);
        $q = IdPassCard::query()->whereIn('kind', $kinds ?: ['-']);
        if ($d['mode'] === 'ids') {
            $q->whereIn('id', array_map('intval', (array) ($d['ids'] ?? [])) ?: [0]);
        } else {
            IdPassQuery::apply($q, IdPassQuery::filters((array) ($d['filters'] ?? [])), $kinds);
        }
        $matched = (clone $q)->count();
        $today = \Carbon\Carbon::today(config('app.timezone'))->toDateString();
        $active = (clone $q)->where('status', IdPassCard::ACTIVE)->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $today))->limit(BatchRunner::MAX + 1)->pluck('id')->all();
        $landscape = ($d['orientation'] ?? 'landscape') !== 'portrait';
        if ($d['layout'] === 'a4_duplex') {
            $landscape = false;
        }
        $this->reprints = $active ? IdPassCard::query()->whereIn('id', array_slice($active, 0, BatchRunner::MAX))->where('print_count', '>', 0)->count() : 0;

        return [$d, $matched, $active, $landscape];
    }

    private function authorise(string $action): void
    {
        $this->centre();
        $u = auth()->user();
        if ($action === 'pdf') {
            abort_unless($u->can('batch-export-ids'), 403);
            $this->feature('id_pdf_export');
        } else {
            abort_unless($u->can('batch-print-ids'), 403);
        }
        $this->feature('id_batch_print');
    }

    public function preview(Request $request)
    {
        $action = $request->input('action') === 'pdf' ? 'pdf' : 'print';
        $this->authorise($action);
        [$d, $matched, $active, $landscape] = $this->selection($request, $action);
        $n = count($active);

        return response()->json(['matched' => $matched, 'cards' => min($n, BatchRunner::MAX), 'skipped' => $matched - $n, 'over' => $n > BatchRunner::MAX, 'sheets' => $d['layout'] === 'card' ? $n * 2 : CardPdf::sheets($d['layout'], $landscape, $n),
            'pages' => $d['layout'] === 'card' ? $n * 2 : null, 'reprints' => $this->reprints, 'parts' => (int) ceil(max(1, $n) / BatchRunner::PART), 'background' => $n > IdPassSettings::syncBatch(), 'landscape' => $landscape, 'per_sheet' => $d['layout'] === 'card' ? null : (function () use ($d, $landscape) {
                [$c, $r] = CardPdf::grid($d['layout'], $landscape);

                return $c * $r;
            })()]);
    }

    public function store(Request $request)
    {
        $action = $request->input('action') === 'pdf' ? 'pdf' : 'print';
        $this->authorise($action);
        [$d, $matched, $active, $landscape] = $this->selection($request, $action);
        if (! $active) {
            return response()->json(['message' => __('No active ID matches. Only active IDs are printed.')], 422);
        }
        $reason = trim((string) $request->input('reason', ''));
        if ($this->reprints > 0 && $reason === '') {
            return response()->json(['message' => __(':n of these IDs were printed before. Say why they are printed again (lost, broken, details changed...).', ['n' => $this->reprints])], 422);
        }
        if (count($active) > BatchRunner::MAX) {
            return response()->json(['message' => __('A batch holds up to :n cards. Narrow the filters or choose fewer cards.', ['n' => BatchRunner::MAX])], 422);
        }
        $b = IdPassBatch::create(['account_id' => getAccountId(1), 'reference' => IdPassBatch::nextReference(), 'user_id' => $request->user()->id, 'action' => $action, 'layout' => $d['layout'],
            'filters' => ['mode' => $d['mode'], 'filters' => $d['filters'] ?? [], 'orientation' => $landscape ? 'landscape' : 'portrait', 'reason' => mb_substr($reason, 0, 240)], 'card_ids' => $active, 'cards' => count($active), 'status' => 'Queued']);
        PrintLoggerAudit::start($b);
        if (count($active) <= IdPassSettings::syncBatch()) {
            BatchRunner::run($b);
        } else {
            GenerateIdPassBatch::dispatch($b->id)->afterResponse();
        }

        return response()->json(['batch' => $this->batchRow($b->fresh())]);
    }

    public function batch(Request $request, int $batch)
    {
        $this->centre();
        $b = IdPassBatch::query()->with('user:id,name')->findOrFail($batch);
        abort_unless($b->user_id === $request->user()->id || $request->user()->can('read-id-print-history'), 403);

        return response()->json($this->batchRow($b));
    }

    public function download(Request $request, int $batch)
    {
        $this->centre();
        $b = IdPassBatch::query()->findOrFail($batch);
        abort_unless($b->user_id === $request->user()->id || $request->user()->can('read-id-print-history'), 403);
        abort_unless($b->status === 'Done', 404);
        $part = max(1, (int) $request->query('part', 1));
        $file = BatchRunner::file($b, $part);
        abort_unless(is_file($file), 404);
        $name = 'rans-ids-' . $b->reference . '-part' . $part . '.pdf';

        return response()->file($file, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($b->action === 'print' ? 'inline' : 'attachment') . '; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }

    public function history(Request $request)
    {
        $this->module();
        $this->feature('id_print_centre');
        $this->need('read-id-print-history');
        $f = ['q' => trim((string) $request->query('q')), 'user_id' => (int) $request->query('user_id'), 'action' => in_array($request->query('action'), array_keys(IdPassPrint::ACTIONS), true) ? $request->query('action') : '',
            'layout' => in_array($request->query('layout'), array_keys(IdPassPrint::LAYOUTS), true) ? $request->query('layout') : '', 'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('from')) ? $request->query('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('to')) ? $request->query('to') : '', 'batch' => trim((string) $request->query('batch'))];
        $rows = IdPassPrint::query()->with(['user:id,name', 'batch:id,reference'])
            ->when($f['q'], fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'like', $this->like($v))->orWhere('full_name', 'like', $this->like($v))))
            ->when($f['user_id'], fn ($q, $v) => $q->where('user_id', $v))->when($f['action'], fn ($q, $v) => $q->where('action', $v))->when($f['layout'], fn ($q, $v) => $q->where('layout', $v))
            ->when($f['from'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($f['batch'], fn ($q, $v) => $q->whereHas('batch', fn ($b) => $b->where('reference', 'like', $this->like($v))))
            ->latest('id')->paginate(25)->withQueryString()->through(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'name' => $p->full_name, 'category' => $p->category_name, 'status' => $p->card_status, 'action' => $p->action, 'layout' => $p->layout, 'copies' => $p->copies,
                'outcome' => $p->outcome, 'message' => $p->message, 'by' => optional($p->user)->name, 'batch' => optional($p->batch)->reference, 'at' => $p->created_at->timezone(config('app.timezone'))->toIso8601String(), 'card_id' => $p->card_id]);
        $users = User::withoutGlobalScopes()->whereIn('id', IdPassPrint::query()->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]);

        return Inertia::render('IdPasses/History', ['rows' => $rows, 'filters' => $f, 'users' => $users, 'actions' => collect(IdPassPrint::ACTIONS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'layouts' => collect(IdPassPrint::LAYOUTS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values()]);
    }
}

/** The audit line of a batch being started. */
final class PrintLoggerAudit
{
    public static function start(IdPassBatch $b): void
    {
        \App\Services\IdPass\PrintLogger::audit('RANS ID batch started', ['batch' => $b->reference, 'action' => $b->action, 'layout' => $b->layout, 'cards' => $b->cards]);
    }
}
