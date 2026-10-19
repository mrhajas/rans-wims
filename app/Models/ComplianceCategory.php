<?php

namespace App\Models;

class ComplianceCategory extends Model
{
    public static $hasAccount = false;

    public function parent()
    {
        return $this->belongsTo(ComplianceCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(ComplianceCategory::class, 'parent_id');
    }

    public function records()
    {
        return $this->hasMany(ComplianceRecord::class, 'compliance_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInGroup($query, $group)
    {
        return $query->where('group', $group);
    }
}
