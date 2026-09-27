<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Switching the database engine is done ONLY here / in .env:
    |   DB_CONNECTION=sqlite | mysql | pgsql
    | No code changes are required. All schema and queries are portable.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        // ------------------------------------------------------------------
        // SQLite — local development and the test suite.
        // WAL journal + busy timeout make concurrent queue workers safe.
        // foreign_keys pragma is enforced so cascades behave like MySQL/PG.
        // ------------------------------------------------------------------
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => (int) env('DB_SQLITE_BUSY_TIMEOUT', 5000),
            'journal_mode' => env('DB_SQLITE_JOURNAL_MODE', 'wal'),
            'synchronous' => env('DB_SQLITE_SYNCHRONOUS', 'normal'),
        ],

        // ------------------------------------------------------------------
        // MySQL 8 — production default. utf8mb4 + InnoDB + strict mode.
        // ------------------------------------------------------------------
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'ahl_alquran'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'ahl_alquran'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // ------------------------------------------------------------------
        // PostgreSQL 15+
        // ------------------------------------------------------------------
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'ahl_alquran'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        // ------------------------------------------------------------------
        // Secondary connection used by `db:transfer` as the TARGET database.
        // Configure with TRANSFER_DB_* variables; driver chosen by
        // TRANSFER_DB_CONNECTION (mysql | pgsql | sqlite).
        // ------------------------------------------------------------------
        'transfer_target' => [
            'driver' => env('TRANSFER_DB_CONNECTION', 'mysql'),
            'url' => env('TRANSFER_DB_URL'),
            'host' => env('TRANSFER_DB_HOST', '127.0.0.1'),
            'port' => env('TRANSFER_DB_PORT', '3306'),
            'database' => env('TRANSFER_DB_DATABASE', 'ahl_alquran'),
            'username' => env('TRANSFER_DB_USERNAME', 'root'),
            'password' => env('TRANSFER_DB_PASSWORD', ''),
            'charset' => env('TRANSFER_DB_CONNECTION', 'mysql') === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'search_path' => env('TRANSFER_DB_SCHEMA', 'public'),
            'sslmode' => 'prefer',
            'foreign_key_constraints' => true,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis — optional. Selected from .env for cache / queue / rate limiting:
    |   CACHE_STORE=redis  QUEUE_CONNECTION=redis
    |--------------------------------------------------------------------------
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'ahl-alquran')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
