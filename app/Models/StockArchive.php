<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockArchive extends Model
{
    protected $guarded = [];

    protected $casts = ['generated_at' => 'datetime', 'deleted_at' => 'datetime', 'emailed_at' => 'datetime', 'total_value' => 'float'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withoutGlobalScopes();
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id')->withoutGlobalScopes();
    }

    public function events()
    {
        return $this->hasMany(StockArchiveEvent::class)->latest('id');
    }

    public function isDeleted(): bool
    {
        return $this->status === 'deleted';
    }
}
