<?php

namespace App\Models;

class LabourWorkItem extends Model
{
    public static $hasAccount = false;

    protected $fillable = ['text', 'use_count'];
}
