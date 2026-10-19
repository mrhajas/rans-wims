<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetDepreciationCollection;
use App\Models\Asset;
use App\Services\DepreciationCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AssetDepreciationController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request, Asset $asset, DepreciationCalculator $calculator)
    {
        $this->authorize('record-asset-depreciation');

        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        $overlaps = $asset->depreciationEntries()
            ->where('period_start', '<=', $data['period_end'])
            ->where('period_end', '>=', $data['period_start'])
            ->exists();
        abort_if($overlaps, 422, 'A depreciation entry already covers part of this period.');

        $entry = $calculator->postPeriod($asset, $data['period_start'], $data['period_end'], $request->user()->id);

        $period = \Illuminate\Support\Carbon::parse($data['period_start'])->format('d M Y') . ' to ' . \Illuminate\Support\Carbon::parse($data['period_end'])->format('d M Y');
        $amount = 'TZS ' . number_format((float) ($entry->period_depreciation ?? 0), 0);
        \App\Services\AssetNotifier::fire('depreciation_posted', $asset, __('Depreciation Posted'), 'Depreciation for ' . $period . ' was posted on ' . $asset->name . ' (' . $asset->asset_number . ') by ' . $request->user()->name . '.', ['period' => $period, 'amount' => $amount]);

        return back()->with('message', __('Depreciation posted.'));
    }

    public function report(Request $request)
    {
        $this->authorize('view-asset-financials');

        $assets = Asset::query()
            ->inAssetRegister()
            ->with(['category', 'depreciationEntries' => fn ($q) => $q->limit(1)])
            ->when($request->query('asset_category_id'), fn ($q, $id) => $q->where('asset_category_id', $id))
            ->active()
            ->orderBy('asset_number')
            ->paginate()
            ->withQueryString()
            ->through(fn (Asset $a) => [
                'id' => $a->id,
                'asset_number' => $a->asset_number,
                'name' => $a->name,
                'category' => optional($a->category)->name,
                'total_capitalised_cost' => (float) $a->total_capitalised_cost,
                'residual_value' => (float) $a->residual_value,
                'accumulated_depreciation' => optional($a->depreciationEntries->first())->accumulated_depreciation ?? 0,
                'current_book_value' => $a->current_book_value,
            ]);

        return Inertia::render('Asset/DepreciationReport', ['assets' => new AssetDepreciationCollection($assets)]);
    }

    private function reportPayload(Request $request): array
    {
        $assets = Asset::query()
            ->inAssetRegister()
            ->with(['category', 'depreciationEntries' => fn ($q) => $q->limit(1)])
            ->when($request->query('asset_category_id'), fn ($q, $id) => $q->where('asset_category_id', $id))
            ->active()
            ->orderBy('asset_number')
            ->limit(5000)
            ->get()
            ->map(fn (Asset $a) => [
                'asset_number' => $a->asset_number,
                'name' => $a->name,
                'category' => optional($a->category)->name,
                'total_capitalised_cost' => (float) $a->total_capitalised_cost,
                'residual_value' => (float) $a->residual_value,
                'accumulated_depreciation' => (float) (optional($a->depreciationEntries->first())->accumulated_depreciation ?? 0),
                'current_book_value' => $a->current_book_value,
            ]);

        return [
            'title' => __('Asset Depreciation Report'),
            'companyName' => optional(auth()->user())->account->name ?? 'RANS Company Limited',
            'generatedBy' => optional(auth()->user())->name,
            'generatedAt' => now(),
            'assets' => $assets,
        ];
    }

    public function reportPrint(Request $request)
    {
        $this->authorize('view-asset-financials');

        return view('assets-depreciation-report-print', $this->reportPayload($request) + ['isPdf' => false]);
    }

    public function reportPdf(Request $request)
    {
        $this->authorize('view-asset-financials');

        return Pdf::loadView('assets-depreciation-report-print', $this->reportPayload($request) + ['isPdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('asset-depreciation-report-' . now()->format('Ymd-His') . '.pdf');
    }
}
