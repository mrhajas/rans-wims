<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectCertificateContractAddendumItem extends Model
{
    protected $fillable = [
        'addendum_id', 'description', 'length', 'width', 'height', 'unit',
        'quantity', 'unit_rate', 'amount', 'sort_order',
    ];

    protected $casts = [
        'length' => 'decimal:3',
        'width' => 'decimal:3',
        'height' => 'decimal:3',
        'quantity' => 'decimal:3',
        'unit_rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function addendum()
    {
        return $this->belongsTo(ProjectCertificateContractAddendum::class, 'addendum_id');
    }
}
