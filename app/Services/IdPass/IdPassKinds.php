<?php

namespace App\Services\IdPass;

use App\Models\User;
use App\Services\FeatureManager;

/** The four kinds of ID, side by side: the words, the route name, the Feature Control key and the permissions of each. One list, so no controller repeats it. */
class IdPassKinds
{
    public const ALL = [
        'staff' => ['slug' => 'staff', 'route' => 'staff', 'feature' => 'id_staff', 'label' => 'Staff IDs', 'singular' => 'Staff ID', 'icon' => 'user', 'tone' => '#1565c0',
            'read' => 'read-staff-ids', 'create' => 'assign-staff-ids', 'update' => 'update-staff-ids', 'print' => 'print-staff-ids', 'download' => 'download-staff-ids', 'revoke' => 'revoke-staff-ids', 'approve' => null],
        'compound' => ['slug' => 'workers', 'route' => 'compound', 'feature' => 'id_compound', 'label' => 'Compound Worker IDs', 'singular' => 'Compound Worker ID', 'icon' => 'group', 'tone' => '#4d7c0f',
            'read' => 'read-compound-ids', 'create' => 'assign-compound-ids', 'update' => 'update-compound-ids', 'print' => 'print-compound-ids', 'download' => 'download-compound-ids', 'revoke' => 'revoke-compound-ids', 'approve' => null],
        'visitor' => ['slug' => 'visitors', 'route' => 'visitors', 'feature' => 'id_visitor', 'label' => 'Visitor Passes', 'singular' => 'Visitor Pass', 'icon' => 'users', 'tone' => '#6d28d9',
            'read' => 'read-visitor-passes', 'create' => 'create-visitor-passes', 'update' => 'update-visitor-passes', 'print' => 'print-visitor-passes', 'download' => 'download-visitor-passes', 'revoke' => 'revoke-visitor-passes', 'approve' => null],
        'gate' => ['slug' => 'gates', 'route' => 'gates', 'feature' => 'id_gate', 'label' => 'Gate Passes', 'singular' => 'Gate Pass', 'icon' => 'car', 'tone' => '#d97706',
            'read' => 'read-people-gate-passes', 'create' => 'create-people-gate-passes', 'update' => 'update-people-gate-passes', 'print' => 'print-people-gate-passes', 'download' => 'download-people-gate-passes', 'revoke' => 'revoke-people-gate-passes', 'approve' => 'approve-people-gate-passes'],
    ];

    public static function get(string $kind): array
    {
        return self::ALL[$kind] ?? abort(404);
    }

    public static function fromSlug(string $slug): string
    {
        foreach (self::ALL as $k => $v) {
            if ($v['slug'] === $slug) {
                return $k;
            }
        }
        abort(404);
    }

    /** Whether $user can use this kind at all: the module and the kind are on in Global Feature Control, and the person may read it. */
    public static function canRead(string $kind, ?User $u = null): bool
    {
        $u ??= auth()->user();
        $k = self::get($kind);

        return $u && FeatureManager::canAccess('id_passes', $u) && FeatureManager::canAccess($k['feature'], $u) && $u->can('read-id-passes') && $u->can($k['read']);
    }

    /** @return string[] the kinds $user may open */
    public static function readable(?User $u = null): array
    {
        return array_values(array_filter(array_keys(self::ALL), fn ($k) => self::canRead($k, $u)));
    }

    public static function can(string $kind, string $action, ?User $u = null): bool
    {
        $u ??= auth()->user();
        $perm = self::get($kind)[$action] ?? null;

        return $perm && $u && self::canRead($kind, $u) && $u->can($perm);
    }
}
