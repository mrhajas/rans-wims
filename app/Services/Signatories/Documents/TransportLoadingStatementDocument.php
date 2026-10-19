<?php

namespace App\Services\Signatories\Documents;

use App\Services\Signatories\Documents\Support\GeneratedReport;

/**
 * Loading and unloading statement: the Loading and Unloading statement of one worker (print and PDF). "Received By (Personnel)" is the worker's own wet signature and is untouched.
 *
 * A report generated on request: no stored record, no review or approval workflow. The only real action is "prepared", the signed-in person
 * who generates the report, at the moment of generation (quality GOOD, see Support\GeneratedReport). The record is transient (id 0), so it is
 * never frozen and never logged as missing: it always uses the person's current usable signature.
 * Not covered, left as the blank wet-signature line they always were (no data source): Received By (Personnel).
 */
final class TransportLoadingStatementDocument implements SignableDocument
{
    use GeneratedReport;

    public static function key(): string
    {
        return 'transport_loading_statement';
    }

    public static function label(): string
    {
        return 'Loading and unloading statement';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (the person who generated the report)'];
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }
}
