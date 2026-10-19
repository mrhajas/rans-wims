<?php

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [
        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DATABASE_URL'),
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver'         => 'mysql',
            'url'            => env('DATABASE_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'forge'),
            'username'       => env('DB_USERNAME', 'forge'),
            'password'       => env('DB_PASSWORD', ''),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => null,
            'timezone'       => null,
            'options'        => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // Accounts Tracking keeps its financial data in its OWN schema. Set DB_ACCOUNTS_DATABASE (and, if the database has its own login,
        // DB_ACCOUNTS_USERNAME / DB_ACCOUNTS_PASSWORD). When it is not set, the tables are created in the main WIMS database instead, so nothing breaks.
        'accounts' => [
            'driver'         => 'mysql',
            'host'           => env('DB_ACCOUNTS_HOST', env('DB_HOST', '127.0.0.1')),
            'port'           => env('DB_ACCOUNTS_PORT', env('DB_PORT', '3306')),
            'database'       => env('DB_ACCOUNTS_DATABASE', env('DB_DATABASE', 'forge')),
            'username'       => env('DB_ACCOUNTS_USERNAME', env('DB_USERNAME', 'forge')),
            'password'       => env('DB_ACCOUNTS_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
            'timezone'       => null,
            'options'        => extension_loaded('pdo_mysql') ? array_filter([PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA')]) : [],
        ],

        // Simulation WIMS (see config/simulation.php). These are only DEFINITIONS: a request is moved onto them by App\Support\Simulation\Environment,
        // never by anything the browser sends. 'mysql' and 'accounts' above are the ones every module uses; the Environment points them at the right database.
        // The simulation database has its OWN login. The read-only production reader is used only by the Import Centre.
        'mysql_simulation' => [
            'driver'         => 'mysql',
            'host'           => env('SIM_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port'           => env('SIM_DB_PORT', env('DB_PORT', '3306')),
            'database'       => env('SIM_DB_DATABASE', ''),
            'username'       => env('SIM_DB_USERNAME', ''),
            'password'       => env('SIM_DB_PASSWORD', ''),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => null,
            'timezone'       => null,
            'options'        => [],
        ],
        'accounts_simulation' => [
            'driver'         => 'mysql',
            'host'           => env('SIM_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port'           => env('SIM_DB_PORT', env('DB_PORT', '3306')),
            // not set = the simulation's accounts tables (acct_*) live inside the simulation's main database, like production when DB_ACCOUNTS_DATABASE is not set
            'database'       => env('SIM_DB_ACCOUNTS_DATABASE', env('SIM_DB_DATABASE', '')),
            'username'       => env('SIM_DB_USERNAME', ''),
            'password'       => env('SIM_DB_PASSWORD', ''),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
            'timezone'       => null,
            'options'        => [],
        ],
        // Production as the Import Centre may look at it: its own login (give it SELECT only), and every transaction on it is READ ONLY at the database level.
        'mysql_production_ro' => [
            'driver'         => 'mysql',
            'host'           => env('SIM_PROD_RO_HOST', env('DB_HOST', '127.0.0.1')),
            'port'           => env('SIM_PROD_RO_PORT', env('DB_PORT', '3306')),
            'database'       => env('SIM_PROD_RO_DATABASE', env('DB_DATABASE', 'forge')),
            'username'       => env('SIM_PROD_RO_USERNAME', env('DB_USERNAME', 'forge')),
            'password'       => env('SIM_PROD_RO_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => null,
            'timezone'       => null,
            'options'        => extension_loaded('pdo_mysql') ? [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY'] : [],
        ],

        'accounts_production_ro' => [
            'driver'         => 'mysql',
            'host'           => env('SIM_PROD_RO_HOST', env('DB_HOST', '127.0.0.1')),
            'port'           => env('SIM_PROD_RO_PORT', env('DB_PORT', '3306')),
            'database'       => env('SIM_PROD_RO_ACCOUNTS_DATABASE', env('DB_ACCOUNTS_DATABASE', env('DB_DATABASE', 'forge'))),
            'username'       => env('SIM_PROD_RO_USERNAME', env('DB_USERNAME', 'forge')),
            'password'       => env('SIM_PROD_RO_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
            'timezone'       => null,
            'options'        => extension_loaded('pdo_mysql') ? [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY'] : [],
        ],

        'pgsql' => [
            'driver'         => 'pgsql',
            'url'            => env('DATABASE_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'forge'),
            'username'       => env('DB_USERNAME', 'forge'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,
            'schema'         => 'public',
            'sslmode'        => 'prefer',
        ],

        'sqlsrv' => [
            'driver'         => 'sqlsrv',
            'url'            => env('DATABASE_URL'),
            'host'           => env('DB_HOST', 'localhost'),
            'port'           => env('DB_PORT', '1433'),
            'database'       => env('DB_DATABASE', 'forge'),
            'username'       => env('DB_USERNAME', 'forge'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix'  => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_database_'),
        ],

        'default' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],
];
