<?php

namespace App\Support;

use Barryvdh\DomPDF\PDF;

/** The DomPDF wrapper of the app: every PDF gets a No column in its data tables (see NumberTables), whichever report made it. */
class NumberedPdf extends PDF
{
    public function loadHTML(string $string, ?string $encoding = null): static
    {
        try {
            $string = NumberTables::inject($string);
        } catch (\Throwable $e) {
            report($e);
        }

        return parent::loadHTML($string, $encoding);
    }
}
