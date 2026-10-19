<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class ConstructionToolboxController extends Controller
{
    public function print(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('use-construction-toolbox'), 403);

        return view('reports.pdf', $this->payload($request, true));
    }

    public function pdf(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('use-construction-toolbox'), 403);

        $pdf = Pdf::loadView('reports.pdf', $this->payload($request, false));

        return $pdf->download('construction-toolbox-' . now()->format('Ymd-His') . '.pdf');
    }

    private function payload(Request $request, bool $autoPrint): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'columns' => 'nullable|array|min:1',
            'columns.*' => 'nullable|string|max:255',
            'rows' => 'required|array|min:1',
            'rows.*' => 'array|min:1',
            'rows.*.*' => 'nullable|string|max:1000',
        ]);

        return [
            'title' => $data['title'],
            'companyName' => optional($request->user()->account ?? null)->name,
            'columns' => $data['columns'] ?? ['Field', 'Value'],
            'rows' => $data['rows'],
            'meta' => array_filter([
                'Category' => $data['category'] ?? null,
            ], fn ($value) => filled($value)),
            'autoPrint' => $autoPrint,
        ];
    }
}
