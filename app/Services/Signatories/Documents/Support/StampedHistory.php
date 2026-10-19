<?php

namespace App\Services\Signatories\Documents\Support;

use App\Services\Signatories\Documents\StockSigningGuard;

/** One history entry from a stamped "who + when" pair, for the approval-based adapters. Nothing is guessed: a half-recorded action is marked partial. */
trait StampedHistory
{
    private function entry($userId, $at, string $note): array
    {
        $at = StockSigningGuard::at($at);
        $ok = $userId && $at;

        return ['user_id' => $userId ? (int) $userId : null, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : $note];
    }

    /** Adds $key only when the person or the time was recorded. */
    private function put(array &$h, string $key, $userId, $at, string $note): void
    {
        if ($userId || $at) {
            $h[$key] = $this->entry($userId, $at, $note);
        }
    }
}
