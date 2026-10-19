<?php

/*
|--------------------------------------------------------------------------
| Simulation WIMS
|--------------------------------------------------------------------------
| A second, completely separate set of databases that the SAME application can run on, for authorised demonstrations, training and scenario planning.
| Everything here is server side. Nothing the browser sends can choose a database: the environment of a request comes from an encrypted cookie that only
| the server issues, and it is checked against the session on every request (see App\Support\Simulation\Environment).
|
| OFF by default. To turn it on set SIMULATION_ENABLED=true and the SIM_DB_* values in .env, then run `php artisan simulation:install`.
*/
return [
    'enabled' => (bool) env('SIMULATION_ENABLED', false),

    // The simulation databases are named in config/database.php (mysql_simulation, accounts_simulation) from SIM_DB_* in .env.
    // The login that owns them should have rights on those two databases only, and none on production.

    // The encrypted cookie that tells the server which environment a browser is using, and the simulation's own session cookie (production's is untouched).
    'cookie' => 'wims_env',
    'session_cookie' => 'wims_sim_session',

    // How long a "pending" selection (made on the login page, before signing in) lives, in minutes.
    'pending_minutes' => (int) env('SIMULATION_PENDING_MINUTES', 10),

    // The hidden trigger on the login page: this many clicks on the logo within this many milliseconds.
    'trigger' => ['clicks' => (int) env('SIMULATION_TRIGGER_CLICKS', 3), 'window_ms' => (int) env('SIMULATION_TRIGGER_WINDOW_MS', 2500)],

    // Where simulation files live: a folder inside each storage disk, so a simulation upload can never overwrite a production file.
    'storage_folder' => '_simulation',

    // The role every simulation user is given, and the abilities that role must NOT have (they are about running production, not simulating it).
    'role' => 'Category Authority Simulation',
    'never_permissions' => [
        'manage-simulation-accounts', 'manage-backups', 'manage-impersonation', 'start-impersonation', 'read-impersonation', 'manage-wims-connect', 'read-wims-connect', 'read-wims-connect-bulk-connect',
        'send-wims-connect-bulk-connect', 'print-wims-connect-bulk-connect', 'read-wims-licence', 'update-wims-licence', 'bypass-system-lockdown', 'send-security-reminders', 'update-permissions', 'update-roles',
    ],

    // While the sign-in alert is being tested only these production usernames are told (comma separated in .env); empty = every Super Admin the Simulation Alerts page names.
    'alert_only_usernames' => array_values(array_filter(array_map('trim', explode(',', (string) env('SIMULATION_ALERT_ONLY', ''))))),
    'refused_alert_after' => (int) env('SIMULATION_REFUSED_ALERT_AFTER', 3),

    // What the Import Centre may read from production. A table that is not listed here cannot be imported (see App\Services\Simulation\SimulationCatalog).
    'import_batch_limit' => 2000,
];
