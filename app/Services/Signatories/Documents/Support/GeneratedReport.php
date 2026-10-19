<?php

namespace App\Services\Signatories\Documents\Support;

use Illuminate\Support\Carbon;

/**
 * Shared behaviour of the reports that are GENERATED on request (Transport reports, Weather report ...). They have no stored record and no
 * review or approval workflow, so the only real action is "prepared": the signed-in person who generates the report, at the moment it is
 * generated. The report is therefore a TRANSIENT record (id 0): the resolver never freezes it, never logs a missing signature for it and
 * always uses the person's current usable signature. The lines that have no data source (Reviewed By, Approved By, Safety Officer) are NOT
 * covered: they stay as the blank wet-signature lines the report always had.
 *
 * Usage in a controller: 'signatures' => app(SignatureResolver::class)->resolve('<key>', GeneratedReport::record($user, $generatedAt, 'reference'))
 */
trait GeneratedReport
{
    /** The transient record handed to the resolver for one generation of a report. */
    public static function record(?object $by = null, ?Carbon $at = null, ?string $reference = null): object
    {
        $by ??= auth()->user();

        return (object) ['id' => 0, 'generated_by_user_id' => $by?->id, 'generated_at' => $at ?? now(), 'reference' => $reference];
    }

    /** There is nothing stored to look up. */
    public function find(int $id): ?object
    {
        return null;
    }

    /** A generated report is never a draft and is never closed: it always uses the person's current signature. */
    public function state(object $record): string
    {
        return 'in_progress';
    }

    public function history(object $record): array
    {
        $userId = $record->generated_by_user_id ?? auth()->id();
        $at = $record->generated_at ?? now();
        $at = $at instanceof Carbon ? $at : Carbon::parse($at);
        if (! $userId) {
            return [];
        }

        return ['prepared' => [
            'user_id' => (int) $userId, 'at' => $at, 'name' => null, 'quality' => 'good',
            'note' => 'Prepared By is the person who generated this report.',
        ]];
    }

    public function reference(object $record): string
    {
        return (string) (($record->reference ?? null) ?: static::label());
    }
}
