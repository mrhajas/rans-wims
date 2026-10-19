<?php

namespace App\Models;

/** The saved Funds Allocation template (a single sheet kept as JSON; see App\Support\FundAllocationTemplateSheet). */
class FundAllocationTemplate extends Model
{
    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    protected $fillable = ['name', 'data', 'updated_by_user_id'];

    public $casts = ['data' => 'array'];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
