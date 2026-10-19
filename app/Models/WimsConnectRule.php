<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WimsConnectRule extends Model
{
    protected $fillable = [
        'name', 'event_key', 'scope_type', 'role_name', 'warehouse_id', 'user_id',
        'channel', 'is_enabled', 'priority', 'created_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Precedence order for RecipientResolver — higher wins. Matches the
     * analysis doc's documented model: user override > project > role >
     * global default.
     */
    public const SCOPE_WEIGHT = [
        'global' => 0,
        'role' => 1,
        'project' => 2,
        'user' => 3,
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * scope_type is deliberately the *most specific* populated dimension
     * rather than a strict single-column selector — a "Role within a
     * Project" rule (section 18 of the brief) sets both role_name and
     * warehouse_id, and is stored as 'project' since warehouse is the more
     * specific of the two. Kept as one small static helper so the Rule
     * Builder controller and this model never disagree on the mapping.
     */
    public static function resolveScopeType(?string $userId, ?string $warehouseId, ?string $roleName): string
    {
        if ($userId) {
            return 'user';
        }
        if ($warehouseId) {
            return 'project';
        }
        if ($roleName) {
            return 'role';
        }

        return 'global';
    }

    public static function computePriority(?int $warehouseId, ?string $roleName, ?int $userId): int
    {
        if ($userId) {
            return 8;
        }

        return ($warehouseId ? 4 : 0) + ($roleName ? 2 : 0);
    }

    public function scopeLabel(): string
    {
        if ($this->scope_type === 'global') {
            return __('Everyone');
        }
        if ($this->scope_type === 'user') {
            return $this->user?->name ?? __('Unknown user');
        }

        $parts = [];
        if ($this->role_name) {
            $parts[] = $this->role_name;
        }
        if ($this->warehouse_id) {
            $parts[] = $this->warehouse?->name ?? __('Unknown project');
        }

        return implode(' · ', $parts) ?: __('Everyone');
    }
}
