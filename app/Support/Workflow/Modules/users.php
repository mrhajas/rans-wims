<?php

use App\Support\Workflow\WorkflowModules as W;

$off = ['enabled' => false, 'roles' => ['Super Admin'], 'bell' => true];

return [
    'key' => 'users', 'path' => 'users/workflow', 'page_route' => 'users.workflow', 'update_route' => 'users.workflow.update',
    'title' => 'Users Alerts', 'subtitle' => 'Decide who is told when a user account is created, changed, disabled, restored or deleted, when a password changes, when sign-in security is touched, when someone logs in as another user and when a role or its permissions change, on which channels and with which words. Nothing is sent until you switch an event on.',
    'record' => 'user', 'owner_label' => 'the user the notice is about',
    'events' => [
        'created' => W::ev('User Created', 'A new user account is created.', $off + ['icon' => 'plus']),
        'updated' => W::ev('User Edited', 'A user account (details, roles or projects) is edited and saved.', $off + ['icon' => 'edit']),
        'deleted' => W::ev('User Deleted', 'A user account is deleted (or removed for good).', $off + ['icon' => 'trash']),
        'disabled' => W::ev('User Disabled', 'A user account is disabled: the person can no longer sign in.', $off + ['owner' => false, 'icon' => 'cross']),
        'enabled' => W::ev('User Re-enabled', 'A disabled user account is switched back on.', $off + ['icon' => 'tick']),
        'restored' => W::ev('User Restored', 'A deleted user account is restored.', $off + ['icon' => 'refresh']),
        'impersonation_started' => W::ev('Signed In As Another User', 'A Super Admin starts "Login As User" and works inside another person\'s account (the reason and mode are in the message).', $off + ['icon' => 'exclamation']),
        'password_set_by_admin' => W::ev('Password Set by an Administrator', 'An administrator sets a new password for a user when editing the account.', $off + ['icon' => 'exclamation']),
        'password_changed' => W::ev('Password Changed', 'A user changes their own password from their profile.', $off + ['icon' => 'exclamation']),
        'password_reset' => W::ev('Password Reset by Email Link', 'A user sets a new password through the "forgot password" link.', $off + ['icon' => 'exclamation']),
        'two_factor_disabled' => W::ev('Two-Factor Switched Off by an Administrator', 'An administrator switches off two-factor sign-in for a user.', $off + ['icon' => 'exclamation']),
        'face_login_reset' => W::ev('Face Login Removed by an Administrator', 'A Super Admin removes a user\'s Face ID enrolment.', $off + ['icon' => 'exclamation']),
        'face_login_paused' => W::ev('Face Login Paused', 'Several face sign-in scans for an account did not pass, so Face ID is paused for that user (the user is always told on their own bell).', $off + ['icon' => 'exclamation']),
        // roles screen
        'role_created' => W::ev('Role Created', 'A new role is created (or copied from another role).', $off + ['icon' => 'plus']),
        'role_updated' => W::ev('Role Edited', 'The name of a role is changed.', $off + ['icon' => 'edit']),
        'role_deleted' => W::ev('Role Deleted', 'A role is deleted (or removed for good).', $off + ['icon' => 'trash']),
        'role_restored' => W::ev('Role Restored', 'A deleted role is restored.', $off + ['icon' => 'refresh']),
        'role_permissions_changed' => W::ev('Role Permissions Changed', 'What a role is allowed to do is changed on the Roles screen. Everyone with that role is affected.', $off + ['icon' => 'lock-open']),
    ],
    'tags' => [
        W::tag('user', 'User', 'User name', 'The name of the user the notice is about.', 'Neema Salum'),
        W::tag('username', 'User', 'Username', 'The sign-in username.', 'neema.salum'),
        W::tag('roles', 'User', 'Roles', 'The roles the user has.', 'Store Manager'),
        W::tag('role', 'Role', 'Role name', 'The name of the role the notice is about (roles screen events only).', 'Store Manager'),
        W::tag('permissions', 'Role', 'Permissions', 'How many permissions the role has now (roles screen events only).', '48'),
        W::tag('people', 'Role', 'People with the role', 'How many active users have the role.', '6'),
        W::tag('until', 'User', 'Paused until', 'The time Face ID is paused until (face login paused only).', '14:35'),
    ],
];
