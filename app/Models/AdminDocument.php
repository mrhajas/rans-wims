<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * One weekly Administration document: numbered lines (description, beneficiary, amount) and an optional Charity section, in three stages: Requested, Approved
 * (by someone who may approve) and Authorized (by someone who may authorize). Only an authorized document counts as spent in the reports unless the report says otherwise.
 */
class AdminDocument extends Model
{
    public const REQUESTED = 'Requested';

    public const APPROVED = 'Approved';

    public const AUTHORIZED = 'Authorized';

    public const REJECTED = 'Rejected';

    public const CANCELLED = 'Cancelled';

    public const STATUSES = [self::REQUESTED, self::APPROVED, self::AUTHORIZED, self::REJECTED, self::CANCELLED];

    /** The three stages of a document, in order. */
    public const STAGES = [self::REQUESTED, self::APPROVED, self::AUTHORIZED];

    public static $tracksDeletes = true;

    protected $table = 'admin_documents';

    protected $setHash = true;

    protected $setUser = true;

    protected $fillable = [
        'account_id', 'reference', 'hq', 'hash', 'status', 'doc_date', 'department', 'currency', 'beneficiary_user_id', 'beneficiary_name', 'include_charity', 'items_total', 'charity_total', 'total_amount', 'paid_total', 'reserved_total', 'payment_status', 'last_paid_at', 'notes',
        'source', 'duplicated_from_id', 'user_id', 'approved_by_user_id', 'approved_at', 'approval_remarks', 'authorized_by_user_id', 'authorized_at', 'authorization_remarks',
        'rejected_by_user_id', 'rejected_at', 'rejection_reason', 'cancelled_by_user_id', 'cancelled_at', 'cancel_reason', 'sent_count', 'last_sent_at', 'deleted_by', 'extra_attributes',
    ];

    public $casts = [
        'doc_date' => 'date', 'include_charity' => 'boolean', 'items_total' => 'decimal:2', 'charity_total' => 'decimal:2', 'total_amount' => 'decimal:2', 'paid_total' => 'decimal:2', 'reserved_total' => 'decimal:2', 'last_paid_at' => 'datetime',
        'approved_at' => 'datetime', 'authorized_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'last_sent_at' => 'datetime', 'extra_attributes' => 'array',
    ];

    public function lines()
    {
        return $this->hasMany(AdminDocumentItem::class, 'admin_document_id')->orderBy('sort_order')->orderBy('id');
    }

    public function charityLines()
    {
        return $this->hasMany(AdminDocumentCharity::class, 'admin_document_id')->orderBy('sort_order')->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(AdminDocumentPayment::class, 'admin_document_id')->orderByDesc('id');
    }

    public function sends()
    {
        return $this->hasMany(AdminDocumentSend::class, 'admin_document_id')->orderByDesc('id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function beneficiary()
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id')->withTrashed();
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function authorizedBy()
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function duplicatedFrom()
    {
        return $this->belongsTo(self::class, 'duplicated_from_id')->withTrashed();
    }

    /** The department wording on every document (Administration settings). */
    public static function departmentName(): string
    {
        return trim((string) (get_settings('admin_department_name') ?: 'ADMINISTRATION')) ?: 'ADMINISTRATION';
    }

    public static function currency(): string
    {
        return strtoupper(mb_substr(trim((string) (get_settings('admin_currency') ?: 'TZS')), 0, 3)) ?: 'TZS';
    }

    /** The wording of a charity line; :name and :month are replaced (Administration settings). */
    public static function charityWording(): string
    {
        return trim((string) (get_settings('admin_charity_wording') ?: 'CHARITY - :name FOR :month')) ?: 'CHARITY - :name FOR :month';
    }

    public const DEFAULT_HQS = 'Unguja HQ, Pemba HQ';

    /** The headquarters a document can belong to (Administration settings); any HQ already used on a document or a charity person is still known. */
    public static function hqs(): array
    {
        $set = collect(preg_split('/[,;\n]+/', (string) (get_settings('admin_hqs') ?: self::DEFAULT_HQS)) ?: [])->map(fn ($x) => mb_substr(trim($x), 0, 60))->filter()->unique()->values()->all();
        $used = collect(DB::table('admin_documents')->whereNotNull('hq')->distinct()->pluck('hq'))->merge(DB::table('admin_charity_people')->whereNotNull('hq')->distinct()->pluck('hq'))->all();
        foreach ($used as $u) {
            if (! in_array(mb_strtolower($u), array_map('mb_strtolower', $set), true)) {
                $set[] = $u;
            }
        }

        return $set;
    }

    public static function nextReference(): string
    {
        $max = (int) DB::table('admin_documents')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'ADM-%')->value('m');

        return 'ADM-' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Which documents a person may see: all of them for people who approve, authorize or may see everything; otherwise the ones they made or are the beneficiary of. */
    public function scopeVisibleTo($q, User $u)
    {
        if ($u->hasRole('Super Admin') || $u->view_all || $u->can('view-all-weekly-administration') || $u->can('approve-weekly-administration') || $u->can('authorize-weekly-administration')
            || $u->can('mark-paid-weekly-administration') || $u->can('create-payment-voucher-from-administration')) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('user_id', $u->id)->orWhere('beneficiary_user_id', $u->id));
    }

    /** Adds the lines and the charity section up and keeps the three totals on the document (in cents, so nothing drifts). */
    public function recalculate(): self
    {
        $items = (int) round(((float) DB::table('admin_document_items')->where('admin_document_id', $this->id)->whereNull('deleted_at')->sum('amount')) * 100);
        $charity = (int) round(((float) DB::table('admin_document_charities')->where('admin_document_id', $this->id)->whereNull('deleted_at')->sum('amount')) * 100);
        $charity = $this->include_charity ? $charity : 0;
        $this->forceFill(['items_total' => number_format($items / 100, 2, '.', ''), 'charity_total' => number_format($charity / 100, 2, '.', ''), 'total_amount' => number_format(($items + $charity) / 100, 2, '.', '')])->save();

        return $this;
    }

    /** A fingerprint of what the document says (date, HQ, every line, the charity section, the total): an approval is only valid for the content the approver saw. */
    public function contentHash(): string
    {
        $l = $this->lines()->get(['description', 'beneficiary', 'category', 'staff_id', 'amount'])->toArray();
        $c = $this->charityLines()->get(['description', 'name', 'amount'])->toArray();

        return md5(json_encode([$this->hq, $this->doc_date ? $this->doc_date->toDateString() : null, (string) $this->total_amount, (bool) $this->include_charity, $l, $c]));
    }

    public function stageIndex(): int
    {
        $i = array_search($this->status, self::STAGES, true);

        return $i === false ? -1 : $i;
    }

    public static function statusChip(string $s): string
    {
        return ['Requested' => 'amber', 'Approved' => 'sky', 'Authorized' => 'green', 'Rejected' => 'red', 'Cancelled' => 'slate'][$s] ?? 'slate';
    }
}
