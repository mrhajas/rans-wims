<?php

namespace App\Http\Controllers\Weather;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Weather\WeatherAccess;
use Illuminate\Http\Request;

/** Weather & Site Risk: the Authorizable auto-derivation is off; every action authorises its own ability, its feature switch and the project. */
abstract class WeatherBaseController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    protected function allow(Request $request, string $ability, string $feature = 'weather_risk'): User
    {
        $user = $request->user();
        abort_unless($user && $user->can($ability) && WeatherAccess::feature('weather_risk', $user) && WeatherAccess::feature($feature, $user), 403);

        return $user;
    }

    /** A project the person may see; anything else answers 404 so its existence is not revealed. */
    protected function project(User $user, $id): Warehouse
    {
        $id = $id instanceof Warehouse ? $id->id : (int) $id;
        $w = WeatherAccess::projects($user, false)->where('warehouses.id', $id)->whereNull('warehouses.deleted_at')->first();
        abort_unless($w, 404);

        return $w;
    }

    protected function projectOptions(User $user, bool $activeOnly = true): array
    {
        return WeatherAccess::projects($user, $activeOnly)->whereNull('warehouses.deleted_at')->orderBy('warehouses.name')->get(['warehouses.id', 'warehouses.name', 'warehouses.code', 'warehouses.status', 'warehouses.completed_at'])
            ->map(fn ($w) => ['value' => (string) $w->id, 'label' => $w->name . ($w->code ? ' (' . $w->code . ')' : ''), 'name' => $w->name, 'status' => $w->status, 'completed_at' => $w->completed_at])->all();
    }
}
