<?php

namespace App\Services\Signatories\Documents;

use App\Services\Signatories\Documents\Support\GeneratedReport;

/**
 * Staff profile print: the Transport Routes report (all report types, print and PDF, including the Records List).
 *
 * A report generated on request: no stored record, no review or approval workflow. The only real action is "prepared", the signed-in person
 * who generates the report, at the moment of generation (quality GOOD, see Support\GeneratedReport). The record is transient (id 0), so it is
 * never frozen and never logged as missing: it always uses the person's current usable signature.
 * Not covered, left as the blank wet-signature line they always were (no data source): the Employee line and the Authorised Signatory line (no data source).
 */
final class StaffProfileDocument implements SignableDocument
{
    use GeneratedReport;

    public static function key(): string
    {
        return 'staff_profile';
    }

    public static function label(): string
    {
        return 'Staff profile print';
    }

    public static function actions(): array
    {
        return ['prepared' => 'Prepared (the Human Resources person who printed the profile)'];
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'prepared_by', 'action' => 'prepared', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }
}
