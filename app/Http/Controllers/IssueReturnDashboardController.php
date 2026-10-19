<?php

namespace App\Http\Controllers;

use App\Models\IssueReturn;
use App\Models\IssueReturnItem;
use App\Models\IssueTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IssueReturnDashboardController extends Controller
{
    public function __construct()
    {
        // Dashboard authorization is handled in canReadModule().
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($this->canReadModule(), 403);

        $today = now()->toDateString();
        $userId = (int) auth()->id();
        $isRestrictedToOwnTransactions = $this->isRestrictedToOwnTransactions();

        $statusCase = "CASE
            WHEN COALESCE(returned_quantity, 0) > 0 AND COALESCE(balance_quantity, 0) <= 0 THEN 'fully_returned'
            WHEN COALESCE(returned_quantity, 0) > 0 THEN 'partially_returned'
            WHEN expected_return_date IS NOT NULL AND expected_return_date < CURDATE() THEN 'overdue'
            ELSE 'open'
        END";

        $issuesBase = IssueTransaction::query()
            ->when($isRestrictedToOwnTransactions, fn ($q) => $q->where('user_id', $userId));

        $returnsBase = IssueReturn::query()
            ->when($isRestrictedToOwnTransactions, fn ($q) => $q->whereHas('issueTransaction', fn ($iq) => $iq->where('user_id', $userId)));

        $cards = [
            'total_issued_items' => (float) (clone $issuesBase)->sum('total_quantity'),
            'outstanding_issues' => (clone $issuesBase)->where('balance_quantity', '>', 0)->count(),
            'fully_returned' => (clone $issuesBase)->whereRaw("{$statusCase} = ?", ['fully_returned'])->count(),
            'partially_returned' => (clone $issuesBase)->whereRaw("{$statusCase} = ?", ['partially_returned'])->count(),
            'overdue_returns' => (clone $issuesBase)->whereRaw("{$statusCase} = ?", ['overdue'])->count(),
            'damaged_returns' => (float) IssueReturnItem::query()
                ->where('condition', 'damaged')
                ->when($isRestrictedToOwnTransactions, fn ($q) => $q->whereHas('issueReturn.issueTransaction', fn ($iq) => $iq->where('user_id', $userId)))
                ->sum('returning_qty'),
            'todays_issues' => (clone $issuesBase)->whereDate('issue_date', $today)->count(),
            'todays_returns' => (clone $returnsBase)->whereDate('return_date', $today)->count(),
        ];

        return Inertia::render('IssueReturn/Dashboard', [
            'today' => $today,
            'cards' => $cards,
            'latestIssues' => IssueTransaction::query()
                ->with(['warehouse:id,name'])
                ->select('issue_transactions.*')
                ->selectRaw("{$statusCase} as computed_status")
                ->when($isRestrictedToOwnTransactions, fn ($q) => $q->where('user_id', $userId))
                ->latest('id')
                ->limit(10)
                ->get(),
            'latestReturns' => IssueReturn::query()
                ->with(['issueTransaction:id,issue_number,warehouse_id', 'issueTransaction.warehouse:id,name'])
                ->when($isRestrictedToOwnTransactions, fn ($q) => $q->whereHas('issueTransaction', fn ($iq) => $iq->where('user_id', $userId)))
                ->latest('id')
                ->limit(10)
                ->get(['id', 'issue_transaction_id', 'return_date', 'returned_by', 'received_by']),
            'allIssues' => IssueTransaction::query()
                ->with(['warehouse:id,name'])
                ->select('issue_transactions.*')
                ->selectRaw("{$statusCase} as computed_status")
                ->when($isRestrictedToOwnTransactions, fn ($q) => $q->where('user_id', $userId))
                ->latest('id')
                ->limit(200)
                ->get(),
            'allReturns' => IssueReturn::query()
                ->with(['issueTransaction:id,issue_number,warehouse_id', 'issueTransaction.warehouse:id,name'])
                ->when($isRestrictedToOwnTransactions, fn ($q) => $q->whereHas('issueTransaction', fn ($iq) => $iq->where('user_id', $userId)))
                ->latest('id')
                ->limit(200)
                ->get(['id', 'issue_transaction_id', 'return_date', 'returned_by', 'received_by']),
        ]);
    }

    private function isProcurementManager(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(['Procurement Manager', 'Procurement Manger']);
    }

    private function isRestrictedToOwnTransactions(): bool
    {
        return $this->isSiteEngineer() || $this->isProcurementManager();
    }

    private function isSiteEngineer(): bool
    {
        $user = auth()->user();

        return (bool) $user && $user->hasRole(User::SITE_ENGINEER_ROLES);
    }

    private function canReadModule(): bool
    {
        $user = auth()->user();

        if ($user && $user->hasRole('Store Manager')) {
            return false;
        }

        return $user && (
            $user->hasRole(['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger', ...User::SITE_ENGINEER_ROLES]) ||
            $user->can('read-issue-returns') ||
            $user->can('create-issue-returns') ||
            $user->can('return-issue-returns')
        );
    }
}
