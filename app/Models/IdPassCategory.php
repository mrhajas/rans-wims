<?php

namespace App\Models;

/** A category of RANS ID or pass: the numbering (prefix, digits, next number), the colours and icon of its card, the validity it starts with and the rules of issuing it. */
class IdPassCategory extends Model
{
    public const KINDS = ['staff' => 'Staff ID', 'compound' => 'Compound Worker ID', 'visitor' => 'Visitor Pass', 'gate' => 'Gate Pass'];

    public const UNITS = ['days' => 'days', 'months' => 'months', 'years' => 'years'];

    protected $table = 'idpass_categories';

    protected $fillable = ['account_id', 'kind', 'key', 'name', 'label', 'prefix', 'digits', 'next_number', 'band_color', 'arrow_color', 'photo_background', 'icon', 'contact_categories', 'validity_value', 'validity_unit',
        'requires_approval', 'photo_required', 'vehicle_required', 'pooled', 'is_active', 'sort_order'];

    public $casts = ['contact_categories' => 'array', 'requires_approval' => 'bool', 'photo_required' => 'bool', 'vehicle_required' => 'bool', 'pooled' => 'bool', 'is_active' => 'bool', 'digits' => 'int', 'next_number' => 'int', 'validity_value' => 'int'];

    public function cards()
    {
        return $this->hasMany(IdPassCard::class, 'category_id');
    }

    /** What the next number looks like: RS001. */
    public function format(int $seq): string
    {
        return $this->prefix . str_pad((string) $seq, max(1, (int) $this->digits), '0', STR_PAD_LEFT);
    }

    /** The expiry a card issued on $from starts with (the day before the same date a validity later: 16 May 2026 + 2 years = 15 May 2028). Null = no expiry. */
    public function expiryFrom(\Carbon\CarbonInterface $from): ?\Carbon\CarbonInterface
    {
        if (! $this->validity_value) {
            return null;
        }
        $d = $from->copy();
        $d = match ($this->validity_unit) {
            'days' => $d->addDays($this->validity_value - 1),
            'months' => $d->addMonthsNoOverflow($this->validity_value)->subDay(),
            default => $d->addYearsNoOverflow($this->validity_value)->subDay(),
        };

        return $d;
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, sscanf(ltrim($hex, '#') ? '#' . ltrim($hex, '#') : '#000000', '#%02x%02x%02x') ?: [0, 0, 0]);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** The colour this category wears in lists and chips: its card band, or (when the band is nearly black, like Aluminium) its arrow colour, so every category can be told apart at a glance. */
    public function uiColor(): string
    {
        $band = (string) ($this->band_color ?: '#1565c0');
        if (self::luminance($band) < 0.045 && self::luminance((string) $this->arrow_color) >= 0.12 && self::luminance((string) $this->arrow_color) <= 0.62) {
            return strtolower((string) $this->arrow_color);
        }

        return strtolower($band);
    }

    /** Text colour on that chip: white on a dark colour, dark on a light one. */
    public function uiInk(): string
    {
        return self::luminance($this->uiColor()) > 0.38 ? '#1f2937' : '#ffffff';
    }

    /** The icon of this category in lists: the one chosen, or one for its kind. */
    public function uiIcon(): string
    {
        return $this->icon ?: ['staff' => 'id-badge', 'compound' => 'toolbox', 'visitor' => 'handshake', 'gate' => 'car'][$this->kind] ?? 'user';
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOfKind($q, string $kind)
    {
        return $q->where('kind', $kind);
    }
}
