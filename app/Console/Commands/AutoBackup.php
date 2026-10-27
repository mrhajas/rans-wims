<?php

namespace App\Console\Commands;

use App\Models\BackupActivity;
use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoBackup extends Command
{
    protected $signature = 'backup:auto {--force : make a backup now, whatever the saved interval}';

    protected $description = 'Create an automatic database backup when the interval chosen on the Backup page has passed, and keep only the number of automatic backups chosen there.';

    public function handle(DatabaseBackup $backup): int
    {
        // the schedule asks every hour; the interval (8 hours to 15 days) is a setting
        if (! $this->option('force') && ! \App\Support\Backup\AutoBackupSettings::isDue()) {
            return 0;
        }

        $database = config('database.connections.' . config('database.default') . '.database');
        $filename = ($database ?: 'database') . '-auto-backup-' . now()->format('Ymd-His') . '.sql.gz';

        try {
            $path = $backup->saveToDisk($filename);
            $filename = basename($path);
        } catch (\Throwable $e) {
            Log::error('Auto backup failed', ['error' => $e->getMessage()]);
            $this->error('Auto backup failed: ' . $e->getMessage());
            return 1;
        }

        BackupActivity::create([
            'user_id' => null,
            'activity' => 'Auto Back Up',
            'database' => $database,
            'filename' => $filename,
            'properties' => [
                'database' => $database,
                'filename' => $filename,
                'path' => $path,
                'size' => filesize($path),
                'actor' => 'RANS WIMS System',
            ],
        ]);

        $oldBackups = BackupActivity::where('activity', 'Auto Back Up')
            ->orderByDesc('created_at')
            ->get()
            ->slice(\App\Support\Backup\AutoBackupSettings::keep());

        foreach ($oldBackups as $oldBackup) {
            $oldPath = $oldBackup->properties['path'] ?? null;
            if ($oldPath && file_exists($oldPath)) {
                @unlink($oldPath);
            }
            $oldBackup->delete();
        }

        $this->info('Auto backup created: ' . $filename);
        return 0;
    }
}
