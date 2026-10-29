<?php

namespace App\Models;

/** A Greeting List Bundle: a named group of the people on the greeting list (Staff, Clients, Directors...) that a greeting can be sent to instead of everybody. */
class HolidayBundle extends Model
{
    protected $table = 'holiday_bundles';

    protected $fillable = ['account_id', 'name', 'note', 'created_by_user_id'];

    public function contacts()
    {
        return $this->belongsToMany(HolidayContact::class, 'holiday_bundle_contacts', 'bundle_id', 'contact_id');
    }
}
