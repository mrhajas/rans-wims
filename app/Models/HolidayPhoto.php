<?php

namespace App\Models;

/** A Super Admin's own photo for the background of a template's email (or "no photo"). Without a row the WIMS photo of the template is used. */
class HolidayPhoto extends Model
{
    protected $table = 'holiday_photos';

    protected $fillable = ['account_id', 'theme', 'path', 'original_name', 'disabled', 'uploaded_by_user_id'];

    public $casts = ['disabled' => 'boolean'];
}
