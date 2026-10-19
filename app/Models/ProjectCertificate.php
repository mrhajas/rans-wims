<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class ProjectCertificate extends Model
{
    use HasAttachments;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_REVISED = 'revised';
    public const STATUS_CANCELLED = 'cancelled';

    public static function statuses(): array
    {
        // STATUS_REVISED is deliberately excluded here — no code path ever
        // assigns it (revise() resets status to STATUS_DRAFT, see
        // ProjectCertificateController::revise()), so listing it as a
        // filterable status always returns zero rows. Left defined as a
        // constant in case it's wired up later.
        return [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_ISSUED, self::STATUS_CANCELLED];
    }

    protected $fillable = [
        'warehouse_id', 'contract_id', 'certificate_number', 'reference', 'certificate_type', 'status',
        'valuation_date', 'certificate_date', 'payment_due_date', 'period_covered',
        'description', 'notes', 'cumulative_certified_amount', 'net_payment_amount',
        'currency', 'exchange_rate',
        'version', 'is_locked', 'status_override_reason', 'payment_status_override',
        'created_by', 'submitted_by', 'approved_by', 'issued_by',
        'submitted_at', 'approved_at', 'issued_at',
    ];

    protected $casts = [
        'valuation_date' => 'date',
        'certificate_date' => 'date',
        'payment_due_date' => 'date',
        'cumulative_certified_amount' => 'decimal:2',
        'net_payment_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'is_locked' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The certificate's own reference is recomputed from the warehouse's
     * CURRENT code every time it's read, never trusted from the stored
     * `reference` column — a project's code can be renamed after
     * certificates already exist, and every reference on that project
     * should follow the rename, not freeze at whatever code was current
     * when each certificate happened to be created.
     */
    protected function reference(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value) {
                $warehouse = $this->warehouse;
                if (! $warehouse || ! $this->certificate_number) {
                    return $value;
                }

                $year = $this->certificate_date ? $this->certificate_date->format('Y') : now()->format('Y');

                return app(\App\Services\ProjectCertificates\CertificateNumbering::class)->buildReference($warehouse->code, $this->certificate_number, (int) $year);
            }
        );
    }

    /**
     * A → B → C… for version 1, 2, 3… — revising a certificate keeps its
     * `certificate_number`/reference unchanged (only `version` bumps), so
     * two certificates can look identical in a list ("RANS/2026/MENTAL/00002"
     * twice, once historical from before a revision, once current) with
     * nothing but a small numeric badge telling them apart. This suffix is
     * purely a DISPLAY label, appended in list views only — the underlying
     * `reference` accessor stays exactly as-is for search, the issued PDF,
     * and anything else that needs the certificate's one official number.
     */
    public function versionLetter(): string
    {
        return self::letterForVersion($this->version);
    }

    /**
     * Base-26 "Excel column" letter for an arbitrary version number (1→A,
     * 2→B, …27→AA) — a static helper so a superseded ProjectCertificateRevision
     * row (which carries its own `version` but isn't itself a ProjectCertificate
     * instance) can be labeled the same way as the live certificate.
     */
    public static function letterForVersion(int $version): string
    {
        $letter = '';
        $n = $version;
        while ($n > 0) {
            $n--;
            $letter = chr(65 + ($n % 26)) . $letter;
            $n = intdiv($n, 26);
        }

        return $letter ?: 'A';
    }

    /**
     * The currency net_payment_amount/outstanding/totalPaid are actually
     * denominated in — the certificate's own `currency` column when set
     * (certificates created before that column existed, or where it was
     * left blank, have currency = NULL), falling back to the contract's,
     * same precedence ProjectCertificateController::certificateRow() uses
     * for display. Centralized here so amountInTzs() and every other
     * currency-aware calculation agree on what "this certificate's
     * currency" means, rather than each picking its own fallback.
     */
    public function effectiveCurrency(): string
    {
        return $this->currency ?: ($this->contract->currency ?? 'TZS');
    }

    /**
     * net_payment_amount converted to TZS using the certificate's own
     * currency/exchange_rate (both stamped at creation from the day's
     * rate) — RANS reports centrally in TZS regardless of which currency
     * an individual contract/certificate is denominated in. Returns null
     * (never a fabricated number) when a foreign-currency certificate has
     * no rate stamped — treating a missing rate as "1" would silently
     * report e.g. USD 410,000 as ≈ TZS 410,000, off by three orders of
     * magnitude.
     */
    public function amountInTzs(): ?float
    {
        if (strtoupper($this->effectiveCurrency()) === 'TZS') {
            return (float) $this->net_payment_amount;
        }

        if (! $this->exchange_rate) {
            return null;
        }

        return round((float) $this->net_payment_amount * (float) $this->exchange_rate, 2);
    }

    /**
     * totalPaid() converted to TZS, same rate/null-safety rules as
     * amountInTzs() — used for the Payment History "Total" row's TZS
     * equivalent on a foreign-currency certificate.
     */
    public function totalPaidInTzs(): ?float
    {
        if (strtoupper($this->effectiveCurrency()) === 'TZS') {
            return $this->totalPaid();
        }

        if (! $this->exchange_rate) {
            return null;
        }

        return round($this->totalPaid() * (float) $this->exchange_rate, 2);
    }

    public function contract()
    {
        return $this->belongsTo(ProjectCertificateContract::class, 'contract_id');
    }

    public function lines()
    {
        return $this->hasMany(ProjectCertificateLine::class, 'certificate_id')->orderBy('sort_order');
    }

    public function payments()
    {
        return $this->hasMany(ProjectCertificatePayment::class, 'certificate_id');
    }

    public function revisions()
    {
        return $this->hasMany(ProjectCertificateRevision::class, 'certificate_id')->orderByDesc('version');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * SUM(payments), never the certificate's own amount — certification and
     * payment are always kept as separate figures (spec's core accounting
     * principle).
     */
    public function totalPaid(): float
    {
        // Use the eager-loaded collection when a list query already fetched it —
        // `->payments()->sum()` always hits the DB again, once per certificate per call.
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->sum('amount');
        }

        return (float) $this->payments()->sum('amount');
    }

    public function outstanding(): float
    {
        return round((float) ($this->net_payment_amount ?? 0) - $this->totalPaid(), 2);
    }

    /**
     * True when this certificate's balance should still count as real money
     * owed. Normally that just means status === issued, but one that was
     * issued and later reopened for revision (revise() reverts status to
     * draft and bumps version) can still carry a genuine, uncollected
     * balance from payments recorded while it WAS issued — that money
     * doesn't stop being owed just because the certificate is mid-revision,
     * so `version > 1` keeps it counted until the revision is resolved and
     * re-issued. Never true once cancelled, matching paymentStatus()'s own
     * "a cancelled certificate is never chased" rule.
     */
    public function countsAsOutstanding(): bool
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return false;
        }

        return $this->status === self::STATUS_ISSUED || $this->version > 1;
    }

    /**
     * Auto-derived unless status_override_reason is set, in which case the
     * DB column (set explicitly by the controller) wins — see
     * ProjectCertificatePaymentController::overrideStatus().
     */
    public function paymentStatus(): string
    {
        if ($this->payment_status_override) {
            return $this->payment_status_override;
        }

        $paid = $this->totalPaid();
        $net = (float) ($this->net_payment_amount ?? 0);

        // A cancelled certificate is never chased for payment — its
        // outstanding balance (if any) isn't money still owed, so it must
        // never compute as "overdue". Still falls through to
        // unpaid/partially_paid/paid_in_full below so the payment history
        // stays honest for audit purposes.
        $isCancelled = $this->status === self::STATUS_CANCELLED;

        // lt(today()), not isPast() — payment_due_date casts to a
        // date-only Carbon at 00:00:00, so isPast() would already read
        // true from the first moment of the due date itself, marking a
        // certificate "overdue" (and 0 days overdue, per the Unpaid
        // register's own diffInDays) on the day payment is actually due.
        if (! $isCancelled && $this->payment_due_date && $this->payment_due_date->lt(today()) && $this->outstanding() > 0) {
            return 'overdue';
        }
        if ($paid <= 0) {
            return 'unpaid';
        }
        if ($paid >= $net) {
            return 'paid_in_full';
        }

        return 'partially_paid';
    }
}
