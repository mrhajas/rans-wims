<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\SubcontractAgreement as A;
use App\Services\Subcontracts\SubcontractAmounts;
use App\Services\Subcontracts\SubcontractLedger;
use App\Services\Subcontracts\SubcontractOpening;
use App\Services\Subcontracts\XlsxReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Opening balances: for every subcontractor account the contract value and what was paid to date, as at a date, with the account's previous data (Excel, PDF,
 * pictures) attached and previewable. Also the reset that takes every account's last amounts as its opening balance (see SubcontractOpening).
 */
class OpeningController extends Controller
{
    use SubcontractHelpers;

    /** group of the attachments that belong to the opening balance (kept apart from the agreement's ordinary documents) */
    public const GROUP = 'opening';

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function guard(bool $write = false): void
    {
        $this->need($write ? 'manage-opening-subcontracts' : 'read-opening-subcontracts', 'manage-opening-subcontracts');
        abort_unless($this->money(), 403, __('Opening balances are contract and payment figures: you need the permission to see financial figures.'));
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->guard();
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
        $base = $this->visibleAgreements()->with(['contact:id,name', 'warehouse:id,name'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('title', 'like', $like)->orWhere('site', 'like', $like)->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like))))
            ->when($status === 'Archived', fn ($q) => $q->where('status', A::ARCHIVED))
            ->when($status !== '' && $status !== 'Archived', fn ($q) => $q->where('status', $status))
            ->when($status === '', fn ($q) => $q->where('status', '<>', A::ARCHIVED));
        $ids = (clone $base)->pluck('id')->all();
        $fig = SubcontractLedger::forAgreements($ids);
        $totals = SubcontractLedger::foldByCurrency($fig);
        $rows = (clone $base)->orderByDesc('id')->paginate(25)->withQueryString();
        $page = $rows->getCollection()->pluck('id')->all();
        $opening = DB::table('subcontract_historical_payments')->whereIn('agreement_id', $page)->whereNull('deleted_at')->where('counted', 1)->selectRaw('agreement_id, COUNT(*) n, SUM(amount) s, MAX(payment_date) d')->groupBy('agreement_id')->get()->keyBy('agreement_id');
        $docs = DB::table('attachments')->where('model_type', A::class)->where('group', self::GROUP)->whereIn('model_id', $page)->selectRaw('model_id, COUNT(*) n')->groupBy('model_id')->pluck('n', 'model_id');
        $rows->through(function ($a) use ($fig, $opening, $docs) {
            $f = $fig[$a->id] ?? [];
            $o = $opening[$a->id] ?? null;

            return ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'status' => $a->status, 'contact' => optional($a->contact)->name, 'project' => optional($a->warehouse)->name, 'currency' => $a->currency,
                'contract' => $f['revised'] ?? '0.00', 'opening_paid' => SubcontractAmounts::money($o->s ?? 0), 'lines' => (int) ($o->n ?? 0), 'paid_total' => $f['paid_total'] ?? '0.00', 'outstanding' => $f['commitment_left'] ?? '0.00',
                'as_at' => $a->as_at_date ? $a->as_at_date->toDateString() : ($o->d ?? null), 'docs' => (int) ($docs[$a->id] ?? 0)];
        });
        $all = $this->visibleAgreements()->count();
        $single = DB::table('subcontract_historical_payments')->whereNull('deleted_at')->where('counted', 1)->selectRaw('agreement_id, COUNT(*) n')->groupBy('agreement_id')->get();
        $isOpening = $single->where('n', 1)->count();

        return Inertia::render('Subcontracts/Opening/Index', [
            'rows' => $rows, 'totals' => $totals, 'filters' => ['search' => $search, 'status' => $status],
            'counts' => ['accounts' => $all, 'opening' => $isOpening, 'detailed' => max(0, $all - $isOpening), 'docs' => (int) DB::table('attachments')->where('model_type', A::class)->where('group', self::GROUP)->count()],
            'can' => ['manage' => $this->can('manage-opening-subcontracts'), 'reset' => $this->can('reset-opening-subcontracts') && auth()->user()->hasRole('Super Admin'), 'print' => $this->can('print-subcontract-reports')],
            'today' => now()->toDateString(),
        ]);
    }

    public function edit(int $agreement)
    {
        $this->guard();
        $a = $this->agreementOrFail($agreement)->load(['contact:id,name', 'warehouse:id,name']);
        $state = SubcontractOpening::state($a->id);
        $files = $a->attachments()->where('group', self::GROUP)->orderByDesc('id')->get()->map(fn ($f) => $this->fileRow($a, $f))->values();

        return Inertia::render('Subcontracts/Opening/Edit', [
            'a' => ['id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'status' => $a->status, 'currency' => $a->currency, 'contact' => optional($a->contact)->name, 'project' => optional($a->warehouse)->name],
            'state' => $state, 'files' => $files,
            'can' => ['manage' => $this->can('manage-opening-subcontracts') && $a->status !== A::ARCHIVED, 'documents' => $this->can('view-documents-subcontracts')],
            'today' => now()->toDateString(),
        ]);
    }

    private function fileRow(A $a, $f): array
    {
        $ext = strtolower(pathinfo($f->title, PATHINFO_EXTENSION));

        return ['id' => $f->id, 'title' => $f->title, 'ext' => $ext, 'size' => (int) $f->filesize, 'at' => optional($f->created_at)->format('Y-m-d H:i'), 'note' => $f->description,
            'previewable' => in_array($ext, ['xlsx', 'csv', 'txt', 'pdf', 'jpg', 'jpeg', 'png', 'webp'], true),
            'preview_url' => route('subcontracts.opening.preview', [$a->id, $f->id]), 'download_url' => route('subcontracts.opening.download', [$a->id, $f->id]), 'remove_url' => route('subcontracts.opening.remove', [$a->id, $f->id])];
    }

    public function saveOpening(Request $request, int $agreement)
    {
        $this->guard(true);
        $a = $this->agreementOrFail($agreement);
        abort_if($a->status === A::ARCHIVED, 422, __('An archived account is read-only. Restore it first.'));
        $d = $request->validate(['contract' => 'required', 'paid' => 'required', 'as_at' => 'nullable|date_format:Y-m-d', 'note' => 'nullable|string|max:500']);
        $after = SubcontractOpening::save($a->id, $d['contract'], $d['paid'], $d['as_at'] ?? null, $d['note'] ?? null, $request->user());

        return back()->with('message', __('Opening balance saved. Outstanding is now :o :c.', ['o' => SubcontractAmounts::group($after['outstanding']), 'c' => $a->currency]));
    }

    public function attach(Request $request, int $agreement)
    {
        $this->guard(true);
        $a = $this->agreementOrFail($agreement);
        $request->validate(['attachments' => 'required|array|min:1|max:10', 'attachments.*' => 'file|max:16384|mimes:' . self::FILE_TYPES, 'note' => 'nullable|string|max:300']);
        $n = 0;
        foreach ((array) $request->file('attachments', []) as $f) {
            if ($f && $f->isValid()) {
                $a->attach($f, ['title' => mb_substr($f->getClientOriginalName(), 0, 90), 'description' => $request->input('note') ? mb_substr((string) $request->input('note'), 0, 300) : null, 'disk' => 'local', 'group' => self::GROUP]);
                $n++;
            }
        }
        log_activity('Subcontract opening balance document added', ['agreement' => $a->reference, 'files' => $n], $a, 'Subcontracts');

        return back()->with('message', trans_choice(':n document attached.|:n documents attached.', $n, ['n' => $n]));
    }

    public function download(int $agreement, int $file)
    {
        $this->guard();

        return $this->downloadFile($this->agreementOrFail($agreement), $file, 'Subcontract opening balance');
    }

    public function removeFile(int $agreement, int $file)
    {
        $this->guard(true);
        $a = $this->agreementOrFail($agreement);
        $this->removeFileOf($a, $file, 'Subcontract opening balance');

        return back()->with('message', __('Document removed.'));
    }

    /** Opens the document in its own browser tab: PDF and pictures as they are, Excel and CSV as readable tables, other types with a download button. Logged. */
    public function preview(int $agreement, int $file)
    {
        $this->guard();
        $this->need('view-documents-subcontracts');
        $a = $this->agreementOrFail($agreement);
        $att = $a->attachments()->whereKey($file)->first();
        abort_unless($att, 404);
        $disk = Storage::disk($att->disk ?: 'local');
        $path = $disk->path($att->filepath);
        abort_unless(is_file($path), 404);
        $ext = strtolower(pathinfo($att->title, PATHINFO_EXTENSION));
        log_activity('Subcontract opening balance document previewed', ['agreement' => $a->reference, 'file' => $att->title], $a, 'Subcontracts');

        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext);

            return response()->file($path, ['Content-Type' => $mime, 'Content-Disposition' => 'inline; filename="' . addslashes($att->title) . '"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'; frame-ancestors 'self'"]);
        }
        $data = ['title' => $att->title, 'agreement' => $a->reference . ' · ' . $a->title, 'download' => route('subcontracts.opening.download', [$a->id, $att->id]), 'size' => (int) $att->filesize, 'note' => null, 'sheets' => []];
        if ($ext === 'xlsx' && filesize($path) <= 12 * 1024 * 1024) {
            try {
                $data['sheets'] = $this->readSheets($path);
            } catch (\Throwable $e) {
                $data['note'] = __('This Excel file could not be read here. Download it to open it in Excel.');
            }
        } elseif (in_array($ext, ['csv', 'txt'], true) && filesize($path) <= 4 * 1024 * 1024) {
            $data['sheets'] = [$this->readText($path, $ext)];
        } else {
            $data['note'] = $ext === 'xlsx' ? __('This workbook is too large to preview. Download it to open it in Excel.') : __('This kind of file cannot be shown in the browser (:e). Download it to open it.', ['e' => strtoupper($ext)]);
        }

        return response()->view('subcontracts.document-preview', $data)->header('X-Content-Type-Options', 'nosniff');
    }

    /** A, B ... Z, AA ... for the column headings of a previewed sheet. */
    public static function colName(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    private const PREVIEW_ROWS = 400;

    private const PREVIEW_COLS = 24;

    private const PREVIEW_SHEETS = 40;

    private function readSheets(string $path): array
    {
        $x = new XlsxReader($path);
        $out = [];
        foreach (array_slice($x->sheets(), 0, self::PREVIEW_SHEETS) as $s) {
            [$cells, $total] = $x->previewCells($s['name'], self::PREVIEW_ROWS); // only the first rows are kept; the rest of the sheet is just counted
            ksort($cells);
            $rows = [];
            $maxCol = 1;
            foreach (array_slice($cells, 0, self::PREVIEW_ROWS, true) as $r => $cols) {
                $maxCol = max($maxCol, min(self::PREVIEW_COLS, max(array_keys($cols))));
            }
            foreach (array_slice($cells, 0, self::PREVIEW_ROWS, true) as $r => $cols) {
                $line = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $cell = $cols[$c] ?? null;
                    $v = $cell['v'] ?? null;
                    if ($cell && $cell['date'] && is_float($v)) {
                        $v = XlsxReader::serialToDate($v) ?: $v;
                    } elseif (is_float($v)) {
                        $v = abs($v - round($v)) < 1e-9 ? number_format($v, 0, '.', ',') : number_format($v, 2, '.', ',');
                    } elseif (is_bool($v)) {
                        $v = $v ? 'TRUE' : 'FALSE';
                    }
                    $line[] = $v === null ? '' : (string) $v;
                }
                $rows[] = ['n' => $r, 'cells' => $line, 'num' => array_map(fn ($c) => isset($cols[$c]) && is_float($cols[$c]['v'] ?? null) && ! ($cols[$c]['date'] ?? false), range(1, $maxCol))];
            }
            $out[] = ['name' => $s['name'], 'hidden' => $s['state'] !== 'visible', 'cols' => $maxCol, 'rows' => $rows, 'total_rows' => $total, 'cut' => $total > self::PREVIEW_ROWS];
        }

        return $out;
    }

    private function readText(string $path, string $ext): array
    {
        $rows = [];
        $h = fopen($path, 'r');
        $n = 0;
        $maxCol = 1;
        while (($line = fgets($h)) !== false && $n < self::PREVIEW_ROWS) {
            $cells = $ext === 'csv' ? str_getcsv(rtrim($line, "\r\n")) : [rtrim($line, "\r\n")];
            $cells = array_slice(array_map(fn ($v) => mb_convert_encoding((string) $v, 'UTF-8', 'UTF-8'), $cells), 0, self::PREVIEW_COLS);
            $maxCol = max($maxCol, count($cells));
            $rows[] = ['n' => ++$n, 'cells' => $cells, 'num' => array_map(fn ($v) => is_numeric(str_replace(',', '', (string) $v)), $cells)];
        }
        $more = fgets($h) !== false;
        fclose($h);

        return ['name' => basename($path), 'hidden' => false, 'cols' => $maxCol, 'rows' => $rows, 'total_rows' => $n + ($more ? 1 : 0), 'cut' => $more];
    }

    /** What a reset would do (counts and the figures that must stay the same); changes nothing. */
    public function resetPlan()
    {
        abort_unless(auth()->user()->hasRole('Super Admin') && $this->can('reset-opening-subcontracts'), 403);
        $p = SubcontractOpening::plan();
        unset($p['figures']);

        return response()->json($p);
    }

    /** Super Admin only, with the password and the word RESET typed in. */
    public function reset(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin') && $this->can('reset-opening-subcontracts'), 403);
        $d = $request->validate(['password' => 'required|string|max:1024', 'confirm' => 'required|string', 'as_at' => 'required|date_format:Y-m-d']);
        if (! Hash::check($d['password'], (string) $request->user()->password)) {
            log_activity('Subcontract reset refused: wrong password', [], null, 'Subcontracts');
            \App\Services\SecurityAlert::note((string) $request->ip(), 'wrong password on the subcontract reset');
            throw ValidationException::withMessages(['password' => [__('The password is not correct.')]]);
        }
        if (strtoupper(trim($d['confirm'])) !== 'RESET') {
            throw ValidationException::withMessages(['confirm' => [__('Type the word RESET to confirm.')]]);
        }
        try {
            $s = SubcontractOpening::reset($request->user(), $d['as_at']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        \App\Services\AdminNotificationService::send(__('Subcontractor accounts reset'), __(':n subcontractor accounts were reset to opening balances by :u (as at :d).', ['n' => $s['accounts'], 'u' => $request->user()->name, 'd' => $d['as_at']]), route('subcontracts.opening.index'));

        return redirect()->route('subcontracts.opening.index')->with('message', __(':n accounts now start from their opening balance. A backup of what was removed is kept in :b.', ['n' => $s['accounts'], 'b' => 'storage/app/' . $s['backup']]));
    }
}
