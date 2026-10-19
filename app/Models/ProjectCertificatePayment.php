<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Illuminate\Database\Eloquent\Model;

class ProjectCertificatePayment extends Model
{
    use HasAttachments;

    protected $fillable = [
        'certificate_id', 'amount', 'paid_currency', 'paid_amount', 'exchange_rate',
        'payment_date', 'reference', 'method', 'paid_by', 'reference_number', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'payment_date' => 'date',
    ];

    /**
     * True only when the payment was actually received in a currency other
     * than the contract's own — the common case (paid_currency null or
     * matching) needs no exchange-rate tooltip at all.
     */
    public function wasForeignCurrency(?string $contractCurrency): bool
    {
        return $this->paid_currency && $contractCurrency && strtoupper($this->paid_currency) !== strtoupper($contractCurrency);
    }

    /**
     * RCT-{project code}-{001}, recomputed from the warehouse's CURRENT
     * code on every read rather than trusted from the stored column — same
     * reasoning as ProjectCertificate::reference(): a project's code can be
     * renamed after payments already exist, and its receipt references
     * should follow the rename. Numbered sequentially across every payment
     * on every certificate for this project (not per-certificate), matching
     * how a receipt book runs one sequence per client/project.
     */
    protected function reference(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value) {
                $certificate = $this->certificate;
                $warehouse = $certificate?->warehouse;
                if (! $certificate || ! $warehouse || ! $this->id) {
                    return $value;
                }

                $sequence = self::whereHas('certificate', fn ($q) => $q->where('warehouse_id', $certificate->warehouse_id))
                    ->where('id', '<=', $this->id)
                    ->count();

                return app(\App\Services\ProjectCertificates\CertificateNumbering::class)->buildPaymentReference($warehouse->code, $sequence);
            }
        );
    }

    public function certificate()
    {
        return $this->belongsTo(ProjectCertificate::class, 'certificate_id')->withoutGlobalScope('workflow_visibility');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
