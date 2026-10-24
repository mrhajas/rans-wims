<?php

namespace App\Support\Performance;

/** "Does this table exist?" through SchemaCache: Schema::hasTable lists every table with its size from information_schema, which took 0.7 s on the test data. */
class Tables
{
    public static function has(string $table): bool
    {
        return \App\Support\SchemaCache::hasTable($table);
    }
}
