<?php

namespace App\Console\Commands;

use App\Models\QrCode;
use App\Models\User;
use App\Services\Qr\QrCodeService;
use App\Support\Qr\QrRegistry;
use Illuminate\Console\Command;

/**
 * Sample QR data for review: a code for the newest records of every kind (so each kind can be scanned and printed), a few scans, and one replaced
 * code. With --scans the scan counts and log lines are invented too (use it on a demo database only).
 */
class QrSamples extends Command
{
    protected $signature = 'qr:samples {--per=2 : records per kind} {--scans : also invent scans, a refusal and a replaced code (demo database only)}';

    protected $description = 'Make sample QR codes (and, with --scans, sample scans) for review';

    public function handle(QrCodeService $qr): int
    {
        $admin = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->orderBy('id')->first();
        if (! $admin) {
            $this->error('No Super Admin found.');

            return self::FAILURE;
        }
        auth()->setUser($admin);
        $per = max(1, (int) $this->option('per'));
        $made = 0;
        $rows = [];

        foreach (QrRegistry::all() as $type => $e) {
            $records = $this->newest($e, $per);
            foreach ($records as $record) {
                $code = $qr->tokenFor($type, $record, $admin);
                $made++;
                $rows[] = [$e['label'], QrRegistry::describe($record)['reference'], $code->token];
                if ($this->option('scans') && $made % 2 === 1) {
                    $code->forceFill(['scans_count' => random_int(2, 14), 'last_scanned_at' => now()->subHours(random_int(1, 70)), 'last_scanned_by' => $admin->id])->saveQuietly();
                    log_activity('QR Scanned', ['kind' => $e['label'], 'reference' => QrRegistry::describe($record)['reference']], $record, 'QR Codes');
                }
            }
        }
        if ($this->option('scans')) {
            $this->scanHistory($admin);
            log_activity('QR Scan Denied', ['reason' => 'no permission', 'code' => '…x7k2', 'type' => 'payment_voucher'], null, 'QR Codes');
            $one = QrCode::query()->whereNull('revoked_at')->orderByDesc('id')->first();
            if ($one) {
                $qr->revoke($one, $admin);
                $this->line('One code was replaced on purpose, to show a refused old code: ' . $one->type . ' #' . $one->record_id);
            }
        }
        $this->table(['Kind', 'Reference', 'Token'], $rows);
        $this->info("{$made} codes ready. Scan address: " . route('qr.scan', 'TOKEN'));

        return self::SUCCESS;
    }

    /** About 160 sample lines of scan history over the last three weeks: every kind, several people, allowed and refused. */
    private function scanHistory(User $admin): void
    {
        $people = User::query()->where('account_id', $admin->account_id)->where('is_active', 1)->orderBy('id')->limit(8)->get();
        $codes = QrCode::query()->whereNull('revoked_at')->get();
        if ($codes->isEmpty() || $people->isEmpty()) {
            return;
        }
        $statuses = ['allowed', 'allowed', 'allowed', 'allowed', 'no_permission', 'allowed', 'unavailable', 'allowed', 'disabled', 'allowed', 'no_permission', 'invalid'];
        $rows = [];
        for ($i = 0; $i < 160; $i++) {
            $code = $codes->random();
            $e = QrRegistry::get($code->type);
            if (! $e) {
                continue;
            }
            $record = $e['model']::withoutGlobalScopes()->withTrashed()->find($code->record_id);
            $status = $statuses[$i % count($statuses)];
            $rows[] = [
                'account_id' => $admin->account_id, 'qr_code_id' => $status === 'invalid' ? null : $code->id, 'user_id' => $people->random()->id, 'type' => $status === 'invalid' ? null : $code->type,
                'record_id' => $status === 'invalid' ? null : $code->record_id, 'kind' => $status === 'invalid' ? null : $e['label'],
                'reference' => $status === 'invalid' ? '…' . substr(md5((string) $i), 0, 4) : ($record ? QrRegistry::describe($record)['reference'] : null),
                'status' => $status, 'reason' => $status === 'allowed' ? null : $status, 'via' => $i % 4 === 0 ? 'link' : 'scanner', 'ip' => '127.0.0.1',
                'created_at' => now()->subMinutes(random_int(3, 60 * 24 * 21)),
            ];
        }
        foreach (array_chunk($rows, 80) as $chunk) {
            \App\Models\QrScan::query()->insert($chunk);
        }
        $this->line(count($rows) . ' sample scan lines written.');
    }

    private function newest(array $e, int $per)
    {
        $q = $e['model']::query();
        if (! empty($e['where'])) {
            $q->where($e['where']);
        }

        return $q->orderByDesc($q->getModel()->getKeyName())->limit($per)->get();
    }
}
