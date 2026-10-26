<?php

namespace App\Http\Controllers;

use App\Models\StockArchive;
use App\Models\StockArchiveEvent;
use App\Models\StockArchiveWipe;
use App\Models\User;
use App\Support\StockArchive\ArchiveService;
use App\Support\StockArchive\ArchiveSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

/**
 * Reports > Stock Archive: a kept record of the whole company's stock (every warehouse and project by category) as a small PDF, made by WIMS every week or by a
 * person on request, with a history of what happened to each entry. Super Admins change the settings and delete entries (with their password).
 */
class StockArchiveController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function account(Request $request): int
    {
        return (int) $request->user()->account_id;
    }

    private function isSuper(Request $request): bool
    {
        return (bool) $request->user()->hasRole('Super Admin');
    }

    private function entry(Request $request, StockArchive $archive): StockArchive
    {
        abort_unless($request->user()->can('read-stock-archives') && (int) $archive->account_id === $this->account($request), 403);

        return $archive;
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-stock-archives'), 403);
        $account = $this->account($request);
        $q = $this->filtered($request, $account)->with(['creator:id,name', 'deleter:id,name'])->withCount(['events as views_count' => fn ($e) => $e->whereIn('action', ['viewed', 'previewed', 'downloaded'])]);
        $archives = $q->orderByDesc('generated_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn (StockArchive $a) => $this->row($a));

        $base = StockArchive::query()->where('account_id', $account);
        $cfg = ArchiveSettings::get($account);
        $super = $this->isSuper($request);

        return Inertia::render('StockArchive/Index', [
            'archives' => $archives,
            'filters' => $request->only('search', 'status', 'kind', 'from', 'to'),
            'stats' => [
                'entries' => (clone $base)->where('status', 'active')->count(),
                'deleted' => (clone $base)->where('status', 'deleted')->count(),
                'size' => (int) (clone $base)->where('status', 'active')->sum('file_size'),
                'last' => optional((clone $base)->where('status', 'active')->max('generated_at')) ? (string) (clone $base)->where('status', 'active')->max('generated_at') : null,
            ],
            'schedule' => ['enabled' => $cfg['enabled'], 'day' => $cfg['send_day'], 'time' => $cfg['send_time'], 'keep_days' => $cfg['keep_days'], 'next' => $this->nextRun($cfg)],
            'can' => ['create' => $request->user()->can('create-stock-archives'), 'super' => $super],
            'config' => $super ? $cfg : null,
            'users' => $super ? User::query()->where('account_id', $account)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']) : [],
            'roles' => $super ? Role::query()->orderBy('name')->pluck('name') : [],
            'recipientCount' => $super ? count(ArchiveService::recipients($cfg, $account)) : 0,
            'wipe' => $super ? $this->wipeRow(StockArchiveWipe::where('account_id', $account)->where('status', 'pending')->with('initiator:id,name')->latest('id')->first(), $request) : null,
            'wipes' => $super ? StockArchiveWipe::where('account_id', $account)->where('status', '!=', 'pending')->with(['initiator:id,name', 'decider:id,name'])->latest('id')->limit(8)->get()->map(fn ($w) => $this->wipeRow($w, $request)) : [],
        ]);
    }

    private function wipeRow(?StockArchiveWipe $w, Request $request): ?array
    {
        if (! $w) {
            return null;
        }

        return [
            'id' => $w->id, 'status' => $w->status, 'count' => $w->entries_count, 'refs' => array_slice((array) $w->refs, 0, 6), 'more' => max(0, count((array) $w->refs) - 6),
            'by' => optional($w->initiator)->name ?: '-', 'by_me' => (int) $w->initiated_by_user_id === (int) $request->user()->id, 'at' => optional($w->created_at)->toIso8601String(),
            'decided_by' => optional($w->decider)->name, 'decided_at' => optional($w->decided_at)->toIso8601String(),
        ];
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => 'required|string']);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }
    }

    /** The entries a bulk action works on: the ticked ones, or every entry of the current list (with its filters) when "all" is chosen. */
    private function chosen(Request $request, string $status)
    {
        $account = $this->account($request);
        $q = $request->boolean('all') ? $this->filtered($request, $account, $status) : StockArchive::query()->where('account_id', $account)->where('status', $status)->whereIn('id', array_map('intval', (array) $request->input('ids', [])));

        return $q->orderBy('id')->limit(5000)->get();
    }

    /** Super Admin, with their password: deletes several kept entries at once (files removed, entries and history stay). */
    public function bulkDestroy(Request $request)
    {
        abort_unless($this->isSuper($request), 403);
        $request->validate(['ids' => 'array', 'ids.*' => 'integer', 'all' => 'boolean', 'reason' => 'nullable|string|max:120']);
        $this->confirmPassword($request);
        $list = $this->chosen($request, 'active');
        abort_if($list->isEmpty(), 422, __('Nothing to delete: choose some kept entries first.'));
        $reason = trim((string) $request->input('reason')) ?: 'Deleted by ' . $request->user()->name;
        foreach ($list as $a) {
            ArchiveService::remove($a, $request->user(), $reason);
        }
        log_activity(__('Stock reports deleted'), ['count' => $list->count(), 'references' => $list->pluck('reference')->all()], $request->user(), 'Stock Archive');

        return back()->with('message', __(':n entries were deleted. Their history is kept.', ['n' => $list->count()]));
    }

    /** First Super Admin, with their password: asks to wipe the chosen deleted entries off for good. A second Super Admin must confirm. */
    public function wipeStart(Request $request)
    {
        abort_unless($this->isSuper($request), 403);
        $request->validate(['ids' => 'array', 'ids.*' => 'integer', 'all' => 'boolean']);
        $this->confirmPassword($request);
        $account = $this->account($request);
        abort_if(StockArchiveWipe::where('account_id', $account)->where('status', 'pending')->exists(), 422, __('A wipe-off is already waiting for a second Super Admin.'));
        $list = $this->chosen($request, 'deleted');
        abort_if($list->isEmpty(), 422, __('Nothing to wipe: choose some deleted entries first.'));
        $w = StockArchiveWipe::create(['account_id' => $account, 'entry_ids' => $list->pluck('id')->all(), 'refs' => $list->pluck('reference')->all(), 'entries_count' => $list->count(), 'status' => 'pending', 'initiated_by_user_id' => $request->user()->id]);
        foreach ($list as $a) {
            ArchiveService::event($a, 'wipe_requested', $request->user(), 'Wipe-off started; a second Super Admin must confirm');
        }
        log_activity(__('Stock report wipe-off started'), ['count' => $list->count()], $request->user(), 'Stock Archive');

        return back()->with('message', __('The wipe-off of :n entries was started. Another Super Admin must now confirm it with their password.', ['n' => $w->entries_count]));
    }

    /** Second Super Admin (not the one who started it), with their password: the entries are wiped off for good. */
    public function wipeConfirm(Request $request, StockArchiveWipe $wipe)
    {
        abort_unless($this->isSuper($request) && (int) $wipe->account_id === $this->account($request), 403);
        abort_unless($wipe->status === 'pending', 422, __('This wipe-off is no longer waiting.'));
        if ((int) $wipe->initiated_by_user_id === (int) $request->user()->id) {
            abort(403, __('A different Super Admin must confirm: you started this wipe-off.'));
        }
        $this->confirmPassword($request);
        $list = StockArchive::query()->where('account_id', $wipe->account_id)->where('status', 'deleted')->whereIn('id', (array) $wipe->entry_ids)->get();
        \Illuminate\Support\Facades\DB::transaction(function () use ($list, $wipe, $request) {
            StockArchiveEvent::whereIn('stock_archive_id', $list->pluck('id'))->delete();
            StockArchive::whereIn('id', $list->pluck('id'))->delete();
            $wipe->forceFill(['status' => 'completed', 'decided_by_user_id' => $request->user()->id, 'decided_at' => now(), 'entries_count' => $list->count()])->save();
        });
        log_activity(__('Stock reports wiped off'), ['count' => $list->count(), 'references' => $list->pluck('reference')->all(), 'started_by' => optional($wipe->initiator)->name], $request->user(), 'Stock Archive');

        return back()->with('message', __(':n deleted entries were wiped off for good.', ['n' => $list->count()]));
    }

    /** The Super Admin who started it cancels it, or another Super Admin turns it down. */
    public function wipeCancel(Request $request, StockArchiveWipe $wipe)
    {
        abort_unless($this->isSuper($request) && (int) $wipe->account_id === $this->account($request) && $wipe->status === 'pending', 403);
        $mine = (int) $wipe->initiated_by_user_id === (int) $request->user()->id;
        $wipe->forceFill(['status' => $mine ? 'cancelled' : 'rejected', 'decided_by_user_id' => $request->user()->id, 'decided_at' => now()])->save();
        foreach (StockArchive::whereIn('id', (array) $wipe->entry_ids)->get() as $a) {
            ArchiveService::event($a, 'wipe_' . ($mine ? 'cancelled' : 'rejected'), $request->user(), $mine ? 'Wipe-off cancelled' : 'Wipe-off turned down');
        }
        log_activity($mine ? __('Stock report wipe-off cancelled') : __('Stock report wipe-off turned down'), ['count' => $wipe->entries_count], $request->user(), 'Stock Archive');

        return back()->with('message', $mine ? __('The wipe-off was cancelled.') : __('The wipe-off was turned down.'));
    }

    /** The entries of the list: the account's, with the search, status, kind and date filters of the page. */
    private function filtered(Request $request, int $account, ?string $forceStatus = null)
    {
        $q = StockArchive::query()->where('account_id', $account);
        if ($s = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%$s%")->orWhere('title', 'like', "%$s%")->orWhereHas('creator', fn ($c) => $c->where('name', 'like', "%$s%")));
        }
        $status = $forceStatus ?? $request->input('status');
        if (in_array($status, ['active', 'deleted'], true)) {
            $q->where('status', $status);
        }
        if (in_array($request->input('kind'), ['Scheduled', 'Manual'], true)) {
            $q->where('kind', $request->input('kind'));
        }
        if ($from = $request->input('from')) {
            $q->whereDate('generated_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $q->whereDate('generated_at', '<=', $to);
        }

        return $q;
    }

    private function row(StockArchive $a): array
    {
        return [
            'id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'kind' => $a->kind,
            'generated_at' => optional($a->generated_at)->toIso8601String(),
            'by' => $a->creator ? $a->creator->name : 'WIMS', 'by_wims' => ! $a->creator,
            'size' => (int) $a->file_size, 'warehouses' => $a->warehouses_count, 'items' => $a->items_count, 'rows' => $a->rows_count, 'value' => (float) $a->total_value,
            'emailed_at' => optional($a->emailed_at)->toIso8601String(), 'emailed_to' => $a->emailed_to,
            'deleted' => $a->isDeleted(), 'deleted_at' => optional($a->deleted_at)->toIso8601String(),
            'deleted_by' => $a->isDeleted() ? ($a->deleter ? $a->deleter->name : 'WIMS') : null, 'deleted_reason' => $a->deleted_reason,
            'views' => (int) ($a->views_count ?? 0),
        ];
    }

    private function nextRun(array $cfg): ?string
    {
        if (! $cfg['enabled']) {
            return null;
        }
        $now = CarbonImmutable::now(ArchiveService::TZ);
        [$h, $m] = array_map('intval', explode(':', $cfg['send_time']));
        $next = $now->setTime($h, $m, 0);
        while ($next->dayOfWeek !== (int) $cfg['send_day'] || $next <= $now || ($cfg['last_run_on'] ?? null) === $next->toDateString()) {
            $next = $next->addDay();
        }

        return $next->toIso8601String();
    }

    /** A person makes the report now. */
    public function generate(Request $request)
    {
        abort_unless($request->user()->can('create-stock-archives'), 403);
        $a = ArchiveService::generate($this->account($request), $request->user());
        log_activity(__('Stock report made'), ['reference' => $a->reference, 'size' => $a->file_size], $a, 'Stock Archive');

        return back()->with('message', __('Stock report :ref was made.', ['ref' => $a->reference]));
    }

    private function file(StockArchive $a)
    {
        abort_if($a->isDeleted() || ! $a->file_path || ! Storage::disk(ArchiveService::DISK)->exists($a->file_path), 410, __('This entry was deleted and its file is gone.'));

        return Storage::disk(ArchiveService::DISK)->path($a->file_path);
    }

    /** Opens the PDF in the browser (the page opens it in a new tab). */
    public function preview(Request $request, StockArchive $archive)
    {
        $archive = $this->entry($request, $archive);
        $path = $this->file($archive);
        ArchiveService::event($archive, 'previewed', $request->user());

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $archive->reference . '.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function download(Request $request, StockArchive $archive)
    {
        $archive = $this->entry($request, $archive);
        $path = $this->file($archive);
        ArchiveService::event($archive, 'downloaded', $request->user());
        log_activity(__('Stock report downloaded'), ['reference' => $archive->reference], $archive, 'Stock Archive');

        return response()->download($path, $archive->reference . '.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** The history of one entry: what happened, who did it and when. Opening it is itself recorded as "viewed". */
    public function history(Request $request, StockArchive $archive)
    {
        $archive = $this->entry($request, $archive);
        $recent = StockArchiveEvent::where('stock_archive_id', $archive->id)->where('user_id', $request->user()->id)->where('action', 'viewed')->where('created_at', '>', now()->subMinutes(2))->exists();
        if (! $recent) {
            ArchiveService::event($archive, 'viewed', $request->user());
        }
        $events = StockArchiveEvent::where('stock_archive_id', $archive->id)->with('user:id,name')->orderByDesc('id')->limit(300)->get()->map(fn ($e) => [
            'id' => $e->id, 'action' => $e->action, 'by' => $e->user ? $e->user->name : 'WIMS', 'by_wims' => ! $e->user, 'note' => $e->note, 'at' => optional($e->created_at)->toIso8601String(),
        ]);

        return response()->json(['entry' => $this->row($archive->loadMissing('creator:id,name', 'deleter:id,name')), 'events' => $events]);
    }

    /** Super Admin, with their password: removes the file; the entry stays, marked red, with its history. */
    public function destroy(Request $request, StockArchive $archive)
    {
        abort_unless($this->isSuper($request) && (int) $archive->account_id === $this->account($request), 403);
        $request->validate(['password' => 'required|string', 'reason' => 'nullable|string|max:120']);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }
        abort_if($archive->isDeleted(), 422, __('This entry is already deleted.'));
        ArchiveService::remove($archive, $request->user(), trim((string) $request->input('reason')) ?: 'Deleted by ' . $request->user()->name);
        log_activity(__('Stock report deleted'), ['reference' => $archive->reference], $archive, 'Stock Archive');

        return back()->with('message', __('The entry was deleted. Its history is kept.'));
    }

    /** Super Admin: sends an entry again (to everybody chosen in the settings, or to one address). */
    public function email(Request $request, StockArchive $archive)
    {
        abort_unless($this->isSuper($request) && (int) $archive->account_id === $this->account($request), 403);
        $data = $request->validate(['to' => 'nullable|email']);
        $only = ! empty($data['to']) ? [['email' => $data['to'], 'name' => '']] : null;
        $n = ArchiveService::email($archive, $request->user(), $only);

        return back()->with($n ? 'message' : 'error', $n ? __('Sent to :n address(es).', ['n' => $n]) : __('Nothing was sent: there is no recipient or the file is gone.'));
    }

    public function settings(Request $request)
    {
        abort_unless($this->isSuper($request), 403);
        $data = $request->validate([
            'enabled' => 'required|boolean', 'send_day' => 'required|integer|min:0|max:6', 'send_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'keep_days' => 'required|integer|min:0|max:3650', 'email' => 'required|boolean',
            'role_names' => 'array', 'role_names.*' => 'string|exists:roles,name', 'user_ids' => 'array', 'user_ids.*' => 'integer|exists:users,id',
            'extra_emails' => 'array|max:30', 'extra_emails.*' => 'email',
        ]);
        $before = ArchiveSettings::get($this->account($request));
        $saved = ArchiveSettings::save($data, $this->account($request));
        log_activity(__('Stock Archive settings saved'), ['from' => $before, 'to' => $saved], $request->user(), 'Setting');

        return back()->with('message', __('Stock Archive settings saved.'));
    }
}
