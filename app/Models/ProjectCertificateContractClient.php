<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One client's share of a project's funding — a contract can now be
 * co-funded by more than one client, each paying in their own currency.
 * See ProjectCertificateContractController::update() for how these roll up
 * into the contract's own (TZS) original_contract_sum.
 */
class ProjectCertificateContractClient extends Model
{
    protected $fillable = ['contract_id', 'client_contact_id', 'currency', 'amount', 'exchange_rate', 'sort_order'];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    public function contract()
    {
        return $this->belongsTo(ProjectCertificateContract::class, 'contract_id');
    }

    public function client()
    {
        return $this->belongsTo(Contact::class, 'client_contact_id');
    }

    /**
     * This client's contribution converted to TZS — null (never a
     * fabricated number) when the currency isn't TZS and no exchange rate
     * has been agreed yet, matching ProjectCertificate::amountInTzs()'s
     * own null-safety convention.
     */
    public function amountInTzs(): ?float
    {
        if (strtoupper($this->currency) === 'TZS') {
            return (float) $this->amount;
        }

        if (! $this->exchange_rate) {
            return null;
        }

        return round((float) $this->amount * (float) $this->exchange_rate, 2);
    }
}
