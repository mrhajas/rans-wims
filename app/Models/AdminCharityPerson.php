<?php

namespace App\Models;

/** A person or place in the Charity list with the monthly amount they receive; the Administration document pulls its Charity section from here. */
class AdminCharityPerson extends Model
{
    public static $tracksDeletes = true;

    protected $table = 'admin_charity_people';

    protected $setUser = true;

    protected $fillable = ['account_id', 'name', 'hq', 'phone', 'notes', 'monthly_amount', 'is_active', 'sort_order', 'user_id', 'deleted_by', 'extra_attributes'];

    public $casts = ['monthly_amount' => 'decimal:2', 'is_active' => 'boolean', 'extra_attributes' => 'array'];
}
