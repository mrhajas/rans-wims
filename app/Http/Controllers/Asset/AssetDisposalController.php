<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetDisposal;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AssetDisposalController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function create(Asset $asset)
    {
        $this->authorize('dispose-assets');
        abort_if($asset->disposal()->exists(), 422, 'This asset has already been disposed of.');

        return Inertia::render('Asset/Dispose', [
            'asset' => [
                'id' => $asset->id,
                'asset_number' => $asset->asset_number,
                'name' => $asset->name,
                'current_book_value' => $asset->current_book_value,
            ],
            'disposalMethods' => AssetDisposal::DISPOSAL_METHODS,
        ]);
    }

    public function store(Request $request, Asset $asset)
    {
        $this->authorize('dispose-assets');
        abort_if($asset->disposal()->exists(), 422, 'This asset has already been disposed of.');

        $data = $request->validate([
            'disposal_date' => 'required|date',
            'disposal_method' => 'required|in:' . implode(',', AssetDisposal::DISPOSAL_METHODS),
            'reason' => 'nullable|string',
            'disposal_value' => 'nullable|numeric',
            'buyer_recipient' => 'nullable|string|max:190',
            'approved_by_user_id' => 'nullable|exists:users,id',
            'last_known_location' => 'nullable|string|max:190',
            'circumstances' => 'nullable|string',
            'report_reference' => 'nullable|string|max:190',
            'investigation_status' => 'nullable|string|max:190',
            'resolution' => 'nullable|string',
        ]);

        $finalBookValue = $asset->current_book_value;
        $disposal = $asset->disposal()->create($data + [
            'final_book_value' => $finalBookValue,
            'gain_loss' => isset($data['disposal_value']) ? ($data['disposal_value'] - $finalBookValue) : null,
            'created_by_user_id' => $request->user()->id,
        ]);

        if ($request->hasFile('attachments')) {
            $disposal->saveAttachments($request->file('attachments'), 'Disposal Document');
        }

        $asset->update([
            'status' => AssetDisposal::STATUS_MAP[$data['disposal_method']] ?? 'Disposed',
            'disposed_at' => now(),
        ]);

        $asset->refresh();
        \App\Services\AssetNotifier::fire('disposed', $asset, __('Asset Disposed'), $asset->name . ' (' . $asset->asset_number . ') was disposed of (' . $data['disposal_method'] . ') by ' . $request->user()->name . '.', ['kind' => (string) $data['disposal_method'], 'status' => (string) $asset->status, 'amount' => isset($data['disposal_value']) ? 'TZS ' . number_format((float) $data['disposal_value'], 0) : ''], ['details' => array_filter([__('Method') => $data['disposal_method'], __('Reason') => $data['reason'] ?? null])]);

        return redirect()->route('assets.show', $asset->id)->with('message', __('Asset disposal recorded.'));
    }
}
