<?php

namespace App\Models;

class AssetType extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['text', 'use_count'];
}
