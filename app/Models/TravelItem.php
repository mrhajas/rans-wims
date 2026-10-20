<?php

namespace App\Models;

/**
 * One other cost of a travel (accommodation, transport, hotel, food, leisure, any expense) with its own currency and exchange rate. With a contact it is owed to
 * that agent (it falls on the agent's balance once the travel is approved); without one it is the company's own expense. Ticket and visa are not items: they
 * stay on the travel and are always owed to their agents.
 */
class TravelItem extends Model
{
    public static $hasAccount = false;

    protected $table = 'travel_items';

    public const DEFAULT_CATEGORIES = 'Accommodation, Hotel, Transportation, Car rental, Food and drinks, Leisure, Allowance, Other expense';

    protected $fillable = ['travel_id', 'category', 'description', 'contact_id', 'currency', 'exchange_rate', 'amount', 'amount_tzs', 'sort_order'];

    public $casts = ['exchange_rate' => 'decimal:6', 'amount' => 'decimal:2', 'amount_tzs' => 'decimal:2'];

    public function travel()
    {
        return $this->belongsTo(Travel::class, 'travel_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id')->withTrashed();
    }

    /** The categories of the Travels settings list (comma separated). */
    public static function categoriesSet(): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) (get_settings('travel_item_categories') ?: self::DEFAULT_CATEGORIES)) ?: []), fn ($x) => $x !== '')));
    }

    /** The categories a person picks from: the settings list plus any already used on a record, so an old record never loses its category. */
    public static function categories(): array
    {
        $set = self::categoriesSet();
        $used = \Illuminate\Support\Facades\DB::table('travel_items')->whereNull('deleted_at')->distinct()->pluck('category')->all();

        return array_values(array_unique(array_merge($set, $used)));
    }

    /** amount x rate in TZS, to the cent. */
    public static function toTzs(float $amount, float $rate): float
    {
        return round($amount * $rate, 2);
    }
}
