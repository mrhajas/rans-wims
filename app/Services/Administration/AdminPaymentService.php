<?php

namespace App\Services\Administration;

use App\Models\AdminDocument;
use App\Models\AdminDocumentCharity;
use App\Models\AdminDocumentItem;
use App\Models\AdminDocumentPayment;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherType;
use App\Models\Staff;
use App\Models\User;
use App\Support\NumberToWords;
use App\Support\PaymentVoucherNumbers;
use App\Support\ReferenceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying an authorized Administration document, in two ways that share one ledger: a person with the permission marks it paid (all of it, some lines, or part of a line,
 * to a chosen user or staff member), or a Payment Voucher is made for it and the payment becomes real when the voucher is marked paid. What a line has been paid is
 * always the sum of its payment lines, so partial payments, reversals and vouchers can never disagree. All amounts are handled in cents.
 */
class AdminPaymentService
{
    /** The voucher type of this module. Vouchers of this type are made only from the Administration module: the Payment Vouchers module refuses to start one. */
    public const VOUCHER_TYPE = 'Weekly Administration';

    public const METHODS = ['Cash', 'Bank transfer', 'Mobile money', 'Cheque'];

    public static function cents($v): int
    {
        return (int) round(((float) $v) * 100);
    }

    public static function money(int $c): string
    {
        return number_format($c / 100, 2, '.', '');
    }

    /** paid and reserved cents per line key ("item:5", "charity:3") of a document. */
    public static function sums(int $documentId): array
    {
        $rows = DB::table('admin_document_payment_lines as l')->join('admin_document_payments as p', 'p.id', '=', 'l.admin_document_payment_id')
            ->whereNull('l.deleted_at')->whereNull('p.deleted_at')->where('p.admin_document_id', $documentId)->whereIn('p.status', [AdminDocumentPayment::PAID, AdminDocumentPayment::PENDING_VOUCHER])
            ->groupBy('l.line_type', 'l.line_id', 'p.status')->selectRaw('l.line_type, l.line_id, p.status, SUM(l.amount) a')->get();
        $out = [];
        foreach ($rows as $r) {
            $k = $r->line_type . ':' . $r->line_id;
            $out[$k] ??= ['paid' => 0, 'reserved' => 0];
            $out[$k][$r->status === AdminDocumentPayment::PAID ? 'paid' : 'reserved'] += self::cents($r->a);
        }

        return $out;
    }

    /** Every payable line of the document with what is paid, reserved and left. A line with nothing left is "Paid", one with something paid is "Partially Paid". */
    public static function lineTable(AdminDocument $d): array
    {
        $sums = self::sums($d->id);
        $rows = [];
        $no = 0;
        $push = function (string $type, $l, string $desc, ?string $who) use (&$rows, &$no, $sums) {
            $amount = self::cents($l->amount);
            $k = $type . ':' . $l->id;
            $paid = $sums[$k]['paid'] ?? 0;
            $res = $sums[$k]['reserved'] ?? 0;
            $left = max(0, $amount - $paid - $res);
            $rows[] = ['key' => $k, 'type' => $type, 'id' => $l->id, 'no' => ++$no, 'description' => $desc, 'beneficiary' => $who, 'amount' => self::money($amount), 'paid' => self::money($paid), 'reserved' => self::money($res), 'balance' => self::money($left),
                'status' => $amount > 0 && $paid >= $amount ? 'Paid' : ($paid > 0 ? 'Partially Paid' : ($res > 0 ? 'Pending Voucher' : 'Unpaid')), 'section' => $type === 'charity' ? 'Charity' : 'Lines'];
        };
        foreach ($d->lines()->get() as $l) {
            $push('item', $l, $l->description, $l->beneficiary);
        }
        if ($d->include_charity) {
            foreach ($d->charityLines()->get() as $c) {
                $push('charity', $c, $c->description, $c->name);
            }
        }

        return $rows;
    }

    /** Keeps the totals and the payment status on the document in step with its payments. */
    public static function recalc(AdminDocument $d): AdminDocument
    {
        $sums = self::sums($d->id);
        $paid = array_sum(array_column($sums, 'paid'));
        $res = array_sum(array_column($sums, 'reserved'));
        $total = self::cents($d->total_amount);
        $status = $total > 0 && $paid >= $total ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid');
        $last = DB::table('admin_document_payments')->where('admin_document_id', $d->id)->where('status', AdminDocumentPayment::PAID)->whereNull('deleted_at')->max('paid_on');
        $d->forceFill(['paid_total' => self::money($paid), 'reserved_total' => self::money($res), 'payment_status' => $status, 'last_paid_at' => $last])->save();

        return $d;
    }

    /** "u:12" (a user) or "s:5" (a staff member) into the payee fields of a payment. */
    public static function resolvePayee(?string $key): array
    {
        $key = trim((string) $key);
        if (preg_match('/^u:(\d+)$/', $key, $m) && ($u = User::query()->where('is_active', true)->find((int) $m[1]))) {
            return ['payee_type' => 'user', 'paid_to_user_id' => $u->id, 'paid_to_staff_id' => null, 'paid_to_name' => $u->name];
        }
        if (preg_match('/^s:(\d+)$/', $key, $m) && ($s = Staff::query()->whereIn('status', Staff::PAYROLL_STATUSES)->find((int) $m[1]))) {
            return ['payee_type' => 'staff', 'paid_to_user_id' => null, 'paid_to_staff_id' => $s->id, 'paid_to_name' => $s->name];
        }
        throw ValidationException::withMessages(['payee' => __('Choose who is paid: a user or a staff member.')]);
    }

    /** The users and the staff members a person can be paid as (value "u:ID" / "s:ID"). */
    public static function payeeOptions(): array
    {
        $users = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => 'u:' . $u->id, 'label' => $u->name . ' (' . __('user') . ')']);
        $staff = Staff::query()->whereIn('status', Staff::PAYROLL_STATUSES)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => 's:' . $s->id, 'label' => $s->name . ' (' . __('staff') . ')']);

        return $users->concat($staff)->values()->all();
    }

    /**
     * The lines and amounts a payment pays, checked against what is left on each line. mode "all" pays what is left on every line; mode "lines" pays the lines given
     * (a missing amount means the whole balance of that line, a smaller amount is a part payment). Call inside the transaction, after the document is locked.
     *
     * @return array<int,array{type:string,id:int,cents:int}>
     */
    public static function allocations(AdminDocument $d, string $mode, array $given): array
    {
        $table = collect(self::lineTable($d))->keyBy('key');
        $out = [];
        if ($mode === 'all') {
            foreach ($table as $r) {
                if (self::cents($r['balance']) > 0) {
                    $out[] = ['type' => $r['type'], 'id' => $r['id'], 'cents' => self::cents($r['balance'])];
                }
            }
        } else {
            foreach ($given as $g) {
                $k = (string) ($g['key'] ?? ($g['type'] ?? '') . ':' . ($g['id'] ?? ''));
                $r = $table->get($k);
                if (! $r) {
                    throw ValidationException::withMessages(['lines' => __('One of the chosen lines is not on this document.')]);
                }
                $left = self::cents($r['balance']);
                $c = isset($g['amount']) && $g['amount'] !== '' && $g['amount'] !== null ? self::cents($g['amount']) : $left;
                if ($c <= 0) {
                    continue;
                }
                if ($c > $left) {
                    throw ValidationException::withMessages(['lines' => __('":d" has only :b left to pay.', ['d' => $r['description'], 'b' => number_format($left / 100, 2)])]);
                }
                $out[] = ['type' => $r['type'], 'id' => $r['id'], 'cents' => $c];
            }
        }
        if (! $out) {
            throw ValidationException::withMessages(['lines' => __('There is nothing left to pay on the chosen lines.')]);
        }

        return $out;
    }

    private static function lockedAuthorized(int $id): AdminDocument
    {
        $d = AdminDocument::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($d->status !== AdminDocument::AUTHORIZED) {
            throw ValidationException::withMessages(['lines' => __('Only an authorized document can be paid.')]);
        }

        return $d;
    }

    private static function make(AdminDocument $d, array $alloc, array $payee, array $attrs, User $by): AdminDocumentPayment
    {
        $total = array_sum(array_column($alloc, 'cents'));
        $p = ReferenceLock::run('admin_payments', fn () => AdminDocumentPayment::create($payee + $attrs + ['reference' => AdminDocumentPayment::nextReference(), 'admin_document_id' => $d->id, 'requested_on' => now()->toDateString(),
            'total_amount' => self::money($total), 'user_id' => $by->id]));
        foreach ($alloc as $a) {
            $p->lines()->create(['line_type' => $a['type'], 'line_id' => $a['id'], 'amount' => self::money($a['cents'])]);
        }

        return $p;
    }

    /** A person marks lines paid: the payment is real at once. */
    public static function record(AdminDocument $document, string $mode, array $given, string $payeeKey, array $meta, User $by, bool $distribute = false): AdminDocumentPayment
    {
        $payee = self::resolvePayee($payeeKey);

        return DB::transaction(function () use ($document, $mode, $given, $payee, $meta, $by, $distribute) {
            $d = self::lockedAuthorized($document->id);
            $alloc = self::allocations($d, $mode, $given);
            $p = self::make($d, $alloc, $payee, ['status' => AdminDocumentPayment::PAID, 'source' => 'direct', 'paid_on' => $meta['paid_on'] ?? now()->toDateString(), 'method' => $meta['method'] ?? null,
                'payment_reference' => $meta['payment_reference'] ?? null, 'notes' => $meta['notes'] ?? null, 'paid_by_user_id' => $by->id, 'extra_attributes' => $distribute ? ['distribute' => true] : null], $by);
            self::recalc($d);
            log_activity('Administration ' . $d->reference . ' payment ' . $p->reference . ' recorded', ['amount' => (string) $p->total_amount, 'to' => $p->paid_to_name, 'lines' => count($alloc), 'mode' => $mode, 'payment_status' => $d->payment_status, 'method' => $p->method, 'distribute' => $distribute], $d, 'Administration');

            return $p->setRelation('document', $d);
        });
    }

    /** A Draft Payment Voucher is made for the chosen lines; the amount is reserved until the voucher is marked paid (then it becomes a real payment) or released. */
    public static function requestVoucher(AdminDocument $document, string $mode, array $given, string $payeeKey, ?string $notes, User $by, bool $distribute = false): AdminDocumentPayment
    {
        $payee = self::resolvePayee($payeeKey);

        return PaymentVoucherNumbers::locked(fn () => DB::transaction(function () use ($document, $mode, $given, $payee, $notes, $by, $distribute) {
            $d = self::lockedAuthorized($document->id);
            $alloc = self::allocations($d, $mode, $given);
            $p = self::voucherPayment($d, $alloc, $payee, $notes, $by, null, $distribute);
            self::recalc($d);
            log_activity('Administration ' . $d->reference . ' sent to Payment Voucher ' . $p->voucher->pv_number, ['payment' => $p->reference, 'amount' => (string) $p->total_amount, 'to' => $p->paid_to_name, 'lines' => count($alloc), 'distribute' => $distribute], $d, 'Administration');

            return $p->setRelation('document', $d);
        }));
    }

    /**
     * One voucher per beneficiary: the chosen (or all remaining) lines are grouped by the beneficiary of each line (charity lines by the person or place), every group gets its
     * own voucher for exactly its amount (already sent for approval), and all of them go into ONE Payment Voucher Batch, so approving the batch approves them all together.
     *
     * @return array{0:\App\Models\PaymentVoucherBatch,1:array<int,AdminDocumentPayment>}
     */
    public static function requestBatch(AdminDocument $document, string $mode, array $given, ?string $notes, User $by): array
    {
        return PaymentVoucherNumbers::locked(fn () => DB::transaction(function () use ($document, $mode, $given, $notes, $by) {
            $d = self::lockedAuthorized($document->id);
            $alloc = self::allocations($d, $mode, $given);
            $groups = self::groupByBeneficiary($d, $alloc);
            $batch = PaymentVoucherNumbers::createBatch(['type' => self::VOUCHER_TYPE, 'payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL, 'voucher_date' => now()->toDateString(),
                'common_particulars' => 'Administration ' . $d->reference . ' - ' . $d->doc_date->format('d M Y'), 'reference' => $d->reference, 'common_notes' => $notes, 'created_by_user_id' => $by->id]);
            $made = [];
            foreach ($groups as $g) {
                $made[] = self::voucherPayment($d, $g['alloc'], self::payeeFromName($g['name']), $notes, $by, $batch->id, false, true);
            }
            self::recalc($d);
            log_activity('Administration ' . $d->reference . ' sent to Payment Voucher Batch ' . $batch->batch_number, ['vouchers' => count($made), 'amount' => number_format(array_sum(array_map(fn ($p) => (float) $p->total_amount, $made)), 2, '.', ''), 'lines' => count($alloc)], $d, 'Administration');
            log_activity(__('Payment Voucher Batch created for an Administration document.'), ['batch_number' => $batch->batch_number, 'document' => $d->reference, 'voucher_count' => count($made)], $batch, 'Payment Voucher Batch');
            foreach ($made as $p) {
                $p->setRelation('document', $d);
            }

            return [$batch, $made];
        }));
    }

    /** The allocations grouped by who each line is for: [['name' => ..., 'alloc' => [...]], ...] in the order they appear. */
    public static function groupByBeneficiary(AdminDocument $d, array $alloc): array
    {
        $table = collect(self::lineTable($d))->keyBy('key');
        $groups = [];
        foreach ($alloc as $a) {
            $r = $table->get($a['type'] . ':' . $a['id']);
            $name = trim((string) ($r['beneficiary'] ?? '')) ?: (string) ($d->beneficiary_name ?: __('Not named'));
            $k = mb_strtolower(preg_replace('/\s+/', ' ', $name));
            $groups[$k] ??= ['name' => preg_replace('/\s+/', ' ', $name), 'alloc' => []];
            $groups[$k]['alloc'][] = $a;
        }

        return array_values($groups);
    }

    /** The payee of a beneficiary typed on a line: the staff member or the user of that exact name, otherwise just the name. */
    public static function payeeFromName(string $name): array
    {
        $n = mb_strtolower(trim($name));
        if ($s = Staff::query()->whereIn('status', Staff::PAYROLL_STATUSES)->whereRaw('LOWER(TRIM(name)) = ?', [$n])->first()) {
            return ['payee_type' => 'staff', 'paid_to_user_id' => null, 'paid_to_staff_id' => $s->id, 'paid_to_name' => $s->name];
        }
        if ($u = User::query()->where('is_active', true)->whereRaw('LOWER(TRIM(name)) = ?', [$n])->first()) {
            return ['payee_type' => 'user', 'paid_to_user_id' => $u->id, 'paid_to_staff_id' => null, 'paid_to_name' => $u->name];
        }

        return ['payee_type' => 'other', 'paid_to_user_id' => null, 'paid_to_staff_id' => null, 'paid_to_name' => mb_substr($name, 0, 190)];
    }

    /** One Pending Voucher payment with its Draft voucher (one voucher line per document line). Call inside the transaction with the document locked. */
    private static function voucherPayment(AdminDocument $d, array $alloc, array $payee, ?string $notes, User $by, ?int $batchId, bool $distribute, bool $submitted = false): AdminDocumentPayment
    {
        $p = self::make($d, $alloc, $payee, ['status' => AdminDocumentPayment::PENDING_VOUCHER, 'source' => 'voucher', 'notes' => $notes, 'extra_attributes' => $distribute ? ['distribute' => true] : null], $by);
        $total = (float) $p->total_amount;
        $staff = $payee['payee_type'] === 'staff';
        $voucher = PaymentVoucherNumbers::createVoucher([
            'payment_voucher_batch_id' => $batchId, 'type' => self::VOUCHER_TYPE, 'payee_type' => $staff ? PaymentVoucher::PAYEE_TYPE_STAFF : PaymentVoucher::PAYEE_TYPE_EXTERNAL, 'payee_contact_id' => null, 'staff_id' => $staff ? $payee['paid_to_staff_id'] : null,
            'payee_name' => $payee['paid_to_name'], 'voucher_date' => now()->toDateString(), 'total_amount' => $total, 'amount_in_words' => NumberToWords::convert($total), 'status' => $submitted ? PaymentVoucher::STATUS_SUBMITTED : PaymentVoucher::STATUS_DRAFT,
            'prepared_by_user_id' => $by->id, 'internal_notes' => __('Administration :d, payment :r', ['d' => $d->reference, 'r' => $p->reference]) . ($distribute ? ' - ' . __('to be distributed to the beneficiaries') : ''),
        ]);
        PaymentVoucherType::firstOrCreate(['text' => self::VOUCHER_TYPE]);
        $p->forceFill(['payment_voucher_id' => $voucher->id])->save();
        $table = collect(self::lineTable($d))->keyBy('key');
        foreach ($alloc as $i => $a) {
            $r = $table->get($a['type'] . ':' . $a['id']);
            $voucher->items()->create(['line_number' => $i + 1, 'admin_document_payment_id' => $i === 0 ? $p->id : null,
                'particulars' => PaymentVoucher::particularsText('Administration ' . $d->reference . ': ' . ($r['description'] ?? '') . ($distribute ? ' (' . __('to be distributed') . ')' : '') . ($notes && $i === 0 ? ' - ' . $notes : '')), 'amount' => self::money($a['cents'])]);
        }
        log_activity(__('Payment Voucher created for an Administration document.'), ['pv_number' => $voucher->pv_number, 'document' => $d->reference, 'amount' => $total, 'payment' => $p->reference], $voucher, 'Payment Voucher');
        if ($submitted) {
            log_activity(__('Payment Voucher submitted for approval.'), ['pv_number' => $voucher->pv_number], $voucher, 'Payment Voucher');
            DB::afterCommit(fn () => event(new \App\Events\PaymentVoucherStatusChanged($voucher->fresh())));
        }

        return $p->setRelation('voucher', $voucher);
    }

    /** A person reverses a payment they marked (never one made through a voucher: that is undone in the Payment Vouchers module). */
    public static function reverse(AdminDocumentPayment $payment, string $reason, User $by): AdminDocumentPayment
    {
        return DB::transaction(function () use ($payment, $reason, $by) {
            $d = AdminDocument::withoutGlobalScopes()->whereKey($payment->admin_document_id)->lockForUpdate()->firstOrFail();
            $p = AdminDocumentPayment::withoutGlobalScopes()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== AdminDocumentPayment::PAID) {
                throw ValidationException::withMessages(['reason' => __('Only a payment that is marked paid can be reversed.')]);
            }
            if ($p->source === 'voucher') {
                throw ValidationException::withMessages(['reason' => __('This payment was made through a Payment Voucher. Undo it in the Payment Vouchers module.')]);
            }
            $p->forceFill(['status' => AdminDocumentPayment::REVERSED, 'reversed_by_user_id' => $by->id, 'reversed_at' => now(), 'reverse_reason' => $reason])->save();
            self::recalc($d);
            log_activity('Administration ' . $d->reference . ' payment ' . $p->reference . ' reversed', ['amount' => (string) $p->total_amount, 'reason' => $reason, 'payment_status' => $d->payment_status], $d, 'Administration');

            return $p->setRelation('document', $d);
        });
    }

    // ---------------------------------------------------------------------------------------------------- Payment Voucher hooks

    /** The voucher was marked paid: its payment becomes real. Call inside the paid transaction. */
    public static function markPaid(PaymentVoucher $voucher): void
    {
        foreach (AdminDocumentPayment::query()->where('payment_voucher_id', $voucher->id)->pendingVoucher()->get() as $p) {
            $p->update(['status' => AdminDocumentPayment::PAID, 'paid_on' => $voucher->paid_at ? $voucher->paid_at->toDateString() : now()->toDateString(), 'method' => $voucher->payment_method,
                'payment_reference' => $voucher->payment_reference ?: $voucher->pv_number, 'paid_by_user_id' => $voucher->paid_by_user_id]);
            $d = self::recalc(AdminDocument::withoutGlobalScopes()->findOrFail($p->admin_document_id));
            log_activity('Administration ' . $d->reference . ' payment ' . $p->reference . ' paid via Payment Voucher ' . $voucher->pv_number, ['amount' => (string) $p->total_amount, 'to' => $p->paid_to_name, 'payment_status' => $d->payment_status], $d, 'Administration');
            DB::afterCommit(fn () => AdminNotifier::firePayment($d->payment_status === 'Paid' ? 'fully_paid' : 'partially_paid', $d->fresh(), $p->fresh(), auth()->user()));
        }
    }

    /** The voucher was rejected, cancelled or deleted: the reserved amount is released (the payment stays as history). */
    public static function release(PaymentVoucher $voucher, string $reason): void
    {
        AdminDocumentPayment::query()->where('payment_voucher_id', $voucher->id)->pendingVoucher()->get()->each(function ($p) use ($voucher, $reason) {
            $p->update(['status' => AdminDocumentPayment::RELEASED, 'released_at' => now(), 'release_reason' => $reason]);
            $d = self::recalc(AdminDocument::withoutGlobalScopes()->findOrFail($p->admin_document_id));
            log_activity('Administration ' . $d->reference . ' payment ' . $p->reference . ' released: ' . $reason . ' (voucher ' . $voucher->pv_number . ')', ['amount' => (string) $p->total_amount], $d, 'Administration');
            DB::afterCommit(fn () => AdminNotifier::firePayment('voucher_released', $d->fresh(), $p->fresh(), auth()->user(), (string) $reason));
        });
    }

    /**
     * A Draft / Rejected Payment Voucher of an Administration payment is edited in its own module: the amount must stay what the document lines say (so nothing is paid that
     * was not authorized), and a released payment reserves its lines again when there is still something left on each. Returns the payment id the first voucher line points at.
     */
    public static function syncOnEdit(PaymentVoucher $voucher, float $total): ?int
    {
        $p = AdminDocumentPayment::query()->where('payment_voucher_id', $voucher->id)->whereIn('status', [AdminDocumentPayment::PENDING_VOUCHER, AdminDocumentPayment::RELEASED])->lockForUpdate()->first();
        if (! $p) {
            return null;
        }
        if (abs(round($total, 2) - (float) $p->total_amount) > 0.001) {
            throw ValidationException::withMessages(['items' => __('The amount of this voucher follows the lines of the Administration document (:a). To pay a different amount, make a new payment on the document.', ['a' => number_format((float) $p->total_amount, 2)])]);
        }
        $d = AdminDocument::withoutGlobalScopes()->whereKey($p->admin_document_id)->lockForUpdate()->firstOrFail();
        if ($p->status === AdminDocumentPayment::RELEASED) {
            $table = collect(self::lineTable($d))->keyBy('key');
            foreach ($p->lines()->get() as $l) {
                if (self::cents($table->get($l->line_type . ':' . $l->line_id)['balance'] ?? 0) < self::cents($l->amount)) {
                    throw ValidationException::withMessages(['items' => __('One of the lines of this voucher has been paid in the meantime, so the voucher cannot be sent again.')]);
                }
            }
            $p->update(['status' => AdminDocumentPayment::PENDING_VOUCHER, 'released_at' => null, 'release_reason' => null]);
            self::recalc($d);
            log_activity('Administration ' . $d->reference . ' payment ' . $p->reference . ' reserved again (voucher ' . $voucher->pv_number . ' edited)', ['amount' => (string) $p->total_amount], $d, 'Administration');
        }

        return $p->id;
    }
}
