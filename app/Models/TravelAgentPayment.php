<?php

namespace App\Models;

use App\Traits\HasAttachments;

/**
 * A payment to a ticket agent / visa issuer. It is made ONLY through a Payment Voucher that is started from the agent's balance in Travels and then waits
 * for approval in the Payment Vouchers module. While the voucher waits the amount is reserved ("Pending Voucher"); when the voucher is marked paid the payment
 * is "Paid" and reduces what the agent is owed; if the voucher is rejected, cancelled or deleted the payment is "Released" (kept as history, counts for nothing).
 */
class TravelAgentPayment extends Model
{
    use HasAttachments;

    public const PENDING_VOUCHER = 'Pending Voucher';

    public const PAID = 'Paid';

    public const RELEASED = 'Released';

    public const STATUSES = [self::PENDING_VOUCHER, self::PAID, self::RELEASED];

    public static $tracksDeletes = true;

    protected $table = 'travel_agent_payments';

    protected $setHash = true;

    protected $setUser = true;

    protected $fillable = [
        'account_id', 'reference', 'hash', 'contact_id', 'travel_id', 'currency', 'amount', 'exchange_rate', 'voucher_amount', 'status', 'payment_voucher_id', 'requested_on', 'paid_on', 'method',
        'payment_reference', 'notes', 'user_id', 'paid_by_user_id', 'released_at', 'release_reason', 'deleted_by', 'extra_attributes',
    ];

    public $casts = ['amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'voucher_amount' => 'decimal:2', 'requested_on' => 'date', 'paid_on' => 'date', 'released_at' => 'datetime', 'extra_attributes' => 'array'];

    public function contact()
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function travel()
    {
        return $this->belongsTo(Travel::class, 'travel_id')->withTrashed();
    }

    public function voucher()
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function scopePendingVoucher($q)
    {
        return $q->where('status', self::PENDING_VOUCHER);
    }

    public static function nextReference(): string
    {
        $max = (int) \Illuminate\Support\Facades\DB::table('travel_agent_payments')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'TPY-%')->value('m');

        return 'TPY-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
