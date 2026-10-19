<?php

namespace App\Services\Signatories\Documents\Support;

use App\Services\Signatories\SignatureResolver;

/**
 * The guard behind "required signatures block finalising". It asks the resolver what the record would look like ONCE the final action has
 * been taken (the caller passes a copy of the record with the final status and the final actor and time stamped on it, nothing is saved),
 * so a missing signature of the very person about to finalise is caught too. Blockers only exist when an administrator turned on
 * "required" and "blocks final" for a mapping, so with the default setup this always returns null.
 */
final class RefusesWithoutSignatures
{
    /** @return ?string a plain sentence naming the missing signatures, or null when the action may go ahead */
    public static function message(string $type, object $recordAsIfFinal, string $verb): ?string
    {
        $blockers = app(SignatureResolver::class)->blockers($type, $recordAsIfFinal);
        if (! $blockers) {
            return null;
        }
        $adapter = SignatureResolver::adapters()[$type] ?? null;
        $label = $adapter ? mb_strtolower($adapter::label()) : 'document';
        $parts = array_map(fn ($b) => $b['role_label'] . ' (' . rtrim($b['detail'], '.') . ')', $blockers);

        return __('This :doc cannot be :verb yet because a required signature is missing: :list.', ['doc' => $label, 'verb' => $verb, 'list' => implode('; ', $parts)]);
    }
}
