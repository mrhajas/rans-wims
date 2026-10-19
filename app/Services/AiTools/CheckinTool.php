<?php

namespace App\Services\AiTools;

use App\Models\Checkin;
use App\Models\User;
use App\Services\AiTools\Concerns\ResolvesEntities;

class CheckinTool
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

        $query = Checkin::ofAccount()->withoutGlobalScope('mine')->with(['warehouse:id,name', 'user:id,name']);

        if ($scope['ids'] !== null) {
            $query->whereIn('warehouse_id', $scope['ids']);
        }

        if ($date) {
            $query->whereDate('date', $date);
        }

        $checkin = $query->latest('id')->first();

        if (! $checkin) {
            return ['found' => false, 'message' => 'No checkin found matching that criteria.'];
        }

        return [
            'found' => true,
            'reference' => $checkin->reference,
            'date' => $checkin->date,
            'warehouse' => optional($checkin->warehouse)->name,
            'performed_by' => optional($checkin->user)->name,
            'item_count' => $checkin->items()->count(),
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

        $count = Checkin::ofAccount()->withoutGlobalScope('mine')
            ->where('user_id', $target->id)
            ->whereDate('date', $date)
            ->count();

        return [
            'found' => true,
            'user' => $target->name,
            'date' => $date->format('Y-m-d'),
            'did_checkin' => $count > 0,
            'count' => $count,
        ];
    }
}
