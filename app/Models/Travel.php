<?php

namespace App\Models;

use App\Traits\HasAttachments;

/**
 * One travel: one or more staff / users going somewhere on a flight, with a ticket (and maybe a visa) issued by a contact. Approval based; an approved travel is
 * what the ticket agent / visa issuer is owed for (see App\Services\Travels\TravelLedger). The date may be in the future.
 */
class Travel extends Model
{
    use HasAttachments;

    protected $table = 'travels';

    public const PENDING = 'Pending Approval';

    public const APPROVED = 'Approved';

    public const REJECTED = 'Rejected';

    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED];

    public const TRIP_TYPES = ['return' => 'Return', 'one_way' => 'One way'];

    /** The lists a person picks from; both can be changed on the Travels settings page. */
    public const DEFAULT_TYPES = 'Business, Training, Meeting, Conference, Site visit, Medical, Personal, Other';

    public const DEFAULT_CURRENCIES = 'TZS, USD, EUR, GBP, AED';

    public static $tracksDeletes = true;

    protected $setHash = true;

    protected $setUser = true;

    protected $fillable = [
        'account_id', 'reference', 'hash', 'status', 'trip_type', 'travel_type', 'purpose', 'from_place', 'from_country', 'to_place', 'to_country', 'scope', 'depart_date', 'return_date', 'booking_date',
        'airline', 'flight_number', 'return_airline', 'return_flight_number', 'booking_reference', 'ticket_agent_contact_id', 'currency', 'exchange_rate', 'ticket_amount',
        'has_visa', 'visa_type', 'visa_country', 'visa_reference', 'visa_agent_contact_id', 'visa_amount', 'total_amount', 'notes', 'user_id',
        'decided_by_user_id', 'decided_at', 'decision_remarks', 'cancelled_by_user_id', 'cancelled_at', 'cancel_reason', 'deleted_by', 'extra_attributes',
    ];

    public $casts = [
        'depart_date' => 'date', 'return_date' => 'date', 'booking_date' => 'date', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime', 'has_visa' => 'boolean',
        'exchange_rate' => 'decimal:6', 'ticket_amount' => 'decimal:2', 'visa_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'extra_attributes' => 'array',
    ];

    public function travellers()
    {
        return $this->hasMany(TravelTraveller::class, 'travel_id')->orderBy('sort_order')->orderBy('id');
    }

    public function items()
    {
        return $this->hasMany(TravelItem::class, 'travel_id')->orderBy('sort_order')->orderBy('id');
    }

    /** Ticket and visa in TZS (their rate is the travel's rate). */
    public function travelTotalTzs(): float
    {
        return round(((float) $this->total_amount) * ((float) ($this->exchange_rate ?: 1)), 2);
    }

    /** The other costs in TZS. */
    public function itemsTotalTzs(): float
    {
        $i = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return round((float) $i->sum(fn ($x) => (float) $x->amount_tzs), 2);
    }

    public function ticketAgent()
    {
        return $this->belongsTo(Contact::class, 'ticket_agent_contact_id')->withTrashed();
    }

    public function visaAgent()
    {
        return $this->belongsTo(Contact::class, 'visa_agent_contact_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function payments()
    {
        return $this->hasMany(TravelAgentPayment::class, 'travel_id');
    }

    public static function travelTypes(): array
    {
        return self::splitList((string) (get_settings('travel_types') ?: self::DEFAULT_TYPES));
    }

    /** The currencies offered: the settings list plus any already used on a record, so an old record never loses its currency. */
    public static function currencies(): array
    {
        $set = array_map('strtoupper', self::splitList((string) (get_settings('travel_currencies') ?: self::DEFAULT_CURRENCIES)));
        $used = \Illuminate\Support\Facades\DB::table('travels')->distinct()->pluck('currency')->all();

        return array_values(array_unique(array_filter(array_merge($set, array_map('strtoupper', $used)))));
    }

    /** The days before departure on which a reminder is sent (Travels settings). */
    public static function remindDays(): array
    {
        $d = array_filter(array_map('intval', self::splitList((string) (get_settings('travel_remind_days') ?: '7, 1'))), fn ($n) => $n >= 0 && $n <= 365);

        return array_values(array_unique($d));
    }

    private static function splitList(string $v): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\n]+/', $v) ?: []), fn ($x) => $x !== '')));
    }

    public const SCOPES = ['local' => 'Local', 'international' => 'International'];

    /** The country the company is in (Travels settings): a travel to any other country is International, a travel inside it is Local. */
    public static function homeCountry(): string
    {
        return trim((string) (get_settings('travel_home_country') ?: 'Tanzania')) ?: 'Tanzania';
    }

    /** local or international, from the country they go to (no country typed = local). */
    public static function scopeOfCountry(?string $country): string
    {
        $c = mb_strtolower(trim((string) $country));

        return $c === '' || $c === mb_strtolower(self::homeCountry()) ? 'local' : 'international';
    }

    public function scopeLabel(): string
    {
        return self::SCOPES[$this->scope] ?? (string) $this->scope;
    }

    public static function nextReference(): string
    {
        $max = (int) \Illuminate\Support\Facades\DB::table('travels')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'TRV-%')->value('m');

        return 'TRV-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function tripLabel(): string
    {
        return self::TRIP_TYPES[$this->trip_type] ?? (string) $this->trip_type;
    }

    /** The travellers' names, for lists, alerts and print. */
    public function travellerNames(string $sep = ', '): string
    {
        $t = $this->relationLoaded('travellers') ? $this->travellers : $this->travellers()->get();

        return $t->pluck('name')->implode($sep);
    }

    /** Who may see which travels: the person who recorded it, a traveller on it, and people who approve / cancel / look after the agents or see everything. */
    public function scopeVisibleTo($q, User $u)
    {
        if ($u->hasRole('Super Admin') || $u->view_all || $u->can('approve-travels') || $u->can('cancel-travels') || $u->can('read-travel-agents')) {
            return $q;
        }

        return $q->where(function ($w) use ($u) {
            $w->where('user_id', $u->id)->orWhereHas('travellers', fn ($t) => $t->where('user_id', $u->id)->orWhereHas('staff', fn ($s) => $s->where('user_id', $u->id)));
        });
    }
}
