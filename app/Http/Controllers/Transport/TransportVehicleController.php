<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransportVehicleRequest;
use App\Http\Resources\TransportVehicleCollection;
use App\Http\Resources\TransportVehicleResource;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Contact;
use App\Models\PaymentVoucher;
use App\Models\TransportRecord;
use App\Traits\HasActivityHistory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

/**
 * A scoped view over the existing Asset model/table — Vehicles are Assets
 * under the 'Vehicles' category tree (see Asset::scopeVehicles()), not a
 * separate table. Every action here checks the Transport Routes
 * permission strings explicitly (route-name-derived Authorizable abilities
 * already happen to match — 'transport-vehicles.store' -> 'create-transport-
 * vehicles' — but explicit checks keep the intent unambiguous and this
 * controller independent of Authorizable's default mapping).
 */
class TransportVehicleController extends Controller
{
    use HasActivityHistory;

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-transport-vehicles'), 403);

        $filters = $request->only('search', 'status', 'asset_category_id');

        $vehicles = $this->listQuery($filters)
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Fleet-wide summary for the stat tiles: same base scope as the list
        // (Vehicles category tree, soft-deleted rows excluded) but ignoring
        // the search/status/category filters.
        $statsRow = Asset::vehicles()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('Active', 'Available', 'Assigned', 'In Use') THEN 1 ELSE 0 END) as in_service")
            ->selectRaw("SUM(CASE WHEN status IN ('Under Maintenance', 'Under Repair') THEN 1 ELSE 0 END) as maintenance")
            ->selectRaw('SUM(CASE WHEN operating_personnel_contact_id IS NULL THEN 1 ELSE 0 END) as unassigned')
            ->first();

        $stats = [
            'total' => (int) ($statsRow->total ?? 0),
            'in_service' => (int) ($statsRow->in_service ?? 0),
            'maintenance' => (int) ($statsRow->maintenance ?? 0),
            'unassigned' => (int) ($statsRow->unassigned ?? 0),
        ];

        return Inertia::render('TransportManagement/Vehicles/Index', [
            'stats' => $stats,
            'filters' => $filters,
            'vehicles' => new TransportVehicleCollection($vehicles),
            'statuses' => Asset::STATUSES,
            'categories' => $this->vehicleCategoryOptions(),
            'canCreate' => $request->user()->can('create-transport-vehicles'),
        ]);
    }

    /** The one filtered query behind both the Vehicles table and its Print / Download, so they can never disagree. */
    private function listQuery(array $filters)
    {
        return Asset::vehicles()
            ->with(['category:id,name,parent_id', 'operatingPersonnelContact:id,name,phone'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('registration_number', 'like', "%{$s}%")
                    ->orWhere('asset_number', 'like', "%{$s}%");
            }))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['asset_category_id'] ?? null, fn ($q, $id) => $q->where('asset_category_id', $id));
    }

    public function printList(Request $request)
    {
        abort_unless($request->user()->can('print-transport-records'), 403);

        return view('reports.register-list', $this->listPayload($request, false));
    }

    public function pdfList(Request $request)
    {
        abort_unless($request->user()->can('print-transport-records'), 403);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.register-list', $this->listPayload($request, true))
            ->setPaper('a4', 'landscape')
            ->download('vehicles-' . now()->format('Ymd-His') . '.pdf');
    }

    private function listPayload(Request $request, bool $isPdf): array
    {
        $filters = $request->only('search', 'status', 'asset_category_id');
        $vehicles = $this->listQuery($filters)->orderBy('name')->limit(5000)->get();

        $inService = ['Active', 'Available', 'Assigned', 'In Use'];
        $maintenance = ['Under Maintenance', 'Under Repair'];

        $chips = [];
        if (! empty($filters['search'])) {
            $chips[] = ['Search', $filters['search']];
        }
        if (! empty($filters['status'])) {
            $chips[] = ['Status', $filters['status']];
        }
        if (! empty($filters['asset_category_id'])) {
            $chips[] = ['Category', AssetCategory::query()->whereKey($filters['asset_category_id'])->value('name') ?? $filters['asset_category_id']];
        }

        $rows = [];
        foreach ($vehicles as $i => $v) {
            $driver = $v->operatingPersonnelContact;
            $rows[] = [
                $i + 1,
                ['text' => $v->registration_number ?: '-', 'strong' => true],
                ['text' => $v->name, 'sub' => implode(' · ', array_filter([$v->manufacturer, $v->model, $v->year]))],
                optional($v->category)->name,
                $driver ? ['text' => $driver->name, 'sub' => $driver->phone] : ['text' => 'Unassigned', 'color' => '#9ca3af'],
                ['text' => $v->status, 'tone' => in_array($v->status, $inService, true) ? 'green' : (in_array($v->status, $maintenance, true) ? 'amber' : (in_array($v->status, ['Lost', 'Stolen', 'Damaged'], true) ? 'red' : 'slate'))],
            ];
        }

        return [
            'title' => 'Vehicles',
            'isPdf' => $isPdf,
            'autoPrint' => ! $isPdf,
            'companyName' => optional($request->user()->account)->name ?? 'RANS Company Limited',
            'generatedBy' => $request->user()->name,
            'generatedAt' => now(),
            'filters' => $chips,
            'scopeNote' => $chips ? 'Exactly the vehicles listed on screen for the filters above, nothing more.' : 'All registered vehicles.',
            'groups' => [['title' => 'Summary of the listed vehicles', 'cards' => [
                ['label' => 'Vehicles', 'value' => number_format($vehicles->count()), 'tone' => 'navy', 'icon' => 'car'],
                ['label' => 'In Service', 'value' => number_format($vehicles->whereIn('status', $inService)->count()), 'tone' => 'emerald', 'icon' => 'check'],
                ['label' => 'Under Maintenance', 'value' => number_format($vehicles->whereIn('status', $maintenance)->count()), 'tone' => 'amber', 'icon' => 'wrench'],
                ['label' => 'No Driver Assigned', 'value' => number_format($vehicles->whereNull('operating_personnel_contact_id')->count()), 'tone' => 'rose', 'icon' => 'user'],
            ]]],
            'tableTitle' => 'Vehicles (' . number_format($vehicles->count()) . ')',
            'columns' => [
                ['label' => 'No', 'width' => '26px'], ['label' => 'Reg. Number'], ['label' => 'Name / Make / Model'],
                ['label' => 'Category'], ['label' => 'Current Driver'], ['label' => 'Status'],
            ],
            'rows' => $rows,
            'emptyText' => 'No vehicles found.',
        ];
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('create-transport-vehicles'), 403);

        return Inertia::render('TransportManagement/Vehicles/Form', [
            'categories' => $this->vehicleCategoryOptions(),
            'ownershipTypes' => Asset::OWNERSHIP_TYPES,
            'statuses' => Asset::STATUSES,
            'operatingPersonnelTypes' => Asset::OPERATING_PERSONNEL_TYPES,
            'fuelTypes' => Asset::FUEL_TYPES,
            'makeSuggestions' => $this->makeSuggestions(),
            'modelSuggestions' => $this->modelSuggestions(),
            'colorSuggestions' => $this->colorSuggestions(),
            'drivers' => $this->driverOptions(),
            'canManageOpeningBalance' => $request->user()->can('manage-transport-vehicle-opening-balance'),
        ]);
    }

    public function store(TransportVehicleRequest $request)
    {
        abort_unless($request->user()->can('create-transport-vehicles'), 403);

        $data = $request->validated();

        // Validated up front, before the vehicle is ever saved — an invalid
        // opening_balance value must fail the whole create request, not
        // leave a vehicle permanently created with no way back to this form
        // to add it (see updateOpeningBalance()'s password gate below).
        $openingBalance = $this->validateOpeningBalanceInput($request);

        $vehicle = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $vehicle = new Asset();
                $vehicle->fill($data);
                $vehicle->asset_number = $this->nextReference();
                $vehicle->condition = 'New';
                $vehicle->created_by_user_id = $request->user()->id;
                $vehicle->registered_in = Asset::REGISTERED_IN_TRANSPORT;

                if ($openingBalance) {
                    $vehicle->fill(array_merge($openingBalance, [
                        'opening_balance_set_by_user_id' => $request->user()->id,
                        'opening_balance_set_at' => now(),
                    ]));
                }

                $vehicle->save();
                break;
            } catch (QueryException $e) {
                $isDuplicate = (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'assets_asset_number_unique');
                if (! $isDuplicate || $attempt === 2) {
                    throw $e;
                }
            }
        }

        log_activity(__('Vehicle registered.'), ['asset_number' => $vehicle->asset_number, 'registration_number' => $vehicle->registration_number], $vehicle, 'Transport Vehicle');
        \App\Services\TransportNotifier::notify('transport-vehicle-changed', __('Vehicle Registered'), __(':v was added to the vehicles by :user.', ['v' => $vehicle->registration_number ?: $vehicle->name, 'user' => $request->user()->name]), route('transport-vehicles.show', $vehicle->id), $vehicle);

        if ($openingBalance) {
            log_activity(__('Vehicle opening balance set.'), ['amount' => (float) $vehicle->opening_balance, 'date' => optional($vehicle->opening_balance_date)->toDateString()], $vehicle, 'Transport Vehicle');
        }

        return redirect()->route('transport-vehicles.show', $vehicle->id)->with('message', __('Vehicle registered.'));
    }

    public function show(Request $request, Asset $asset)
    {
        abort_unless($request->user()->can('read-transport-vehicles'), 403);
        $this->assertIsVehicle($asset);

        $this->loadShowData($asset);

        return Inertia::render('TransportManagement/Vehicles/Show', [
            'vehicle' => new TransportVehicleResource($asset),
            'canManageOpeningBalance' => $request->user()->can('manage-transport-vehicle-opening-balance'),
        ]);
    }

    public function edit(Request $request, Asset $asset)
    {
        abort_unless($request->user()->can('update-transport-vehicles'), 403);
        $this->assertIsVehicle($asset);
        $asset->load('attachments');

        return Inertia::render('TransportManagement/Vehicles/Form', [
            'vehicle' => new TransportVehicleResource($asset),
            'categories' => $this->vehicleCategoryOptions(),
            'ownershipTypes' => Asset::OWNERSHIP_TYPES,
            'statuses' => Asset::STATUSES,
            'operatingPersonnelTypes' => Asset::OPERATING_PERSONNEL_TYPES,
            'fuelTypes' => Asset::FUEL_TYPES,
            'makeSuggestions' => $this->makeSuggestions(),
            'modelSuggestions' => $this->modelSuggestions(),
            'colorSuggestions' => $this->colorSuggestions(),
            'drivers' => $this->driverOptions(),
            'canManageOpeningBalance' => $request->user()->can('manage-transport-vehicle-opening-balance'),
        ]);
    }

    public function update(TransportVehicleRequest $request, Asset $asset)
    {
        abort_unless($request->user()->can('update-transport-vehicles'), 403);
        $this->assertIsVehicle($asset);

        $asset->fill($request->validated());
        $asset->save();

        log_activity(__('Vehicle updated.'), ['asset_number' => $asset->asset_number], $asset, 'Transport Vehicle');
        \App\Services\TransportNotifier::notify('transport-vehicle-changed', __('Vehicle Edited'), __(':v was edited by :user.', ['v' => $asset->registration_number ?: $asset->name, 'user' => $request->user()->name]), route('transport-vehicles.show', $asset->id), $asset);

        $this->maybeSetInitialOpeningBalance($request, $asset);

        return redirect()->route('transport-vehicles.show', $asset->id)->with('message', __('Vehicle updated.'));
    }

    /**
     * Validates the create/edit form's optional one-time Opening Balance
     * fields and returns them normalized (with 'opening_balance_date'
     * defaulted), or null when nothing was submitted or the user lacks the
     * permission — a silent no-op, not an aborted request, since this is a
     * side effect of the ordinary vehicle save and must never block it.
     * Shared by store() (validated before the vehicle is even created) and
     * maybeSetInitialOpeningBalance() (used by update()).
     */
    private function validateOpeningBalanceInput(Request $request): ?array
    {
        if (! $request->filled('opening_balance')) {
            return null;
        }
        if (! $request->user()->can('manage-transport-vehicle-opening-balance')) {
            return null;
        }

        $data = $request->validate([
            'opening_balance' => 'numeric|min:0',
            'opening_balance_date' => 'nullable|date',
            'opening_balance_notes' => 'nullable|string|max:1000',
        ]);

        // `?:` (not `??`) — a date input left visually blank submits an
        // empty string, not null, and `''` cast to a 'date' column parses
        // as "now" (confirmed: Carbon treats an empty string as "now"),
        // silently turning a blank date into today's date instead of
        // falling through to the intended default.
        $data['opening_balance_date'] = $data['opening_balance_date'] ?: now()->toDateString();
        $data['opening_balance_notes'] = $data['opening_balance_notes'] ?: null;

        return $data;
    }

    /**
     * Sets a vehicle's Opening Balance the very first time only, called
     * after update()'s own save already succeeded — the vehicle isn't a
     * new record here, so a validation failure on this step just leaves
     * the opening balance unset rather than orphaning anything. Correcting
     * an already-set value is a separate, password-confirmed action — see
     * updateOpeningBalance() below.
     */
    private function maybeSetInitialOpeningBalance(Request $request, Asset $vehicle): void
    {
        if (! is_null($vehicle->opening_balance)) {
            return;
        }

        $data = $this->validateOpeningBalanceInput($request);
        if (! $data) {
            return;
        }

        $vehicle->fill(array_merge($data, [
            'opening_balance_set_by_user_id' => $request->user()->id,
            'opening_balance_set_at' => now(),
        ]));
        $vehicle->save();

        log_activity(
            __('Vehicle opening balance set.'),
            ['amount' => (float) $vehicle->opening_balance, 'date' => optional($vehicle->opening_balance_date)->toDateString()],
            $vehicle,
            'Transport Vehicle'
        );
    }

    /**
     * Corrects an already-set Opening Balance — gated on the same
     * manage-transport-vehicle-opening-balance permission as the initial
     * set, plus the route's 'password.confirm' middleware (see routes/
     * web.php), so whoever holds the permission must also freshly re-enter
     * their own password before a correction is accepted. Mirrors
     * SupplierLedgerController::updateOpeningBalance()'s exact pattern.
     */
    public function updateOpeningBalance(Request $request, Asset $asset)
    {
        abort_unless($request->user()->can('manage-transport-vehicle-opening-balance'), 403);
        $this->assertIsVehicle($asset);
        abort_if(is_null($asset->opening_balance), 404, __('No opening balance has been set yet, set it once from the vehicle\'s Edit page first.'));

        $data = $request->validate([
            'opening_balance' => 'required|numeric|min:0',
            'opening_balance_date' => 'nullable|date',
            'opening_balance_notes' => 'nullable|string|max:1000',
        ]);

        $before = [
            'amount' => (float) $asset->opening_balance,
            'date' => optional($asset->opening_balance_date)->toDateString(),
        ];

        $asset->fill([
            'opening_balance' => $data['opening_balance'],
            // `?:` — see validateOpeningBalanceInput()'s comment: a blank
            // date submits as '', which `??` never catches, silently
            // resetting the effective date to today on every correction
            // that didn't deliberately change it.
            'opening_balance_date' => ($data['opening_balance_date'] ?? null) ?: $asset->opening_balance_date,
            'opening_balance_notes' => ($data['opening_balance_notes'] ?? null) ?: null,
            'opening_balance_set_by_user_id' => $request->user()->id,
            'opening_balance_set_at' => now(),
        ]);
        $asset->save();

        log_activity(
            __('Vehicle opening balance corrected.'),
            ['before' => $before, 'after' => ['amount' => (float) $asset->opening_balance, 'date' => optional($asset->opening_balance_date)->toDateString()]],
            $asset,
            'Transport Vehicle'
        );

        \App\Services\TransportNotifier::notify('transport-vehicle-changed', __('Vehicle Opening Balance Changed'), __(':v: opening balance is now :amount (changed by :user).', ['v' => $asset->registration_number ?: $asset->name, 'amount' => number_format((float) $asset->opening_balance, 2), 'user' => $request->user()->name]), route('transport-vehicles.show', $asset->id), $asset);

        return back()->with('message', __('Opening balance updated.'));
    }

    public function destroy(Request $request, Asset $asset)
    {
        abort_unless($request->user()->can('delete-transport-vehicles'), 403);
        $this->assertIsVehicle($asset);
        abort_if(TransportRecord::where('asset_id', $asset->id)->exists(), 422, __('This vehicle has Transport Records and cannot be deleted, retire it instead.'));

        $asset->deleted_by = $request->user()->id;
        $asset->save();
        $asset->delete();

        log_activity(__('Vehicle deleted.'), ['asset_number' => $asset->asset_number], $asset, 'Transport Vehicle');
        \App\Services\TransportNotifier::notify('transport-vehicle-changed', __('Vehicle Deleted'), __(':v was deleted by :user.', ['v' => $asset->registration_number ?: $asset->name, 'user' => $request->user()->name]), route('transport-vehicles.index'), $asset);

        return redirect()->route('transport-vehicles.index')->with('message', __('Vehicle deleted.'));
    }

    public function downloadAttachment(Request $request, Asset $asset, Attachment $attachment)
    {
        abort_unless($request->user()->can('read-transport-vehicles'), 403);
        $this->assertIsVehicle($asset);
        abort_unless((int) $attachment->model_id === (int) $asset->id && $attachment->model_type === Asset::class, 404);

        if ($request->query('disposition') === 'inline') {
            return \Illuminate\Support\Facades\Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return \Illuminate\Support\Facades\Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function destroyAttachment(Request $request, Asset $asset, Attachment $attachment)
    {
        abort_unless($request->user()->can('update-transport-vehicles'), 403);
        $this->assertIsVehicle($asset);
        abort_unless((int) $attachment->model_id === (int) $asset->id && $attachment->model_type === Asset::class, 404);

        \Illuminate\Support\Facades\Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        log_activity(__('Vehicle attachment removed.'), ['asset_number' => $asset->asset_number, 'title' => $attachment->title], $asset, 'Transport Vehicle');

        return back()->with('message', __('Attachment removed.'));
    }

    /** Everything the vehicle page - and its printed / PDF copy - shows: trip totals, recent trips, vouchers and history. */
    private function loadShowData(Asset $asset): void
    {
        $asset->load(['category:id,name,parent_id', 'operatingPersonnelContact:id,name,phone', 'attachments', 'openingBalanceSetBy:id,name']);

        $trips = TransportRecord::where('asset_id', $asset->id)->orderByDesc('trip_date')->orderByDesc('id');

        // Money totals exclude Cancelled and unverified trips — the same
        // 'activeTrips' scoping TransportDashboardController and
        // TransportReportController already use, so this vehicle's own
        // Total Amount/Paid/Outstanding stay consistent with the
        // module-wide totals instead of a Cancelled or not-yet-confirmed
        // trip inflating what this vehicle appears to have cost. total_trips
        // intentionally still counts every trip, same as the dashboard's.
        $activeTrips = (clone $trips)->where('payment_status', '!=', TransportRecord::STATUS_CANCELLED)->whereNotNull('verified_at');

        // The opening balance is historic debt this vehicle already carried
        // before joining Transport Routes — it folds into both what the
        // vehicle has cost in total and what's still outstanding on it, but
        // never into total_paid (no historic payment was actually tracked).
        $openingBalance = (float) ($asset->opening_balance ?? 0);

        $asset->trip_stats = [
            'total_trips' => (int) (clone $trips)->sum('trips_count'),
            'total_amount' => (float) (clone $activeTrips)->sum('amount') + $openingBalance,
            'total_paid' => (float) (clone $activeTrips)->sum('paid_amount'),
            'total_outstanding' => (float) (clone $activeTrips)->sum(DB::raw('amount - paid_amount')) + $openingBalance,
        ];
        $asset->setRelation('transportRecords', (clone $trips)->with(['driver:id,name,phone', 'warehouse:id,name'])->limit(25)->get());
        $asset->activity = $this->activityHistory(Asset::class, $asset->id);

        $voucherIds = (clone $trips)->whereNotNull('payment_voucher_id')->pluck('payment_voucher_id')->unique();
        $asset->payment_vouchers = PaymentVoucher::whereIn('id', $voucherIds)
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->get(['id', 'pv_number', 'status', 'voucher_date', 'total_amount']);
    }

    public function print(Request $request, Asset $asset)
    {
        abort_unless($request->user()->can('print-transport-records'), 403);
        $this->assertIsVehicle($asset);
        $this->loadShowData($asset);

        return view('transport-vehicle-print', $this->printData($asset));
    }

    public function pdf(Request $request, Asset $asset)
    {
        abort_unless($request->user()->can('print-transport-records'), 403);
        $this->assertIsVehicle($asset);
        $this->loadShowData($asset);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('transport-vehicle-print', $this->printData($asset) + ['isPdf' => true])
            ->setPaper('a4')
            ->download('vehicle-' . $asset->asset_number . '.pdf');
    }

    private function printData(Asset $asset): array
    {
        return [
            'vehicle' => $asset,
            'isPdf' => false,
            'companyName' => optional(auth()->user())->account->name ?? 'RANS Company Limited',
            'qr' => \App\Support\PrintQrCode::dataUri(route('transport-vehicles.show', $asset->id)),
        ];
    }

    /**
     * index() correctly scopes with Asset::vehicles(), but every other
     * action here bound a bare `Asset $asset` with no vehicle check —
     * GET /transport-vehicles/{any-asset-id} rendered a laptop or a
     * generator as a vehicle, and update-/delete-transport-vehicles became
     * de-facto write permissions over the entire asset register.
     */
    private function assertIsVehicle(Asset $asset): void
    {
        abort_unless(Asset::vehicles()->whereKey($asset->id)->exists(), 404);
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) DB::table('assets')
            ->selectRaw('MAX(CAST(SUBSTRING(asset_number, 5) AS UNSIGNED)) as max_suffix')
            ->where('asset_number', 'like', 'AST-%')
            ->value('max_suffix');

        return 'AST-' . str_pad((string) ($maxSuffix + 1), 6, '0', STR_PAD_LEFT);
    }

    /** The 'Vehicles' top-level category plus its children only — never the broader machinery tree. */
    private function vehicleCategoryOptions(): array
    {
        $vehiclesId = AssetCategory::where('name', Asset::TRANSPORT_VEHICLE_CATEGORY_NAME)->whereNull('parent_id')->value('id');

        return AssetCategory::active()
            ->where(fn ($q) => $q->where('id', $vehiclesId)->orWhere('parent_id', $vehiclesId))
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id'])
            ->values()
            ->all();
    }

    private function driverOptions(): array
    {
        return Contact::where('category', 'Drivers')->orderBy('name')->get(['id', 'name', 'phone'])->values()->all();
    }

    private const MAKE_STARTERS = [
        'Toyota', 'Isuzu', 'Mitsubishi', 'Nissan', 'Ford', 'Hino', 'Scania', 'Mercedes-Benz',
        'MAN', 'Bajaj', 'TVS', 'Land Rover', 'Mazda', 'Suzuki', 'Fuso', 'Volvo', 'Hyundai',
    ];

    private const MODEL_STARTERS = [
        'Hilux', 'Land Cruiser', 'Land Cruiser Prado', 'Fuso Canter', 'Coaster', 'Hiace',
        'Rosa', 'FRR', 'NPR', 'Actros', 'Defender', 'Boxer', 'Vitz', 'Probox', 'Noah', 'CX-5',
    ];

    private const COLOR_STARTERS = [
        'White', 'Black', 'Silver', 'Grey', 'Red', 'Blue', 'Green', 'Yellow', 'Orange', 'Brown', 'Beige', 'Maroon',
    ];

    /**
     * The curated starter list keeps the dropdown useful on a brand-new
     * install (nothing in `assets` yet), while merging in every distinct
     * value already on a real vehicle means the catalog grows on its own as
     * users add makes/models the starter list didn't anticipate — no admin
     * screen needed to maintain it.
     */
    private function makeSuggestions(): array
    {
        $existing = Asset::vehicles()->whereNotNull('manufacturer')->distinct()->pluck('manufacturer');

        return collect(self::MAKE_STARTERS)->merge($existing)->unique()->sort()->values()->all();
    }

    private function modelSuggestions(): array
    {
        $existing = Asset::vehicles()->whereNotNull('model')->distinct()->pluck('model');

        return collect(self::MODEL_STARTERS)->merge($existing)->unique()->sort()->values()->all();
    }

    private function colorSuggestions(): array
    {
        $existing = Asset::vehicles()->whereNotNull('color')->distinct()->pluck('color');

        return collect(self::COLOR_STARTERS)->merge($existing)->unique()->sort()->values()->all();
    }
}
