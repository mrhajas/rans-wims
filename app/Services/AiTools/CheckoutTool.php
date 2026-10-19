<?php

namespace App\Services\AiTools;

use App\Models\Checkout;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class CheckoutTool
{
    use ResolvesEntities;

    public function last(array $input, User $user): array
    {
        $warehouse = $this->resolveWarehouse($input['warehouse_name'] ?? null);
        $scope = $this->resolveWarehouseScope($warehouse);

        if ($scope['denied']) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to that warehouse.'];
        }

        $date = $this->parseDate($input['date'] ?? null);

        $query = Checkout::ofAccount()->withoutGlobalScope('mine')->with(['warehouse:id,name', 'user:id,name']);

        if ($scope['ids'] !== null) {
            $query->whereIn('warehouse_id', $scope['ids']);
        }

        if ($date) {
            $query->whereDate('date', $date);
        }

        $checkout = $query->latest('id')->first();

        if (! $checkout) {
            return ['found' => false, 'message' => 'No checkout found matching that criteria.'];
        }

        return [
            'found' => true,
            'reference' => $checkout->reference,
            'date' => $checkout->date,
            'warehouse' => optional($checkout->warehouse)->name,
            'performed_by' => optional($checkout->user)->name,
            'item_count' => $checkout->items()->count(),
        ];
    }

    public function didUserAct(array $input, User $user): array
    {
        $userName = $input['user_name'] ?? null;
        $target = $this->resolveUser($userName);

        if (! $target) {
            return ['found' => false, 'message' => "No user matching \"{$userName}\" was found."];
        }

        if ($target->id !== $user->id && ! $this->userMayViewOthers($user)) {
            return ['error' => 'not_permitted', 'message' => "You do not have permission to view another user's activity."];
        }

        $date = $this->parseDate($input['date'] ?? null) ?? today();

        $count = Checkout::ofAccount()->withoutGlobalScope('mine')
            ->where('user_id', $target->id)
            ->whereDate('date', $date)
            ->count();

        return [
            'found' => true,
            'user' => $target->name,
            'date' => $date->format('Y-m-d'),
            'did_checkout' => $count > 0,
            'count' => $count,
        ];
    }
}
