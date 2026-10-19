<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Services\AssetRegisterReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use App\Models\User;
use Inertia\Inertia;

class AssetDashboardController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index()
    {
        $user = auth()->user();
        abort_unless($user->can('read-asset-dashboard'), 403);

        // Site Engineers get their own personal slice of the dashboard —
        // only assets assigned to them, per assigned_to_user_id, not the
        // warehouse-based scoping the Asset list page uses — and never see
        // the fleet-wide financial cards (see Dashboard.vue).
        $isSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES);
        $scope = fn ($q) => ($isSiteEngineer ? $q->where('assigned_to_user_id', $user->id) : $q)->inAssetRegister();

        $counts = $scope(Asset::query())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $active = $scope(Asset::query())->active()->count();

        $cards = [
            'total_assets' => $scope(Asset::query())->count(),
            'active_assets' => $active,
            'assigned_assets' => (int) ($counts['Assigned'] ?? 0),
            'available_assets' => (int) ($counts['Available'] ?? 0),
            'under_maintenance' => (int) ($counts['Under Maintenance'] ?? 0) + (int) ($counts['Under Repair'] ?? 0),
            'lost_stolen' => (int) ($counts['Lost'] ?? 0) + (int) ($counts['Stolen'] ?? 0),
            'disposed' => (int) ($counts['Disposed'] ?? 0) + (int) ($counts['Sold'] ?? 0) + (int) ($counts['Written Off'] ?? 0) + (int) ($counts['Retired'] ?? 0),
        ];

        // Asset classes: Fixed / UnFixed and the Land & Buildings family (Land, plus everything else under it).
        $landBuildingIds = AssetCategory::query()->where('name', 'Land & Buildings')->orWhereHas('parent', fn ($q) => $q->where('name', 'Land & Buildings'))->pluck('id');
        $landIds = AssetCategory::query()->where('name', 'Land')->whereIn('id', $landBuildingIds)->pluck('id');

        $familyIds = fn (string $name) => AssetCategory::query()->where('name', $name)->orWhereHas('parent', fn ($q) => $q->where('name', $name))->pluck('id');
        $toolIds = $familyIds('Tools & Equipment');
        $vehicleIds = $familyIds(Asset::TRANSPORT_VEHICLE_CATEGORY_NAME);

        $classRow = fn ($q) => [
            'count' => (int) $q->count(),
            'value' => $isSiteEngineer ? null : (float) $q->sum('total_capitalised_cost'),
        ];
        $cards['fixed'] = $classRow($scope(Asset::query())->where('asset_type', Asset::ASSET_TYPE_FIXED));
        $cards['unfixed'] = $classRow($scope(Asset::query())->where('asset_type', Asset::ASSET_TYPE_UNFIXED));
        $cards['tools_equipment'] = $classRow($scope(Asset::query())->whereIn('asset_category_id', $toolIds));
        $cards['land'] = $classRow($scope(Asset::query())->whereIn('asset_category_id', $landIds));
        $cards['buildings'] = $classRow($scope(Asset::query())->whereIn('asset_category_id', $landBuildingIds->diff($landIds)));
        $cards['vehicles'] = $classRow($scope(Asset::query())->whereIn('asset_category_id', $vehicleIds));

        if (! $isSiteEngineer) {
            $totalAcquisitionValue = (float) Asset::inAssetRegister()->sum('total_capitalised_cost');
            $totalAccumulatedDepreciation = (float) \App\Models\AssetDepreciationEntry::query()
                ->whereIn('asset_id', Asset::inAssetRegister()->select('id'))
                ->whereIn('id', function ($q) {
                    $q->selectRaw('MAX(id)')->from('asset_depreciation_entries')->groupBy('asset_id');
                })
                ->sum('accumulated_depreciation');

            $cards['total_acquisition_value'] = $totalAcquisitionValue;
            $cards['current_book_value'] = max($totalAcquisitionValue - $totalAccumulatedDepreciation, 0);
            $cards['accumulated_depreciation'] = $totalAccumulatedDepreciation;
        }

        return Inertia::render('Asset/Dashboard', [
            'cards' => $cards,
            'isSiteEngineer' => $isSiteEngineer,
        ]);
    }

    /** Dashboard report: the cards first, then every asset. Named print/pdf so every use is logged. */
    public function print(Request $request)
    {
        abort_unless($request->user()->can('print-assets'), 403);

        return view('reports.register-list', AssetRegisterReport::build($request->user(), [], AssetRegisterReport::MODE_DASHBOARD, false));
    }

    public function pdf(Request $request)
    {
        abort_unless($request->user()->can('print-assets'), 403);

        return Pdf::loadView('reports.register-list', AssetRegisterReport::build($request->user(), [], AssetRegisterReport::MODE_DASHBOARD, true))
            ->setPaper('a4', 'landscape')
            ->download('asset-dashboard-report-' . now()->format('Ymd-His') . '.pdf');
    }
}
