<?php

namespace App\Http\Controllers\ComplianceManagement;

use App\Http\Controllers\Controller;
use App\Models\ComplianceRecord;
use App\Models\ComplianceRenewalHistory;
use Inertia\Inertia;

class ComplianceDashboardController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index()
    {
        $this->authorize('read-compliance-records');

        $statusCounts = ComplianceRecord::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        // compliance_records.status flows Renewed -> Active in the same
        // request (see ComplianceRenewalController::complete()), so
        // "Renewed" never actually persists there — the completed-renewal
        // count has to come from the renewal history rows instead.
        $completedThisMonth = ComplianceRenewalHistory::query()
            ->where('status', 'Renewed')
            ->whereMonth('renewed_at', now()->month)
            ->whereYear('renewed_at', now()->year)
            ->count();

        $buckets = [
            'expired' => ComplianceRecord::expired()->count(),
            'due_0_7' => ComplianceRecord::dueWithin(7)->count(),
            'due_8_30' => ComplianceRecord::query()->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>', now()->addDays(7))
                ->whereDate('expiry_date', '<=', now()->addDays(30))->count(),
            'due_31_60' => ComplianceRecord::query()->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>', now()->addDays(30))
                ->whereDate('expiry_date', '<=', now()->addDays(60))->count(),
            'due_61_90' => ComplianceRecord::query()->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>', now()->addDays(60))
                ->whereDate('expiry_date', '<=', now()->addDays(90))->count(),
        ];

        return Inertia::render('Compliance/Dashboard', [
            'cards' => [
                'total_items' => ComplianceRecord::count(),
                'active' => (int) ($statusCounts['Active'] ?? 0),
                'due_within_90' => ComplianceRecord::dueWithin(90)->count(),
                'due_within_30' => ComplianceRecord::dueWithin(30)->count(),
                'due_within_7' => ComplianceRecord::dueWithin(7)->count(),
                'expired' => (int) ($statusCounts['Expired'] ?? 0),
                'renewal_in_progress' => (int) ($statusCounts['Renewal in Progress'] ?? 0),
                'pending_approval' => (int) ($statusCounts['Pending Approval'] ?? 0),
                'completed_this_month' => $completedThisMonth,
            ],
            'expiryBuckets' => $buckets,
        ]);
    }
}
