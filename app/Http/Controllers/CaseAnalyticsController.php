<?php

namespace App\Http\Controllers;

use App\Models\CaseCategory;
use App\Models\CasePriority;
use App\Models\CaseTicket;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CaseAnalyticsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAnalytics', CaseTicket::class);

        return Inertia::render('CaseTracking/Analytics', $this->computeStats($request) + [
            'statuses' => CaseTicket::statuses(),
            'categories' => CaseCategory::active()->ordered()->get(['id', 'name']),
            'priorities' => CasePriority::active()->ordered()->get(['id', 'name']),
        ]);
    }

    public function print(Request $request)
    {
        $this->authorize('viewAnalytics', CaseTicket::class);

        return view('case-tracking-analytics-print', $this->computeStats($request) + [
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $request->user()->name,
            'isPdf' => false,
        ]);
    }

    public function pdf(Request $request)
    {
        $this->authorize('viewAnalytics', CaseTicket::class);

        $pdf = Pdf::loadView('case-tracking-analytics-print', $this->computeStats($request) + [
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $request->user()->name,
            'isPdf' => true,
        ]);

        return $pdf->download('case-tracking-analytics-' . now()->format('Ymd-His') . '.pdf');
    }

    /** Shared by the Inertia page, the print view, and the PDF download — one computation, three presentations. */
    protected function computeStats(Request $request): array
    {
        $filters = $request->only(['date_from', 'date_to', 'status', 'case_priority_id', 'case_category_id', 'created_by_user_id', 'assigned_to_user_id', 'warehouse_id']);
        $base = CaseTicket::query()->filter($filters);

        // viewAnalytics is a permission independent of read-all-case-tracking
        // (CaseTicketPolicy::viewAnalytics()/allowedTargetStatuses()) — a
        // role granted only "View Case Analytics" would otherwise see
        // company-wide per-assignee/per-category breakdowns covering every
        // user's cases, not just their own. Mirrors
        // CaseTrackingController::scopedQuery()'s exact ownership condition.
        $user = $request->user();
        if (! $user->hasRole(['Super Admin', 'Admin']) && ! $user->can('read-all-case-tracking')) {
            $base->where(function ($q) use ($user) {
                $q->where('created_by_user_id', $user->id)->orWhere('assigned_to_user_id', $user->id);
            });
        }

        $all = (clone $base)->get(['id', 'status', 'case_category_id', 'case_priority_id', 'created_by_user_id', 'assigned_to_user_id', 'warehouse_id', 'created_at', 'resolved_at']);

        $resolved = $all->whereNotNull('resolved_at');
        $avgResolutionHours = $resolved->isNotEmpty()
            ? round($resolved->avg(fn ($c) => $c->created_at->diffInHours($c->resolved_at)), 1)
            : null;

        $byStatus = $all->groupBy('status')->map->count();
        $highPriorityIds = CasePriority::where('level', '>=', 3)->pluck('id');

        return [
            'filters' => $filters,
            'stats' => [
                'total' => $all->count(),
                'open' => $all->whereNotIn('status', [CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED])->count(),
                'in_review' => (int) ($byStatus[CaseTicket::STATUS_IN_REVIEW] ?? 0),
                'in_progress' => (int) ($byStatus[CaseTicket::STATUS_IN_PROGRESS] ?? 0),
                'resolved' => (int) ($byStatus[CaseTicket::STATUS_RESOLVED] ?? 0),
                'closed' => (int) ($byStatus[CaseTicket::STATUS_CLOSED] ?? 0),
                'high_priority' => $all->whereIn('case_priority_id', $highPriorityIds->all())
                    ->whereNotIn('status', [CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED])->count(),
                'avg_resolution_hours' => $avgResolutionHours,
                'long_running' => $all->filter(fn ($c) => ! in_array($c->status, [CaseTicket::STATUS_RESOLVED, CaseTicket::STATUS_CLOSED], true)
                    && $c->created_at->diffInDays(now()) > 14)->count(),
            ],
            'byStatus' => $byStatus,
            'byCategory' => (clone $base)->join('case_categories', 'case_categories.id', '=', 'case_tickets.case_category_id')
                ->select('case_categories.name', DB::raw('count(*) as total'))->groupBy('case_categories.name')->pluck('total', 'name'),
            'byPriority' => (clone $base)->join('case_priorities', 'case_priorities.id', '=', 'case_tickets.case_priority_id')
                ->select('case_priorities.name', DB::raw('count(*) as total'))->groupBy('case_priorities.name')->pluck('total', 'name'),
            'byAssignee' => (clone $base)->whereNotNull('assigned_to_user_id')
                ->join('users', 'users.id', '=', 'case_tickets.assigned_to_user_id')
                ->select('users.name', DB::raw('count(*) as total'))->groupBy('users.name')->orderByDesc('total')->limit(10)->pluck('total', 'name'),
            'createdOverTime' => (clone $base)->select(DB::raw('DATE(created_at) as day'), DB::raw('count(*) as total'))
                ->groupBy('day')->orderBy('day')->pluck('total', 'day'),
            'resolvedOverTime' => (clone $base)->whereNotNull('resolved_at')
                ->select(DB::raw('DATE(resolved_at) as day'), DB::raw('count(*) as total'))
                ->groupBy('day')->orderBy('day')->pluck('total', 'day'),
        ];
    }
}
