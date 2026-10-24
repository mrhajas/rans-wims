<?php

namespace App\Models;

/**
 * A Travel Package: a ready-made travel (for example Unguja to Pemba) with what it costs for ONE person: the ticket, the accommodation, the per diem / expenses
 * and the inside transport. A Travel Request made from it multiplies the amounts by the number of people. Every change is kept in travel_package_audits.
 */
class TravelPackage extends Model
{
    protected $table = 'travel_packages';

    public static $tracksDeletes = true;

    /** amount columns shown in the audit history, with their labels */
    public const AMOUNTS = ['ticket_amount' => 'Ticket', 'accommodation_amount' => 'Accommodation', 'per_diem_amount' => 'Expenses / Per Diem', 'transport_amount' => 'Inside Transport'];

    protected $fillable = ['account_id', 'name', 'trip_type', 'from_place', 'from_country', 'to_place', 'to_country', 'currency', 'exchange_rate', 'ticket_amount', 'accommodation_amount', 'per_diem_amount',
        'transport_amount', 'ticket_agent_contact_id', 'notes', 'is_active', 'created_by_user_id', 'deleted_by', 'extra_attributes'];

    public $casts = ['exchange_rate' => 'decimal:6', 'ticket_amount' => 'decimal:2', 'accommodation_amount' => 'decimal:2', 'per_diem_amount' => 'decimal:2', 'transport_amount' => 'decimal:2',
        'is_active' => 'boolean', 'extra_attributes' => 'array'];

    public function audits()
    {
        return $this->hasMany(TravelPackageAudit::class, 'travel_package_id')->orderByDesc('id');
    }

    public function ticketAgent()
    {
        return $this->belongsTo(Contact::class, 'ticket_agent_contact_id')->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function totalPerPerson(): float
    {
        return round((float) $this->ticket_amount + (float) $this->accommodation_amount + (float) $this->per_diem_amount + (float) $this->transport_amount, 2);
    }

    /** "Unguja, Tanzania > Pemba, Tanzania" for lists and the history. */
    public function routeLabel(): string
    {
        $part = fn (?string $place, ?string $country) => trim(implode(', ', array_filter([trim((string) $place), trim((string) $country)])));

        return trim(($part($this->from_place, $this->from_country) ?: '-') . ' > ' . $part($this->to_place, $this->to_country));
    }

    /** The package as it is now: what a history line keeps. */
    public function snapshot(): array
    {
        return [
            'name' => $this->name, 'route' => $this->routeLabel(), 'currency' => $this->currency, 'exchange_rate' => $this->exchange_rate,
            'ticket_amount' => $this->ticket_amount, 'accommodation_amount' => $this->accommodation_amount, 'per_diem_amount' => $this->per_diem_amount, 'transport_amount' => $this->transport_amount,
            'total_per_person' => $this->totalPerPerson(), 'is_active' => (bool) $this->is_active,
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
