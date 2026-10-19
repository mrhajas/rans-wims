<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class FaceTemplate extends Model
{
    protected $guarded = [];

    protected $hidden = ['descriptors'];

    protected $casts = [
        'enrolled_at' => 'datetime', 'last_used_at' => 'datetime', 'locked_until' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<list<float>> the enrolled face templates (each 128 numbers) */
    public function vectors(): array
    {
        try {
            $v = json_decode(Crypt::decryptString($this->getRawOriginal('descriptors')), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($v) ? $v : [];
    }

    /** @param list<list<float>> $vectors */
    public function setVectors(array $vectors): void
    {
        $this->attributes['descriptors'] = Crypt::encryptString(json_encode($vectors));
        $this->attributes['sample_count'] = count($vectors);
    }
}
