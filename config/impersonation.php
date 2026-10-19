<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Session Lifetime
    |--------------------------------------------------------------------------
    |
    | How long a "Login As User" session stays active before it auto-expires,
    | in minutes. The Super Admin can end it earlier at any time.
    |
    */
    'default_expiry_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Allow Impersonating Other Super Admins
    |--------------------------------------------------------------------------
    |
    | Off by default — impersonating another Super Admin is the single
    | highest-privilege-escalation-risk target. Flip only if there is a real
    | operational need, and prefer a short-lived override over leaving it on.
    |
    */
    'allow_super_admin_targets' => false,

    /*
    |--------------------------------------------------------------------------
    | Default Reasons
    |--------------------------------------------------------------------------
    |
    | Always offered in the "Reason for impersonation" dropdown, alongside
    | whatever an account has typed and saved before (see ImpersonationReason).
    |
    */
    'default_reasons' => [
        'Troubleshooting user access',
        'Testing permissions',
        'Investigating a reported issue',
        'User support request',
        'Verifying report access',
        'Confirming project or warehouse access',
        'Reproducing a workflow issue',
        'Auditing role or permission changes',
        'Routine access review',
        'Onboarding verification',
        'Confirming module or feature visibility',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked Route Name Prefixes
    |--------------------------------------------------------------------------
    |
    | Any route whose name starts with one of these prefixes is refused for
    | non-GET/HEAD requests while impersonation is active, regardless of
    | mode — these are the "irreversible / privilege" operations the spec
    | calls out (user & role management, permissions, security & system
    | settings, backups, other people's accounts). Extend this list as new
    | high-risk modules are added.
    |
    */
    'blocked_route_prefixes' => [
        'users.',
        'roles.',
        'system-features.',
        'settings.',
        'backup.',
        'maintenance.',
        'password.update',
        'two-factor.',
        'profile.',
        'account-deletion.',
        'login-logs.',
        'impersonation.',
        'user-profile-information.update',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked Digital Attendance Actions
    |--------------------------------------------------------------------------
    |
    | Viewing attendance (history, reports, calendar) stays available and is
    | scoped to whatever the target user can normally see. Actually clocking
    | in/out or sending a heartbeat as someone else would create attendance
    | records that don't reflect reality, so those specific actions are
    | always blocked during impersonation.
    |
    */
    'blocked_digital_attendance_routes' => [
        'digital-attendance.clock-in',
        'digital-attendance.clock-out',
        'digital-attendance.heartbeat',
    ],
];
