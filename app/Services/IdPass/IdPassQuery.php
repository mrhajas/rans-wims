<?php

namespace App\Services\IdPass;

use App\Models\IdPassCard;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/** The one way IDs are searched and filtered (the lists, the Print Centre, the batches, the reports and ARIS all use it), and the one way a card row is shown in a list. */
class IdPassQuery
{
    public const SORTS = ['number' => 'number', 'name' => 'full_name', 'status' => 'status', 'issued' => 'issued_at', 'expires' => 'expires_at', 'printed' => 'last_printed_at', 'category' => 'category_id', 'created' => 'id'];

    public static function filters(array $in): array
    {
        $s = fn ($k) => trim((string) ($in[$k] ?? ''));
        $date = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s($k)) ? $s($k) : '';

        return ['q' => $s('q'), 'category_id' => ctype_digit($s('category_id')) ? (int) $s('category_id') : 0, 'status' => in_array($s('status'), IdPassCard::STATUSES, true) || $s('status') === 'Valid' ? $s('status') : '',
            'issued_from' => $date('issued_from'), 'issued_to' => $date('issued_to'), 'expires_from' => $date('expires_from'), 'expires_to' => $date('expires_to'), 'title' => $s('title'), 'vehicle' => $s('vehicle'),
            'kind' => in_array($s('kind'), array_keys(IdPassKinds::ALL), true) ? $s('kind') : '', 'printed' => in_array($s('printed'), ['yes', 'no'], true) ? $s('printed') : ''];
    }

    private static function like(string $v): string
    {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
    }

    public static function apply(Builder $q, array $f, ?array $kinds = null): Builder
    {
        $today = Carbon::today(config('app.timezone'))->toDateString();
        $q->when($kinds !== null, fn ($w) => $w->whereIn('kind', $kinds ?: ['-']))
            ->when($f['kind'] ?? '', fn ($w, $v) => $w->where('kind', $v))
            ->when($f['category_id'] ?? 0, fn ($w, $v) => $w->where('category_id', $v))
            ->when($f['title'] ?? '', fn ($w, $v) => $w->where('title', 'like', self::like($v)))
            ->when($f['vehicle'] ?? '', fn ($w, $v) => $w->where('vehicle_registration', 'like', self::like($v)))
            ->when($f['issued_from'] ?? '', fn ($w, $v) => $w->whereDate('issued_at', '>=', $v))->when($f['issued_to'] ?? '', fn ($w, $v) => $w->whereDate('issued_at', '<=', $v))
            ->when($f['expires_from'] ?? '', fn ($w, $v) => $w->whereDate('expires_at', '>=', $v))->when($f['expires_to'] ?? '', fn ($w, $v) => $w->whereDate('expires_at', '<=', $v))
            ->when(($f['printed'] ?? '') === 'yes', fn ($w) => $w->where('print_count', '>', 0))->when(($f['printed'] ?? '') === 'no', fn ($w) => $w->where('print_count', 0));
        $st = $f['status'] ?? '';
        if ($st === IdPassCard::EXPIRED) {
            $q->expired();
        } elseif ($st === IdPassCard::ACTIVE || $st === 'Valid') {
            $q->where('status', IdPassCard::ACTIVE)->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $today));
        } elseif ($st !== '') {
            $q->where('status', $st);
        }
        if (($v = $f['q'] ?? '') !== '') {
            $like = self::like($v);
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('full_name', 'like', $like)->orWhere('vehicle_registration', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('company', 'like', $like)
                ->orWhereHas('staff', fn ($s) => $s->where('staff_number', 'like', $like))->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like)));
        }

        return $q;
    }

    public static function sort(Builder $q, string $sort, string $dir): Builder
    {
        $col = self::SORTS[$sort] ?? 'id';

        return $q->orderBy($col, $dir === 'asc' ? 'asc' : 'desc')->orderByDesc('id');
    }

    public static function row(IdPassCard $c): array
    {
        $eff = $c->effective_status;

        return ['id' => $c->id, 'kind' => $c->kind, 'number' => $c->number, 'name' => NameFitter::display((string) $c->full_name), 'title' => $c->title, 'category' => optional($c->category)->name, 'category_id' => $c->category_id, 'color' => $c->category ? $c->category->uiColor() : '#64748b', 'ink' => $c->category ? $c->category->uiInk() : '#ffffff', 'icon' => $c->category ? $c->category->uiIcon() : 'user',
            'status' => $eff, 'issued_at' => optional($c->issued_at)->toDateString(), 'expires_at' => optional($c->expires_at)->toDateString(), 'days_left' => $c->daysToExpiry(), 'vehicle' => $c->vehicle_registration, 'phone' => $c->phone,
            'staff_number' => optional($c->staff)->staff_number, 'print_count' => (int) $c->print_count, 'last_printed_at' => optional($c->last_printed_at)->toIso8601String(), 'has_photo' => (bool) $c->photo_path, 'photo_background' => optional($c->category)->photo_background,
            'authorisation' => $c->authorisation_type, 'host' => $c->host_name, 'staff_id' => $c->staff_id, 'contact_id' => $c->contact_id,
            'pooled' => (bool) optional($c->category)->pooled, 'holder' => $c->relationLoaded('openAssignment') && $c->openAssignment ? $c->openAssignment->holder_name : null];
    }

    /** Counts by what a person reads as the status. @return array<string,int> */
    public static function counts(?array $kinds = null): array
    {
        $today = Carbon::today(config('app.timezone'))->toDateString();
        $base = IdPassCard::query()->when($kinds !== null, fn ($w) => $w->whereIn('kind', $kinds ?: ['-']));
        $by = (clone $base)->selectRaw("status, SUM(CASE WHEN status = 'Active' AND expires_at IS NOT NULL AND expires_at < ? THEN 1 ELSE 0 END) as expired, COUNT(*) as n", [$today])->groupBy('status')->get()->keyBy('status');
        $expired = (int) $by->sum('expired');
        $get = fn ($s) => (int) ($by[$s]->n ?? 0);

        return ['total' => (int) $by->sum('n'), 'active' => $get('Active') - $expired, 'expired' => $expired, 'pending' => $get('Pending Approval'), 'suspended' => $get('Suspended'), 'revoked' => $get('Revoked'), 'rejected' => $get('Rejected'), 'replaced' => $get('Replaced')];
    }
}
