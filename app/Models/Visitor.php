<?php

namespace App\Models;

use App\Support\TzPhone;

/**
 * A person who visits the premises (the Visitor Directory). Not a Contact and not a user: it only exists so a returning visitor is chosen instead of typed again.
 * The identification number is encrypted and only shown to people who may see sensitive details.
 */
class Visitor extends Model
{
    public static $tracksDeletes = true;

    protected $table = 'visitors';

    protected $setHash = true;

    protected $fillable = ['account_id', 'reference', 'hash', 'full_name', 'company', 'phone', 'phone_key', 'email', 'id_type', 'id_number', 'nationality', 'address', 'category', 'vehicle_registration',
        'emergency_notes', 'remarks', 'contact_id', 'is_active', 'created_by_user_id', 'deactivated_by_user_id', 'deactivated_at', 'deleted_by', 'extra_attributes'];

    public $casts = ['is_active' => 'boolean', 'deactivated_at' => 'datetime', 'id_number' => 'encrypted', 'extra_attributes' => 'array'];

    /** What a person without the sensitive permission never gets. */
    public const SENSITIVE = ['id_type', 'id_number', 'nationality', 'address', 'emergency_notes'];

    public function visits()
    {
        return $this->hasMany(VisitorVisit::class, 'visitor_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The phone as it is kept: +255 form when it is a Tanzanian number, otherwise what was typed (visitors can be from anywhere). */
    public static function cleanPhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        return TzPhone::normalize($raw) ?: mb_substr($raw, 0, 40);
    }

    /** The last 9 digits: two ways of writing the same number have the same key (0777..., +255777...). */
    public static function phoneKey(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);

        return strlen($d) >= 7 ? substr($d, -9) : null;
    }

    public static function nextReference(): string
    {
        $max = (int) \Illuminate\Support\Facades\DB::table('visitors')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'VST-%')->value('m');

        return 'VST-' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /** People who look like this one: the same phone number, or the same name at the same company (or the same name when there is no company). */
    public static function likelyMatches(string $name, ?string $company, ?string $phone, ?int $exceptId = null)
    {
        $key = self::phoneKey($phone);
        $name = mb_strtolower(trim($name));

        return static::query()->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(function ($q) use ($key, $name, $company) {
                if ($key) {
                    $q->orWhere('phone_key', $key);
                }
                if ($name !== '') {
                    $q->orWhere(fn ($w) => $w->whereRaw('LOWER(full_name) = ?', [$name])->when(trim((string) $company) !== '', fn ($x) => $x->whereRaw('LOWER(COALESCE(company, "")) = ?', [mb_strtolower(trim((string) $company))])));
                }
            })->orderBy('full_name')->limit(5)->get();
    }

    /** The compact card used by the visitor picker and the lists: no sensitive detail. */
    public function summary(?User $u = null, bool $withHistory = false): array
    {
        $out = ['id' => $this->id, 'reference' => $this->reference, 'name' => $this->full_name, 'company' => $this->company, 'phone' => $this->phone, 'email' => $this->email, 'category' => $this->category,
            'vehicle' => $this->vehicle_registration, 'contact_id' => $this->contact_id, 'is_active' => (bool) $this->is_active, 'label' => trim($this->full_name . ($this->company ? ' · ' . $this->company : '') . ($this->phone ? ' · ' . $this->phone : ''))];
        if ($withHistory) {
            $last = $this->visits()->whereIn('status', [VisitorVisit::IN, VisitorVisit::OUT])->latest('check_in_at')->first(['id', 'reference', 'check_in_at']);
            $out['visits'] = (int) $this->visits()->whereIn('status', [VisitorVisit::IN, VisitorVisit::OUT])->count();
            $out['last_visit_at'] = $last ? $last->check_in_at->toIso8601String() : null;
        }

        return $out;
    }
}
