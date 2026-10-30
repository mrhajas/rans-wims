<?php

namespace App\Services\IdPass;

use App\Models\IdPassBatch;
use App\Models\IdPassCard;
use App\Models\IdPassPrint;

/**
 * The print and export history. It says what the APPLICATION did ("PDF generated", "Print initiated": the PDF was made and opened for printing); a browser cannot tell whether paper came out of a printer,
 * so that is never claimed. Rows are never edited or deleted, a reprint adds a row.
 */
class PrintLogger
{
    public static function record(IdPassCard $c, string $action, string $layout, int $copies = 1, ?IdPassBatch $batch = null, string $outcome = 'Generated', ?string $message = null): IdPassPrint
    {
        $c->loadMissing('category');
        $row = IdPassPrint::create(['account_id' => $c->account_id, 'card_id' => $c->id, 'batch_id' => $batch?->id, 'user_id' => auth()->id(), 'number' => $c->number, 'full_name' => $c->full_name,
            'category_name' => optional($c->category)->name, 'card_status' => $c->effective_status, 'action' => $action, 'layout' => $layout, 'format' => 'pdf', 'copies' => max(1, $copies), 'outcome' => $outcome,
            'message' => $message ? mb_substr($message, 0, 250) : null, 'ip' => request()?->ip()]);
        if ($outcome !== 'Failed') {
            CardService::printed($c, $copies);
        }

        return $row;
    }

    /** One audit line for the whole operation, in the WIMS activity log. */
    public static function audit(string $what, array $props): void
    {
        log_activity($what, $props, null, 'RANS ID & Pass Management');
    }
}
