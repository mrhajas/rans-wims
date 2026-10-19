<?php

namespace App\Console\Commands;

use App\Models\BackupActivity;
use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoBackup extends Command
{
    protected $signature = 'backup:auto';

    protected $description = 'Create an automatic database backup and retain only the latest 3 auto backups.';

    public function handle(DatabaseBackup $backup): int
    {
        $database = config('database.connections.' . config('database.default') . '.database');
        $filename = ($database ?: 'database') . '-auto-backup-' . now()->format('Ymd-His') . '.sql';

        try {
            $path = $backup->saveToDisk($filename);
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
            ->slice(3);

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
