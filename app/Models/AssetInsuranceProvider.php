<?php

namespace App\Models;

class AssetInsuranceProvider extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['text', 'use_count'];
}
