<?php

namespace App\Models;

use App\Traits\Paginatable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as ActivityModel;

class Activity extends ActivityModel
{
    use Paginatable;

    public function scopeCurrentAccount($query)
    {
        return $query->where('account_id', auth()->user()->account_id);
    }

    public function scopeFilter($query, array $filters)
    {
        $localCreatedAt = 'created_at';

        $query->when($filters['search'] ?? null, function ($query, $search) use ($localCreatedAt) {
            $query->where(function ($query) use ($search, $localCreatedAt) {
                $query->where('log_name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhereHasMorph('causer', '*', function ($query) use ($search) {
                        $query->where('name', 'like', '%' . $search . '%')
                            ->orWhere('username', 'like', '%' . $search . '%');
                    })
                    ->orWhere('properties->context->device', 'like', '%' . $search . '%')
                    ->orWhere('properties->context->location', 'like', '%' . $search . '%')
                    ->orWhere('properties->context->ip', 'like', '%' . $search . '%')
                    ->orWhere('properties->context->os', 'like', '%' . $search . '%')
                    ->orWhereRaw("DATE_FORMAT({$localCreatedAt}, '%Y-%m-%d') like ?", ['%' . $search . '%'])
                    ->orWhereRaw("DATE_FORMAT({$localCreatedAt}, '%d/%m/%Y') like ?", ['%' . $search . '%'])
                    ->orWhereRaw("DATE_FORMAT({$localCreatedAt}, '%b %e, %Y') like ?", ['%' . $search . '%'])
                    ->orWhereRaw("DATE_FORMAT({$localCreatedAt}, '%M %e, %Y') like ?", ['%' . $search . '%']);
            });
        });

        $query->when($filters['from'] ?? null, function ($query, $from) use ($localCreatedAt) {
            $query->whereRaw("DATE({$localCreatedAt}) >= ?", [$from]);
        });

        $query->when($filters['to'] ?? null, function ($query, $to) use ($localCreatedAt) {
            $query->whereRaw("DATE({$localCreatedAt}) <= ?", [$to]);
        });

        // Module = every log name that belongs to that WIMS module (see ActivityModules); an unknown key matches nothing.
        $query->when($filters['module'] ?? null, function ($query, $module) {
            $names = \App\Support\ActivityModules::namesFor($module);
            $names ? $query->whereIn('log_name', $names) : $query->whereRaw('1 = 0');
        });

        $query->when($filters['user_id'] ?? null, function ($query, $userId) {
            $query->where('causer_type', User::class)->where('causer_id', $userId);
        });
    }

    protected static function booted()
    {
        static::addGlobalScope('account', function (Builder $builder) {
            // Scheduled jobs (e.g. the nightly clean-up) run with nobody logged in: no account to scope to there.
            if ($user = auth()->user()) {
                $builder->where('account_id', $user->account_id);
            }
        });

        static::creating(function ($activity) {
            if (! $activity->account_id) {
                $activity->account_id = auth()->user() ? auth()->user()->account_id : $activity->subject->account_id;
            }

            // Tag every activity with where/how it was performed (IP, location, device, OS).
            if (! app()->runningInConsole()) {
                $properties = $activity->properties;
                if (! $properties instanceof \Illuminate\Support\Collection) {
                    $properties = collect($properties ?? []);
                }
                $activity->properties = $properties->put('context', request_context());
            }

            // Dual-identity during "Login As User": every ordinary log_activity()
            // call elsewhere in the app defaults its causer to Auth::user(),
            // which during impersonation IS the target user — re-attribute
            // those to the real actor (the Super Admin) so the audit trail
            // never falsely reads as if the target independently performed
            // the action, and record both identities in properties. Skipped
            // for impersonation's own start/end/expire/terminate entries,
            // which set their causer explicitly (see App\Support\Impersonation)
            // and may legitimately be a different Super Admin.
            if (\App\Support\Impersonation::isActive()) {
                $properties = $activity->properties instanceof \Illuminate\Support\Collection
                    ? $activity->properties
                    : collect($activity->properties ?? []);

                if ($properties->get('_impersonation_explicit_causer')) {
                    $activity->properties = $properties->forget('_impersonation_explicit_causer');
                } else {
                    $record = \App\Support\Impersonation::record();
                    $original = $record?->originalUser;
                    $target = $record?->targetUser;

                    if ($original) {
                        $activity->causer_type = User::class;
                        $activity->causer_id = $original->id;
                    }

                    $activity->properties = $properties->put('impersonation', [
                        'impersonation_id' => $record?->code,
                        'original_actor_id' => $original?->id,
                        'original_actor_name' => $original?->name,
                        'effective_user_id' => $target?->id,
                        'effective_user_name' => $target?->name,
                    ]);
                }
            }
        });
    }
}
