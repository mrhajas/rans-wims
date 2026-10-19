<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Illuminate\Database\Eloquent\Model;

class ProjectCertificateContract extends Model
{
    use HasAttachments;

    protected $fillable = [
        'warehouse_id', 'client_contact_id', 'consultant_contact_id', 'contractor_name',
        'contract_reference', 'currency', 'vat_included', 'vat_rate', 'vat_exempted', 'original_contract_sum',
        'contract_variation_amount', 'revised_contract_sum', 'contract_start_date',
        'contract_end_date', 'created_by',
    ];

    protected $casts = [
        'vat_included' => 'boolean',
        'vat_rate' => 'decimal:2',
        'vat_exempted' => 'boolean',
        'original_contract_sum' => 'decimal:2',
        'contract_variation_amount' => 'decimal:2',
        'revised_contract_sum' => 'decimal:2',
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function client()
    {
        return $this->belongsTo(Contact::class, 'client_contact_id');
    }

    /**
     * Every client funding this contract, each in their own currency — the
     * source of truth original_contract_sum is now computed from. `client`
     * (above) stays a plain FK kept in sync with the first row here, purely
     * so older/unrelated call sites that just need "a" client to show
     * (PDFs, dashboards) keep working without every one of them having to
     * learn about this breakdown.
     */
    public function clients()
    {
        return $this->hasMany(ProjectCertificateContractClient::class, 'contract_id')->orderBy('sort_order');
    }

    public function consultant()
    {
        return $this->belongsTo(Contact::class, 'consultant_contact_id');
    }

    public function certificates()
    {
        return $this->hasMany(ProjectCertificate::class, 'contract_id');
    }

    public function addendums()
    {
        return $this->hasMany(ProjectCertificateContractAddendum::class, 'contract_id')->orderByDesc('addendum_number');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contractorDisplayName(): string
    {
        return $this->contractor_name ?: (optional($this->warehouse?->account)->name ?: 'RANS');
    }
}
