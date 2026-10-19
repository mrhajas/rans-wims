<?php

namespace App\Http\Controllers;

use App\Models\StaffContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Staff > Contracts calendar: every contract that ends, starts or was renewed, by day, with what needs attention. */
class StaffContractCalendarController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-staff-contracts'), 403);
        $today = CarbonImmutable::today();

        $rows = StaffContract::query()->with('staff:id,name,staff_number,position,photo')->where('status', StaffContract::STATUS_ACTIVE)->whereNotNull('end_date')->orderBy('end_date')->get();
        $pack = fn (StaffContract $c) => $this->event($c, 'ends');

        return Inertia::render('Staff/ContractCalendar', [
            'today' => $today->toDateString(),
            'attention' => [
                'expired' => $rows->filter(fn ($c) => $c->daysLeft() < 0)->map($pack)->values(),
                'soon' => $rows->filter(fn ($c) => $c->daysLeft() >= 0 && $c->daysLeft() <= 90)->map($pack)->values(),
            ],
            'totals' => [
                'active' => StaffContract::where('status', StaffContract::STATUS_ACTIVE)->count(),
                'open' => StaffContract::where('status', StaffContract::STATUS_ACTIVE)->whereNull('end_date')->count(),
                'permanent' => StaffContract::where('status', StaffContract::STATUS_ACTIVE)->where('type', 'Permanent')->count(),
                'expired' => $rows->filter(fn ($c) => $c->daysLeft() < 0)->count(),
                'soon' => $rows->filter(fn ($c) => $c->daysLeft() >= 0 && $c->daysLeft() <= StaffContract::SOON_DAYS)->count(),
                'renewed_this_year' => StaffContract::where('status', StaffContract::STATUS_RENEWED)->whereYear('updated_at', $today->year)->count(),
            ],
            'canUpdate' => $request->user()->can('update-staffs'),
        ]);
    }

    /** The events of one stretch of dates (the month on screen, plus the grey days around it). */
    /** The small summary behind the dashboard icon: how many contracts need attention, and the first few (most urgent first). */
    public function summary(Request $request)
    {
        abort_unless($request->user()->can('read-staff-contracts'), 403);

        $rows = StaffContract::query()->with('staff:id,name,position')->where('status', StaffContract::STATUS_ACTIVE)->whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays(StaffContract::SOON_DAYS)->toDateString())->orderBy('end_date')->get();
        $count = fn (string $state) => $rows->filter(fn ($c) => $c->state() === $state)->count();

        return response()->json([
            'count' => $rows->count(),
            'expired' => $count('expired'),
            'urgent' => $count('urgent'),
            'soon' => $count('soon'),
            'contracts' => $rows->take(6)->map(function (StaffContract $c) {
                $left = $c->daysLeft();

                return [
                    'id' => $c->id,
                    'name' => optional($c->staff)->name ?: '-',
                    'detail' => $c->position ?: (optional($c->staff)->position ?: $c->type),
                    'end' => optional($c->end_date)->format('d M Y'),
                    'state' => $c->state(),
                    'label' => $left < 0 ? abs($left).' d overdue' : ($left === 0 ? 'ends today' : $left.' d left'),
                ];
            })->values(),
        ]);
    }

    public function events(Request $request)
    {
        abort_unless($request->user()->can('read-staff-contracts'), 403);
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 62, 422, 'Ask for at most two months at a time.');

        $events = [];
        $endings = StaffContract::query()->with('staff:id,name,staff_number,position,photo')->whereBetween('end_date', [$from->toDateString(), $to->toDateString()])->get();
        foreach ($endings as $c) {
            $events[] = $this->event($c, 'ends');
        }
        $starts = StaffContract::query()->with('staff:id,name,staff_number,position,photo', 'renewedFrom:id,contract_number')->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])->get();
        foreach ($starts as $c) {
            $events[] = $this->event($c, $c->renewed_from_id ? 'renewal' : 'starts');
        }

        return response()->json(['events' => $events]);
    }

    private function event(StaffContract $c, string $kind): array
    {
        $p = $c->present();

        return [
            'id' => $c->id.'-'.$kind, 'kind' => $kind, 'date' => $kind === 'ends' ? $p['end_date'] : $p['start_date'],
            'contract_id' => $c->id, 'contract_number' => $c->contract_number, 'type' => $c->type, 'state' => $p['state'], 'status' => $c->status, 'days_left' => $p['days_left'],
            'staff' => ['id' => $c->staff_id, 'name' => optional($c->staff)->name, 'number' => optional($c->staff)->staff_number, 'position' => optional($c->staff)->position],
            'url' => route('staffs.show', $c->staff_id).'?tab=contracts',
        ];
    }
}
