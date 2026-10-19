<?php

namespace App\Services\AiTools;

use App\Models\IssueTransaction;
use App\Models\IssueTransactionItem;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class IssueReturnTool
{
    use ResolvesEntities;

    public function whoHasItem(array $input, User $user): array
    {
        $itemName = $input['item_name'] ?? null;
        $item = $this->resolveItem($itemName);

        if (! $item) {
            return ['found' => false, 'message' => "No item matching \"{$itemName}\" was found."];
        }

        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $query = IssueTransactionItem::ofAccount()
            ->where('item_id', $item->id)
            ->where('balance_quantity', '>', 0)
            // The header (IssueTransaction) is $hasUser-scoped to "mine" by
            // default — "who has this issued" is a company-wide question,
            // not a personal one, so we deliberately look across everyone's
            // issue transactions here, same as CheckinTool::last() does.
            ->with(['issueTransaction' => fn ($q) => $q->withoutGlobalScope('mine')->with('warehouse:id,name')]);

        if ($scope['ids'] !== null) {
            $query->whereHas('issueTransaction', function ($q) use ($scope) {
                $q->withoutGlobalScope('mine')->whereIn('warehouse_id', $scope['ids']);
            });
        }

        $rows = $query->limit(25)->get()
            ->filter(fn ($line) => $line->issueTransaction !== null)
            ->map(fn ($line) => [
                'issued_to' => $line->issueTransaction->issued_to,
                'issue_number' => $line->issueTransaction->issue_number,
                'warehouse' => optional($line->issueTransaction->warehouse)->name,
                'outstanding_quantity' => (float) $line->balance_quantity,
            ])->values()->all();

        return ['found' => true, 'item' => $item->name, 'count' => count($rows), 'holders' => $rows];
    }

    public function overdue(array $input, User $user): array
    {
        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $query = IssueTransaction::ofAccount()->withoutGlobalScope('mine')
            ->where('status', 'overdue')
            ->with('warehouse:id,name');

        if ($scope['ids'] !== null) {
            $query->whereIn('warehouse_id', $scope['ids']);
        }

        $rows = $query->limit(25)->get()->map(fn ($t) => [
            'issue_number' => $t->issue_number,
            'issued_to' => $t->issued_to,
            'warehouse' => optional($t->warehouse)->name,
            'expected_return_date' => optional($t->expected_return_date)->format('Y-m-d'),
            'balance_quantity' => (float) $t->balance_quantity,
        ])->values()->all();

        return ['found' => true, 'count' => count($rows), 'overdue' => $rows];
    }
}
