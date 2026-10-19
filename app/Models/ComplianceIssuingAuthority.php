<?php

namespace App\Models;

class ComplianceIssuingAuthority extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['text', 'use_count'];
}
