<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackup;
use App\Support\Backup\AutoBackupSettings;
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
            // the log is paged (a long history never loads in one go)
            'activities' => BackupActivity::with('user:id,name')->latest('id')->paginate(15)->withQueryString(),
            'backupStats' => [
                'last_backup' => optional(BackupActivity::where('activity', '!=', 'Restore')->where('activity', '!=', 'Safety Backup')->latest('id')->first())->created_at,
                'last_restore' => optional(BackupActivity::where('activity', 'Restore')->latest('id')->first())->created_at,
                'backups' => BackupActivity::where('activity', '!=', 'Restore')->where('activity', '!=', 'Safety Backup')->count(),
            ],
            'auto' => [
                'enabled' => AutoBackupSettings::enabled(),
                'interval_hours' => AutoBackupSettings::intervalHours(),
                'keep' => AutoBackupSettings::keep(),
                'options' => AutoBackupSettings::options(),
                'last' => optional(AutoBackupSettings::lastAutoAt())->toIso8601String(),
                'next' => optional(AutoBackupSettings::nextDueAt())->toIso8601String(),
            ],
        ]);
    }

    /** Super Admin: how often the automatic backup runs (not less than every 8 hours, up to 15 days), whether it runs, and how many are kept. */
    public function autoSettings(Request $request)
    {
        $this->authorizeSuperAdmin();
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'interval_hours' => ['required', 'integer', 'in:' . implode(',', AutoBackupSettings::options())],
            'keep' => 'required|integer|min:1|max:30',
        ]);
        $before = ['enabled' => AutoBackupSettings::enabled(), 'interval_hours' => AutoBackupSettings::intervalHours(), 'keep' => AutoBackupSettings::keep()];
        AutoBackupSettings::save((bool) $data['enabled'], (int) $data['interval_hours'], (int) $data['keep'], $request->user()->account_id);
        log_activity(__('Automatic backup settings saved'), ['from' => $before, 'to' => $data], $request->user(), 'Backup');

        return back()->with('message', __('Automatic backup settings saved.'));
    }

    public function download(Request $request, DatabaseBackup $backup)
    {
        $this->authorizeSuperAdmin();

        $request->validate([
            'password' => 'required|current_password',
        ]);

        $database = DB::connection()->getDatabaseName();
        $filename = $database . '-backup-' . now()->format('Ymd-His') . '.sql.gz'; // compressed: about 7 times smaller, so a quicker download

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
            'user_id' => $this->actorId(),
            'activity' => 'Backup',
            'database' => $database,
            'filename' => $filename,
            'properties' => ['database' => $database, 'filename' => $filename, 'size' => $size],
        ]);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/gzip',
        ])->deleteFileAfterSend(true);
    }

    public function createSystemBackup(DatabaseBackup $backup)
    {
        $this->authorizeSuperAdmin();

        $database = DB::connection()->getDatabaseName();
        $filename = ($database ?: 'database') . '-system-backup-' . now()->format('Ymd-His') . '.sql.gz';

        $oldSystemBackups = BackupActivity::where('activity', 'System BackUp')->get();

        try {
            $path = $backup->saveToDisk($filename);
            $filename = basename($path);
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
            'user_id' => $this->actorId(),
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

        if (! in_array($backupActivity->activity, ['Auto Back Up', 'System BackUp', 'Safety Backup'], true)) {
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
            'user_id' => $this->actorId(),
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
            'Content-Type' => str_ends_with(strtolower($path), '.gz') ? 'application/gzip' : 'application/sql',
        ]);
    }

    public function restore(Request $request, DatabaseBackup $backup)
    {
        $this->authorizeSuperAdmin();

        if (! empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE) {
            return back()->withErrors(['file' => 'The file exceeds the server upload limit (' . ini_get('upload_max_filesize') . ').']);
        }

        $request->validate([
            'file'    => 'required|file|max:512000',
            'confirm' => 'required|in:RESTORE',
            'password' => 'required|current_password',
        ]);

        $uploaded = $request->file('file');
        $extension = strtolower($uploaded->getClientOriginalExtension());
        if (! in_array($extension, ['sql', 'txt', 'gz'], true)) {
            return back()->withErrors(['file' => 'The file must be a .sql, .sql.gz or .txt file.']);
        }

        $path = $uploaded->getRealPath();
        $originalName = $uploaded->getClientOriginalName();
        $size = $uploaded->getSize();

        $database = DB::connection()->getDatabaseName();
        try {
            $safety = $this->safetyBackup($backup); // a restore overwrites the database: the way back is saved first
            $result = $backup->restoreFromFile($path);
        } catch (\Throwable $e) {
            Log::error('Database restore failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Restore failed: ' . $e->getMessage());
        }

        $this->recordSafety($safety);

        // writing the log must never turn a restore that WORKED into an error page
        try {
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
                'user_id' => $this->actorId(),
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


        } catch (\Throwable $e) {
            Log::warning('Restore log entry could not be written', ['error' => $e->getMessage()]);
        }

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

        if (! in_array($backupActivity->activity, ['Auto Back Up', 'System BackUp', 'Safety Backup'], true)) {
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
            $safety = $this->safetyBackup($backup);
            $result = $backup->restoreFromFile($path);
        } catch (\Throwable $e) {
            Log::error('Auto backup restore failed', ['error' => $e->getMessage(), 'backup' => $backupActivity->filename]);

            return back()->with('error', 'Restore failed: ' . $e->getMessage());
        }

        $this->recordSafety($safety);

        // writing the log must never turn a restore that WORKED into an error page
        try {
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
                'user_id' => $this->actorId(),
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


        } catch (\Throwable $e) {
            Log::warning('Restore log entry could not be written', ['error' => $e->getMessage()]);
        }

        $message = 'Restore completed: ' . $result['statements'] . ' statement(s) executed';
        if (! empty($result['errors'])) {
            $message .= ', ' . count($result['errors']) . ' error(s) logged.';

            return back()->with('error', $message)->with('restoreErrors', array_slice($result['errors'], 0, 10));
        }

        return back()->with('message', $message . '.');
    }

    /**
     * Saves the database as it is NOW before a restore overwrites it (compressed; the latest two are kept in the backups folder), so a restore that
     * stops half way, or a wrong file, can always be undone. Returns what is needed to list it AFTERWARDS: the restore replaces the backup log
     * itself, so a log row written before would be wiped by the very restore it protects against.
     */
    private function safetyBackup(DatabaseBackup $backup): array
    {
        $database = DB::connection()->getDatabaseName();
        $path = $backup->saveToDisk(($database ?: 'database') . '-before-restore-' . now()->format('Ymd-His') . '.sql.gz');
        $files = glob(dirname($path) . DIRECTORY_SEPARATOR . '*-before-restore-*.sql.gz') ?: [];
        rsort($files);
        foreach (array_slice($files, 2) as $old) {
            @unlink($old);
        }

        return ['database' => $database, 'path' => $path, 'filename' => basename($path), 'size' => filesize($path), 'user_id' => $this->actorId(), 'actor' => optional(auth()->user())->name];
    }

    /** Lists the safety backup in the log once the restore is done (and removes the log rows of safety backups whose file was pruned). */
    private function recordSafety(array $meta): void
    {
        try {
            BackupActivity::create([
                'user_id' => $this->actorId(),
                'activity' => 'Safety Backup',
                'database' => $meta['database'],
                'filename' => $meta['filename'],
                'properties' => ['database' => $meta['database'], 'filename' => $meta['filename'], 'path' => $meta['path'], 'size' => $meta['size'], 'actor' => $meta['actor']],
            ]);
            foreach (BackupActivity::where('activity', 'Safety Backup')->get() as $row) {
                $p = $row->properties['path'] ?? null;
                if (! $p || ! file_exists($p)) {
                    $row->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Safety backup log entry could not be written', ['error' => $e->getMessage()]);
        }
    }

    /** The person doing a restore, or null when the restored database does not contain them (a restore replaces the users table too). */
    private function actorId(): ?int
    {
        try {
            $id = auth()->id();

            return $id && \App\Models\User::withoutGlobalScopes()->whereKey($id)->exists() ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user() && auth()->user()->hasRole('Super Admin'), 403, 'Only Super Admin can manage backups.');
    }
}
