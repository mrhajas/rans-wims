<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\AppreciationCalculator;
use Illuminate\Http\Request;

class AssetAppreciationController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request, Asset $asset, AppreciationCalculator $calculator)
    {
        $this->authorize('record-asset-appreciation');

        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'new_value' => 'required|numeric|min:0',
        ]);

        $calculator->postPeriod($asset, $data['period_start'], $data['period_end'], (float) $data['new_value'], $request->user()->id);

        \App\Services\AssetNotifier::fire('appreciation_posted', $asset, __('Appreciation Posted'), 'A new value of TZS ' . number_format((float) $data['new_value'], 0) . ' was posted for ' . $asset->name . ' (' . $asset->asset_number . ') for ' . \Illuminate\Support\Carbon::parse($data['period_start'])->format('d M Y') . ' to ' . \Illuminate\Support\Carbon::parse($data['period_end'])->format('d M Y') . ' by ' . $request->user()->name . '.', ['amount' => 'TZS ' . number_format((float) $data['new_value'], 0), 'period' => \Illuminate\Support\Carbon::parse($data['period_start'])->format('d M Y') . ' to ' . \Illuminate\Support\Carbon::parse($data['period_end'])->format('d M Y')]);

        return back()->with('message', __('Appreciation posted.'));
    }
}
