<?php

namespace App\Models;

class ComplianceType extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['text', 'use_count'];
}
