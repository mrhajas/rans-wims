<?php

namespace App\Models;

/** A person who receives the holiday greetings: copied from a user, a staff record or a contact when somebody imported them, or typed in by hand. */
class HolidayContact extends Model
{
    protected $table = 'holiday_contacts';

    protected $fillable = ['account_id', 'title', 'name', 'email', 'mobile', 'source', 'source_id', 'group_label', 'is_active', 'created_by_user_id'];

    public $casts = ['is_active' => 'boolean'];

    public const SOURCES = ['user' => 'User', 'staff' => 'Staff', 'contact' => 'Contact', 'manual' => 'Added by hand'];

    /** What the greeting calls the person: "Eng. Hajas Seif". */
    public function fullName(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . $this->name);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
