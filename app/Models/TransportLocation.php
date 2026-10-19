<?php

namespace App\Models;

/**
 * A small, reusable, searchable master list for the Transport Record
 * destination types that aren't already first-class WIMS entities
 * (Warehouses/Projects and Suppliers/Clients are, and are referenced
 * directly instead — see TransportRecord::warehouse()/destinationContact()).
 */
class TransportLocation extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    const TYPE_OFFICE = 'Office';

    const TYPE_SITE = 'Site';

    const TYPE_WORKSHOP = 'Workshop';

    const TYPE_PORT = 'Port';

    const TYPE_AIRPORT = 'Airport';

    const TYPE_GOVERNMENT_OFFICE = 'Government Office';

    const TYPE_OTHER = 'Other Location';

    const LOCATION_TYPES = [
        self::TYPE_OFFICE, self::TYPE_SITE, self::TYPE_WORKSHOP, self::TYPE_PORT,
        self::TYPE_AIRPORT, self::TYPE_GOVERNMENT_OFFICE, self::TYPE_OTHER,
    ];

    protected $fillable = ['name', 'location_type', 'address', 'notes', 'is_active', 'created_by_user_id'];

    public $casts = [
        'is_active' => 'boolean',
        'extra_attributes' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function transportRecords()
    {
        return $this->hasMany(TransportRecord::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%");
            });
        })->when($filters['location_type'] ?? null, function ($query, $type) {
            $query->where('location_type', $type);
        });
    }
}
