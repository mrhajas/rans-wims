<?php

namespace App\Traits;

use App\Support\Permissions\TrackedPermissionRegistrar;
use Spatie\Permission\PermissionRegistrar;

/**
 * Answers `$user->can('some-permission')` from a ready list instead of searching all permissions every time.
 *
 * Spatie's own check takes about 2.5 ms (it looks the permission up among about nine hundred and walks the person's roles), and a list page asks it hundreds of times
 * (once per row and button; the Weather page 900 times), which was most of the page's time. Here the person's full list of permission names is built once and every
 * check afterwards is a lookup (0.03 ms). The answers are the same as Spatie's: anything this cannot decide for certain (a permission id or object, wildcard permissions,
 * another guard) is still handed to Spatie. A name that is not a permission at all (a policy ability such as "update") answers "no", as Spatie's can() does, but without
 * the exception Spatie throws to say so (which cost about 4 ms each time). The lists are thrown away whenever permissions or roles are changed (TrackedPermissionRegistrar counts that).
 */
trait FastPermissionChecks
{
    /** @var array{gen:int, known:array<string,bool>|null} every real permission name, for the current count of permission resets */
    private static array $permissionSets = ['gen' => -1, 'known' => null];

    /** @var array<string,bool>|null this person's permission names, kept on the user object (so a new request or job always starts from the database) */
    private ?array $fastPermissionNames = null;

    private int $fastPermissionGen = -1;

    private bool $fastPermissionBusy = false;

    /** Giving or taking a permission or a role from this person drops their relations (Spatie does that, but does not reset its permission list): the ready list goes with them. */
    public function unsetRelation($relation)
    {
        if (! $this->fastPermissionBusy && ($relation === 'roles' || $relation === 'permissions')) {
            $this->fastPermissionNames = null;
        }

        return parent::unsetRelation($relation);
    }

    /** true / false when the ready list can answer, null when Spatie must decide. @return array{0:?bool,1:bool} [answer, is a known permission name] */
    private function fastPermission($permission, $guardName): array
    {
        $registrar = app(PermissionRegistrar::class);
        if (! is_string($permission) || $guardName !== null || ! $registrar instanceof TrackedPermissionRegistrar || config('permission.enable_wildcard_permission') || config('permission.teams')
            || PermissionRegistrar::isUid($permission)) {
            return [null, false];
        }

        if (self::$permissionSets['gen'] !== TrackedPermissionRegistrar::$generation) {
            self::$permissionSets = ['gen' => TrackedPermissionRegistrar::$generation, 'known' => null];
        }
        $set = &self::$permissionSets;
        $set['known'] ??= array_fill_keys($registrar->getPermissions()->pluck('name')->all(), true);
        if ($this->fastPermissionNames === null || $this->fastPermissionGen !== TrackedPermissionRegistrar::$generation) {
            $this->fastPermissionBusy = true;
            $hadOwn = $this->relationLoaded('permissions');
            $this->fastPermissionNames = array_fill_keys($this->getAllPermissions()->pluck('name')->all(), true);
            $this->fastPermissionGen = TrackedPermissionRegistrar::$generation;
            // getAllPermissions() leaves each role's full permission list hanging on the user, which the pages would then send to the browser (about 100 KB): put it back as it was
            $this->roles->each(fn ($role) => $role->unsetRelation('permissions'));
            if (! $hadOwn) {
                $this->unsetRelation('permissions');
            }
            $this->fastPermissionBusy = false;
        }
        $mine = $this->fastPermissionNames;

        if (isset($mine[$permission])) {
            return [true, true];
        }

        return [false, isset($set['known'][$permission])];
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        [$answer, $known] = $this->fastPermission($permission, $guardName);
        if ($answer === true || ($answer === false && $known)) {
            return $answer;
        }

        return $this->spatieHasPermissionTo($permission, $guardName); // not a known permission name: Spatie decides (it throws)
    }

    public function checkPermissionTo($permission, $guardName = null): bool
    {
        [$answer, $known] = $this->fastPermission($permission, $guardName);
        if ($answer !== null) {
            return $answer; // true, or false (a permission the person does not hold, or no permission of that name at all)
        }

        return $this->spatieCheckPermissionTo($permission, $guardName);
    }
}
