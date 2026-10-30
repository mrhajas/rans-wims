<?php

namespace App\Services\IdPass;

use App\Models\Contact;
use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\Staff;

/** The "RANS Identification" blocks on a staff profile and on a contact: what ID the person has (shown only to people who may see that kind) and the action to give one. Nothing is copied or changed on the staff or contact record. */
class IdPassPanels
{
    private static function card(string $kind, string $col, int $id): ?IdPassCard
    {
        return IdPassCard::query()->where('kind', $kind)->where($col, $id)->whereIn('status', array_merge(IdPassCard::OPEN, [IdPassCard::REVOKED, IdPassCard::REPLACED]))->orderByRaw("FIELD(status, 'Active', 'Suspended', 'Pending Approval', 'Revoked', 'Replaced')")->latest('id')->first();
    }

    private static function describe(IdPassCard $c, $user = null): array
    {
        $r = IdPassKinds::get($c->kind)['route'];

        return ['id' => $c->id, 'color' => $c->category ? $c->category->uiColor() : '#1565c0', 'can_print' => IdPassKinds::can($c->kind, 'print', $user), 'can_download' => IdPassKinds::can($c->kind, 'download', $user), 'active' => $c->effective_status === IdPassCard::ACTIVE,
            'faces_url' => route('id-passes.' . $r . '.faces', ['card' => $c->id]), 'print_url' => route('id-passes.' . $r . '.print', ['card' => $c->id]), 'pdf_url' => route('id-passes.' . $r . '.pdf', ['card' => $c->id]),
            'number' => $c->number, 'status' => $c->effective_status, 'issued_at' => optional($c->issued_at)->toDateString(), 'expires_at' => optional($c->expires_at)->toDateString(), 'last_printed_at' => optional($c->last_printed_at)->toIso8601String(), 'print_count' => (int) $c->print_count,
            'url' => route('id-passes.' . IdPassKinds::get($c->kind)['route'] . '.show', ['card' => $c->id])];
    }

    /** @return array|null null when the person may not see Staff IDs (the block is then not shown at all) */
    public static function staff(Staff $s, $user): ?array
    {
        if (! IdPassKinds::canRead('staff', $user)) {
            return null;
        }
        $c = self::card('staff', 'staff_id', $s->id);

        return ['card' => $c ? self::describe($c, $user) : null, 'assign_url' => IdPassKinds::can('staff', 'create', $user) && (! $c || ! in_array($c->status, IdPassCard::OPEN, true)) ? route('id-passes.staff.create', ['staff' => $s->id]) : null, 'list_url' => route('id-passes.staff.index')];
    }

    /** @return array{label:string,url:string}[] links for the contact's panel: the pass it holds now, since when, the passes it held before, and the way to give one */
    public static function contactLinks(Contact $contact, $user): array
    {
        $links = [];
        $tz = config('app.timezone');
        foreach (['compound' => 'assign-compound-ids', 'gate' => 'create-people-gate-passes'] as $kind => $perm) {
            if (! IdPassKinds::canRead($kind, $user)) {
                continue;
            }
            $cards = IdPassCard::query()->with('category')->where('kind', $kind)->where('contact_id', $contact->id)->orderByDesc('id')->limit(12)->get();
            foreach ($cards as $i => $c) {
                $from = optional($c->issued_at)->format('d M Y');
                $to = in_array($c->status, [IdPassCard::REPLACED, IdPassCard::REVOKED, IdPassCard::REJECTED], true) ? ($c->status_at ? $c->status_at->timezone($tz)->format('d M Y') : null) : ($c->expires_at ? $c->expires_at->format('d M Y') : null);
                $why = trim((string) $c->status_reason);
                $label = in_array($c->status, IdPassCard::OPEN, true)
                    ? __(':n (:s): holds it since :f', ['n' => $c->number, 's' => __($c->effective_status), 'f' => $from]) . ($c->expires_at ? ' ' . __('until :t', ['t' => $to]) : '')
                    : __(':n: :f to :t (:s)', ['n' => $c->number, 'f' => $from, 't' => $to ?: '-', 's' => __($c->effective_status) . ($why !== '' ? ': ' . $why : '')]);
                $links[] = ['label' => ($i === 0 ? '' : __('Earlier') . ' - ') . __(IdPassCategory::KINDS[$kind]) . ' ' . $label, 'url' => route('id-passes.' . IdPassKinds::get($kind)['route'] . '.show', ['card' => $c->id])];
            }
            $open = $cards->first(fn ($c) => in_array($c->status, IdPassCard::OPEN, true));
            $cat = mb_strtolower(trim((string) $contact->category));
            $eligible = IdPassCategory::query()->ofKind($kind)->active()->get()->contains(fn ($x) => in_array($cat, array_map('mb_strtolower', (array) ($x->contact_categories ?: [])), true));
            if ($eligible && ! $open && IdPassKinds::can($kind, 'create', $user)) {
                $links[] = ['label' => $kind === 'gate' ? __('Assign a Gate Pass') : __('Assign a Compound Worker ID'), 'url' => route('id-passes.' . IdPassKinds::get($kind)['route'] . '.create', ['contact' => $contact->id])];
            }
        }

        return $links;
    }
}
