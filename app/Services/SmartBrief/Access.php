<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use App\Services\FeatureManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may see whose Smart Brief. Every request goes through here on the server; nothing relies on a name appearing in a list.
 *
 *  - your own brief: needs "view-own-smart-brief" and the My Smart Brief function switched on;
 *  - somebody else's: needs the Management function on, "view-management-smart-brief", and a SCOPE that reaches that person:
 *      all (view-all-smart-briefs) | team = shares a project with you (view-team-smart-briefs) | assigned = an active access assignment from
 *      you to them (view-assigned-smart-briefs). The subject must be in the same account; a Super Admin's brief is for Super Admins only.
 *  - what you see inside someone else's brief is cut down again by the section abilities (timeline, sessions, record changes, approvals,
 *    sensitive) and by the modules you may read yourself (Modules::visibleTo).
 * Super Admin passes every ability (Gate::before) and every Feature Control switch, as everywhere in WIMS.
 */
class Access
{
    public static function feature(string $key, ?User $user = null): bool
    {
        return FeatureManager::canAccess($key, $user ?? auth()->user());
    }

    public static function canViewOwn(User $user): bool
    {
        return $user->can('view-own-smart-brief') && self::feature('smart_brief_my', $user);
    }

    public static function hasScope(User $viewer, string $scope): bool
    {
        return $viewer->can(['all' => 'view-all-smart-briefs', 'team' => 'view-team-smart-briefs', 'assigned' => 'view-assigned-smart-briefs'][$scope]);
    }

    public static function canOpenManagement(User $viewer): bool
    {
        return $viewer->can('view-management-smart-brief') && self::feature('smart_brief_management', $viewer)
            && ($viewer->can('view-all-smart-briefs') || $viewer->can('view-team-smart-briefs') || $viewer->can('view-assigned-smart-briefs'));
    }

    public static function activeAssignment(int $viewerId, int $subjectId): bool
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        return DB::table('smart_brief_access_assignments')
            ->where('viewer_user_id', $viewerId)->where('subject_user_id', $subjectId)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->exists();
    }

    public static function projectIds(int $userId): array
    {
        return DB::table('user_warehouse')->where('user_id', $userId)->pluck('warehouse_id')->all();
    }

    public static function sharesProject(int $a, int $b): bool
    {
        $mine = self::projectIds($a);

        return $mine !== [] && DB::table('user_warehouse')->where('user_id', $b)->whereIn('warehouse_id', $mine)->exists();
    }

    /** May $viewer open the brief of $subject at all? (Own brief included.) */
    public static function canViewSubject(User $viewer, User $subject): bool
    {
        if ((int) $viewer->id === (int) $subject->id) {
            return self::canViewOwn($viewer);
        }
        if (! self::canOpenManagement($viewer) || (int) $viewer->account_id !== (int) $subject->account_id) {
            return false;
        }
        if ($subject->hasRole('Super Admin') && ! $viewer->hasRole('Super Admin')) {
            return false;
        }
        if (self::hasScope($viewer, 'all')) {
            return true;
        }
        if (self::hasScope($viewer, 'assigned') && self::activeAssignment($viewer->id, $subject->id)) {
            return true;
        }

        return self::hasScope($viewer, 'team') && self::sharesProject($viewer->id, $subject->id);
    }

    /** Query of the users whose brief $viewer may open through the management view (never includes the viewer). */
    public static function viewableUsers(User $viewer): Builder
    {
        $q = User::query()->where('account_id', $viewer->account_id)->where('id', '!=', $viewer->id);
        if (! self::canOpenManagement($viewer)) {
            return $q->whereRaw('1 = 0');
        }
        if (! $viewer->hasRole('Super Admin')) {
            $q->whereDoesntHave('roles', fn ($r) => $r->where('name', 'Super Admin'));
        }
        if (self::hasScope($viewer, 'all')) {
            return $q;
        }
        $ids = collect();
        if (self::hasScope($viewer, 'assigned')) {
            $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
            $ids = $ids->merge(DB::table('smart_brief_access_assignments')->where('viewer_user_id', $viewer->id)->whereNull('revoked_at')
                ->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
                ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->pluck('subject_user_id'));
        }
        if (self::hasScope($viewer, 'team') && ($mine = self::projectIds($viewer->id)) !== []) {
            $ids = $ids->merge(DB::table('user_warehouse')->whereIn('warehouse_id', $mine)->pluck('user_id'));
        }

        return $q->whereIn('id', $ids->unique()->values()->all());
    }

    /** The sections of a brief that $viewer may see for $subject. Your own brief is whole; somebody else's depends on the abilities. */
    public static function sections(User $viewer, User $subject): array
    {
        $own = (int) $viewer->id === (int) $subject->id;
        $on = fn (string $feature, string $ability) => self::feature($feature, $viewer) && ($own || $viewer->can($ability));

        return [
            'own' => $own,
            'timeline' => $on('smart_brief_timeline', 'view-smart-brief-timeline'),
            'sessions' => $on('smart_brief_sessions', 'view-smart-brief-sessions'),
            'changes' => $on('smart_brief_changes', 'view-smart-brief-record-changes'),
            'approvals' => $on('smart_brief_approvals', 'view-smart-brief-approvals'),
            'sensitive' => $own || $viewer->can('view-smart-brief-sensitive'),
            'export' => self::feature('smart_brief_export', $viewer) && ($own || $viewer->can('export-smart-brief')),
        ];
    }

    /** Record that somebody looked at / exported another person's brief (own views are not logged). */
    public static function audit(User $viewer, User $subject, string $action, array $details = []): void
    {
        if ((int) $viewer->id === (int) $subject->id) {
            return;
        }
        log_activity('Smart Brief ' . $action, array_merge([
            'viewer_user_id' => $viewer->id, 'viewer' => $viewer->name,
            'subject_user_id' => $subject->id, 'subject' => $subject->name,
        ], $details), $subject, 'Smart Brief');
    }
}
