<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TaskReportController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        Gate::authorize('viewReports', Task::class);

        return Inertia::render('TaskManager/Report', $this->reportData($request));
    }

    public function print(Request $request)
    {
        Gate::authorize('printReports', Task::class);

        return view('task-manager-report-print', $this->reportData($request, false) + [
            'printedAt' => now()->format('Y-m-d H:i'),
            'printedBy' => $request->user()->name,
            'isPdf' => false,
        ]);
    }

    public function pdf(Request $request)
    {
        Gate::authorize('printReports', Task::class);

        $pdf = Pdf::loadView('task-manager-report-print', $this->reportData($request, false) + [
            'printedAt' => now()->format('Y-m-d H:i'),
            'printedBy' => $request->user()->name,
            'isPdf' => true,
        ]);

        return $pdf->download('task-report-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function reportData(Request $request, bool $paginate = true): array
    {
        $filters = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'assignee_id' => 'nullable|integer',
            'priority' => 'nullable|string|in:low,normal,high,urgent',
            'status' => 'nullable|string|in:todo,in_progress,review,done',
            'category' => 'nullable|string',
            'sort' => 'nullable|string|in:title,priority,status,category,due_date,created_at',
            'direction' => 'nullable|string|in:asc,desc',
        ]);

        $base = Task::query();
        $this->applyFilters($base, $filters);

        $stats = [
            'total' => (clone $base)->count(),
            'todo' => (clone $base)->where('status', 'todo')->count(),
            'in_progress' => (clone $base)->where('status', 'in_progress')->count(),
            'review' => (clone $base)->where('status', 'review')->count(),
            'completed' => (clone $base)->where('status', 'done')->count(),
            'overdue' => (clone $base)->whereNotNull('due_date')->where('status', '!=', 'done')
                ->whereDate('due_date', '<', now()->toDateString())->count(),
        ];

        $byPriority = (clone $base)->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority');
        $byStatus = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $listQuery = (clone $base)->with(['createdBy:id,name', 'assignees.assignee']);
        $sort = $filters['sort'] ?? 'due_date';
        $direction = $filters['direction'] ?? 'asc';
        $listQuery->orderBy($sort, $direction);

        return [
            'stats' => $stats,
            'byPriority' => $byPriority,
            'byStatus' => $byStatus,
            'tasks' => $paginate ? paginated_response($listQuery->paginate(20)->withQueryString()) : $listQuery->get(),
            'assignableUsers' => User::ofAccount()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => TaskManagerController::defaultCategories(),
            'filters' => $filters,
        ];
    }

    protected function applyFilters($query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['assignee_id'])) {
            $query->whereHas('assignees', fn ($q) => $q->where('assignee_type', User::class)->where('assignee_id', $filters['assignee_id']));
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
    }
}
