<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackup;
use App\Models\BackupActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BackupController extends Controller
{
    /**
     * Every action here already hard-gates on authorizeSuperAdmin() below,
     * so the auto-derived read-backup ability (unregistered — only
     * manage-backups exists) never actually blocks anyone beyond what that
     * explicit check already blocks; disabling it removes the dead,
     * misleading auto-check rather than fixing a real gap.
     */
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index()
    {
        $this->authorizeSuperAdmin();

        return Inertia::render('Backup/Index', [
            'database' => DB::connection()->getDatabaseName(),
            'activities' => BackupActivity::with('user')->latest()->take(50)->get(),
        ]);
    }

    public function download(Request $request, DatabaseBackup $backup)
    {
        $this->authorizeSuperAdmin();

        $request->validate([
            'password' => 'required|current_password',
        ]);

        $database = DB::connection()->getDatabaseName();
        $filename = $database . '-backup-' . now()->format('Ymd-His') . '.sql';

        try {
            $path = $backup->saveToDisk($filename, storage_path('app/tmp-backups'));
        } catch (\Throwable $e) {
            Log::error('Database backup download failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Backup could not be generated: ' . $e->getMessage(),
            ], 500);
        }

        $size = filesize($path);

        activity('Backup')
            ->causedBy(auth()->user())
            ->withProperties(['database' => $database, 'filename' => $filename, 'size' => $size])
            ->log('Database backup downloaded by ' . optional(auth()->user())->name);

        BackupActivity::create([
            'user_id' => auth()->id(),
            'activity' => 'Backup',
            'database' => $database,
            'filename' => $filename,
            'properties' => ['database' => $database, 'filename' => $filename, 'size' => $size],
        ]);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/sql',
        ])->deleteFileAfterSend(true);
    }

    public function createSystemBackup(DatabaseBackup $backup)
    {
        $this->authorizeSuperAdmin();

        $database = DB::connection()->getDatabaseName();
        $filename = ($database ?: 'database') . '-system-backup-' . now()->format('Ymd-His') . '.sql';

        $oldSystemBackups = BackupActivity::where('activity', 'System BackUp')->get();

        try {
            $path = $backup->saveToDisk($filename);
        } catch (\Throwable $e) {
            Log::error('System backup creation failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'System backup failed: ' . $e->getMessage());
        }

        foreach ($oldSystemBackups as $oldBackup) {
            $oldPath = $oldBackup->properties['path'] ?? null;
            if ($oldPath && file_exists($oldPath)) {
                @unlink($oldPath);
            }
            $oldBackup->delete();
        }

        $size = filesize($path);

        activity('Backup')
            ->causedBy(auth()->user())
            ->withProperties([
                'database' => $database,
                'filename' => $filename,
                'path' => $path,
                'size' => $size,
                'replaced_previous' => $oldSystemBackups->isNotEmpty(),
            ])
            ->log('System backup created by ' . optional(auth()->user())->name);

        BackupActivity::create([
            'user_id' => auth()->id(),
            'activity' => 'System BackUp',
            'database' => $database,
            'filename' => $filename,
            'properties' => [
                'database' => $database,
                'filename' => $filename,
                'path' => $path,
                'size' => $size,
            ],
        ]);

        $message = 'System backup created and saved in the server.';
        if ($oldSystemBackups->isNotEmpty()) {
            $message .= ' The previous system backup was replaced.';
        }

        return back()->with('message', $message);
    }

    public function downloadAuto(Request $request, BackupActivity $backupActivity)
    {
        $this->authorizeSuperAdmin();

        if (! in_array($backupActivity->activity, ['Auto Back Up', 'System BackUp'], true)) {
            abort(404);
        }

        $request->validate([
            'password' => 'required|current_password',
        ]);

        $path = $backupActivity->properties['path'] ?? storage_path('app/backups/' . $backupActivity->filename);
        if (! is_file($path) || ! is_readable($path)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The selected backup file could not be found.'], 404);
            }

            return back()->with('error', 'The selected backup file could not be found.');
        }

        $size = filesize($path);

        activity('Backup')
            ->causedBy(auth()->user())
            ->withProperties([
                'filename' => $backupActivity->filename,
                'source' => $backupActivity->activity === 'Auto Back Up' ? 'auto_backup' : 'system_backup',
                'size' => $size,
            ])
            ->log(($backupActivity->activity === 'Auto Back Up' ? 'Auto backup' : 'System backup') . ' downloaded by ' . optional(auth()->user())->name);

        BackupActivity::create([
            'user_id' => auth()->id(),
            'activity' => 'Backup',
            'database' => DB::connection()->getDatabaseName(),
            'filename' => ($backupActivity->activity === 'Auto Back Up' ? 'Auto backup: ' : 'System backup: ') . $backupActivity->filename,
            'properties' => [
                'filename' => $backupActivity->filename,
                'source' => $backupActivity->activity === 'Auto Back Up' ? 'auto_backup' : 'system_backup',
                'size' => $size,
            ],
        ]);

        return response()->download($path, $backupActivity->filename, [
            'Content-Type' => 'application/sql',
        ]);
    }

    public function restore(Request $request, DatabaseBackup $backup)
    {
        $this->authorizeSuperAdmin();

        if (! empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE) {
            return back()->withErrors(['file' => 'The file exceeds the server upload limit (' . ini_get('upload_max_filesize') . ').']);
        }

        $request->validate([
            'file'    => 'required|file|max:204800',
            'confirm' => 'required|in:RESTORE',
            'password' => 'required|current_password',
        ]);

        $uploaded = $request->file('file');
        $extension = strtolower($uploaded->getClientOriginalExtension());
        if (! in_array($extension, ['sql', 'txt'], true)) {
            return back()->withErrors(['file' => 'The file must be a .sql or .txt file.']);
        }

        $path = $uploaded->getRealPath();
        $originalName = $uploaded->getClientOriginalName();
        $size = $uploaded->getSize();

        $database = DB::connection()->getDatabaseName();
        try {
            $result = $backup->restoreFromFile($path);
        } catch (\Throwable $e) {
            Log::error('Database restore failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Restore failed: ' . $e->getMessage());
        }

        activity('Backup')
            ->causedBy(auth()->user())
            ->withProperties([
                'filename'        => $originalName,
                'statements_run'  => $result['statements'],
                'errors_count'    => count($result['errors']),
                'first_errors'    => array_slice($result['errors'], 0, 5),
                'size'            => $size,
            ])
            ->log('Database restore performed by ' . optional(auth()->user())->name);

        BackupActivity::create([
            'user_id' => auth()->id(),
            'activity' => 'Restore',
            'database' => $database,
            'filename' => $originalName,
            'statements_run' => $result['statements'],
            'errors_count' => count($result['errors']),
            'properties' => [
                'filename' => $originalName,
                'statements_run' => $result['statements'],
                'errors_count' => count($result['errors']),
                'first_errors' => array_slice($result['errors'], 0, 5),
                'size' => $size,
            ],
        ]);

        $message = 'Restore completed: ' . $result['statements'] . ' statement(s) executed';
        if (! empty($result['errors'])) {
            $message .= ', ' . count($result['errors']) . ' error(s) logged.';

            return back()->with('error', $message)->with('restoreErrors', array_slice($result['errors'], 0, 10));
        }

        return back()->with('message', $message . '.');
    }

    public function restoreAuto(Request $request, BackupActivity $backupActivity, DatabaseBackup $backup)
    {
        $this->authorizeSuperAdmin();

        if (! in_array($backupActivity->activity, ['Auto Back Up', 'System BackUp'], true)) {
            abort(404);
        }

        $request->validate([
            'confirm' => 'required|in:RESTORE',
            'password' => 'required|current_password',
        ]);

        $path = $backupActivity->properties['path'] ?? storage_path('app/backups/' . $backupActivity->filename);
        if (! is_file($path) || ! is_readable($path)) {
            return back()->with('error', 'The selected backup file could not be found.');
        }

        try {
            $result = $backup->restoreFromFile($path);
        } catch (\Throwable $e) {
            Log::error('Auto backup restore failed', ['error' => $e->getMessage(), 'backup' => $backupActivity->filename]);

            return back()->with('error', 'Restore failed: ' . $e->getMessage());
        }

        $size = filesize($path);

        activity('Backup')
            ->withProperties([
                'filename' => $backupActivity->filename,
                'statements_run' => $result['statements'],
                'errors_count' => count($result['errors']),
                'first_errors' => array_slice($result['errors'], 0, 5),
                'restored_from_auto_backup' => $backupActivity->activity === 'Auto Back Up',
                'restored_from_system_backup' => $backupActivity->activity === 'System BackUp',
                'size' => $size,
            ])
            ->log(($backupActivity->activity === 'Auto Back Up' ? 'Auto backup' : 'System backup') . ' restored by ' . optional(auth()->user())->name);

        BackupActivity::create([
            'user_id' => auth()->id(),
            'activity' => 'Restore',
            'database' => DB::connection()->getDatabaseName(),
            'filename' => ($backupActivity->activity === 'Auto Back Up' ? 'Auto backup: ' : 'System backup: ') . $backupActivity->filename,
            'statements_run' => $result['statements'],
            'errors_count' => count($result['errors']),
            'properties' => [
                'filename' => $backupActivity->filename,
                'statements_run' => $result['statements'],
                'errors_count' => count($result['errors']),
                'first_errors' => array_slice($result['errors'], 0, 5),
                'restored_from_auto_backup' => $backupActivity->activity === 'Auto Back Up',
                'restored_from_system_backup' => $backupActivity->activity === 'System BackUp',
                'size' => $size,
            ],
        ]);

        $message = 'Restore completed: ' . $result['statements'] . ' statement(s) executed';
        if (! empty($result['errors'])) {
            $message .= ', ' . count($result['errors']) . ' error(s) logged.';

            return back()->with('error', $message)->with('restoreErrors', array_slice($result['errors'], 0, 10));
        }

        return back()->with('message', $message . '.');
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user() && auth()->user()->hasRole('Super Admin'), 403, 'Only Super Admin can manage backups.');
    }
}
