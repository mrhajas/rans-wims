<?php

namespace App\Support\StockArchive;

use App\Mail\StockArchiveMail;
use App\Models\Account;
use App\Models\StockArchive;
use App\Models\StockArchiveEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Makes, e-mails, keeps and removes Stock Archive entries. Every step is written to the entry's own history. */
class ArchiveService
{
    public const TZ = 'Africa/Dar_es_Salaam';
    public const DISK = 'local';

    public static function available(): bool
    {
        try {
            return \App\Support\SchemaCache::hasTable('stock_archives') && \App\Services\FeatureManager::isEnabled('stock_archive');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function event(StockArchive $a, string $action, ?User $by = null, ?string $note = null): void
    {
        StockArchiveEvent::create([
            'stock_archive_id' => $a->id, 'user_id' => $by?->id, 'action' => $action, 'note' => $note ? mb_substr($note, 0, 250) : null,
            'ip' => app()->runningInConsole() ? null : request()->ip(), 'created_at' => now(),
        ]);
    }

    /** Makes the PDF of today's stock and keeps it as a new entry. $by null = made by WIMS itself (the schedule). */
    public static function generate(int $accountId, ?User $by = null): StockArchive
    {
        $now = CarbonImmutable::now(self::TZ);
        $data = StockData::build($accountId);
        $reference = self::nextReference($accountId, $now);
        $company = optional(Account::find($accountId))->name ?: 'RANS Company Limited';
        $pdf = StockPdf::render($data, [
            'reference' => $reference, 'title' => 'Stock report ' . $reference, 'generated_at' => $now, 'by' => $by ? $by->name : 'WIMS', 'company' => $company,
        ]);
        $path = 'stock-archives/' . $now->format('Y') . '/' . $reference . '.pdf';
        Storage::disk(self::DISK)->put($path, $pdf);

        $a = StockArchive::create([
            'account_id' => $accountId, 'reference' => $reference, 'title' => 'Stock report ' . $now->format('d/m/Y'),
            'kind' => $by ? 'Manual' : 'Scheduled', 'generated_at' => now(), 'created_by_user_id' => $by?->id,
            'file_path' => $path, 'file_size' => strlen($pdf),
            'warehouses_count' => $data['totals']['warehouses'], 'items_count' => $data['totals']['items'], 'rows_count' => $data['totals']['rows'], 'total_value' => $data['totals']['value'],
            'status' => 'active',
        ]);
        self::event($a, 'generated', $by, $by ? 'Made by ' . $by->name : 'Made by WIMS');

        return $a;
    }

    private static function nextReference(int $accountId, CarbonImmutable $now): string
    {
        $prefix = 'STK-' . $now->format('Ymd') . '-';
        $n = StockArchive::query()->where('reference', 'like', $prefix . '%')->count() + 1;
        while (StockArchive::where('reference', $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** Everybody who gets the report: the chosen roles and people, and the extra addresses. @return array<int, array{email:string, name:string}> */
    public static function recipients(array $cfg, int $accountId): array
    {
        $byId = $cfg['user_ids'] ? User::query()->whereIn('id', $cfg['user_ids'])->get() : collect();
        $byRole = $cfg['role_names'] ? User::role($cfg['role_names'])->get() : collect();
        $out = [];
        foreach ($byId->merge($byRole)->unique('id')->filter(fn (User $u) => $u->is_active !== false && $u->account_id == $accountId) as $u) {
            $email = $u->contactEmail();
            if (filled($email)) {
                $out[mb_strtolower($email)] = ['email' => $email, 'name' => $u->name];
            }
        }
        foreach ($cfg['extra_emails'] as $e) {
            $out[mb_strtolower($e)] ??= ['email' => $e, 'name' => ''];
        }

        return array_values($out);
    }

    /** E-mails the entry (PDF attached) to everybody chosen in the settings. @return int how many were sent */
    public static function email(StockArchive $a, ?User $by = null, ?array $onlyTo = null): int
    {
        if ($a->isDeleted() || ! $a->file_path || ! Storage::disk(self::DISK)->exists($a->file_path)) {
            return 0;
        }
        $cfg = ArchiveSettings::get((int) $a->account_id);
        $to = $onlyTo ?? self::recipients($cfg, (int) $a->account_id);
        $pdf = Storage::disk(self::DISK)->get($a->file_path);
        $company = optional(Account::find($a->account_id))->name ?: 'RANS Company Limited';
        $byName = optional($a->creator)->name;
        $sent = 0;
        foreach ($to as $r) {
            try {
                Mail::to($r['email'])->send(new StockArchiveMail($a, $r['name'], $company, $pdf, $byName));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                self::event($a, 'email_failed', $by, mb_substr($r['email'] . ': ' . $e->getMessage(), 0, 240));
            }
        }
        if ($sent) {
            $a->forceFill(['emailed_at' => now(), 'emailed_to' => $sent])->save();
            self::event($a, 'emailed', $by, 'Sent to ' . $sent . ' ' . ($sent === 1 ? 'address' : 'addresses'));
        }

        return $sent;
    }

    /** Removes the file and marks the entry deleted (the entry and its history stay). $by null = removed by WIMS (kept for the set number of days). */
    public static function remove(StockArchive $a, ?User $by, string $reason): void
    {
        if ($a->isDeleted()) {
            return;
        }
        if ($a->file_path) {
            Storage::disk(self::DISK)->delete($a->file_path);
        }
        $a->forceFill(['status' => 'deleted', 'file_path' => null, 'deleted_at' => now(), 'deleted_by_user_id' => $by?->id, 'deleted_reason' => mb_substr($reason, 0, 120)])->save();
        self::event($a, $by ? 'deleted' : 'expired', $by, $reason);
    }

    /** True when the saved day and time (East Africa Time) have been reached and nothing was made yet today. */
    public static function isDue(array $cfg, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now(self::TZ);
        if (! $cfg['enabled'] || ($cfg['last_run_on'] ?? null) === $now->toDateString() || $now->dayOfWeek !== (int) $cfg['send_day']) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', $cfg['send_time']));

        return $now >= $now->setTime($h, $m, 0);
    }

    /** Removes the entries older than the number of days set in the settings (0 = never). @return int how many */
    public static function purge(int $accountId): int
    {
        $days = ArchiveSettings::get($accountId)['keep_days'];
        if ($days <= 0) {
            return 0;
        }
        $n = 0;
        foreach (StockArchive::query()->where('account_id', $accountId)->where('status', 'active')->where('generated_at', '<', now()->subDays($days))->get() as $a) {
            self::remove($a, null, 'Older than ' . $days . ' days');
            $n++;
        }

        return $n;
    }
}
