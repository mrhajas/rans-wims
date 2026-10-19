<?php

namespace App\Models;

class WorkActivity extends Model
{
    protected $table = 'work_activities';

    public function checkouts()
    {
        return $this->hasMany(Checkout::class, 'work_activity_id');
    }

    public function del()
    {
        if ($this->checkouts()->exists()) {
            return false;
        }

        return $this->delete();
    }

    public function delP()
    {
        if ($this->checkouts()->exists()) {
            return false;
        }

        log_activity(__choice('delete_text', ['record' => 'Activity']), $this, $this, 'WorkActivity');

        return $this->forceDelete();
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['trashed'] ?? null, fn ($q, $t) => $q->{$t . 'Trashed'}())
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->search($search));
    }

    public function scopeSearch($query, $s)
    {
        $query->where('name', 'like', "%{$s}%");
    }
}
