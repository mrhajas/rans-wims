<?php

namespace App\Http\Controllers\VisitorLogs;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VisitorLogs\Concerns\Guards;
use App\Models\VisitorIncomingItem;
use App\Models\VisitorVisit;
use App\Services\VisitorLogs\VisitorSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The reception dashboard: who is on the premises now, the day's visits and the items waiting for their recipients. Every figure follows the person's permissions. */
class DashboardController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->needAny(['read-visitor-logs', 'read-incoming-items', 'read-visitors']);
        $me = $request->user();
        $visits = $me->can('read-visitor-logs');
        $items = $me->can('read-incoming-items');
        $days = VisitorSettings::collectionDays();
        $hours = VisitorSettings::overstayHours();
        $out = ['can' => ['visits' => $visits, 'items' => $items, 'create' => $me->can('create-visitor-logs'), 'checkout' => $me->can('checkout-visitor-logs'), 'createItem' => $me->can('create-incoming-items'), 'directory' => $me->can('read-visitors'),
            'reports' => $me->can('read-visitor-reports'), 'settings' => $me->can('manage-visitor-options') || $me->can('manage-visitor-settings'), 'notifications' => $me->can('manage-visitor-notifications') || $me->hasRole('Super Admin'), 'viewActive' => $me->can('view-active-visitors') || $me->can('view-all-visits') || $me->hasRole('Super Admin')],
            'overstayHours' => $hours, 'collectionDays' => $days, 'readyStatus' => \App\Models\VisitorOption::firstWithFlag('item_status', 'ready'), 'location' => VisitorSettings::location(), 'stats' => [], 'onPremises' => [], 'recentVisits' => [], 'recentItems' => []];

        if ($visits) {
            $scope = fn () => VisitorVisit::query()->visibleTo($me);
            $today = now()->toDateString();
            $out['stats'] += [
                'on_premises' => $scope()->active()->count(), 'in_today' => $scope()->whereDate('check_in_at', $today)->whereIn('status', [VisitorVisit::IN, VisitorVisit::OUT])->count(),
                'out_today' => $scope()->whereDate('check_out_at', $today)->where('status', VisitorVisit::OUT)->count(), 'month' => $scope()->whereYear('check_in_at', now()->year)->whereMonth('check_in_at', now()->month)->whereIn('status', [VisitorVisit::IN, VisitorVisit::OUT])->count(),
            ];
            $out['onPremises'] = $scope()->active()->with(['visitor:id,full_name,company,phone', 'registeredBy:id,name'])->orderBy('check_in_at')->limit(100)->get()->map(fn ($v) => $this->visitRow($v, $hours))->values();
            $out['recentVisits'] = $scope()->with(['visitor:id,full_name,company', 'registeredBy:id,name'])->orderByDesc('check_in_at')->limit(8)->get()->map(fn ($v) => $this->visitRow($v, $hours))->values();
        }
        if ($items) {
            $out['stats'] += [
                'items_today' => VisitorIncomingItem::query()->visibleTo($me)->whereDate('received_at', now()->toDateString())->count(), 'items_open' => VisitorIncomingItem::query()->visibleTo($me)->open()->count(), 'items_ready' => VisitorIncomingItem::query()->visibleTo($me)->where('status_flag', 'ready')->count(),
                'items_overdue' => $days > 0 ? VisitorIncomingItem::query()->visibleTo($me)->open()->where('received_at', '<', now()->subDays($days))->count() : 0,
            ];
            $out['recentItems'] = VisitorIncomingItem::query()->visibleTo($me)->with('receivedBy:id,name')->orderByDesc('received_at')->limit(8)->get()->map(function ($i) use ($me) {
                $see = $i->canSeeDetails($me);

                return ['id' => $i->id, 'reference' => $i->reference, 'type' => $i->item_type, 'status' => $i->status, 'flag' => $i->status_flag, 'recipient' => $i->recipient_name, 'sender' => $see ? ($i->sender_company ?: $i->sender_name) : null, 'masked' => ! $see, 'received_at' => $i->received_at->toIso8601String()];
            })->values();
        }

        return Inertia::render('VisitorLogs/Dashboard', $out);
    }

    private function visitRow(VisitorVisit $v, int $overstayHours): array
    {
        $minutes = $v->status === VisitorVisit::IN ? $v->minutesOnPremises() : (int) $v->duration_minutes;

        return ['id' => $v->id, 'reference' => $v->reference, 'visitor' => optional($v->visitor)->full_name, 'company' => optional($v->visitor)->company, 'host' => $v->host_name, 'purpose' => $v->purpose_other ?: $v->purpose, 'status' => $v->status,
            'check_in_at' => $v->check_in_at->toIso8601String(), 'check_out_at' => optional($v->check_out_at)->toIso8601String(), 'minutes' => $minutes, 'duration' => VisitorVisit::durationText($minutes), 'registered_by' => optional($v->registeredBy)->name,
            'long_stay' => $v->status === VisitorVisit::IN && $overstayHours > 0 && $minutes >= $overstayHours * 60];
    }
}
