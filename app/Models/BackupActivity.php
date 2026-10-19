<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class BackupActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'activity',
        'database',
        'filename',
        'statements_run',
        'errors_count',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    protected $appends = [
        'file_size',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getFileSizeAttribute(): ?int
    {
        $size = $this->properties['size'] ?? null;
        if ($size !== null) {
            return (int) $size;
        }

        $path = $this->properties['path'] ?? null;
        if ($path && is_file($path)) {
            return filesize($path);
        }

        return null;
    }
}
