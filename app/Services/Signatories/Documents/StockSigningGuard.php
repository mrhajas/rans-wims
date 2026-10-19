<?php

namespace App\Services\Signatories\Documents;

use App\Models\SignatureSnapshot;
use App\Services\Signatories\SignatureResolver;

/**
 * Shared helpers for the stock and site document adapters (transfer, adjustment, super adjustment, site meeting minutes, daily site report).
 *
 *  - refusal(): the "block finalisation" guard. It asks the resolver whether the record, AS IT WOULD BE once the final action is stamped,
 *    still has every required signature that is set to block. It only ever returns a message when an administrator turned on
 *    "required" and "blocks final" on a setup row; with the defaults it always returns null.
 *  - forget(): drops the frozen signature set of a record whose final stage was taken back (approval reversed, minutes reopened), so the
 *    next person who completes that stage is the one who gets frozen, not the previous one.
 */
final class StockSigningGuard
{
    /** A stored date or datetime (some of these models do not cast their columns) as a Carbon, or null. The resolver only accepts Carbon. */
    public static function at($value): ?\Illuminate\Support\Carbon
    {
        if (! $value) {
            return null;
        }

        return $value instanceof \Illuminate\Support\Carbon ? $value : \Illuminate\Support\Carbon::parse($value);
    }

    /**
     * @param array<string, mixed> $after the columns the final action is about to stamp (for example ['draft' => false, 'approved_by' => 5, 'approved_at' => now()])
     * @return string|null a clear refusal message, or null when the action may go ahead
     */
    public static function refusal(string $key, object $record, array $after, string $actionText): ?string
    {
        if (! auth()->check()) {
            return null;
        }
        $resolver = app(SignatureResolver::class);
        if (! $resolver->enabledFor($key)) {
            return null;
        }
        $sim = clone $record;
        foreach ($after as $column => $value) {
            $sim->setAttribute($column, $value);
        }
        $blockers = $resolver->blockers($key, $sim);
        if (! $blockers) {
            return null;
        }
        $list = collect($blockers)->map(fn ($b) => $b['role_label'] . ' (' . rtrim($b['detail'], '.') . ')')->unique()->implode('; ');

        return __('This cannot be :action yet because a required signature is missing: :list.', ['action' => $actionText, 'list' => $list]);
    }

    public static function forget(string $key, int $recordId): void
    {
        SignatureSnapshot::query()->where('document_type', $key)->where('record_id', $recordId)->delete();
    }
}
