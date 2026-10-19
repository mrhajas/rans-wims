<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectCertificateRevision extends Model
{
    protected $fillable = ['certificate_id', 'version', 'snapshot', 'changed_by', 'reason'];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function certificate()
    {
        return $this->belongsTo(ProjectCertificate::class, 'certificate_id')->withoutGlobalScope('workflow_visibility');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
