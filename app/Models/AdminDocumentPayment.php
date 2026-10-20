<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * A payment of an Administration document: the lines it pays (and how much of each), to whom, and how it came about. "Paid" payments count; "Pending Voucher"
 * ones are reserved while their Payment Voucher waits; "Released" (voucher rejected / cancelled / deleted) and "Reversed" ones count for nothing and stay as history.
 */
class AdminDocumentPayment extends Model
{
    public const PAID = 'Paid';

    public const PENDING_VOUCHER = 'Pending Voucher';

    public const RELEASED = 'Released';

    public const REVERSED = 'Reversed';

    public const STATUSES = [self::PAID, self::PENDING_VOUCHER, self::RELEASED, self::REVERSED];

    protected $table = 'admin_document_payments';

    protected $fillable = ['account_id', 'reference', 'admin_document_id', 'status', 'source', 'requested_on', 'paid_on', 'method', 'payment_reference', 'payee_type', 'paid_to_user_id', 'paid_to_staff_id', 'paid_to_name',
        'total_amount', 'notes', 'payment_voucher_id', 'user_id', 'paid_by_user_id', 'released_at', 'release_reason', 'reversed_by_user_id', 'reversed_at', 'reverse_reason', 'extra_attributes'];

    public $casts = ['requested_on' => 'date', 'paid_on' => 'date', 'total_amount' => 'decimal:2', 'released_at' => 'datetime', 'reversed_at' => 'datetime', 'extra_attributes' => 'array'];

    public function document()
    {
        return $this->belongsTo(AdminDocument::class, 'admin_document_id')->withTrashed();
    }

    public function lines()
    {
        return $this->hasMany(AdminDocumentPaymentLine::class, 'admin_document_payment_id');
    }

    public function voucher()
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** Paid to one person who gives the money to the beneficiaries offline. */
    public function isDistribution(): bool
    {
        $raw = $this->getRawOriginal('extra_attributes');
        $a = is_string($raw) ? json_decode($raw, true) : (array) $raw;

        return ! empty($a['distribute']);
    }

    public function scopePendingVoucher($q)
    {
        return $q->where('status', self::PENDING_VOUCHER);
    }

    public static function nextReference(): string
    {
        $max = (int) DB::table('admin_document_payments')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'ADP-%')->value('m');

        return 'ADP-' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }
}
