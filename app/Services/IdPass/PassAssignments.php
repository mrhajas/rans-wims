<?php

namespace App\Services\IdPass;

use App\Models\IdPassAssignment;
use App\Models\IdPassCard;
use App\Models\IdPassEvent;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorVisit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Passes that are handed over and taken back (the pooled Visitor Passes): who holds pass 001 now, who held it before and for how long. A pass can be held by one person at a time,
 * only an active pass that has not expired can be handed out, and every hand-over and return is in idpass_assignments and in the card's own history.
 */
class PassAssignments
{
    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function open(IdPassCard $c): ?IdPassAssignment
    {
        return IdPassAssignment::query()->where('card_id', $c->id)->whereNull('released_at')->latest('id')->first();
    }

    /** The passes that can be handed out now, lowest number first. */
    public static function free(int $limit = 60)
    {
        $today = Carbon::today(config('app.timezone'))->toDateString();

        return IdPassCard::query()->with('category:id,name,pooled')->whereHas('category', fn ($c) => $c->where('pooled', true)->where('kind', 'visitor'))
            ->where('status', IdPassCard::ACTIVE)->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $today))
            ->whereNotIn('id', IdPassAssignment::query()->whereNull('released_at')->select('card_id'))->orderBy('category_id')->orderBy('seq_no')->limit($limit)->get();
    }

    /** @param array{name:string,company?:?string,phone?:?string,host?:?string,visitor_id?:?int,contact_id?:?int} $holder */
    public static function assign(IdPassCard $card, array $holder, ?int $visitId, ?User $by, ?string $note = null): IdPassAssignment
    {
        return DB::transaction(function () use ($card, $holder, $visitId, $by, $note) {
            $c = IdPassCard::query()->with('category')->whereKey($card->id)->lockForUpdate()->firstOrFail();
            if (! optional($c->category)->pooled) {
                self::fail('pass', __('This pass is not one that is handed out and taken back.'));
            }
            if ($c->effective_status !== IdPassCard::ACTIVE) {
                self::fail('pass', __('Pass :n cannot be given out: it is :s.', ['n' => $c->number, 's' => strtolower(__($c->effective_status))]));
            }
            if ($open = self::open($c)) {
                self::fail('pass', __('Pass :n is with :p since :t. Take it back first.', ['n' => $c->number, 'p' => $open->holder_name, 't' => $open->assigned_at->timezone(config('app.timezone'))->format('d M Y, H:i')]));
            }
            $a = IdPassAssignment::create(['card_id' => $c->id, 'visit_id' => $visitId, 'visitor_id' => $holder['visitor_id'] ?? null, 'contact_id' => $holder['contact_id'] ?? null, 'holder_name' => mb_substr(trim((string) $holder['name']), 0, 190) ?: __('Visitor'),
                'holder_company' => $holder['company'] ?? null, 'holder_phone' => $holder['phone'] ?? null, 'host_name' => $holder['host'] ?? null, 'assigned_at' => now(), 'assigned_by_user_id' => $by?->id, 'note' => $note]);
            IdPassEvent::record($c, 'assigned', null, null, ['holder' => $a->holder_name, 'visit_id' => $visitId, 'note' => $note]);
            if ($visitId) {
                VisitorVisit::query()->whereKey($visitId)->update(['idpass_card_id' => $c->id]);
            }
            log_activity('RANS pass given out', ['number' => $c->number, 'holder' => $a->holder_name, 'visit' => $visitId], $c, 'RANS ID & Pass Management');

            return $a;
        });
    }

    /** Gives a pass to the visitor of a visit (check-in). */
    public static function assignToVisit(VisitorVisit $visit, int $cardId, ?User $by): IdPassAssignment
    {
        $card = IdPassCard::query()->findOr($cardId, fn () => self::fail('id_pass_id', __('Choose a pass from the list.')));
        $visit->loadMissing('visitor');
        $v = $visit->visitor;

        return self::assign($card, ['name' => optional($v)->full_name ?: __('Visitor'), 'company' => optional($v)->company, 'phone' => optional($v)->phone, 'host' => $visit->host_name, 'visitor_id' => $visit->visitor_id], $visit->id, $by, $visit->reference);
    }

    public static function release(IdPassCard $card, ?User $by, string $reason): ?IdPassAssignment
    {
        return DB::transaction(function () use ($card, $by, $reason) {
            $c = IdPassCard::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            $a = self::open($c);
            if (! $a) {
                return null;
            }
            $a->forceFill(['released_at' => now(), 'released_by_user_id' => $by?->id, 'release_reason' => mb_substr($reason, 0, 250)])->save();
            IdPassEvent::record($c, 'released', null, null, ['holder' => $a->holder_name, 'reason' => $reason]);
            log_activity('RANS pass taken back', ['number' => $c->number, 'holder' => $a->holder_name, 'reason' => $reason], $c, 'RANS ID & Pass Management');

            return $a;
        });
    }

    /** At check-out or when a visit is cancelled: the pass of that visit comes back. Never throws (a visit must always be able to be closed). */
    public static function releaseVisit(VisitorVisit $visit, ?User $by, string $reason): void
    {
        try {
            $a = IdPassAssignment::query()->where('visit_id', $visit->id)->whereNull('released_at')->first();
            if ($a && $a->card) {
                self::release($a->card, $by, $reason);
            }
        } catch (\Throwable $e) {
            \Log::warning('Visitor pass not taken back', ['visit' => $visit->id, 'error' => $e->getMessage()]);
        }
    }

    public static function history(IdPassCard $card, int $limit = 100): array
    {
        $tz = config('app.timezone');

        return IdPassAssignment::query()->with(['assignedBy:id,name', 'releasedBy:id,name'])->where('card_id', $card->id)->latest('id')->limit($limit)->get()->map(fn ($a) => [
            'id' => $a->id, 'holder' => $a->holder_name, 'company' => $a->holder_company, 'phone' => $a->holder_phone, 'host' => $a->host_name, 'visit_id' => $a->visit_id, 'note' => $a->note,
            'from' => $a->assigned_at->timezone($tz)->toIso8601String(), 'to' => ($a->released_at ? $a->released_at->timezone($tz)->toIso8601String() : null), 'given_by' => optional($a->assignedBy)->name, 'taken_by' => optional($a->releasedBy)->name, 'reason' => $a->release_reason])->all();
    }

    /** What the Visitor Logs screens need: who may hand out passes, which are free. */
    public static function enabledFor(?User $u): bool
    {
        return $u && \App\Services\FeatureManager::canAccess('id_passes', $u) && \App\Services\FeatureManager::canAccess('id_visitor', $u) && $u->can('assign-visitor-passes');
    }

    public static function formProps(?User $u): array
    {
        if (! self::enabledFor($u)) {
            return ['enabled' => false, 'free' => []];
        }
        $free = self::free(80);

        return ['enabled' => true, 'free' => $free->map(fn ($c) => ['value' => $c->id, 'label' => $c->number])->values()->all(), 'suggested' => optional($free->first())->id, 'label' => __('Visitor pass')];
    }

    public static function forVisit(?int $visitId, ?User $u): ?array
    {
        if (! $visitId || ! $u || ! \App\Services\FeatureManager::canAccess('id_passes', $u)) {
            return null;
        }
        $a = IdPassAssignment::query()->with('card:id,number,kind')->where('visit_id', $visitId)->latest('id')->first();
        if (! $a) {
            return null;
        }

        return ['number' => optional($a->card)->number, 'card_id' => $a->card_id, 'from' => $a->assigned_at->toIso8601String(), 'to' => optional($a->released_at)->toIso8601String(), 'url' => $u->can('read-visitor-passes') && $a->card ? route('id-passes.visitors.show', ['card' => $a->card_id]) : null];
    }
}
