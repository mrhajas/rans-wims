<?php

namespace App\Services\Signatories\Documents;

use App\Services\Signatories\Documents\Support\GeneratedReport;

/**
 * External stock report: generated on request (print, PDF). No stored record and no approval workflow: the only real action is "prepared", the person who
 * generated the report, at the moment of generation (see Support\GeneratedReport). Never frozen, never logged as missing.
 */
final class ExternalStockReportDocument implements SignableDocument
{
    use GeneratedReport;

    public static function key(): string
    {
        return 'external_stock_report';
    }

    public static function label(): string
    {
        return 'External stock report';
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
