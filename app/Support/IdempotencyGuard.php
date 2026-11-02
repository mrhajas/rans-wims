<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Double-submit protection for forms that CREATE something (Payment Vouchers first). The form makes a random request key when it opens and sends it with the
 * POST (`request_key`); the key stays the same for every retry of that form. The first successful submission records the key together with the record it
 * created, in the SAME database transaction; any later submission with that key (a double click, a retry after a lost answer) creates nothing and is sent to
 * the first record. The same key with different data is refused. A request without a key behaves exactly as before.
 *
 * Use:  $key = IdempotencyGuard::fromRequest($request, 'payment_voucher.store', $data);   // null = no guard
 *       ...inside DB::transaction:  IdempotencyGuard::claim($key);  ...create...;  IdempotencyGuard::finish($key, $record);
 *       ...outside, catch IdempotentReplay: redirect to $e->recordId.
 */
class IdempotencyGuard
{
    public const KEEP_DAYS = 30;

    /** @return array{scope: string, key: string, hash: string, user_id: int, account_id: ?int}|null */
    public static function fromRequest(Request $request, string $scope, array $payload): ?array
    {
        $key = $request->input('request_key');
        if ($key === null || $key === '') {
            return null; // an old client or an API call: no protection, nothing breaks
        }
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key)) {
            throw ValidationException::withMessages(['request_key' => __('This form could not be checked. Reload the page and try again.')]);
        }
        $user = $request->user();
        if (! $user || ! self::ready()) {
            return null;
        }

        return ['scope' => $scope, 'key' => $key, 'hash' => self::hash($payload), 'user_id' => (int) $user->id, 'account_id' => $user->account_id ? (int) $user->account_id : null];
    }

    /** The same data always gives the same hash (key order and files do not matter). */
    public static function hash(array $payload): string
    {
        unset($payload['attachments'], $payload['request_key'], $payload['confirm_duplicate']);

        return hash('sha256', json_encode(self::normalise($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * First thing inside the creating transaction. Throws IdempotentReplay when the key was already used by the same data, or a validation error when it was
     * used by different data. Otherwise the key is reserved; it becomes visible to others only when the transaction commits.
     */
    public static function claim(?array $key): void
    {
        if (! $key) {
            return;
        }
        // INSERT first, never "look, then insert": two twins that both looked at the empty slot would each hold a gap lock and deadlock on their inserts.
        // A lone insert simply waits for a twin that is still saving, then fails on the unique key once the twin has committed.
        try {
            IdempotencyKey::create([
                'account_id' => $key['account_id'], 'user_id' => $key['user_id'], 'scope' => $key['scope'], 'key' => $key['key'],
                'request_hash' => $key['hash'], 'status' => 'processing', 'created_at' => now(),
            ]);

            return;
        } catch (QueryException $e) {
            if (! self::isDuplicate($e) && ! self::isDeadlock($e)) {
                throw $e;
            }
            // the key is already taken (or the database picked this request to give way): read what the first one saved
            $row = self::lookup($key, true);
            // after a deadlock the twin may not have committed yet: wait a moment and look again before giving up
            for ($i = 0; ! $row && self::isDeadlock($e) && $i < 4; $i++) {
                usleep(200000);
                $row = self::lookup($key, false);
            }
            if (! $row) {
                if (self::isDeadlock($e)) {
                    throw ValidationException::withMessages(['request_key' => __('This form is being saved by another request. Wait a moment, check the list, then try again if it is not there.')]);
                }
                throw $e;
            }
        }
        if (! hash_equals((string) $row->request_hash, $key['hash'])) {
            throw ValidationException::withMessages(['request_key' => __('This form was already saved once, with different details. Check the list to see what was saved, then reload the page to start a new form.')]);
        }
        throw new IdempotentReplay((string) $row->record_type, $row->record_id ? (int) $row->record_id : null);
    }

    /**
     * A quick look BEFORE anything is prepared: the record this form already created (same data), null when the key is new. Different data under the same key is
     * refused here too. The decisive check is still claim() inside the transaction; this one only lets a plain retry skip the other checks (such as the
     * "possible duplicate" warning, which would otherwise greet a retry with a question about the voucher it just saved).
     */
    public static function peek(?array $key): ?IdempotencyKey
    {
        if (! $key) {
            return null;
        }
        $row = self::lookup($key, false);
        if (! $row || $row->status !== 'done') {
            return null;
        }
        if (! hash_equals((string) $row->request_hash, $key['hash'])) {
            throw ValidationException::withMessages(['request_key' => __('This form was already saved once, with different details. Check the list to see what was saved, then reload the page to start a new form.')]);
        }

        return $row;
    }

    /** Last thing inside the creating transaction: the reserved key now points at the record that was created. */
    public static function finish(?array $key, $record): void
    {
        if (! $key) {
            return;
        }
        IdempotencyKey::query()->where('user_id', $key['user_id'])->where('scope', $key['scope'])->where('key', $key['key'])
            ->update(['record_type' => is_object($record) ? get_class($record) : null, 'record_id' => is_object($record) ? $record->getKey() : null, 'status' => 'done']);
    }

    /** True when the idempotency table exists (a server that has not run the migration yet simply has no protection). */
    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('idempotency_keys');
    }

    private static function lookup(array $key, bool $lock): ?IdempotencyKey
    {
        $q = IdempotencyKey::query()->where('user_id', $key['user_id'])->where('scope', $key['scope'])->where('key', $key['key']);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    private static function isDuplicate(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint') || str_contains($e->getMessage(), 'Duplicate entry');
    }

    private static function isDeadlock(QueryException $e): bool
    {
        return (string) $e->getCode() === '40001' || str_contains($e->getMessage(), 'Deadlock');
    }

    private static function normalise($value)
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = array_map([self::class, 'normalise'], $value);
            if (! $isList) {
                ksort($out);
            }

            return $out;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) || $value === null ? ($value === null ? null : (string) $value) : null;
    }
}
