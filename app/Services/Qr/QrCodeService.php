<?php

namespace App\Services\Qr;

use App\Models\QrCode;
use App\Models\QrScan;
use App\Models\User;
use App\Support\PrintQrCode;
use App\Support\Qr\QrRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Scan-to-open codes for records. A code carries ONLY the address `<site>/q/<token>`: a random token, never a database id and
 * never any record data. The token is looked up when somebody scans it, and only a signed-in person who is allowed to open the
 * record is sent on to it (see QrCodeController::scan).
 */
class QrCodeService
{
    private static ?bool $ready = null;

    /** Patterns of every kind's page address, built once per request: [type, regex]. */
    private static ?array $patterns = null;

    public static function ready(): bool
    {
        return self::$ready ??= \App\Support\SchemaCache::hasTable('qr_codes');
    }

    /** Is the QR capability switched on (Feature Control and the setting)? */
    public static function enabled(): bool
    {
        try {
            return self::ready() && \App\Services\FeatureManager::isEnabled('qr_codes') && QrSettings::get()['enabled'];
        } catch (\Throwable) {
            return false;
        }
    }

    public function tokenFor(string $type, Model $record, ?User $by = null): QrCode
    {
        $code = QrCode::query()->where('type', $type)->where('record_id', $record->getKey())->whereNull('revoked_at')->first();
        if ($code) {
            return $code;
        }
        $length = (int) config('qr.token_length', 20);
        do {
            $token = strtolower(Str::random($length));
        } while (QrCode::query()->where('token', $token)->exists());

        return QrCode::query()->create([
            // an Accounts Tracking record's own account_id is the tracked bank/cash account, not the WIMS account
            'account_id' => (int) (($record instanceof \App\Models\Accounts\AcctModel ? null : $record->getAttribute('account_id')) ?: ($by->account_id ?? auth()->user()->account_id ?? 1)),
            'type' => $type, 'record_id' => $record->getKey(), 'token' => $token, 'created_by' => $by?->id ?? auth()->id(),
        ]);
    }

    public function url(QrCode|string $code): string
    {
        $token = $code instanceof QrCode ? $code->token : $code;
        // the address of the site as configured (APP_URL), not the address this one request happened to arrive on: a printed code must keep working
        $base = rtrim((string) config('app.url'), '/');

        return $base !== '' && filter_var($base, FILTER_VALIDATE_URL) ? $base . '/q/' . $token : route('qr.scan', $token);
    }

    /** The code as a PNG. $px is the finished width (the quiet zone is included). */
    public function png(string $url, ?int $moduleSize = null): string
    {
        $s = QrSettings::get();

        return PrintQrCode::png($url, $moduleSize ?? 8, 3, $s['color'], $s['error_correction']);
    }

    public function dataUri(string $url, ?int $moduleSize = null): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png($url, $moduleSize));
    }

    /**
     * What happens when THIS person scans THIS token, without doing it: status (allowed | no_permission | unavailable | disabled | invalid) plus the code,
     * the kind and the record when there is one. A deleted record is "unavailable"; one that exists but is outside the person's projects or
     * confidentiality level is "no_permission". Nothing here opens, counts or logs anything.
     *
     * @return array{status:string, code:?QrCode, entry:?array, record:?\Illuminate\Database\Eloquent\Model}
     */
    public function resolveScan(User $user, string $token): array
    {
        $out = ['status' => 'invalid', 'code' => null, 'entry' => null, 'record' => null, 'reason' => 'unknown code'];
        $code = preg_match('/^[a-z0-9]{12,40}$/', $token) ? QrCode::query()->where('token', $token)->first() : null;
        $entry = $code ? QrRegistry::get($code->type) : null;
        if (! $code || ! $entry) {
            return $out;
        }
        $out['code'] = $code;
        $out['entry'] = $entry;
        if ($code->revoked_at) {
            return ['status' => 'invalid', 'reason' => 'code replaced'] + $out;
        }
        if ((int) $code->account_id !== (int) $user->account_id) {
            return ['status' => 'invalid', 'reason' => 'other account'] + $out;
        }
        if (! QrRegistry::active($code->type)) {
            return ['status' => 'disabled', 'reason' => 'module off'] + $out;
        }
        if (! QrRegistry::canOpen($user, $entry)) {
            return ['status' => 'no_permission', 'reason' => 'no ability'] + $out;
        }
        $record = QrRegistry::findFor($user, $code->type, (int) $code->record_id);
        if ($record) {
            return ['status' => 'allowed', 'record' => $record, 'reason' => null] + $out;
        }
        $any = QrRegistry::findAny($entry['model'], $code->record_id);

        return ['status' => ! $any || (method_exists($any, 'trashed') && $any->trashed()) ? 'unavailable' : 'no_permission', 'reason' => $any ? ((method_exists($any, 'trashed') && $any->trashed()) ? 'record deleted' : 'outside access') : 'record gone'] + $out;
    }

    /** Write one line of the scan history (and the activity log); counts the scan on the code when it was allowed. */
    public function record(User $user, array $r, string $via = 'scanner', ?string $token = null): void
    {
        $code = $r['code'];
        $entry = $r['entry'];
        $shown = $r['record'] ?: ($code && $entry ? QrRegistry::findAny($entry['model'], $code->record_id) : null);
        $d = $shown ? QrRegistry::describe($shown) : null;
        // the history keeps the reference for administrators; QR Scans hides it from anybody who could not open the record (see QrCodeController::scans)
        QrScan::query()->create([
            'account_id' => (int) $user->account_id, 'qr_code_id' => $code?->id, 'user_id' => $user->id, 'type' => $code?->type, 'record_id' => $code?->record_id,
            'kind' => $entry['label'] ?? null, 'reference' => $d['reference'] ?? ($r['status'] === 'invalid' && $token ? '…' . substr($token, -4) : null), 'status' => $r['status'], 'reason' => $r['reason'] ?? null,
            'via' => $via, 'ip' => request()->ip(), 'created_at' => now(),
        ]);
        if ($r['status'] === 'allowed') {
            $code->forceFill(['scans_count' => $code->scans_count + 1, 'last_scanned_at' => now(), 'last_scanned_by' => $user->id])->saveQuietly();
            log_activity('QR Scanned', ['kind' => $entry['label'], 'reference' => $d['reference']], $r['record'], 'QR Codes');
        } else {
            log_activity('QR Scan Denied', ['status' => $r['status'], 'reason' => $r['reason'], 'code' => $token ? '…' . substr($token, -4) : null, 'type' => $code?->type], null, 'QR Codes');
        }
    }

    /** Throw away the code of a record: the printed copies stop working at once, and the next request makes a new one. */
    public function revoke(QrCode $code, ?User $by = null): void
    {
        $code->update(['revoked_at' => now(), 'revoked_by' => $by?->id]);
    }

    /**
     * Printed reports already encode a record's own address. This turns such an address into its scan-to-open address, so every
     * printed document carries the token and none shows a database id. Anything it does not recognise (or any failure, or the
     * capability being off) leaves the text exactly as it was.
     */
    public static function forPrint(string $data): string
    {
        try {
            if (! self::enabled() || ! QrSettings::get()['on_prints']) {
                return $data;
            }
            $path = parse_url($data, PHP_URL_PATH);
            if (! is_string($path) || str_contains($data, '#') || str_contains($data, '?')) {
                return $data;
            }
            $path = ltrim($path, '/');
            foreach (self::patterns() as [$type, $regex]) {
                if (preg_match($regex, $path, $m)) {
                    if (! QrRegistry::active($type)) {
                        return $data;
                    }
                    $e = QrRegistry::get($type);
                    $record = QrRegistry::findAny($e['model'], $m[1]);
                    if (! $record) {
                        return $data;
                    }

                    return app(self::class)->url(app(self::class)->tokenFor($type, $record));
                }
            }
        } catch (\Throwable) {
            // a code on a printout must never break the printout
        }

        return $data;
    }

    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }
        $out = [];
        foreach (QrRegistry::all() as $type => $e) {
            $route = Route::getRoutes()->getByName($e['route']);
            if (! $route || substr_count($route->uri(), '{') !== 1) {
                continue; // only addresses that name the record by a single id can be recognised
            }
            $parts = preg_split('#\{[^}]+\}#', $route->uri());
            $out[] = [$type, '#^' . implode('(\d+)', array_map(fn ($x) => preg_quote($x, '#'), $parts)) . '$#'];
        }

        return self::$patterns = $out;
    }
}
