<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BackupActivity;
use Illuminate\Support\Facades\DB;

class AutoBackupTestSeeder extends Seeder
{
    public function run(): void
    {
        $database = DB::connection()->getDatabaseName() ?: 'database';
        $filename = $database . '-auto-backup-' . now()->format('Ymd-His') . '.sql';
        $directory = storage_path('app/backups');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, "-- Test auto backup created by seeder\n-- Generated: " . now()->toDateTimeString());

        BackupActivity::create([
            'user_id' => null,
            'activity' => 'Auto Back Up',
            'database' => $database,
            'filename' => $filename,
            'statements_run' => null,
            'errors_count' => null,
            'properties' => [
                'path' => $path,
                'actor' => 'RANS WIMS System',
            ],
        ]);

        if ($this->command) {
            $this->command->info('Auto backup test entry created: ' . $filename);
        }
    }
}
