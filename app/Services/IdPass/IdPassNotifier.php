<?php

namespace App\Services\IdPass;

use App\Models\IdPassCard;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/** Hands an ID event to its Alerts page (who is told, on which channel, with which words). Never throws: a failed message must not stop an ID from being saved. No link in the text. */
class IdPassNotifier
{
    public static function fire(string $event, IdPassCard $c, ?User $actor = null, ?string $reason = null): void
    {
        try {
            $c->loadMissing('category');
            $kind = __(\App\Models\IdPassCategory::KINDS[$c->kind] ?? $c->kind);
            $expires = $c->expires_at ? $c->expires_at->locale('en')->translatedFormat('j M Y') : IdPassSettings::get('idpass_no_expiry_text');
            $holder = NameFitter::display((string) $c->full_name);
            $category = (string) optional($c->category)->name;
            WorkflowMessenger::dispatch('id_passes', $event, $c, $actor, [
                'account_id' => $c->account_id ?? $actor?->account_id ?? 1,
                'title' => $kind . ' ' . $c->number, 'message' => $holder . ' - ' . $category,
                'url' => route('id-passes.' . self::slug($c->kind) . '.show', ['card' => $c->id]), 'owner_user_id' => (int) $c->created_by_user_id,
                'dedupe' => 'idpass-' . $c->id . '-' . $event . '-' . ($event === 'expiring' || $event === 'expired' ? optional($c->expires_at)->format('Ymd') : $c->status . '-' . optional($c->status_at)->timestamp),
                'values' => ['kind' => $kind, 'number' => (string) $c->number, 'holder' => $holder, 'category' => $category, 'expires' => $expires, 'purpose' => (string) ($c->purpose ?: ''), 'reason' => (string) ($reason ?: $c->status_reason ?: $c->decision_reason ?: '')],
                'details' => array_filter([__('Kind') => $kind, __('ID number') => $c->number, __('Holder') => $holder, __('Category') => $category, __('Valid until') => $expires, __('Reason') => (string) ($reason ?: $c->status_reason ?: '')]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('ID alert failed', ['card' => $c->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    public static function slug(string $kind): string
    {
        return ['staff' => 'staff', 'compound' => 'compound', 'visitor' => 'visitors', 'gate' => 'gates'][$kind] ?? 'staff';
    }
}
