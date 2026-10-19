<?php

namespace App\Support\Simulation;

use Barryvdh\DomPDF\PDF;

/** The PDF maker the application uses: in the simulation every page of every PDF carries the simulation marking. */
class SimulationPdf extends PDF
{
    public function loadHTML(string $string, ?string $encoding = null): PDF
    {
        return parent::loadHTML(Environment::isSimulation() ? ExportMarker::html($string) : $string, $encoding);
    }
}
