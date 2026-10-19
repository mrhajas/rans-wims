<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetMaintenanceRecord;
use Illuminate\Http\Request;

class AssetMaintenanceController extends Controller
{
    // The base Authorizable trait would otherwise auto-derive create/update/
    // delete-assets for these method names (store/update/destroy) — this
    // module wants the distinct 'record-asset-maintenance' permission
    // instead, so auto-derivation is disabled and each method authorizes
    // explicitly (same pattern as AssetController's custom actions).
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request, Asset $asset)
    {
        $this->authorize('record-asset-maintenance');

        $data = $this->validated($request);

        $record = $asset->maintenanceRecords()->create($data + ['created_by_user_id' => $request->user()->id]);

        if ($request->hasFile('attachments')) {
            $record->saveAttachments($request->file('attachments'), 'Maintenance Invoice');
        }

        if (! empty($data['under_warranty']) === false && ($data['next_service_date'] ?? null)) {
            $asset->update(['next_service_date' => $data['next_service_date']]);
        }

        \App\Services\AssetNotifier::fire('maintenance_added', $asset, __('Maintenance Recorded'), $data['maintenance_type'] . ' maintenance was recorded for ' . $asset->name . ' (' . $asset->asset_number . ') by ' . $request->user()->name . '.', ['kind' => (string) $data['maintenance_type'], 'amount' => isset($data['cost']) ? 'TZS ' . number_format((float) $data['cost'], 0) : '']);

        return back()->with('message', __('Maintenance record added.'));
    }

    public function update(Request $request, Asset $asset, AssetMaintenanceRecord $asset_maintenance_record)
    {
        $this->authorize('record-asset-maintenance');

        $asset_maintenance_record->update($this->validated($request));
        \App\Services\AssetNotifier::fire('maintenance_updated', $asset, __('Maintenance Record Updated'), 'A maintenance record of ' . $asset->name . ' (' . $asset->asset_number . ') was updated by ' . $request->user()->name . '.', ['kind' => (string) $asset_maintenance_record->maintenance_type]);

        return back()->with('message', __('Maintenance record updated.'));
    }

    public function destroy(Asset $asset, AssetMaintenanceRecord $asset_maintenance_record)
    {
        $this->authorize('record-asset-maintenance');

        $asset_maintenance_record->delete();
        \App\Services\AssetNotifier::fire('maintenance_deleted', $asset, __('Maintenance Record Deleted'), 'A maintenance record of ' . $asset->name . ' (' . $asset->asset_number . ') was deleted by ' . (auth()->user()?->name ?? 'an administrator') . '.', ['kind' => (string) $asset_maintenance_record->maintenance_type]);

        return back()->with('message', __('Maintenance record deleted.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'maintenance_date' => 'required|date',
            'maintenance_type' => 'required|in:' . implode(',', \App\Models\AssetMaintenanceRecord::MAINTENANCE_TYPES),
            'service_provider' => 'nullable|string|max:190',
            'description' => 'nullable|string',
            'cost' => 'nullable|numeric',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'meter_reading' => 'nullable|numeric',
            'next_service_date' => 'nullable|date',
            'next_service_meter' => 'nullable|numeric',
            'under_warranty' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
    }
}
