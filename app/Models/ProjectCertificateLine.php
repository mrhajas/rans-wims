<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectCertificateLine extends Model
{
    public const TYPE_INPUT = 'input';
    public const TYPE_ADDITION = 'addition';
    public const TYPE_DEDUCTION = 'deduction';
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_REFERENCE = 'reference';
    public const TYPE_FORMULA = 'formula';
    public const TYPE_SUBTOTAL = 'subtotal';
    public const TYPE_TOTAL = 'total';

    public static function types(): array
    {
        return [
            self::TYPE_INPUT, self::TYPE_ADDITION, self::TYPE_DEDUCTION, self::TYPE_PERCENTAGE,
            self::TYPE_REFERENCE, self::TYPE_FORMULA, self::TYPE_SUBTOTAL, self::TYPE_TOTAL,
        ];
    }

    protected $fillable = ['certificate_id', 'line_key', 'label', 'type', 'sort_order', 'operands', 'value', 'is_locked'];

    protected $casts = [
        'operands' => 'array',
        'value' => 'decimal:2',
        'is_locked' => 'boolean',
    ];

    public function certificate()
    {
        return $this->belongsTo(ProjectCertificate::class, 'certificate_id')->withoutGlobalScope('workflow_visibility');
    }
}
