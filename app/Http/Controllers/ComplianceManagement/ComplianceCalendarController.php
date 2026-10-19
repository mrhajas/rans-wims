<?php

namespace App\Http\Controllers\ComplianceManagement;

use App\Http\Controllers\Controller;
use App\Models\ComplianceRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ComplianceCalendarController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('read-compliance-records');

        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);
        $firstOfWindow = now()->setDate($year, $month, 1)->startOfMonth();
        // Shows a 6-month span starting at month/year (padded a week either
        // side so a period boundary event isn't dropped for landing one day
        // outside the visible grid).
        $start = $firstOfWindow->copy()->subDays(7);
        $end = $firstOfWindow->copy()->addMonthsNoOverflow(5)->endOfMonth()->addDays(7);
        $months = collect(range(0, 5))->map(fn ($i) => [
            'year' => (int) $firstOfWindow->copy()->addMonthsNoOverflow($i)->year,
            'month' => (int) $firstOfWindow->copy()->addMonthsNoOverflow($i)->month,
        ])->values();

        $events = $this->buildEvents($start, $end);

        return Inertia::render('Compliance/Calendar', [
            'events' => $events->values(),
            'month' => $month,
            'year' => $year,
            'months' => $months,
        ]);
    }

    /**
     * JSON widget for embedding a compact multi-month view elsewhere (e.g.
     * a modal on the main dashboard) without paying for a full Inertia page
     * render. `months` controls the span width (default 4).
     */
    public function widget(Request $request)
    {
        $this->authorize('read-compliance-records');

        $month = (int) $request->query('month', now()->month);
        $year = (int) $request->query('year', now()->year);
        $span = max(1, (int) $request->query('months', 4));
        $firstOfWindow = now()->setDate($year, $month, 1)->startOfMonth();
        $start = $firstOfWindow->copy()->subDays(7);
        $end = $firstOfWindow->copy()->addMonthsNoOverflow($span - 1)->endOfMonth()->addDays(7);

        return response()->json([
            'events' => $this->buildEvents($start, $end)->values(),
            'month' => $month,
            'year' => $year,
        ]);
    }

    /**
     * Live badge count for the dashboard calendar icon — records due within
     * the next 14 days or already expired (mirrors the "critical"/"warning"
     * severity bands used elsewhere).
     */
    public function dueCount(Request $request)
    {
        $this->authorize('read-compliance-records');

        // Every not-yet-resolved record with a due date — expired-and-unrenewed
        // included — not just a near-term window, so the badge reflects the
        // true outstanding total.
        $query = ComplianceRecord::query()
            ->whereNotNull('expiry_date')
            ->whereNotIn('status', ['Cancelled', 'Renewed']);

        $count = (clone $query)->count();

        $upcoming = (clone $query)
            ->orderBy('expiry_date')
            ->limit(8)
            ->get()
            ->map(fn (ComplianceRecord $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'compliance_number' => $r->compliance_number,
                'days_remaining' => $r->days_remaining,
                'days_remaining_label' => $r->days_remaining_label,
                'url' => route('compliance-records.show', $r->id),
            ]);

        return response()->json(['count' => $count, 'upcoming' => $upcoming]);
    }

    private function buildEvents($start, $end)
    {
        // The "Contract Notice" event is derived (expiry_date minus a
        // per-record notice period), so it can fall inside the visible
        // window even when the raw expiry_date itself is months away —
        // filtering by date columns in SQL would silently drop those.
        // Compute every candidate date in PHP first, then keep only what
        // actually lands in [start, end].
        $records = ComplianceRecord::query()
            ->where(function ($q) {
                $q->whereNotNull('expiry_date')
                    ->orWhereNotNull('renewal_date')
                    ->orWhereNotNull('submission_date')
                    ->orWhereNotNull('next_review_date')
                    ->orWhereNotNull('loan_next_payment_date');
            })
            ->get();

        $events = collect();

        foreach ($records as $record) {
            $this->pushEvent($events, $record, $record->expiry_date, 'Expiry', $this->severity($record->days_remaining), $start, $end);
            $this->pushEvent($events, $record, $record->renewal_date, 'Renewal', 'info', $start, $end);
            $this->pushEvent($events, $record, $record->submission_date, 'Submission', 'info', $start, $end);
            $this->pushEvent($events, $record, $record->next_review_date, 'Review', 'info', $start, $end);
            $this->pushEvent($events, $record, $record->loan_next_payment_date, 'Loan Payment', 'info', $start, $end);

            if ($record->expiry_date && $record->contract_notice_period_days) {
                $noticeDate = $record->expiry_date->copy()->subDays($record->contract_notice_period_days);
                $this->pushEvent($events, $record, $noticeDate, 'Contract Notice', 'warning', $start, $end);
            }
        }

        return $events;
    }

    private function pushEvent($events, ComplianceRecord $record, $date, string $type, string $severity, $start, $end): void
    {
        if (! $date || $date->lt($start) || $date->gt($end)) {
            return;
        }

        $events->push([
            'date' => $date->toDateString(),
            'title' => "{$record->title} - {$type}",
            'type' => $type,
            'severity' => $severity,
            'url' => route('compliance-records.show', $record->id),
            'meta' => [
                'compliance_number' => $record->compliance_number,
                'compliance_type' => $record->compliance_type,
                'status' => $record->status,
                'issuing_authority' => $record->issuing_authority,
                'days_remaining_label' => $record->days_remaining_label,
                'photo_url' => $record->photo ? Storage::disk('assets')->url($record->photo) : null,
            ],
        ]);
    }

    private function severity(?int $daysRemaining): string
    {
        if ($daysRemaining === null) {
            return 'info';
        }
        if ($daysRemaining < 0) {
            return 'critical';
        }
        if ($daysRemaining <= 7) {
            return 'critical';
        }
        if ($daysRemaining <= 30) {
            return 'warning';
        }

        return 'info';
    }
}
