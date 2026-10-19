<?php

namespace App\Console\Commands;

use App\Support\PermissionRegistry;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissionRegistry extends Command
{
    protected $signature = 'permissions:sync {--dry-run : List what would be created without writing anything}';

    protected $description = 'Idempotently create any Permission rows declared in the PermissionRegistry but missing from the database.';

    public function handle(): int
    {
        $registryNames = collect(PermissionRegistry::allPermissionNames());
        $existingNames = Permission::pluck('name');
        $missing = $registryNames->diff($existingNames)->values();

        if ($missing->isEmpty()) {
            $this->info('Nothing to do, every registry permission already exists.');

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->info("Would create {$missing->count()} permission(s):");
            $missing->each(fn ($name) => $this->line(" - {$name}"));

            return 0;
        }

        foreach ($missing as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("Created {$missing->count()} permission(s):");
        $missing->each(fn ($name) => $this->line(" - {$name}"));

        return 0;
    }
}
