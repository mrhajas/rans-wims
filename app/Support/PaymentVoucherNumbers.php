<?php

namespace App\Support;

use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherBatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place that numbers Payment Vouchers and Payment Voucher batches (PV-2026-000123 / PVB-2026-000012), used by every generator:
 * the voucher form, the bulk batch form, Labour, Transport trips, Funds Allocation and Loading & Unloading.
 *
 * "MAX + 1" is only safe when two writers cannot be between the read and the insert at once, so every generator takes the same named lock
 * (see locked()) BEFORE it opens its transaction: the number is then read from a fresh snapshot, and the lock is released only after the commit.
 * If the lock could not be had in time (ReferenceLock falls through) or an old code path took a number without the lock, create() still
 * recovers by re-reading the latest committed number with a locking read instead of the transaction's older snapshot.
 */
class PaymentVoucherNumbers
{
    public const LOCK = 'payment_vouchers';

    /** Run $callback (normally a whole DB::transaction that creates vouchers) while holding the numbering lock. */
    public static function locked(callable $callback)
    {
        return ReferenceLock::run(self::LOCK, $callback);
    }

    public static function nextVoucher(bool $latest = false): string
    {
        return self::next('payment_vouchers', 'pv_number', 'PV', $latest);
    }

    public static function nextBatch(bool $latest = false): string
    {
        return self::next('payment_voucher_batches', 'batch_number', 'PVB', $latest);
    }

    /** Creates a voucher with the next free number; a number taken meanwhile is retried (see the class comment). */
    public static function createVoucher(array $attributes): PaymentVoucher
    {
        return self::insert(fn (string $number) => PaymentVoucher::create(['pv_number' => $number] + $attributes), fn (bool $latest) => self::nextVoucher($latest), 'pv_number');
    }

    public static function createBatch(array $attributes): PaymentVoucherBatch
    {
        return self::insert(fn (string $number) => PaymentVoucherBatch::create(['batch_number' => $number] + $attributes), fn (bool $latest) => self::nextBatch($latest), 'batch_number');
    }

    private static function insert(callable $make, callable $next, string $column)
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                // the first attempt reads normally; a retry means somebody else got in between, so read the latest committed number
                return $make($next($attempt > 0));
            } catch (QueryException $e) {
                $duplicate = (string) $e->getCode() === '23000' && str_contains($e->getMessage(), $column);
                if (! $duplicate || $attempt === 4) {
                    throw $e;
                }
            }
        }

        abort(500, 'Unable to generate a unique number.');
    }

    private static function next(string $table, string $column, string $prefix, bool $latest): string
    {
        $year = now()->format('Y');
        $head = "{$prefix}-{$year}-";
        $prefixLength = strlen($head) + 1;

        $query = DB::table($table)
            ->selectRaw("MAX(CAST(SUBSTRING({$column}, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where($column, 'like', $head . '%');
        if ($latest) {
            // a locking read sees the latest committed rows, not the REPEATABLE READ snapshot of the open transaction
            $query->lockForUpdate();
        }

        return $head . str_pad((string) (((int) $query->value('max_suffix')) + 1), 6, '0', STR_PAD_LEFT);
    }
}
