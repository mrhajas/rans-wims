<?php

namespace App\Models;

use App\Traits\LogActivity;
use App\Traits\Paginatable;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Spatie\SchemalessAttributes\SchemalessAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Model extends Eloquent
{
    use HasFactory;
    use LogActivity;
    use Paginatable;
    use SoftDeletes;

    public $casts = ['extra_attributes' => 'array'];

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = true;

    // Records that should remember which user soft-deleted them (deleted_by column).
    public static $tracksDeletes = false;

    protected $guarded = [];

    protected static $logAttributesToIgnore = ['account_id'];

    protected static $logOnlyDirty = true;

    protected $setHash = false;

    protected $setUser = false;

    protected static $submitEmptyLogs = false;

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty();
    }

    public function getExtraAttributesAttribute(): SchemalessAttributes
    {
        return SchemalessAttributes::createForModel($this, 'extra_attributes');
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($this->getRouteKeyName(), $value)->withTrashed()->first();
    }

    public function scopeOfAccount($query, $account = null)
    {
        return $query->where('account_id', $account ?: optional(auth()->user())->account_id);
    }

    public function scopeWithExtraAttributes(): Builder
    {
        return SchemalessAttributes::scopeWithSchemalessAttributes('extra_attributes');
    }

    protected static function booted()
    {
        if (static::$hasAccount) {
            static::addGlobalScope('account', function (Builder $builder) {
                $builder->where('account_id', getAccountId(1));
            });
        }

        if (static::$hasUser) {
            static::addGlobalScope('mine', function (Builder $builder) {
                $user = auth()->user();
                if ($user && ! $user->hasRole('Super Admin') && ! $user->view_all) {
                    $builder->where('user_id', $user->id);
                }
            });
        }

        // Remember who soft-deleted a record, and clear it again on restore.
        static::deleting(function ($model) {
            if ($model::$tracksDeletes && ! $model->isForceDeleting() && auth()->check()) {
                $model->deleted_by = auth()->id();
                $model->saveQuietly();
            }
        });

        static::restoring(function ($model) {
            if ($model::$tracksDeletes) {
                $model->deleted_by = null;
            }
        });

        static::creating(function ($model) {
            if ($model::$hasAccount && ! $model->account_id) {
                $model->account_id = getAccountId();
            }
            if ($model->setHash && ! $model->hash) {
                $model->hash = uuid4();
            }
            if ($model->setUser && ! $model->user_id) {
                $model->user_id = auth()->user()->id;
            }
            if ($model::$hasReference && ! $model->reference) {
                $model->reference = get_reference($model);
            }
        });
    }
}
