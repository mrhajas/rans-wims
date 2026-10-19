<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/** One approval of a Super Adjustment (phase 1 or 2). A voided row no longer counts but stays as history. */
class SuperAdjustmentApproval extends EloquentModel
{
    protected $table = 'super_adjustment_approvals';

    protected $guarded = [];

    protected $casts = ['approved_at' => 'datetime', 'voided_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function superAdjustment()
    {
        return $this->belongsTo(SuperAdjustment::class);
    }
}
