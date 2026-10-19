<?php

namespace App\Services\Transport;

use App\Models\Contact;
use App\Models\TransportLoadingAttendance;
use App\Models\TransportLoadingOpeningBalance;
use App\Models\TransportLoadingPayment;
use Illuminate\Support\Facades\DB;

/**
 * Loading & Unloading money, in one place: what each worker earned (days Present x the rate saved with that day), what
 * was paid, and the balance between them. Used by the Loading & Unloading pages and by both Transport reports so the
 * numbers always agree.
 *
 * Balance = previous balance (brought forward, if one was recorded) + earned - paid.
 */
class LoadingLedger
{
    public static function rate(): float
    {
        return (float) transport_settings()['transport_loading_daily_rate'];
    }

    /** Workers = contacts in the Loading & UnLoading category, plus anyone who already has attendance or payments recorded. */
    public static function workerIds(): array
    {
        $category = Contact::query()->where('category', Contact::LOADING_CATEGORY)->pluck('id');

        return $category
            ->merge(TransportLoadingAttendance::query()->distinct()->pluck('contact_id'))
            ->merge(TransportLoadingPayment::query()->distinct()->pluck('contact_id'))
            ->merge(TransportLoadingOpeningBalance::query()->pluck('contact_id'))
            ->unique()->values()->all();
    }

    /**
     * One row per worker: days / earned / paid inside [from, to] and the balance owed up to the end of the range
     * (everything earned minus everything paid up to `to`, or up to today when no end is given).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function summary(?string $from = null, ?string $to = null, ?int $warehouseId = null): array
    {
        $ids = self::workerIds();
        if (! $ids) {
            return [];
        }

        $inRange = function ($q, string $col) use ($from, $to) {
            return $q->when($from, fn ($x) => $x->whereDate($col, '>=', $from))->when($to, fn ($x) => $x->whereDate($col, '<=', $to));
        };

        $att = $inRange(TransportLoadingAttendance::query()->whereIn('contact_id', $ids)->where('status', TransportLoadingAttendance::PRESENT)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId)), 'work_date')
            ->selectRaw('contact_id, COUNT(*) as days, SUM(amount) as earned')->groupBy('contact_id')->get()->keyBy('contact_id');

        $paid = $inRange(TransportLoadingPayment::query()->paid()->whereIn('contact_id', $ids), 'payment_date')
            ->selectRaw('contact_id, SUM(amount) as paid')->groupBy('contact_id')->pluck('paid', 'contact_id');

        // Balance to date: not limited by the start of the range, so it is what is really still owed.
        $earnedToDate = TransportLoadingAttendance::query()->whereIn('contact_id', $ids)
            ->when($to, fn ($q) => $q->whereDate('work_date', '<=', $to))
            ->selectRaw('contact_id, SUM(amount) as earned')->groupBy('contact_id')->pluck('earned', 'contact_id');
        $paidToDate = TransportLoadingPayment::query()->paid()->whereIn('contact_id', $ids)
            ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->selectRaw('contact_id, SUM(amount) as paid')->groupBy('contact_id')->pluck('paid', 'contact_id');

        // Previous balance brought forward; it counts once the range reaches the date it stands at.
        $opening = TransportLoadingOpeningBalance::query()->whereIn('contact_id', $ids)
            ->when($to, fn ($q) => $q->whereDate('as_of_date', '<=', $to))->get()->keyBy('contact_id');

        // Money already put on a Payment Voucher that is not paid yet (reserved, but still owed).
        $pending = TransportLoadingPayment::query()->pendingVoucher()->whereIn('contact_id', $ids)
            ->selectRaw('contact_id, SUM(amount) as s')->groupBy('contact_id')->pluck('s', 'contact_id');

        return Contact::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(function (Contact $c) use ($att, $paid, $earnedToDate, $paidToDate, $pending, $opening) {
                $openRow = $opening->get($c->id);
                $previous = round((float) ($openRow->amount ?? 0), 2);
                $balance = round($previous + (float) ($earnedToDate[$c->id] ?? 0) - (float) ($paidToDate[$c->id] ?? 0), 2);
                $onVoucher = round((float) ($pending[$c->id] ?? 0), 2);

                $a = $att->get($c->id);

                return [
                    'contact_id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'days' => (int) ($a->days ?? 0),
                    'opening' => $previous,
                    'opening_date' => $openRow?->as_of_date?->toDateString(),
                    'opening_notes' => $openRow?->notes,
                    'earned' => round((float) ($a->earned ?? 0), 2),
                    'paid' => round((float) ($paid[$c->id] ?? 0), 2),
                    'balance' => $balance,
                    'pending' => $onVoucher,
                    'available' => round($balance - $onVoucher, 2),
                ];
            })->values()->all();
    }

    /**
     * The Loading & Unloading block of the Transport reports: only workers with something to show in the range
     * (days worked, a payment, or a balance still owed), their totals and the current daily rate.
     */
    public static function reportSection(?string $from, ?string $to): array
    {
        $rows = array_values(array_filter(self::summary($from, $to), fn ($r) => $r['days'] > 0 || $r['paid'] != 0 || $r['balance'] != 0 || $r['opening'] != 0));

        return ['rows' => $rows, 'totals' => self::totals($rows), 'rate' => self::rate()];
    }

    public static function totals(array $rows): array
    {
        return [
            'workers' => count($rows),
            'days' => (int) array_sum(array_column($rows, 'days')),
            'opening' => round((float) array_sum(array_column($rows, 'opening')), 2),
            'earned' => round((float) array_sum(array_column($rows, 'earned')), 2),
            'paid' => round((float) array_sum(array_column($rows, 'paid')), 2),
            'balance' => round((float) array_sum(array_column($rows, 'balance')), 2),
            'pending' => round((float) array_sum(array_column($rows, 'pending')), 2),
        ];
    }

    /** The previous (brought forward) balance recorded for one worker; 0 when none. */
    public static function openingOf(int $contactId): float
    {
        return round((float) TransportLoadingOpeningBalance::where('contact_id', $contactId)->value('amount'), 2);
    }

    /** What one worker is owed right now (previous balance + earned minus PAID, all time). Can be negative when paid in advance. */
    public static function balanceOf(int $contactId): float
    {
        $earned = (float) TransportLoadingAttendance::where('contact_id', $contactId)->sum('amount');
        $paid = (float) TransportLoadingPayment::paid()->where('contact_id', $contactId)->sum('amount');

        return round(self::openingOf($contactId) + $earned - $paid, 2);
    }

    /** Amount already on an unpaid Payment Voucher for this worker. */
    public static function pendingOf(int $contactId): float
    {
        return round((float) TransportLoadingPayment::pendingVoucher()->where('contact_id', $contactId)->sum('amount'), 2);
    }

    /** What can still be paid or put on a new voucher: balance minus what is already waiting on a voucher. */
    public static function availableOf(int $contactId): float
    {
        return round(self::balanceOf($contactId) - self::pendingOf($contactId), 2);
    }

    /** Balances for many workers at once, keyed by contact id. */
    public static function balances(array $contactIds): array
    {
        if (! $contactIds) {
            return [];
        }
        $earned = TransportLoadingAttendance::query()->whereIn('contact_id', $contactIds)->selectRaw('contact_id, SUM(amount) as s')->groupBy('contact_id')->pluck('s', 'contact_id');
        $paid = TransportLoadingPayment::query()->paid()->whereIn('contact_id', $contactIds)->selectRaw('contact_id, SUM(amount) as s')->groupBy('contact_id')->pluck('s', 'contact_id');

        $opening = TransportLoadingOpeningBalance::query()->whereIn('contact_id', $contactIds)->pluck('amount', 'contact_id');

        return collect($contactIds)->mapWithKeys(fn ($id) => [$id => round((float) ($opening[$id] ?? 0) + (float) ($earned[$id] ?? 0) - (float) ($paid[$id] ?? 0), 2)])->all();
    }
}
