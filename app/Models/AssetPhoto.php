<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Support\Facades\Storage;

class AssetPhoto extends Eloquent
{
    protected $guarded = [];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('assets')->url($this->path);
    }
}
