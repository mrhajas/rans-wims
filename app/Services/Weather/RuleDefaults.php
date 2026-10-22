<?php

namespace App\Services\Weather;

use App\Models\WeatherRiskRule;
use Illuminate\Support\Facades\DB;

/**
 * STARTING values for the weather-sensitive activities. They are placeholders chosen to make the module usable on day one, NOT engineering or
 * safety limits: every one is shown as "starting value, review against applicable engineering and safety requirements" until a person marks it
 * reviewed. A null threshold is simply not checked.
 */
class RuleDefaults
{
    public const NOTICE = 'Starting value. Review against the applicable engineering and safety requirements (the method statement, the equipment manufacturer\'s limits and site rules) before relying on it.';

    public static function all(): array
    {
        return [
            'concrete_placement' => [
                'label' => 'Concrete placement', 'sort_order' => 1, 'severity' => 'warning', 'lookahead_hours' => 24,
                'keywords' => ['concrete', 'pour', 'slab', 'casting', 'screed', 'blinding', 'footing'],
                'max_wind_kmh' => null, 'max_gust_kmh' => null, 'max_rain_probability_pct' => 40, 'max_precip_mm_per_hour' => 1.0, 'max_precip_mm_per_day' => 10.0, 'min_temp_c' => 5.0, 'max_temp_c' => 35.0,
            ],
            'roofing' => [
                'label' => 'Roofing', 'sort_order' => 2, 'severity' => 'warning', 'lookahead_hours' => 24,
                'keywords' => ['roof', 'truss', 'purlin', 'sheeting', 'gutter'],
                'max_wind_kmh' => 30.0, 'max_gust_kmh' => 45.0, 'max_rain_probability_pct' => 40, 'max_precip_mm_per_hour' => 1.0, 'max_precip_mm_per_day' => null, 'min_temp_c' => null, 'max_temp_c' => 38.0,
            ],
            'crane_lifting' => [
                'label' => 'Crane and lifting', 'sort_order' => 3, 'severity' => 'warning', 'lookahead_hours' => 24,
                'keywords' => ['crane', 'lift', 'hoist', 'rigging', 'precast', 'steel erection', 'erection'],
                'max_wind_kmh' => 30.0, 'max_gust_kmh' => 45.0, 'max_rain_probability_pct' => null, 'max_precip_mm_per_hour' => 10.0, 'max_precip_mm_per_day' => null, 'min_temp_c' => null, 'max_temp_c' => null,
            ],
            'external_finishing' => [
                'label' => 'External finishing and painting', 'sort_order' => 4, 'severity' => 'caution', 'lookahead_hours' => 24,
                'keywords' => ['external', 'facade', 'paint', 'render', 'cladding', 'plaster'],
                'max_wind_kmh' => 40.0, 'max_gust_kmh' => null, 'max_rain_probability_pct' => 40, 'max_precip_mm_per_hour' => 0.5, 'max_precip_mm_per_day' => 5.0, 'min_temp_c' => 5.0, 'max_temp_c' => 35.0,
            ],
            'excavation' => [
                'label' => 'Excavation and earthworks', 'sort_order' => 5, 'severity' => 'caution', 'lookahead_hours' => 48,
                'keywords' => ['excavat', 'earthwork', 'trench', 'foundation', 'backfill', 'grading', 'dewater'],
                'max_wind_kmh' => null, 'max_gust_kmh' => null, 'max_rain_probability_pct' => 60, 'max_precip_mm_per_hour' => 5.0, 'max_precip_mm_per_day' => 25.0, 'min_temp_c' => null, 'max_temp_c' => null,
            ],
        ];
    }

    /** Make sure an account has its five starting rules (never touches a rule that exists). Returns the number added. */
    /** @var array<int,bool> accounts already checked in this process (the check cost one query per rule, per project card) */
    private static array $ensured = [];

    public static function ensure(int $accountId): int
    {
        if (isset(self::$ensured[$accountId])) {
            return 0;
        }
        self::$ensured[$accountId] = true;
        $added = 0;
        foreach (self::all() as $key => $r) {
            if (WeatherRiskRule::query()->where('account_id', $accountId)->where('activity_key', $key)->exists()) {
                continue;
            }
            WeatherRiskRule::query()->create(['account_id' => $accountId, 'activity_key' => $key, 'is_active' => true, 'is_builtin' => true] + $r);
            $added++;
        }

        return $added;
    }

    /** Put one built-in rule back to its starting values (clears the review). */
    public static function restore(WeatherRiskRule $rule): bool
    {
        $d = self::all()[$rule->activity_key] ?? null;
        if (! $d) {
            return false;
        }
        $rule->update($d + ['reviewed_by_user_id' => null, 'reviewed_at' => null, 'review_note' => null]);

        return true;
    }

    public static function accountIds(): array
    {
        return DB::table('accounts')->pluck('id')->map(fn ($i) => (int) $i)->all() ?: [1];
    }
}
