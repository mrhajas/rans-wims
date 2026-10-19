<?php

namespace App\Http\Controllers\WimsChat;

use App\Models\User;
use App\Services\WimsChat\WimsChatFiles;
use App\Services\WimsChat\WimsChatLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * WIMSChat analytics and storage figures. Everything is an aggregate over indexed tables (never a filesystem scan),
 * cached for a minute, and only counts — never message text.
 */
class WimsChatAnalyticsController extends WimsChatBaseController
{
    private function guard(): User
    {
        $u = auth()->user();
        abort_unless($u && $u->can('view-wimschat-analytics') && $this->svc->feature('wimschat_analytics', $u), 403);

        return $u;
    }

    private function range(Request $r): array
    {
        $to = $r->filled('to') ? Carbon::parse($r->input('to'))->endOfDay() : now()->endOfDay();
        $from = $r->filled('from') ? Carbon::parse($r->input('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return [$from, $to];
    }

    public function index(Request $request)
    {
        $me = $this->guard();
        [$from, $to] = $this->range($request);

        return Inertia::render('WimsChat/Analytics', [
            'stats' => $this->stats($me, $from, $to),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'can_logs' => $me->can('view-wimschat-logs'),
        ]);
    }

    public function stats(User $me, Carbon $from, Carbon $to): array
    {
        $acc = (int) $me->account_id;
        $key = "wimschat.stats.$acc.".$from->toDateString().'.'.$to->toDateString();

        return Cache::remember($key, 60, function () use ($me, $acc, $from, $to) {
            $cfg = $this->cfg($me);
            $msgs = fn () => DB::table('wimschat_messages')->where('wimschat_messages.account_id', $acc);
            $atts = fn () => DB::table('wimschat_attachments')->where('wimschat_attachments.account_id', $acc)->where('wimschat_attachments.is_copy', false);
            $inRange = fn ($q, $col = 'created_at') => $q->whereBetween($col, [$from, $to]);

            $kpi = [
                'conversations' => DB::table('wimschat_conversations')->where('account_id', $acc)->whereNull('deleted_at')->count(),
                'groups' => DB::table('wimschat_conversations')->where('account_id', $acc)->whereNull('deleted_at')->where('type', 'group')->count(),
                'active_conversations' => $inRange($msgs()->where('type', '!=', 'system'))->distinct()->count('conversation_id'),
                'messages_today' => $msgs()->where('type', 'text')->where('created_at', '>=', now()->startOfDay())->count(),
                'messages_week' => $msgs()->where('type', 'text')->where('created_at', '>=', now()->startOfWeek())->count(),
                'messages_month' => $msgs()->where('type', 'text')->where('created_at', '>=', now()->startOfMonth())->count(),
                'messages_range' => $inRange($msgs()->where('type', 'text'))->count(),
                'active_users' => $inRange($msgs()->where('type', '!=', 'system'))->distinct()->count('user_id'),
                'online_now' => User::query()->where('account_id', $acc)->where('is_active', true)->where('last_seen_at', '>=', now()->subSeconds((int) $cfg->get('active_within_seconds')))->count(),
                'attachments' => $atts()->whereNull('deleted_at')->whereNull('purged_at')->count(),
                'storage_bytes' => (int) $atts()->whereNull('purged_at')->sum('size_bytes'),
                'uploads_today' => $atts()->where('created_at', '>=', now()->startOfDay())->count(),
                'upload_bytes_today' => (int) $atts()->where('created_at', '>=', now()->startOfDay())->sum('size_bytes'),
                'nudges' => $inRange($msgs()->where('type', 'nudge'))->count(),
                'reactions' => $inRange(DB::table('wimschat_reactions as r')->join('wimschat_messages as m', 'm.id', '=', 'r.message_id')->where('m.account_id', $acc), 'r.created_at')->count(),
                'failed_uploads' => $inRange(DB::table('activity_log')->where('log_name', WimsChatLogger::LOG_NAME)->where('event', 'attachment_rejected'))->count(),
            ];

            $daily = $inRange($msgs()->where('type', 'text'))->selectRaw('DATE(created_at) d, COUNT(*) c')->groupBy('d')->orderBy('d')->pluck('c', 'd')->all();
            $dailyFiles = $inRange($atts())->selectRaw('DATE(created_at) d, COUNT(*) c, SUM(size_bytes) b')->groupBy('d')->orderBy('d')->get()->keyBy('d');
            $series = [];
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $k = $d->toDateString();
                $series[] = ['date' => $k, 'messages' => (int) ($daily[$k] ?? 0), 'files' => (int) ($dailyFiles[$k]->c ?? 0), 'bytes' => (int) ($dailyFiles[$k]->b ?? 0)];
            }

            $topUsers = $inRange($msgs()->where('wimschat_messages.type', 'text'), 'wimschat_messages.created_at')->join('users as u', 'u.id', '=', 'wimschat_messages.user_id')
                ->selectRaw('u.id, u.name, COUNT(*) messages')->groupBy('u.id', 'u.name')->orderByDesc('messages')->limit(10)->get();
            $fileByUser = $inRange($atts())->selectRaw('user_id, COUNT(*) files, SUM(size_bytes) bytes')->groupBy('user_id')->get()->keyBy('user_id');
            $topUsers = $topUsers->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'messages' => (int) $r->messages, 'files' => (int) ($fileByUser[$r->id]->files ?? 0), 'bytes' => (int) ($fileByUser[$r->id]->bytes ?? 0)])->all();

            $groups = $inRange(DB::table('wimschat_messages as m')->join('wimschat_conversations as c', 'c.id', '=', 'm.conversation_id')->where('m.account_id', $acc)->where('c.type', 'group')->where('m.type', 'text'), 'm.created_at')
                ->selectRaw('c.id, c.title, COUNT(*) messages, COUNT(DISTINCT m.user_id) people')->groupBy('c.id', 'c.title')->orderByDesc('messages')->limit(10)->get()
                ->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'messages' => (int) $r->messages, 'people' => (int) $r->people])->all();

            $byType = $atts()->whereNull('purged_at')->selectRaw('COALESCE(category, "other") k, COUNT(*) files, SUM(size_bytes) bytes')->groupBy('k')->orderByDesc('bytes')->get()
                ->map(fn ($r) => ['key' => $r->k, 'files' => (int) $r->files, 'bytes' => (int) $r->bytes])->all();

            $byUser = $atts()->whereNull('wimschat_attachments.purged_at')->join('users as u', 'u.id', '=', 'wimschat_attachments.user_id')->selectRaw('u.id, u.name, COUNT(*) files, SUM(wimschat_attachments.size_bytes) bytes')
                ->groupBy('u.id', 'u.name')->orderByDesc('bytes')->limit(10)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'files' => (int) $r->files, 'bytes' => (int) $r->bytes])->all();

            $byRole = DB::table('wimschat_attachments as a')->where('a.account_id', $acc)->where('a.is_copy', false)->whereNull('a.purged_at')
                ->join('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'a.user_id')->where('mr.model_type', User::class))
                ->join('roles as r', 'r.id', '=', 'mr.role_id')->selectRaw('r.name, COUNT(*) files, SUM(a.size_bytes) bytes')->groupBy('r.name')->orderByDesc('bytes')->get()
                ->map(fn ($r) => ['name' => $r->name, 'files' => (int) $r->files, 'bytes' => (int) $r->bytes])->all();

            // today's quota use of the people who uploaded today
            $todayUsers = $atts()->where('created_at', '>=', now()->startOfDay())->selectRaw('user_id, SUM(size_bytes) bytes')->groupBy('user_id')->orderByDesc('bytes')->limit(10)->get();
            $people = User::query()->whereIn('id', $todayUsers->pluck('user_id'))->get()->keyBy('id');
            $quota = $todayUsers->map(function ($r) use ($people, $cfg) {
                $u = $people[$r->user_id] ?? null;
                $limit = $u ? $cfg->dailyQuotaBytes($u) : 0;

                return ['id' => $r->user_id, 'name' => $u->name ?? '—', 'used' => (int) $r->bytes, 'limit' => $limit, 'pct' => $limit > 0 ? min(100, (int) round($r->bytes / $limit * 100)) : 100];
            })->all();

            return compact('kpi', 'series', 'topUsers', 'groups', 'byType', 'byUser', 'byRole', 'quota');
        });
    }

    private function printPayload(User $me, Carbon $from, Carbon $to, bool $isPdf): array
    {
        $s = $this->stats($me, $from, $to);
        $k = $s['kpi'];
        $fmt = fn ($b) => WimsChatFiles::human((int) $b);

        return [
            'title' => 'WIMSChat Analytics', 'ref' => $from->format('d M Y').' – '.$to->format('d M Y'), 'isPdf' => $isPdf, 'autoPrint' => ! $isPdf,
            'companyName' => optional($me->account)->name ?? 'RANS Company Limited', 'generatedBy' => $me->name, 'generatedAt' => now(),
            'filters' => [['Period', $from->format('d M Y').' → '.$to->format('d M Y')]],
            'scopeNote' => 'Counts only, message text is never part of this report.',
            'groups' => [['title' => 'Activity', 'cards' => [
                ['label' => 'Conversations', 'value' => number_format($k['conversations']), 'tone' => 'navy', 'icon' => 'grid'],
                ['label' => 'Messages in period', 'value' => number_format($k['messages_range']), 'tone' => 'sky', 'icon' => 'box'],
                ['label' => 'Active users', 'value' => number_format($k['active_users']), 'tone' => 'teal', 'icon' => 'users'],
                ['label' => 'Attachments', 'value' => number_format($k['attachments']), 'tone' => 'amber', 'icon' => 'tag'],
                ['label' => 'Storage used', 'value' => $fmt($k['storage_bytes']), 'tone' => 'violet', 'icon' => 'building'],
                ['label' => 'Failed uploads', 'value' => number_format($k['failed_uploads']), 'tone' => 'rose', 'icon' => 'grid'],
            ]]],
            'tableTitle' => 'Most active people',
            'columns' => [['label' => 'No', 'width' => '26px'], ['label' => 'Person'], ['label' => 'Messages'], ['label' => 'Files'], ['label' => 'Uploaded']],
            'rows' => collect($s['topUsers'])->values()->map(fn ($r, $i) => [$i + 1, ['text' => $r['name'], 'strong' => true], (string) $r['messages'], (string) $r['files'], $fmt($r['bytes'])])->all(),
            'emptyText' => 'No chat activity in this period.',
        ];
    }

    public function print(Request $request)
    {
        $me = $this->guard();
        [$from, $to] = $this->range($request);
        WimsChatLogger::log($me, 'REPORT_EXPORTED', null, ['report' => 'analytics', 'format' => 'print']);

        return view('reports.register-list', $this->printPayload($me, $from, $to, false));
    }

    public function pdf(Request $request)
    {
        $me = $this->guard();
        [$from, $to] = $this->range($request);
        WimsChatLogger::log($me, 'REPORT_EXPORTED', null, ['report' => 'analytics', 'format' => 'pdf']);

        return Pdf::loadView('reports.register-list', $this->printPayload($me, $from, $to, true))->setPaper('a4', 'landscape')->download('wimschat-analytics-'.now()->format('Ymd-His').'.pdf');
    }

    public function export(Request $request)
    {
        $me = $this->guard();
        [$from, $to] = $this->range($request);
        $s = $this->stats($me, $from, $to);
        WimsChatLogger::log($me, 'REPORT_EXPORTED', null, ['report' => 'analytics', 'format' => 'xlsx']);

        $sheets = new \Rap2hpoutre\FastExcel\SheetCollection([
            'Daily activity' => collect($s['series'])->map(fn ($r) => ['Date' => $r['date'], 'Messages' => $r['messages'], 'Files' => $r['files'], 'Uploaded (MB)' => round($r['bytes'] / 1048576, 2)]),
            'People' => collect($s['topUsers'])->map(fn ($r) => ['Person' => $r['name'], 'Messages' => $r['messages'], 'Files' => $r['files'], 'Uploaded (MB)' => round($r['bytes'] / 1048576, 2)]),
            'Storage by role' => collect($s['byRole'])->map(fn ($r) => ['Role' => $r['name'], 'Files' => $r['files'], 'Size (MB)' => round($r['bytes'] / 1048576, 2)]),
            'Storage by type' => collect($s['byType'])->map(fn ($r) => ['Type' => $r['key'], 'Files' => $r['files'], 'Size (MB)' => round($r['bytes'] / 1048576, 2)]),
        ]);

        return (new \Rap2hpoutre\FastExcel\FastExcel($sheets))->download('wimschat-analytics-'.now()->format('Ymd-His').'.xlsx');
    }
}
