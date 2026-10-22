<?php

namespace App\Services\VisitorLogs;

use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorIncomingItem;
use App\Models\VisitorLogEvent;
use App\Models\VisitorOption;
use App\Models\VisitorVisit;
use Illuminate\Support\Facades\DB;

/**
 * Visitor Logs reports in the WIMS report shape (title, filters, tiles, columns, rows, notes), printed with the same layout as every other WIMS report. Visits follow the
 * person's visit permissions; the sender and subject of a confidential item are masked for people who may not see them; identification numbers are never in a report.
 */
class VisitorReports
{
    public const LIMIT = 5000;

    private const MAIN = ['search', 'from', 'to', 'status', 'host_id', 'department', 'purpose', 'visitor_id', 'company', 'item_status', 'item_type', 'recipient_id', 'event', 'channel', 'warehouse_id'];

    public static function keys(): array
    {
        return self::MAIN;
    }

    /** @return list<array{key:string,title:string,about:string,filters:string[],needs:string[]}> every report, with the abilities it needs (all of them). */
    public static function catalog(): array
    {
        return [
            ['key' => 'daily_register', 'title' => __('Daily Visitor Register'), 'about' => __('Every visit of the day (or the dates chosen): visitor, who they came to see, purpose, times, duration and the receptionist.'), 'filters' => ['search', 'from', 'to', 'status', 'host_id', 'department', 'purpose', 'warehouse_id'], 'needs' => ['read-visitor-logs']],
            ['key' => 'person_history', 'title' => __('Visitor History by Person'), 'about' => __('All the visits of one visitor. Choose the visitor.'), 'filters' => ['visitor_id', 'from', 'to', 'status', 'host_id', 'warehouse_id'], 'needs' => ['read-visitor-logs', 'view-visitor-history']],
            ['key' => 'by_company', 'title' => __('Visitors by Company'), 'about' => __('Each company: how many visits and visitors, the time spent and the last visit.'), 'filters' => ['search', 'from', 'to', 'host_id', 'department', 'warehouse_id'], 'needs' => ['read-visitor-logs']],
            ['key' => 'by_host', 'title' => __('Visitors by Recipient / Employee'), 'about' => __('Each person visited: how many visits and visitors and the time spent with them.'), 'filters' => ['search', 'from', 'to', 'department', 'purpose', 'warehouse_id'], 'needs' => ['read-visitor-logs']],
            ['key' => 'by_department', 'title' => __('Visitors by Department'), 'about' => __('Each department: how many visits and visitors and the time spent.'), 'filters' => ['from', 'to', 'purpose', 'warehouse_id'], 'needs' => ['read-visitor-logs']],
            ['key' => 'on_premises', 'title' => __('Currently on Premises'), 'about' => __('Visitors who are checked in now, with the time they have spent.'), 'filters' => ['search', 'host_id', 'department', 'warehouse_id'], 'needs' => ['read-visitor-logs']],
            ['key' => 'checkin_out', 'title' => __('Visitor Check-In and Check-Out History'), 'about' => __('Every check-in and check-out in the period, with who processed each.'), 'filters' => ['search', 'from', 'to', 'status', 'host_id', 'department', 'warehouse_id'], 'needs' => ['read-visitor-logs']],
            ['key' => 'durations', 'title' => __('Visit Duration Report'), 'about' => __('How long visits lasted, longest first, with the average, the longest and the shortest.'), 'filters' => ['search', 'from', 'to', 'host_id', 'department', 'purpose', 'warehouse_id'], 'needs' => ['read-visitor-logs']],
            ['key' => 'letters', 'title' => __('Incoming Letters and Documents Register'), 'about' => __('Letters, documents and official correspondence received at reception.'), 'filters' => ['search', 'from', 'to', 'item_status', 'recipient_id', 'warehouse_id'], 'needs' => ['read-incoming-items']],
            ['key' => 'packages', 'title' => __('Incoming Packages and Deliveries Report'), 'about' => __('Packages, parcels and courier deliveries received at reception.'), 'filters' => ['search', 'from', 'to', 'item_status', 'recipient_id', 'warehouse_id'], 'needs' => ['read-incoming-items']],
            ['key' => 'awaiting', 'title' => __('Items Awaiting Collection'), 'about' => __('Items not yet handed over or closed, with the days they have waited.'), 'filters' => ['search', 'item_type', 'recipient_id', 'warehouse_id'], 'needs' => ['read-incoming-items']],
            ['key' => 'delivered', 'title' => __('Items Delivered to Recipients'), 'about' => __('Items handed over: to whom, when and who released them.'), 'filters' => ['search', 'from', 'to', 'item_type', 'recipient_id', 'warehouse_id'], 'needs' => ['read-incoming-items']],
            ['key' => 'overdue', 'title' => __('Overdue Collection Report'), 'about' => __('Items that have waited longer than the days set on the Visitor Logs settings.'), 'filters' => ['search', 'item_type', 'recipient_id', 'warehouse_id'], 'needs' => ['read-incoming-items']],
            ['key' => 'notifications', 'title' => __('Visitor Notification Log'), 'about' => __('Every alert sent about visitors and incoming items: who, on which channel and whether it was delivered.'), 'filters' => ['from', 'to', 'channel', 'status'], 'needs' => ['view-visitor-notification-log']],
            ['key' => 'audit', 'title' => __('Audit and Correction History'), 'about' => __('Every check-out, correction, cancellation, status change and hand-over, with who did it, when, the reason and the values before and after.'), 'filters' => ['search', 'from', 'to', 'event'], 'needs' => ['view-visitor-audit']],
        ];
    }

    /** The reports a person may open. */
    public static function allowedFor(User $u): array
    {
        return array_values(array_filter(self::catalog(), fn ($c) => collect($c['needs'])->every(fn ($a) => $u->can($a))));
    }

    public static function exists(string $type): bool
    {
        return in_array($type, array_column(self::catalog(), 'key'), true);
    }

    public static function normalize(array $f): array
    {
        $date = fn ($v) => ! empty($v) && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
        $int = fn ($v) => ctype_digit((string) ($v ?? '')) && ($v ?? '') !== '' ? (int) $v : null;

        return ['search' => mb_substr(trim((string) ($f['search'] ?? '')), 0, 100), 'from' => $date($f['from'] ?? null), 'to' => $date($f['to'] ?? null), 'status' => mb_substr(trim((string) ($f['status'] ?? '')), 0, 80),
            'host_id' => $int($f['host_id'] ?? null), 'department' => mb_substr(trim((string) ($f['department'] ?? '')), 0, 120), 'purpose' => mb_substr(trim((string) ($f['purpose'] ?? '')), 0, 160), 'visitor_id' => $int($f['visitor_id'] ?? null),
            'company' => mb_substr(trim((string) ($f['company'] ?? '')), 0, 190), 'item_status' => mb_substr(trim((string) ($f['item_status'] ?? '')), 0, 80), 'item_type' => mb_substr(trim((string) ($f['item_type'] ?? '')), 0, 80), 'recipient_id' => $int($f['recipient_id'] ?? null),
            'warehouse_id' => $int($f['warehouse_id'] ?? null), 'event' => mb_substr(trim((string) ($f['event'] ?? '')), 0, 32), 'channel' => in_array($f['channel'] ?? '', ['bell', 'sms', 'email', 'whatsapp', 'meta'], true) ? $f['channel'] : ''];
    }

    public static function build(User $user, string $type, array $f, int $limit = self::LIMIT): array
    {
        $f = self::normalize($f);
        [$cols, $rows, $title, $about, $tiles] = match ($type) {
            'person_history' => self::personHistory($f, $user),
            'by_company' => self::grouped($f, $user, 'company'),
            'by_host' => self::grouped($f, $user, 'host'),
            'by_department' => self::grouped($f, $user, 'department'),
            'on_premises' => self::onPremises($f, $user),
            'checkin_out' => self::checkinOut($f, $user),
            'durations' => self::durations($f, $user),
            'letters' => self::items($f, $user, 'letters'),
            'packages' => self::items($f, $user, 'packages'),
            'awaiting' => self::items($f, $user, 'awaiting'),
            'delivered' => self::items($f, $user, 'delivered'),
            'overdue' => self::items($f, $user, 'overdue'),
            'notifications' => self::notifications($f),
            'audit' => self::audit($f),
            default => self::dailyRegister($f, $user),
        };
        $total = count($rows);
        $kept = array_slice($rows, 0, $limit);
        $display = [];
        foreach ($kept as $i => $r) {
            $o = ['no' => $i + 1];
            foreach ($cols as $c) {
                $o[$c['key']] = $r[$c['key']] ?? '';
            }
            $display[] = $o;
        }
        array_unshift($cols, ['key' => 'no', 'label' => __('No'), 'type' => 'num']);
        $cols = array_map(fn ($c) => $c + ['align' => ($c['type'] ?? '') === 'num' ? 'right' : 'left'], $cols);

        return ['type' => $type, 'title' => $title, 'reference' => 'VLR-' . now()->format('Ymd-His'), 'subtitle' => $about, 'table_title' => $title, 'filters' => self::filterText($f), 'tiles' => array_values(array_merge([['label' => __('Rows'), 'value' => $total]], $tiles)),
            'columns' => $cols, 'rows' => $display, 'raw' => $display, 'total_rows' => $total, 'truncated' => $total > $limit, 'sections' => [],
            'notes' => [__('Times are in the WIMS timezone (:tz).', ['tz' => config('app.timezone')]), __('Visits are limited to the ones the person who generated the report may see.'), __('The sender and the subject of confidential items are hidden from people who may not see them.'), __('Figures are drawn from the records at the time of generation.')]];
    }

    private static function filterText(array $f): array
    {
        $o = [];
        $names = ['search' => __('Search'), 'from' => __('From'), 'to' => __('To'), 'status' => __('Status'), 'department' => __('Department'), 'purpose' => __('Purpose'), 'item_status' => __('Item status'), 'item_type' => __('Item type'), 'event' => __('Event'), 'channel' => __('Channel')];
        foreach ($names as $k => $l) {
            if (! empty($f[$k])) {
                $o[$l] = $f[$k];
            }
        }
        if ($f['host_id']) {
            $o[__('Person visited')] = optional(User::withTrashed()->find($f['host_id']))->name ?? '#' . $f['host_id'];
        }
        if ($f['recipient_id']) {
            $o[__('Recipient')] = optional(User::withTrashed()->find($f['recipient_id']))->name ?? '#' . $f['recipient_id'];
        }
        if ($f['warehouse_id']) {
            $o[__('Project')] = optional(\App\Models\Warehouse::withTrashed()->find($f['warehouse_id']))->name ?? '#' . $f['warehouse_id'];
        }
        if ($f['visitor_id']) {
            $o[__('Visitor')] = optional(Visitor::withTrashed()->find($f['visitor_id']))->full_name ?? '#' . $f['visitor_id'];
        }

        return $o ?: [__('Filters') => __('None')];
    }

    private static function dt($d): string
    {
        return $d ? $d->format('d M Y H:i') : '';
    }

    private static function mins(?int $m): string
    {
        return $m === null ? '' : VisitorVisit::durationText($m);
    }

    // ---------------------------------------------------------------------------------------------------- visits

    private static function visits(array $f, User $u)
    {
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';

        return VisitorVisit::query()->visibleTo($u)->with(['visitor:id,full_name,company,phone', 'registeredBy:id,name', 'checkedOutBy:id,name', 'warehouse:id,name'])
            ->when($f['warehouse_id'], fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($f['status'] !== '' && in_array($f['status'], VisitorVisit::STATUSES, true), fn ($q) => $q->where('status', $f['status']))
            ->when($f['from'], fn ($q, $v) => $q->whereDate('visit_date', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('visit_date', '<=', $v))
            ->when($f['host_id'], fn ($q, $v) => $q->where('host_user_id', $v))->when($f['department'] !== '', fn ($q) => $q->where('department', $f['department']))->when($f['purpose'] !== '', fn ($q) => $q->where('purpose', $f['purpose']))
            ->when($f['visitor_id'], fn ($q, $v) => $q->where('visitor_id', $v))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('host_name', 'like', $like($f['search']))->orWhere('purpose', 'like', $like($f['search']))
                ->orWhereHas('visitor', fn ($v) => $v->where('full_name', 'like', $like($f['search']))->orWhere('company', 'like', $like($f['search'])))));
    }

    private static function visitRow(VisitorVisit $v): array
    {
        $mins = $v->check_out_at ? (int) $v->duration_minutes : ($v->status === VisitorVisit::IN ? $v->minutesOnPremises() : null);

        return ['ref' => $v->reference, 'visitor' => optional($v->visitor)->full_name, 'company' => optional($v->visitor)->company, 'phone' => optional($v->visitor)->phone, 'host' => $v->host_name, 'department' => $v->department, 'purpose' => $v->purpose_other ? $v->purpose . ': ' . $v->purpose_other : $v->purpose,
            'in' => self::dt($v->check_in_at), 'out' => self::dt($v->check_out_at), 'duration' => self::mins($mins), 'minutes' => $mins, 'status' => $v->status, 'by' => optional($v->registeredBy)->name, 'out_by' => optional($v->checkedOutBy)->name, 'companions' => (int) $v->companions, 'project' => optional($v->warehouse)->name];
    }

    private static function visitCols(): array
    {
        return [['key' => 'ref', 'label' => __('Visit')], ['key' => 'visitor', 'label' => __('Visitor')], ['key' => 'company', 'label' => __('Company')], ['key' => 'project', 'label' => __('Attended from')], ['key' => 'host', 'label' => __('Person visited')], ['key' => 'purpose', 'label' => __('Purpose')],
            ['key' => 'in', 'label' => __('Check-in')], ['key' => 'out', 'label' => __('Check-out')], ['key' => 'duration', 'label' => __('Duration')], ['key' => 'status', 'label' => __('Status')], ['key' => 'by', 'label' => __('Receptionist')]];
    }

    private static function dailyRegister(array $f, User $u): array
    {
        if (! $f['from'] && ! $f['to']) {
            $f['from'] = $f['to'] = now()->toDateString();
        }
        $rows = self::visits($f, $u)->orderBy('check_in_at')->get()->map(fn ($v) => self::visitRow($v))->all();
        $tiles = [['label' => __('Still on premises'), 'value' => count(array_filter($rows, fn ($r) => $r['status'] === VisitorVisit::IN))], ['label' => __('Checked out'), 'value' => count(array_filter($rows, fn ($r) => $r['status'] === VisitorVisit::OUT))]];

        return [self::visitCols(), $rows, __('Daily Visitor Register'), __('Every visit of :d.', ['d' => $f['from'] === $f['to'] ? date('d M Y', strtotime($f['from'])) : date('d M Y', strtotime($f['from'] ?: 'now')) . ' - ' . date('d M Y', strtotime($f['to'] ?: 'now'))]), $tiles];
    }

    private static function personHistory(array $f, User $u): array
    {
        $rows = self::visits($f, $u)->orderByDesc('check_in_at')->get()->map(fn ($v) => self::visitRow($v))->all();

        return [self::visitCols(), $rows, __('Visitor History by Person'), $f['visitor_id'] ? __('All the visits of :n.', ['n' => optional(Visitor::withTrashed()->find($f['visitor_id']))->full_name]) : __('Choose the visitor to see their visits.'), [['label' => __('Visits'), 'value' => count($rows)]]];
    }

    private static function grouped(array $f, User $u, string $by): array
    {
        $visits = self::visits($f, $u)->whereIn('status', [VisitorVisit::IN, VisitorVisit::OUT])->get();
        $key = fn ($v) => match ($by) { 'company' => optional($v->visitor)->company ?: __('(no company)'), 'host' => $v->host_name ?: __('(unknown)'), default => $v->department ?: __('(no department)') };
        $rows = [];
        foreach ($visits->groupBy($key) as $name => $g) {
            $done = $g->filter(fn ($v) => $v->check_out_at);
            $rows[] = ['name' => $name, 'visits' => $g->count(), 'visitors' => $g->pluck('visitor_id')->unique()->count(), 'total' => self::mins((int) $done->sum('duration_minutes')), 'average' => $done->count() ? self::mins((int) round($done->avg('duration_minutes'))) : '', 'last' => self::dt($g->max('check_in_at')), 'minutes' => (int) $done->sum('duration_minutes')];
        }
        usort($rows, fn ($a, $b) => $b['visits'] <=> $a['visits'] ?: strcmp($a['name'], $b['name']));
        $label = ['company' => __('Company'), 'host' => __('Person visited'), 'department' => __('Department')][$by];
        $title = ['company' => __('Visitors by Company'), 'host' => __('Visitors by Recipient / Employee'), 'department' => __('Visitors by Department')][$by];

        return [[['key' => 'name', 'label' => $label], ['key' => 'visits', 'label' => __('Visits'), 'type' => 'num'], ['key' => 'visitors', 'label' => __('Visitors'), 'type' => 'num'], ['key' => 'total', 'label' => __('Total time')], ['key' => 'average', 'label' => __('Average visit')], ['key' => 'last', 'label' => __('Last visit')]],
            $rows, $title, __('Visits added up by :l (cancelled visits are not counted).', ['l' => mb_strtolower($label)]), [['label' => __('Visits'), 'value' => $visits->count()]]];
    }

    private static function onPremises(array $f, User $u): array
    {
        $rows = self::visits(['from' => null, 'to' => null, 'status' => ''] + $f, $u)->where('status', VisitorVisit::IN)->orderBy('check_in_at')->get()->map(fn ($v) => self::visitRow($v))->all();
        $cols = array_values(array_filter(self::visitCols(), fn ($c) => ! in_array($c['key'], ['out', 'status'], true)));

        return [$cols, $rows, __('Currently on Premises'), __('Visitors who are checked in now (:t).', ['t' => now()->format('d M Y H:i')]), [['label' => __('On premises'), 'value' => count($rows)]]];
    }

    private static function checkinOut(array $f, User $u): array
    {
        $rows = self::visits($f, $u)->orderBy('check_in_at')->get()->map(fn ($v) => self::visitRow($v))->all();
        $cols = [['key' => 'ref', 'label' => __('Visit')], ['key' => 'visitor', 'label' => __('Visitor')], ['key' => 'company', 'label' => __('Company')], ['key' => 'host', 'label' => __('Person visited')], ['key' => 'in', 'label' => __('Check-in')], ['key' => 'by', 'label' => __('Checked in by')],
            ['key' => 'out', 'label' => __('Check-out')], ['key' => 'out_by', 'label' => __('Checked out by')], ['key' => 'duration', 'label' => __('Duration')], ['key' => 'status', 'label' => __('Status')]];

        return [$cols, $rows, __('Visitor Check-In and Check-Out History'), __('Each visit with who checked the visitor in and out.'), [['label' => __('Checked out'), 'value' => count(array_filter($rows, fn ($r) => $r['status'] === VisitorVisit::OUT))]]];
    }

    private static function durations(array $f, User $u): array
    {
        $rows = self::visits($f, $u)->where('status', VisitorVisit::OUT)->get()->sortByDesc('duration_minutes')->map(fn ($v) => self::visitRow($v))->values()->all();
        $m = array_column($rows, 'minutes');
        $cols = [['key' => 'ref', 'label' => __('Visit')], ['key' => 'visitor', 'label' => __('Visitor')], ['key' => 'company', 'label' => __('Company')], ['key' => 'host', 'label' => __('Person visited')], ['key' => 'purpose', 'label' => __('Purpose')], ['key' => 'in', 'label' => __('Check-in')], ['key' => 'out', 'label' => __('Check-out')], ['key' => 'duration', 'label' => __('Duration')]];
        $tiles = $m ? [['label' => __('Average'), 'value' => self::mins((int) round(array_sum($m) / count($m)))], ['label' => __('Longest'), 'value' => self::mins(max($m))], ['label' => __('Shortest'), 'value' => self::mins(min($m))]] : [];

        return [$cols, $rows, __('Visit Duration Report'), __('Completed visits, longest first.'), $tiles];
    }

    // ---------------------------------------------------------------------------------------------------- items

    private static function items(array $f, User $u, string $kind): array
    {
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $days = VisitorSettings::collectionDays();
        $docTypes = collect(VisitorOption::listOf('item_type', false))->where('flag', 'document')->pluck('name')->all();
        $pkgTypes = collect(VisitorOption::listOf('item_type', false))->where('flag', 'package')->pluck('name')->all();
        $q = VisitorIncomingItem::query()->visibleTo($u)->with(['receivedBy:id,name', 'releasedBy:id,name', 'warehouse:id,name'])->when($f['warehouse_id'], fn ($x, $v) => $x->where('warehouse_id', $v))
            ->when($f['from'], fn ($x, $v) => $x->whereDate('received_at', '>=', $v))->when($f['to'], fn ($x, $v) => $x->whereDate('received_at', '<=', $v))
            ->when($f['recipient_id'], fn ($x, $v) => $x->where('recipient_user_id', $v))->when($f['item_status'] !== '', fn ($x) => $x->where('status', $f['item_status']))->when($f['item_type'] !== '', fn ($x) => $x->where('item_type', $f['item_type']))
            ->when($f['search'] !== '', fn ($x) => $x->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('recipient_name', 'like', $like($f['search']))->orWhere('tracking_number', 'like', $like($f['search']))
                ->orWhere(fn ($s) => $s->where(fn ($y) => $y->where('sender_name', 'like', $like($f['search']))->orWhere('sender_company', 'like', $like($f['search']))->orWhere('subject', 'like', $like($f['search'])))
                    ->when(! ($u->hasRole('Super Admin') || $u->can('view-confidential-incoming-items')), fn ($y) => $y->where(fn ($c) => $c->where('is_confidential', false)->orWhere('recipient_user_id', $u->id)->orWhere('received_by_user_id', $u->id))))));
        $title = '';
        $about = '';
        switch ($kind) {
            case 'letters': $q->whereIn('item_type', $docTypes ?: ['-']); $title = __('Incoming Letters and Documents Register'); $about = __('Letters, documents and official correspondence received at reception.'); break;
            case 'packages': $q->whereIn('item_type', $pkgTypes ?: ['-']); $title = __('Incoming Packages and Deliveries Report'); $about = __('Packages, parcels and courier deliveries received at reception.'); break;
            case 'awaiting': $q->open(); $title = __('Items Awaiting Collection'); $about = __('Items not yet handed over or closed.'); break;
            case 'delivered': $q->where('status_flag', 'handed'); $title = __('Items Delivered to Recipients'); $about = __('Items handed over to their recipient or an authorized collector.'); break;
            default: $q->open()->when($days > 0, fn ($x) => $x->where('received_at', '<', now()->subDays($days)), fn ($x) => $x->whereRaw('1 = 0')); $title = __('Overdue Collection Report'); $about = $days > 0 ? __('Items that have waited more than :n day(s).', ['n' => $days]) : __('The reminder is off: set the days on the Visitor Logs settings.');
        }
        $rows = $q->orderBy('received_at', $kind === 'delivered' ? 'desc' : 'asc')->get()->map(function ($i) use ($u) {
            $see = $i->canSeeDetails($u);

            return ['ref' => $i->reference, 'received' => self::dt($i->received_at), 'type' => $i->item_type, 'sender' => $see ? trim(($i->sender_name ?: '') . ($i->sender_company ? ' (' . $i->sender_company . ')' : '')) : __('Confidential'), 'subject' => $see ? $i->subject : __('Confidential'),
                'courier' => $see ? trim(($i->courier ?: '') . ($i->tracking_number ? ' ' . $i->tracking_number : '')) : '', 'recipient' => $i->recipient_name, 'status' => $i->status, 'received_by' => optional($i->receivedBy)->name, 'changed' => self::dt($i->status_changed_at),
                'project' => optional($i->warehouse)->name, 'waiting' => $i->isOpen() ? (int) $i->received_at->diffInDays(now()) : '', 'handed_to' => $i->handed_to, 'handed_at' => self::dt($i->handed_at), 'released_by' => optional($i->releasedBy)->name];
        })->all();
        $base = [['key' => 'ref', 'label' => __('Item')], ['key' => 'received', 'label' => __('Received')], ['key' => 'project', 'label' => __('Attended from')], ['key' => 'type', 'label' => __('Type')], ['key' => 'sender', 'label' => __('Sender')], ['key' => 'subject', 'label' => __('Subject')], ['key' => 'recipient', 'label' => __('Recipient')]];
        $cols = match ($kind) {
            'delivered' => array_merge($base, [['key' => 'handed_to', 'label' => __('Handed to')], ['key' => 'handed_at', 'label' => __('Handed at')], ['key' => 'released_by', 'label' => __('Released by')], ['key' => 'status', 'label' => __('Status')]]),
            'awaiting', 'overdue' => array_merge($base, [['key' => 'status', 'label' => __('Status')], ['key' => 'waiting', 'label' => __('Days waiting'), 'type' => 'num'], ['key' => 'received_by', 'label' => __('Received by')]]),
            default => array_merge($base, [['key' => 'courier', 'label' => __('Courier / tracking')], ['key' => 'status', 'label' => __('Status')], ['key' => 'received_by', 'label' => __('Received by')], ['key' => 'changed', 'label' => __('Last change')]]),
        };

        return [$cols, $rows, $title, $about, [['label' => __('Items'), 'value' => count($rows)]]];
    }

    // ---------------------------------------------------------------------------------------------------- notifications and audit

    private static function notifications(array $f): array
    {
        $rows = [];
        $logs = DB::table('wims_connect_logs')->where('event_key', 'like', 'visitor\\_logs-%')->when($f['from'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($f['channel'] !== '' && $f['channel'] !== 'bell', fn ($q) => $q->where('channel', $f['channel']))->when($f['channel'] === 'bell', fn ($q) => $q->whereRaw('1 = 0'))
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))->orderByDesc('id')->limit(3000)->get();
        $names = User::withTrashed()->whereIn('id', $logs->pluck('recipient_user_id')->filter())->pluck('name', 'id');
        foreach ($logs as $l) {
            $rows[] = ['at' => date('d M Y H:i', strtotime($l->created_at)), 'channel' => strtoupper($l->channel), 'event' => ucfirst(str_replace(['visitor_logs-', '-'], ['', ' '], $l->event_key)), 'recipient' => $names[$l->recipient_user_id] ?? '', 'address' => $l->channel === 'email' ? $l->address : preg_replace('/\d(?=\d{3})/', '*', (string) $l->address),
                'status' => $l->status, 'error' => $l->error_message];
        }
        if ($f['channel'] === '' || $f['channel'] === 'bell') {
            $bells = DB::table('notifications')->where('data', 'like', '%"module":"visitor_logs"%')->when($f['from'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v))->orderByDesc('created_at')->limit(3000)->get();
            $bn = User::withTrashed()->whereIn('id', $bells->pluck('notifiable_id'))->pluck('name', 'id');
            foreach ($bells as $b) {
                $data = json_decode($b->data, true) ?: [];
                $rows[] = ['at' => date('d M Y H:i', strtotime($b->created_at)), 'channel' => 'APP', 'event' => (string) ($data['title'] ?? ''), 'recipient' => $bn[$b->notifiable_id] ?? '', 'address' => '', 'status' => $b->read_at ? 'READ' : 'SENT', 'error' => ''];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($b['at'], $a['at']));
        $cols = [['key' => 'at', 'label' => __('When')], ['key' => 'channel', 'label' => __('Channel')], ['key' => 'event', 'label' => __('Alert')], ['key' => 'recipient', 'label' => __('Recipient')], ['key' => 'address', 'label' => __('Sent to')], ['key' => 'status', 'label' => __('Outcome')], ['key' => 'error', 'label' => __('Problem')]];

        return [$cols, $rows, __('Visitor Notification Log'), __('Alerts about visitors and incoming items. The message text is not repeated here.'), [['label' => __('Failed'), 'value' => count(array_filter($rows, fn ($r) => $r['status'] === 'FAILED'))]]];
    }

    private static function audit(array $f): array
    {
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $ev = VisitorLogEvent::query()->with('user:id,name')->when($f['from'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($f['event'] !== '', fn ($q) => $q->where('event', $f['event']))->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('subject_ref', 'like', $like($f['search']))->orWhere('reason', 'like', $like($f['search']))))->orderByDesc('id')->limit(5000)->get();
        $text = fn ($a) => $a ? collect($a)->map(fn ($v, $k) => str_replace('_', ' ', $k) . ': ' . (is_scalar($v) || $v === null ? (string) $v : json_encode($v)))->implode('; ') : '';
        $rows = $ev->map(fn ($e) => ['at' => $e->created_at->format('d M Y H:i'), 'kind' => ucfirst($e->subject_type), 'ref' => $e->subject_ref, 'event' => ucfirst(str_replace('_', ' ', $e->event)), 'by' => optional($e->user)->name, 'reason' => $e->reason, 'before' => $text($e->old_value), 'after' => $text($e->new_value)])->all();
        $cols = [['key' => 'at', 'label' => __('When')], ['key' => 'kind', 'label' => __('Record')], ['key' => 'ref', 'label' => __('Reference')], ['key' => 'event', 'label' => __('What happened')], ['key' => 'by', 'label' => __('By')], ['key' => 'reason', 'label' => __('Reason')], ['key' => 'before', 'label' => __('Before')], ['key' => 'after', 'label' => __('After')]];

        return [$cols, $rows, __('Audit and Correction History'), __('Every change to a visit, an item or a visitor, in time order (newest first).'), [['label' => __('Corrections'), 'value' => count(array_filter($rows, fn ($r) => $r['event'] === 'Corrected'))]]];
    }
}
