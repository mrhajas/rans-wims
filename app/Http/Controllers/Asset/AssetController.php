<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetCollection;
use App\Http\Resources\AssetMovementCollection;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCurrency;
use App\Models\AssetInsuranceProvider;
use App\Models\AssetMovement;
use App\Models\AssetPhoto;
use App\Models\Contact;
use App\Models\Staff;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AssetPlacements;
use App\Services\AssetRegisterReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;
use Tecdiary\Laravel\Attachments\Attachment;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'asset_category_id' => $request->query('asset_category_id'),
            'asset_type' => in_array($request->query('asset_type'), Asset::ASSET_TYPES, true) ? $request->query('asset_type') : null,
            'warehouse_id' => $request->query('warehouse_id'),
            'assigned_to_user_id' => $request->query('assigned_to_user_id'),
            'status' => $request->query('status'),
            'condition' => $request->query('condition'),
            'supplier_id' => $request->query('supplier_id'),
            'acquisition_date_from' => $request->query('acquisition_date_from'),
            'acquisition_date_to' => $request->query('acquisition_date_to'),
            'value_min' => $request->query('value_min'),
            'value_max' => $request->query('value_max'),
            'search' => trim((string) $request->query('search', '')) ?: null,
            'sort' => in_array($request->query('sort'), Asset::SORTABLE, true) ? $request->query('sort') : null,
            'direction' => $request->query('direction') === 'asc' ? 'asc' : ($request->query('sort') ? 'desc' : null),
        ];

        // Site Engineers only ever work a handful of assigned projects —
        // scoping the register to current_warehouse_id (an asset's live
        // location) keeps the list to what's actually relevant to them,
        // the same warehouse_scope_ids() convention IssueMaterialController
        // already uses. Book/Total value is also hidden for this role on
        // this list view (see presentSummary() and the Index props below) —
        // financial figures they have no reason to see on a register they
        // browse day to day. Opening an individual asset is untouched: full
        // detail (including value) still shows on Show, and this scope
        // never applies there.
        $isSiteEngineer = $request->user()->hasRole(User::SITE_ENGINEER_ROLES);
        $scopeWarehouseIds = $isSiteEngineer ? (warehouse_scope_ids() ?? []) : null;

        $totalValue = (float) Asset::query()
            ->inAssetRegister()
            ->filter($filters)
            ->when(! $request->boolean('include_disposed'), fn ($q) => $q->active())
            ->when($scopeWarehouseIds !== null, fn ($q) => $q->whereIn('current_warehouse_id', $scopeWarehouseIds))
            ->sum('total_capitalised_cost');

        $assets = Asset::query()
            ->inAssetRegister()
            ->with(['category:id,name,parent_id', 'category.parent:id,name', 'currentWarehouse:id,name', 'assignedUser:id,name', 'unit:id,name'])
            ->filter($filters)
            ->when(! $request->boolean('include_disposed'), fn ($q) => $q->active())
            ->when($scopeWarehouseIds !== null, fn ($q) => $q->whereIn('current_warehouse_id', $scopeWarehouseIds))
            ->sortBy($filters['sort'], $filters['direction'], 'id', 'desc')
            ->paginate()
            ->withQueryString();
        // each row shows its current book value, which reads the newest depreciation entry: fetch those for the whole page in one query (it was one query per row)
        $latest = \App\Models\AssetDepreciationEntry::query()->whereIn('asset_id', $assets->getCollection()->pluck('id'))->orderByDesc('period_end')->get()->groupBy('asset_id');
        $assets->getCollection()->each(fn (Asset $a) => $a->setRelation('depreciationEntries', collect($latest->get($a->id, collect())->take(1)->all())));
        $assets = $assets->through(fn (Asset $a) => $this->presentSummary($a, $isSiteEngineer));

        // Register-wide summary for the stat tiles: same scope as the list
        // (disposed hidden unless include_disposed, Site Engineers limited
        // to their warehouses) but deliberately ignoring the search filters
        // so the tiles describe the whole register, not the current page.
        $statsRow = Asset::query()
            ->inAssetRegister()
            ->when(! $request->boolean('include_disposed'), fn ($q) => $q->active())
            ->when($scopeWarehouseIds !== null, fn ($q) => $q->whereIn('current_warehouse_id', $scopeWarehouseIds))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN asset_type = ? THEN 1 ELSE 0 END) as fixed', [Asset::ASSET_TYPE_FIXED])
            ->selectRaw('SUM(CASE WHEN asset_type = ? THEN 1 ELSE 0 END) as unfixed', [Asset::ASSET_TYPE_UNFIXED])
            ->selectRaw("SUM(CASE WHEN status IN ('Active', 'Assigned', 'In Use') THEN 1 ELSE 0 END) as in_use")
            ->selectRaw("SUM(CASE WHEN status IN ('Under Maintenance', 'Under Repair') THEN 1 ELSE 0 END) as maintenance")
            ->first();

        $stats = [
            'total' => (int) ($statsRow->total ?? 0),
            'fixed' => (int) ($statsRow->fixed ?? 0),
            'unfixed' => (int) ($statsRow->unfixed ?? 0),
            'in_use' => (int) ($statsRow->in_use ?? 0),
            'maintenance' => (int) ($statsRow->maintenance ?? 0),
        ];

        return Inertia::render('Asset/Index', [
            'stats' => $stats,
            'filters' => $filters,
            'assets' => new AssetCollection($assets),
            'totalValue' => $isSiteEngineer ? null : $totalValue,
            'categories' => $this->categoryOptions(),
            'warehouses' => $this->warehouseOptions(),
            'statuses' => Asset::STATUSES,
            'conditions' => Asset::CONDITIONS,
            'assetTypes' => Asset::ASSET_TYPES,
            'isSiteEngineer' => $isSiteEngineer,
        ]);
    }

    public function create()
    {
        return Inertia::render('Asset/Form', [
            'asset' => null,
            'categories' => $this->categoryOptions(),
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => $this->supplierOptions(),
            'statuses' => Asset::STATUSES,
            'conditions' => Asset::CONDITIONS,
            'ownershipTypes' => Asset::OWNERSHIP_TYPES,
            'assignedToTypes' => Asset::ASSIGNED_TO_TYPES,
            'assetTypes' => Asset::ASSET_TYPES,
            'currencies' => $this->currenciesBank(),
            'staffs' => $this->staffOptions(),
            'operatingPersonnelContacts' => $this->operatingPersonnelContactOptions(),
            'operatingPersonnelTypes' => Asset::OPERATING_PERSONNEL_TYPES,
            'insuranceProviders' => $this->insuranceProvidersBank(),
            'users' => $this->userOptions(),
            'units' => $this->unitOptions(),
            'defaultUnitId' => $this->defaultUnitId(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->convertToTzs($this->normalizeOperatingPersonnel($this->validated($request)));
        $data['quantity'] = isset($data['quantity']) && (float) $data['quantity'] > 0 ? (float) $data['quantity'] : 1;
        $data['unit_id'] = $data['unit_id'] ?? $this->defaultUnitId();
        $this->assertPhotoLimit($request);
        $this->rememberCurrency($data['original_currency'] ?? $data['currency'] ?? '');
        $this->rememberInsuranceProvider($data['insurance_provider'] ?? '');
        unset($data['photos'], $data['remove_photo_ids']);

        $asset = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $asset = new Asset();
                $asset->fill($data);
                $asset->asset_number = $this->nextReference();
                $asset->total_capitalised_cost = $this->computeCapitalisedCost($data);
                $asset->status = $data['status'] ?? 'Available';
                $asset->condition = $data['condition'] ?? 'New';
                $asset->current_warehouse_id = $data['current_warehouse_id'] ?? $data['warehouse_id'] ?? null;
                $asset->created_by_user_id = $request->user()->id;
                $asset->registered_in = Asset::REGISTERED_IN_ASSETS;
                $asset->save();
                break;
            } catch (QueryException $e) {
                $isDuplicate = (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'assets_asset_number_unique');
                if (! $isDuplicate || $attempt === 2) {
                    throw $e;
                }
                $asset = null;
            }
        }

        if (! $asset) {
            abort(500, 'Unable to generate a unique asset number.');
        }

        AssetPlacements::ensure($asset);

        AssetMovement::create([
            'asset_id' => $asset->id,
            'movement_type' => 'Registered',
            'quantity' => $asset->quantity,
            'unit_id' => $asset->unit_id,
            'to_warehouse_id' => $asset->current_warehouse_id,
            'to_label' => $asset->current_warehouse_id ? null : $asset->current_location_label,
            'to_responsible_user_id' => $asset->assigned_to_user_id,
            'to_responsible_label' => $asset->assigned_to_label,
            'movement_date' => $asset->acquisition_date ?? now()->toDateString(),
            'performed_by_user_id' => $request->user()->id,
            'notes' => 'Asset registered.',
        ]);

        $this->syncPhotos($request, $asset);

        if ($request->hasFile('attachments')) {
            $asset->saveAttachments($request->file('attachments'), $request->input('document_type', 'Supporting Documents'));
        }

        \App\Services\AssetNotifier::fire('created', $asset, __('Asset Registered'), $asset->name . ' (' . $asset->asset_number . ') was added to the Asset Register.');

        return redirect()->route('assets.show', $asset->id)->with('message', __choice('action_text', ['record' => 'Asset', 'action' => 'created']));
    }

    public function show(Asset $asset)
    {
        $asset->load(['unit', 'category.parent', 'supplier', 'purchaseOrder', 'paymentVoucher', 'currentWarehouse', 'assignedUser', 'creator', 'operatingPersonnel', 'operatingPersonnelContact', 'movements.fromWarehouse', 'movements.toWarehouse', 'movements.fromResponsibleUser', 'movements.toResponsibleUser', 'movements.performedBy', 'maintenanceRecords', 'depreciationEntries', 'appreciationEntries', 'disposal', 'attachments', 'photos']);

        return Inertia::render('Asset/Show', [
            'asset' => $this->presentDetail($asset),
            'warehouses' => $this->warehouseOptions(),
            'users' => $this->userOptions(),
            'activity' => $this->activityHistory($asset),
            'convert' => $this->convertOptions($asset),
        ]);
    }

    public function edit(Asset $asset)
    {
        $asset->load(['category', 'supplier', 'photos']);

        return Inertia::render('Asset/Form', [
            'asset' => $this->presentDetail($asset),
            'categories' => $this->categoryOptions(),
            'warehouses' => $this->warehouseOptions(),
            'suppliers' => $this->supplierOptions(),
            'statuses' => Asset::STATUSES,
            'conditions' => Asset::CONDITIONS,
            'ownershipTypes' => Asset::OWNERSHIP_TYPES,
            'assignedToTypes' => Asset::ASSIGNED_TO_TYPES,
            'assetTypes' => Asset::ASSET_TYPES,
            'currencies' => $this->currenciesBank(),
            'staffs' => $this->staffOptions(),
            'operatingPersonnelContacts' => $this->operatingPersonnelContactOptions(),
            'operatingPersonnelTypes' => Asset::OPERATING_PERSONNEL_TYPES,
            'insuranceProviders' => $this->insuranceProvidersBank(),
            'users' => $this->userOptions(),
            'units' => $this->unitOptions(),
        ]);
    }

    public function update(Request $request, Asset $asset)
    {
        $data = $this->convertToTzs($this->normalizeOperatingPersonnel($this->validated($request, $asset->id)), $asset);
        if (! isset($data['quantity']) || (float) $data['quantity'] <= 0) {
            unset($data['quantity']); // left empty: keep the current quantity
        }
        $data['total_capitalised_cost'] = $this->computeCapitalisedCost($data + ['quantity' => $asset->quantity]);
        $this->assertPhotoLimit($request, $asset);
        $this->rememberCurrency($data['original_currency'] ?? $data['currency'] ?? '');
        $this->rememberInsuranceProvider($data['insurance_provider'] ?? '');
        unset($data['photos'], $data['remove_photo_ids']);

        DB::transaction(function () use ($request, $asset, $data) {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->first();
            $old = $asset->only(['current_warehouse_id', 'current_location_label', 'assigned_to_user_id', 'assigned_to_label']);
            $this->logLocationChangeIfAny($request, $asset, $data);

            $asset->update($data);
            // the form's location / holder / quantity describe the main place and the asset's total
            AssetPlacements::applyFormSave($asset, $old, $data + $asset->only(['current_warehouse_id', 'current_location_label', 'assigned_to_user_id', 'assigned_to_label']));
        });
        $asset->refresh();
        $this->syncPhotos($request, $asset);

        if ($request->hasFile('attachments')) {
            $asset->saveAttachments($request->file('attachments'), $request->input('document_type', 'Supporting Documents'));
        }

        \App\Services\AssetNotifier::fire('updated', $asset, __('Asset Details Updated'), $asset->name . ' (' . $asset->asset_number . ') was updated by ' . $request->user()->name . '.', ['status' => (string) $asset->status, 'location' => \App\Services\AssetNotifier::place($asset)]);

        return back()->with('message', __choice('action_text', ['record' => 'Asset', 'action' => 'updated']));
    }

    public function destroyPhoto(Asset $asset)
    {
        $this->authorize('update-assets');

        if ($asset->photo && Storage::disk('assets')->delete($asset->photo)) {
            $asset->photos()->where('path', $asset->photo)->delete();
            $this->syncCoverPhoto($asset);

            return back()->with('message', __('Asset photo removed.'));
        }

        return back()->with('error', __('The photo could not be removed.'));
    }

    public function uploadDocument(Request $request, Asset $asset)
    {
        $this->authorize('update-assets');

        $data = $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),
            'document_type' => 'required|string|max:100',
        ]);

        $asset->saveAttachments($request->file('attachments'), $data['document_type']);
        \App\Services\AssetNotifier::fire('document_added', $asset, __('Asset Document Uploaded'), count($request->file('attachments')) . ' document(s) (' . $data['document_type'] . ') were added to ' . $asset->name . ' (' . $asset->asset_number . ') by ' . $request->user()->name . '.', ['file' => (string) $data['document_type']]);

        return back()->with('message', __('Document uploaded.'));
    }

    public function destroyAttachment(Asset $asset, Attachment $attachment)
    {
        $this->authorize('update-assets');

        abort_unless((int) $attachment->model_id === (int) $asset->id && $attachment->model_type === Asset::class, 404);

        $file = (string) ($attachment->filename ?: $attachment->title);
        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();
        \App\Services\AssetNotifier::fire('document_removed', $asset, __('Asset Document Removed'), 'The document ' . $file . ' of ' . $asset->name . ' (' . $asset->asset_number . ') was removed by ' . auth()->user()->name . '.', ['file' => $file]);

        return back()->with('message', __('Attachment removed.'));
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();
        \App\Services\AssetNotifier::fire('deleted', $asset, __('Asset Deleted'), $asset->name . ' (' . $asset->asset_number . ') was deleted.');

        return redirect()->route('assets.index')->with('message', __choice('action_text', ['record' => 'Asset', 'action' => 'deleted']));
    }

    /**
     * Turns an asset into a catalogue Item using the asset's own information (name, description, photo, unit, cost per unit),
     * in the Item category the person picks. The person also chooses what happens to the asset record: keep it (it then
     * stays in both the Asset Register and the Items list) or delete it. No stock is created: the item is a catalogue entry
     * and stock for it is received through the normal Check-in / Adjustment screens.
     */
    public function convertToItem(Request $request, Asset $asset)
    {
        $this->authorize('create-items');
        $this->authorize('read-assets');

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'category_id' => 'required|exists:categories,id',
            'unit_id' => 'nullable|exists:units,id',
            'after' => 'required|in:keep,delete',
        ]);

        if ($data['after'] === 'delete') {
            $this->authorize('delete-assets');
        }

        $name = mb_strtoupper(trim($data['name']));
        if ($existing = \App\Models\Item::where('name', $name)->first()) {
            return back()->withErrors(['name' => __('An item with this name already exists (:code). Change the name to convert anyway.', ['code' => $existing->code])]);
        }

        $category = \App\Models\Category::findOrFail($data['category_id']);
        // the cost on an asset is per item, so it is already the price of one unit
        $perUnit = round((float) $asset->acquisition_cost, 2);
        $lines = array_filter([
            $asset->description,
            $asset->manufacturer ? __('Manufacturer') . ': ' . $asset->manufacturer : null,
            $asset->model ? __('Model') . ': ' . $asset->model : null,
            $asset->serial_number ? __('Serial Number') . ': ' . $asset->serial_number : null,
            __('Converted from asset :n', ['n' => $asset->asset_number]),
        ]);

        $photo = null;
        if ($asset->photo && Storage::disk('assets')->exists($asset->photo)) {
            $photo = 'items/' . \Illuminate\Support\Str::uuid() . '.' . pathinfo($asset->photo, PATHINFO_EXTENSION);
            Storage::disk('assets')->copy($asset->photo, $photo);
        }

        $item = DB::transaction(function () use ($asset, $data, $name, $category, $perUnit, $lines, $photo) {
            $item = \App\Support\ReferenceLock::run('items', function () use ($data, $name, $category, $perUnit, $lines, $photo) {
                $maxSuffix = (int) DB::table('items')
                    ->selectRaw('MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_suffix')
                    ->where('code', 'like', 'RSI-%')
                    ->value('max_suffix');

                $payload = [
                    'name' => $name,
                    'code' => 'RSI-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT),
                    'symbology' => 'code128',
                    'track_quantity' => true,
                    'price' => $perUnit,
                    'details' => implode("\n", $lines),
                    'unit_id' => $data['unit_id'] ?? null,
                    'photo' => $photo,
                ];
                $created = \App\Models\Item::create($payload);
                // a child category is saved together with its parent, the same as the Item form does
                $ids = ['category_id' => $category->parent_id ?: $category->id];
                if ($category->parent_id) {
                    $ids['child_category_id'] = $category->id;
                }

                return $created->addVariations()->saveRelations($ids);
            });

            log_activity('Asset converted to item', ['asset' => $asset->asset_number, 'item' => $item->code, 'asset_kept' => $data['after'] === 'keep'], $asset, 'Asset');

            if ($data['after'] === 'delete') {
                $asset->delete();
            } else {
                $extra = (array) $asset->extra_attributes;
                $extra['converted_item_id'] = $item->id;
                $extra['converted_item_code'] = $item->code;
                $asset->forceFill(['extra_attributes' => $extra])->save();
            }

            return $item;
        });

        \App\Services\AssetNotifier::fire('converted_to_item', $asset, __('Asset Converted to Item'), $asset->name . ' (' . $asset->asset_number . ') was converted to item ' . $item->code . '.', ['item' => (string) $item->code]);

        $msg = $data['after'] === 'delete'
            ? __('The asset was converted to item :code and the asset record was deleted.', ['code' => $item->code])
            : __('The asset was converted to item :code. The asset record was kept.', ['code' => $item->code]);

        return redirect()->route('items.edit', $item->id)->with('message', $msg);
    }

    public function assign(Request $request, Asset $asset)
    {
        $this->authorize('assign-assets');

        $data = $request->validate([
            'assigned_to_type' => 'required|in:' . implode(',', Asset::ASSIGNED_TO_TYPES),
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'assigned_to_label' => 'nullable|string|max:190',
            'assignment_date' => 'required|date',
            'expected_return_date' => 'nullable|date',
        ]);

        $asset->update($data + ['status' => 'Assigned']);
        $holder = $asset->assigned_to_user_id ? optional(\App\Models\User::find($asset->assigned_to_user_id))->name : null;
        \App\Services\AssetNotifier::fire('assigned', $asset, __('Asset Assigned'), $asset->name . ' (' . $asset->asset_number . ') was assigned to ' . ($holder ?: ($asset->assigned_to_label ?: $asset->assigned_to_type)) . ' by ' . $request->user()->name . '.', ['assignedto' => (string) ($holder ?: $asset->assigned_to_label), 'status' => (string) $asset->status]);

        return back()->with('message', __('Asset assignment updated.'));
    }

    /** The two new fields on every movement window: where the items are now, and how many are moving. */
    private function movementPieceRules(): array
    {
        return [
            'from_placement_id' => 'nullable|integer',
            'quantity' => 'nullable|numeric|min:0.001|max:999999999',
        ];
    }

    private function placementFromRow(object $from): array
    {
        return [
            'from_warehouse_id' => $from->warehouse_id,
            'from_label' => $from->location_label !== '' ? $from->location_label : null,
            'from_responsible_user_id' => $from->responsible_user_id,
            'from_responsible_label' => $from->responsible_label !== '' ? $from->responsible_label : null,
        ];
    }

    /** A Check-Out / Transfer has to say where the pieces go (the place, a location, or a responsible person). */
    private function requireDestination(array $data): void
    {
        if (empty($data['to_warehouse_id']) && trim((string) ($data['to_label'] ?? '')) === '' && empty($data['to_responsible_user_id']) && trim((string) ($data['to_responsible_label'] ?? '')) === '') {
            throw ValidationException::withMessages(['to_warehouse_id' => __('Choose where the items are going: a project / warehouse, a location or a responsible person.')]);
        }
    }

    public function checkOut(Request $request, Asset $asset)
    {
        $this->authorize('check-out-assets');

        $data = $request->validate([
            'to_warehouse_id' => 'nullable|exists:warehouses,id',
            'to_label' => 'nullable|string|max:190',
            'to_responsible_user_id' => 'nullable|exists:users,id',
            'to_responsible_label' => 'nullable|string|max:190',
            'movement_date' => 'required|date',
            'reason' => 'nullable|string|max:190',
            'condition_after' => 'nullable|in:' . implode(',', Asset::CONDITIONS),
            'notes' => 'nullable|string',
        ] + $this->movementPieceRules());

        $this->requireDestination($data);
        $moved = 0.0;
        DB::transaction(function () use ($request, $asset, $data, &$moved) {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->first();
            AssetPlacements::ensure($asset);
            $totalBefore = AssetPlacements::total($asset);
            $from = AssetPlacements::move($asset, $data['from_placement_id'] ?? null, isset($data['quantity']) ? (float) $data['quantity'] : null, [
                'warehouse_id' => $data['to_warehouse_id'] ?? null, 'label' => $data['to_label'] ?? null,
                'user_id' => $data['to_responsible_user_id'] ?? null, 'responsible_label' => $data['to_responsible_label'] ?? null,
            ]);
            $moved = (float) $from->moved;
            $whole = abs($from->moved - $totalBefore) < AssetPlacements::EPS;

            AssetMovement::create(array_replace(array_diff_key($data, ['from_placement_id' => 1]), $this->placementFromRow($from), [
                'asset_id' => $asset->id,
                'movement_type' => 'Check-Out',
                'quantity' => $from->moved,
                'unit_id' => $asset->unit_id,
                'condition_before' => $asset->condition,
                'performed_by_user_id' => $request->user()->id,
            ]));

            if ($whole) {
                $asset->update([
                    'current_warehouse_id' => $data['to_warehouse_id'] ?? null,
                    'current_location_label' => $data['to_label'] ?? null,
                    'assigned_to_user_id' => $data['to_responsible_user_id'] ?? null,
                    'assigned_to_label' => $data['to_responsible_label'] ?? null,
                    'assignment_date' => $data['movement_date'],
                    'condition' => $data['condition_after'] ?? $asset->condition,
                    'status' => 'Assigned',
                ]);
            } else {
                AssetPlacements::syncSnapshot($asset);
            }
        });

        $asset->refresh();
        $place = \App\Services\AssetNotifier::place($asset);
        \App\Services\AssetNotifier::fire('checked_out', $asset, __('Asset Checked Out'), ($moved > 0 && $moved != (float) $asset->quantity ? rtrim(rtrim(number_format($moved, 3, '.', ''), '0'), '.') . ' of ' : '') . $asset->name . ' (' . $asset->asset_number . ') was checked out to ' . ($place ?: ($data['to_responsible_label'] ?? null) ?: 'the responsible person') . ' by ' . $request->user()->name . '.', ['location' => $place, 'quantity' => (string) $moved, 'status' => (string) $asset->status]);

        return back()->with('message', __('Asset checked out.'));
    }

    public function checkIn(Request $request, Asset $asset)
    {
        $this->authorize('check-in-assets');

        $data = $request->validate([
            'to_warehouse_id' => 'nullable|exists:warehouses,id',
            'to_label' => 'nullable|string|max:190',
            'movement_date' => 'required|date',
            'condition_after' => 'required|in:' . implode(',', Asset::CONDITIONS),
            'damage_notes' => 'nullable|string',
            'missing_parts' => 'nullable|string',
            'accessories' => 'nullable|string',
            'meter_reading' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'received_by_user_id' => 'nullable|exists:users,id',
        ] + $this->movementPieceRules());

        $moved = 0.0;
        DB::transaction(function () use ($request, $asset, $data, &$moved) {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->first();
            AssetPlacements::ensure($asset);
            $totalBefore = AssetPlacements::total($asset);
            $source = DB::table('asset_placements')->where('asset_id', $asset->id)->whereNull('deleted_at')
                ->when($data['from_placement_id'] ?? null, fn ($q, $id) => $q->where('id', $id), fn ($q) => $q->orderByDesc('quantity')->orderBy('id'))->first();
            $toWarehouse = $data['to_warehouse_id'] ?? ($source->warehouse_id ?? $asset->current_warehouse_id);
            $from = AssetPlacements::move($asset, $data['from_placement_id'] ?? null, isset($data['quantity']) ? (float) $data['quantity'] : null, [
                'warehouse_id' => $toWarehouse, 'label' => $data['to_label'] ?? null, 'user_id' => null, 'responsible_label' => null,
            ]);
            $moved = (float) $from->moved;
            $whole = abs($from->moved - $totalBefore) < AssetPlacements::EPS;

            AssetMovement::create(array_replace(array_diff_key($data, ['from_placement_id' => 1]), $this->placementFromRow($from), [
                'asset_id' => $asset->id,
                'movement_type' => 'Check-In',
                'to_warehouse_id' => $toWarehouse,
                'quantity' => $from->moved,
                'unit_id' => $asset->unit_id,
                'condition_before' => $asset->condition,
                'performed_by_user_id' => $request->user()->id,
            ]));

            if ($whole) {
                $newStatus = in_array($data['condition_after'], ['Unserviceable', 'Scrapped'], true) ? 'Under Repair' : 'Available';
                $asset->update([
                    'current_warehouse_id' => $toWarehouse,
                    'current_location_label' => $data['to_label'] ?? null,
                    'assigned_to_user_id' => null,
                    'assigned_to_label' => null,
                    'assignment_date' => null,
                    'expected_return_date' => null,
                    'condition' => $data['condition_after'],
                    'status' => $newStatus,
                    'current_meter' => $data['meter_reading'] ?? $asset->current_meter,
                ]);
            } else {
                AssetPlacements::syncSnapshot($asset);
            }
        });

        $asset->refresh();
        $place = \App\Services\AssetNotifier::place($asset);
        \App\Services\AssetNotifier::fire('checked_in', $asset, __('Asset Checked In'), ($moved > 0 && $moved != (float) $asset->quantity ? rtrim(rtrim(number_format($moved, 3, '.', ''), '0'), '.') . ' of ' : '') . $asset->name . ' (' . $asset->asset_number . ') was checked in at ' . ($place ?: ($data['to_responsible_label'] ?? null) ?: 'the responsible person') . ' by ' . $request->user()->name . '.', ['location' => $place, 'quantity' => (string) $moved, 'status' => (string) $asset->status]);

        return back()->with('message', __('Asset checked in.'));
    }

    public function transfer(Request $request, Asset $asset)
    {
        $this->authorize('transfer-assets');

        $data = $request->validate([
            'to_warehouse_id' => 'nullable|exists:warehouses,id',
            'to_label' => 'nullable|string|max:190',
            'to_responsible_user_id' => 'nullable|exists:users,id',
            'to_responsible_label' => 'nullable|string|max:190',
            'movement_date' => 'required|date',
            'reason' => 'nullable|string|max:190',
            'condition_after' => 'nullable|in:' . implode(',', Asset::CONDITIONS),
            'authorised_by_user_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ] + $this->movementPieceRules());

        $this->requireDestination($data);
        $moved = 0.0;
        DB::transaction(function () use ($request, $asset, $data, &$moved) {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->first();
            AssetPlacements::ensure($asset);
            $totalBefore = AssetPlacements::total($asset);
            $from = AssetPlacements::move($asset, $data['from_placement_id'] ?? null, isset($data['quantity']) ? (float) $data['quantity'] : null, [
                'warehouse_id' => $data['to_warehouse_id'] ?? null, 'label' => $data['to_label'] ?? null,
                'user_id' => $data['to_responsible_user_id'] ?? null, 'responsible_label' => $data['to_responsible_label'] ?? null,
            ]);
            $moved = (float) $from->moved;
            $whole = abs($from->moved - $totalBefore) < AssetPlacements::EPS;

            AssetMovement::create(array_replace(array_diff_key($data, ['from_placement_id' => 1]), $this->placementFromRow($from), [
                'asset_id' => $asset->id,
                'movement_type' => 'Transfer',
                'quantity' => $from->moved,
                'unit_id' => $asset->unit_id,
                'condition_before' => $asset->condition,
                'performed_by_user_id' => $request->user()->id,
            ]));

            if ($whole) {
                $asset->update([
                    'current_warehouse_id' => $data['to_warehouse_id'] ?? null,
                    'current_location_label' => $data['to_label'] ?? null,
                    'assigned_to_user_id' => $data['to_responsible_user_id'] ?? null,
                    'assigned_to_label' => $data['to_responsible_label'] ?? null,
                    'condition' => $data['condition_after'] ?? $asset->condition,
                ]);
            } else {
                AssetPlacements::syncSnapshot($asset);
            }
        });

        $asset->refresh();
        $place = \App\Services\AssetNotifier::place($asset);
        \App\Services\AssetNotifier::fire('transferred', $asset, __('Asset Transferred'), ($moved > 0 && $moved != (float) $asset->quantity ? rtrim(rtrim(number_format($moved, 3, '.', ''), '0'), '.') . ' of ' : '') . $asset->name . ' (' . $asset->asset_number . ') was transferred to ' . ($place ?: ($data['to_responsible_label'] ?? null) ?: 'the responsible person') . ' by ' . $request->user()->name . '.', ['location' => $place, 'quantity' => (string) $moved, 'status' => (string) $asset->status]);

        return back()->with('message', __('Asset transferred.'));
    }

    public function updateStatus(Request $request, Asset $asset)
    {
        $this->authorize('update-assets');

        $data = $request->validate([
            'status' => 'required|in:' . implode(',', Asset::STATUSES),
        ]);

        $before = $asset->status;
        $asset->update($data);
        if ($before !== $asset->status) {
            \App\Services\AssetNotifier::fire('status_changed', $asset, __('Asset Status Changed'), $asset->name . ' (' . $asset->asset_number . ') is now ' . $asset->status . ' (was ' . $before . '). Changed by ' . $request->user()->name . '.', ['status' => (string) $asset->status], ['details' => [__('From') => $before, __('To') => $asset->status]]);
        }

        return back()->with('message', __('Asset status updated.'));
    }

    /** `?tab=` prints just the tab the user is looking at; without it the whole record is printed. */
    public function print(Request $request, Asset $asset)
    {
        $this->authorize('print-assets');
        $this->loadForPrint($asset);

        return view('assets.print', $this->printData($asset, $request->query('tab')));
    }

    public function pdf(Request $request, Asset $asset)
    {
        $this->authorize('print-assets');
        $this->loadForPrint($asset);

        $data = $this->printData($asset, $request->query('tab')) + ['isPdf' => true];

        return Pdf::loadView('assets.print', $data)
            ->setPaper('a4')
            ->download('asset-' . $asset->asset_number . ($data['tab'] !== 'full' ? '-' . $data['tab'] : '') . '.pdf');
    }

    private function loadForPrint(Asset $asset): void
    {
        $asset->load([
            'category', 'currentWarehouse', 'assignedUser', 'creator', 'operatingPersonnel', 'supplier',
            'movements.fromWarehouse', 'movements.toWarehouse', 'movements.fromResponsibleUser', 'movements.toResponsibleUser', 'movements.performedBy',
            'maintenanceRecords', 'depreciationEntries', 'appreciationEntries', 'disposal', 'attachments',
        ]);
    }

    public function printList(Request $request)
    {
        $this->authorize('print-assets');

        return view('reports.register-list', AssetRegisterReport::build($request->user(), $request->query(), AssetRegisterReport::MODE_LIST, false));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('print-assets');

        return Pdf::loadView('reports.register-list', AssetRegisterReport::build($request->user(), $request->query(), AssetRegisterReport::MODE_LIST, true))
            ->setPaper('a4', 'landscape')
            ->download('assets-' . now()->format('Ymd-His') . '.pdf');
    }

    /** "Download Assets": the whole register (never filtered), cards first, then every asset. */
    public function pdfFull(Request $request)
    {
        $this->authorize('print-assets');

        return Pdf::loadView('reports.register-list', AssetRegisterReport::build($request->user(), [], AssetRegisterReport::MODE_FULL, true))
            ->setPaper('a4', 'landscape')
            ->download('assets-complete-report-' . now()->format('Ymd-His') . '.pdf');
    }

    public function export(Request $request)
    {
        $this->authorize('export-assets');

        $filters = $request->query();
        $rows = Asset::query()->inAssetRegister()->with(['category:id,name', 'currentWarehouse:id,name', 'assignedUser:id,name'])->filter($filters)->active()->orderBy('asset_number')->limit(10000)->get();

        return (new \Rap2hpoutre\FastExcel\FastExcel($rows))->download('assets-' . now()->format('Ymd-His') . '.xlsx', function (Asset $a) {
            return [
                'Asset No.' => $a->asset_number,
                'Name' => $a->name,
                'Category' => optional($a->category)->name,
                'Location' => optional($a->currentWarehouse)->name ?: $a->current_location_label,
                'Responsible' => optional($a->assignedUser)->name,
                'Status' => $a->status,
                'Condition' => $a->condition,
                'Cost' => $a->total_capitalised_cost,
                'Book Value' => $a->current_book_value,
            ];
        });
    }

    public function reportsHub()
    {
        $this->authorize('print-assets');

        return Inertia::render('Asset/Reports');
    }

    public function movementReport(Request $request)
    {
        $this->authorize('print-assets');

        $movements = AssetMovement::query()
            ->whereHas('asset', fn ($q) => $q->inAssetRegister())
            ->with(['asset:id,asset_number,name', 'fromWarehouse:id,name', 'toWarehouse:id,name', 'fromResponsibleUser:id,name', 'toResponsibleUser:id,name', 'performedBy:id,name'])
            ->when($request->query('asset_id'), fn ($q, $id) => $q->where('asset_id', $id))
            ->when($request->query('movement_type'), fn ($q, $t) => $q->where('movement_type', $t))
            ->when($request->query('date_from'), fn ($q, $d) => $q->whereDate('movement_date', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->whereDate('movement_date', '<=', $d))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate()
            ->withQueryString();

        return Inertia::render('Asset/MovementReport', [
            'movements' => new AssetMovementCollection($movements),
            'filters' => $request->only(['asset_id', 'movement_type', 'date_from', 'date_to']),
            'movementTypes' => AssetMovement::TYPES,
        ]);
    }

    private function movementReportPayload(Request $request): array
    {
        $movements = AssetMovement::query()
            ->whereHas('asset', fn ($q) => $q->inAssetRegister())
            ->with(['asset:id,asset_number,name', 'fromWarehouse:id,name', 'toWarehouse:id,name', 'fromResponsibleUser:id,name', 'toResponsibleUser:id,name', 'performedBy:id,name'])
            ->when($request->query('asset_id'), fn ($q, $id) => $q->where('asset_id', $id))
            ->when($request->query('movement_type'), fn ($q, $t) => $q->where('movement_type', $t))
            ->when($request->query('date_from'), fn ($q, $d) => $q->whereDate('movement_date', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->whereDate('movement_date', '<=', $d))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        return [
            'title' => __('Asset Movement Report'),
            'companyName' => optional(auth()->user())->account->name ?? 'RANS Company Limited',
            'generatedBy' => optional(auth()->user())->name,
            'generatedAt' => now(),
            'movements' => $movements,
        ];
    }

    public function movementReportPrint(Request $request)
    {
        $this->authorize('print-assets');

        return view('assets-movement-report-print', $this->movementReportPayload($request) + ['isPdf' => false]);
    }

    public function movementReportPdf(Request $request)
    {
        $this->authorize('print-assets');

        return Pdf::loadView('assets-movement-report-print', $this->movementReportPayload($request) + ['isPdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('asset-movement-report-' . now()->format('Ymd-His') . '.pdf');
    }

    public function downloadAttachment(Request $request, Asset $asset, Attachment $attachment)
    {
        $this->authorize('read-assets');

        abort_unless((int) $attachment->model_id === (int) $asset->id && $attachment->model_type === Asset::class, 404);

        if ($request->query('disposition') === 'inline') {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    /**
     * The amounts on the form are PER ITEM. The stored total capitalised cost is the total for the whole asset record:
     * (acquisition + transport + installation + other) x quantity. Assets of 1 piece are unchanged.
     */
    private function computeCapitalisedCost(array $data): float
    {
        $qty = isset($data['quantity']) && (float) $data['quantity'] > 0 ? (float) $data['quantity'] : 1.0;

        return round($this->capitalisedCostPerItem($data) * $qty, 2);
    }

    private function capitalisedCostPerItem(array $data): float
    {
        return (float) ($data['acquisition_cost'] ?? 0)
            + (float) ($data['transport_cost'] ?? 0)
            + (float) ($data['installation_cost'] ?? 0)
            + (float) ($data['other_capitalised_cost'] ?? 0);
    }

    /**
     * Assets are always saved in TZS. When the form currency is another one, the exchange rate is required and every cost amount is
     * multiplied by it; what was originally entered (currency, rate, per-item acquisition cost) is remembered on the asset.
     */
    private function convertToTzs(array $data, ?Asset $existing = null): array
    {
        $cur = strtoupper(trim((string) ($data['currency'] ?? '')));
        $foreign = $cur !== '' && ! in_array($cur, ['TZS', 'TSH'], true);
        if (! $foreign) {
            $data['currency'] = 'TZS';
            unset($data['exchange_rate']);
            // typed in TZS directly: what was remembered from an earlier conversion no longer describes these amounts
            if ($existing && $existing->original_currency && (float) ($data['acquisition_cost'] ?? 0) !== (float) $existing->acquisition_cost) {
                $data['original_currency'] = null;
                $data['exchange_rate'] = null;
                $data['original_acquisition_cost'] = null;
            }

            return $data;
        }
        $rate = (float) ($data['exchange_rate'] ?? 0);
        if ($rate <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => __('Enter the exchange rate: how many TZS are 1 :c. The asset is saved in TZS.', ['c' => $cur])]);
        }
        $data['original_currency'] = $cur;
        $data['exchange_rate'] = $rate;
        $data['original_acquisition_cost'] = (float) ($data['acquisition_cost'] ?? 0);
        foreach (['acquisition_cost', 'tax_amount', 'transport_cost', 'installation_cost', 'other_capitalised_cost', 'residual_value'] as $f) {
            if (isset($data[$f]) && $data[$f] !== '') {
                $data[$f] = round((float) $data[$f] * $rate, 2);
            }
        }
        $data['currency'] = 'TZS';

        return $data;
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        // A record still carrying an old free-text type may keep it on save;
        // new values must be one of Asset::ASSET_TYPES.
        $allowedTypes = Asset::ASSET_TYPES;
        if ($ignoreId) {
            $legacyType = Asset::withTrashed()->whereKey($ignoreId)->value('asset_type');
            if ($legacyType !== null && $legacyType !== '' && ! in_array($legacyType, $allowedTypes, true)) {
                $allowedTypes[] = $legacyType;
            }
        }

        return $request->validate([
            'name' => 'required|string|max:190',
            'asset_category_id' => 'nullable|exists:asset_categories,id',
            'asset_type' => ['nullable', 'string', 'max:190', Rule::in($allowedTypes)],
            'description' => 'nullable|string',
            'manufacturer' => 'nullable|string|max:190',
            'model' => 'nullable|string|max:190',
            'serial_number' => 'nullable|string|max:190',
            'registration_number' => 'nullable|string|max:190',
            'vin_chassis_number' => 'nullable|string|max:190',
            'engine_number' => 'nullable|string|max:190',
            'barcode' => 'nullable|string|max:190',
            'tag_number' => 'nullable|string|max:190',
            'operating_personnel_type' => 'nullable|in:' . implode(',', Asset::OPERATING_PERSONNEL_TYPES),
            'operating_personnel_staff_id' => 'nullable|exists:staffs,id',
            'operating_personnel_contact_id' => 'nullable|exists:contacts,id',
            'photos' => 'nullable|array|max:' . Asset::MAX_PHOTOS,
            'photos.*' => 'file|mimes:png,jpg,jpeg,webp|max:5120',
            'remove_photo_ids' => 'nullable|array',
            'remove_photo_ids.*' => 'integer',

            'ownership_type' => 'required|in:' . implode(',', Asset::OWNERSHIP_TYPES),
            'owner_name' => 'nullable|string|max:190',
            'lease_contract_reference' => 'nullable|string|max:190',
            'lease_start_date' => 'nullable|date',
            'lease_end_date' => 'nullable|date',
            'lease_monthly_cost' => 'nullable|numeric',
            'lease_deposit' => 'nullable|numeric',

            'acquisition_date' => 'nullable|date',
            'supplier_id' => 'nullable|exists:contacts,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'invoice_reference' => 'nullable|string|max:190',
            'payment_voucher_id' => 'nullable|exists:payment_vouchers,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'acquisition_cost' => 'nullable|numeric',
            'currency' => 'nullable|string|max:8',
            'exchange_rate' => 'nullable|numeric|gt:0|max:999999999',
            'tax_amount' => 'nullable|numeric',
            'transport_cost' => 'nullable|numeric',
            'installation_cost' => 'nullable|numeric',
            'other_capitalised_cost' => 'nullable|numeric',
            'funding_source' => 'nullable|string|max:190',

            'status' => 'nullable|in:' . implode(',', Asset::STATUSES),
            'condition' => 'nullable|in:' . implode(',', Asset::CONDITIONS),

            'current_warehouse_id' => 'nullable|exists:warehouses,id',
            'current_location_label' => 'nullable|string|max:190',
            'quantity' => 'nullable|numeric|min:0.001|max:999999999',
            'unit_id' => 'nullable|exists:units,id',
            'assigned_to_type' => 'nullable|in:' . implode(',', Asset::ASSIGNED_TO_TYPES),
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'assigned_to_label' => 'nullable|string|max:190',
            'assignment_date' => 'nullable|date',
            'expected_return_date' => 'nullable|date',

            'depreciation_method' => 'nullable|string|max:64',
            'useful_life_years' => 'nullable|integer|min:1',
            'residual_value' => 'nullable|numeric',
            'depreciation_start_date' => 'nullable|date',
            'depreciation_frequency' => 'nullable|in:Monthly,Quarterly,Annually',

            'warranty_provider' => 'nullable|string|max:190',
            'warranty_start' => 'nullable|date',
            'warranty_end' => 'nullable|date',
            'warranty_reference' => 'nullable|string|max:190',

            'insurance_provider' => 'nullable|string|max:190',
            'insurance_policy_number' => 'nullable|string|max:190',
            'insurance_start' => 'nullable|date',
            'insurance_expiry' => 'nullable|date',
            'insurance_insured_value' => 'nullable|numeric',
            'insurance_premium' => 'nullable|numeric',

            'fuel_type' => 'nullable|string|max:64',
            'fuel_capacity' => 'nullable|numeric',
            'current_meter' => 'nullable|numeric',
            'current_odometer' => 'nullable|numeric',
            'operating_hours' => 'nullable|numeric',
            'service_interval_value' => 'nullable|integer',
            'service_interval_unit' => 'nullable|in:km,hours,months',
            'next_service_date' => 'nullable|date',
            'registration_expiry' => 'nullable|date',
            'inspection_expiry' => 'nullable|date',
            'license_expiry' => 'nullable|date',

            'notes' => 'nullable|string',
        ]);
    }

    /**
     * The Edit form lets a user directly correct the current-state location/
     * assignment snapshot (rather than only through Check-Out/Check-In/
     * Transfer). Any actual change is still recorded as a movement so the
     * history stays complete.
     */
    private function logLocationChangeIfAny(Request $request, Asset $asset, array $data): void
    {
        $fields = ['current_warehouse_id', 'current_location_label', 'assigned_to_type', 'assigned_to_user_id', 'assigned_to_label'];
        $dateFields = ['assignment_date', 'expected_return_date'];

        $changed = false;
        foreach ($fields as $field) {
            if (($data[$field] ?? null) != $asset->{$field}) {
                $changed = true;
                break;
            }
        }
        foreach ($dateFields as $field) {
            if (! $changed && ($data[$field] ?? null) != optional($asset->{$field})->toDateString()) {
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        AssetMovement::create([
            'asset_id' => $asset->id,
            'movement_type' => 'Assigned',
            'from_warehouse_id' => $asset->current_warehouse_id,
            'from_label' => $asset->current_location_label,
            'from_responsible_user_id' => $asset->assigned_to_user_id,
            'from_responsible_label' => $asset->assigned_to_label,
            'to_warehouse_id' => $data['current_warehouse_id'] ?? null,
            'to_label' => $data['current_location_label'] ?? null,
            'to_responsible_user_id' => $data['assigned_to_user_id'] ?? null,
            'to_responsible_label' => $data['assigned_to_label'] ?? null,
            'condition_before' => $asset->condition,
            'movement_date' => now()->toDateString(),
            'performed_by_user_id' => $request->user()->id,
            'reason' => 'Corrected via Edit form.',
        ]);
    }

    private function nextReference(): string
    {
        $maxSuffix = (int) DB::table('assets')
            ->selectRaw('MAX(CAST(SUBSTRING(asset_number, 5) AS UNSIGNED)) as max_suffix')
            ->where('asset_number', 'like', 'AST-%')
            ->value('max_suffix');

        return 'AST-' . str_pad((string) ($maxSuffix + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Never lets an asset end up with more than Asset::MAX_PHOTOS photos (kept + newly uploaded). */
    private function assertPhotoLimit(Request $request, ?Asset $asset = null): void
    {
        $incoming = count($request->file('photos', []));
        $kept = 0;
        if ($asset) {
            $remove = array_map('intval', (array) $request->input('remove_photo_ids', []));
            $kept = $asset->photos()->whereNotIn('id', $remove ?: [0])->count();
        }

        if ($kept + $incoming > Asset::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => __('An asset can have up to :max photos.', ['max' => Asset::MAX_PHOTOS]),
            ]);
        }
    }

    /** Applies the form's photo changes: delete the ticked existing photos, store the new uploads, then refresh the cover. */
    private function syncPhotos(Request $request, Asset $asset): void
    {
        $remove = array_map('intval', (array) $request->input('remove_photo_ids', []));
        if ($remove) {
            $asset->photos()->whereIn('id', $remove)->get()->each(function (AssetPhoto $photo) {
                Storage::disk('assets')->delete($photo->path);
                $photo->delete();
            });
        }

        $position = (int) $asset->photos()->max('position');
        foreach (array_slice($request->file('photos', []), 0, Asset::MAX_PHOTOS) as $file) {
            $position++;
            $asset->photos()->create([
                'path' => $file->store('assets', 'assets'),
                'position' => $position,
            ]);
        }

        $this->syncCoverPhoto($asset);
    }

    /** assets.photo mirrors the first photo so list views / zoom cards that only read that column keep working. */
    private function syncCoverPhoto(Asset $asset): void
    {
        $first = $asset->photos()->first();
        Asset::withTrashed()->whereKey($asset->id)->toBase()->update(['photo' => $first?->path]);
        $asset->photo = $first?->path;
        $asset->syncOriginalAttribute('photo');
    }

    private function rememberCurrency(string $currency): void
    {
        if ($currency === '') {
            return;
        }

        $record = AssetCurrency::firstOrCreate(['text' => $currency]);
        if (! $record->wasRecentlyCreated) {
            $record->increment('use_count');
        }
    }

    private function currenciesBank(): array
    {
        return AssetCurrency::orderByDesc('use_count')->limit(200)->pluck('text')->values()->all();
    }

    /** Same growable-bank pattern as typesBank()/currenciesBank() — every provider a user has ever typed is offered back on the next asset. */
    private function rememberInsuranceProvider(string $provider): void
    {
        if ($provider === '') {
            return;
        }

        $record = AssetInsuranceProvider::firstOrCreate(['text' => $provider]);
        if (! $record->wasRecentlyCreated) {
            $record->increment('use_count');
        }
    }

    private function insuranceProvidersBank(): array
    {
        return AssetInsuranceProvider::orderByDesc('use_count')->limit(200)->pluck('text')->values()->all();
    }

    private function activityHistory(Asset $asset): array
    {
        return $asset->activities()
            ->with('causer:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Activity $a) => [
                'description' => $a->description,
                'causer' => $a->causer?->name ?? __('System'),
                'created_at' => optional($a->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function staffOptions(): array
    {
        return Staff::where('status', 'Active')->orderBy('name')->get(['id', 'name', 'staff_number'])->values()->all();
    }

    /** Every Contact except the Suppliers category — operating personnel are people who use the asset, not who sold it. */
    private function operatingPersonnelContactOptions(): array
    {
        return Contact::where('category', '!=', 'Suppliers')->orderBy('name')->get(['id', 'name', 'category'])->values()->all();
    }

    /** Whichever of staff_id/contact_id doesn't match operating_personnel_type is stale client state (a leftover value from before the type was switched) — drop it so it never persists. */
    private function normalizeOperatingPersonnel(array $data): array
    {
        $type = $data['operating_personnel_type'] ?? Asset::OPERATING_PERSONNEL_TYPE_STAFF;

        if ($type === Asset::OPERATING_PERSONNEL_TYPE_CONTACT) {
            $data['operating_personnel_staff_id'] = null;
        } else {
            $data['operating_personnel_contact_id'] = null;
        }

        return $data;
    }

    private function categoryOptions(): array
    {
        return AssetCategory::active()->orderBy('name')->get(['id', 'name', 'parent_id'])->values()->all();
    }

    private function warehouseOptions(): array
    {
        return Warehouse::active()->visible()->orderBy('name')->get(['id', 'name', 'status'])->values()->all();
    }

    private function supplierOptions(): array
    {
        return Contact::where('category', 'Suppliers')->orderBy('name')->get(['id', 'name', 'category'])->values()->all();
    }

    /** What the "Convert to Item" window needs; empty for people who cannot create items. */
    private function convertOptions(Asset $asset): ?array
    {
        if (! auth()->user()->can('create-items')) {
            return null;
        }
        $cats = \App\Models\Category::ofAccount()->orderBy('name')->get(['id', 'name', 'parent_id']);
        $byId = $cats->keyBy('id');
        $label = fn ($c) => $c->parent_id && $byId->has($c->parent_id) ? $byId[$c->parent_id]->name . ' / ' . $c->name : $c->name;
        $match = $cats->first(fn ($c) => $asset->category && mb_strtolower($c->name) === mb_strtolower($asset->category->name));

        return [
            'categories' => $cats->map(fn ($c) => ['value' => $c->id, 'label' => $label($c)])->sortBy('label')->values()->all(),
            'units' => $this->unitOptions(),
            'suggested_category_id' => $match ? $match->id : null,
            'can_delete' => auth()->user()->can('delete-assets'),
        ];
    }

    /** "Pcs" is the unit an asset gets unless another is chosen. */
    private function defaultUnitId(): ?int
    {
        return \App\Models\Unit::ofAccount()->base()->where('code', 'pcs')->orderBy('id')->value('id');
    }

    private function unitOptions(): array
    {
        return \App\Models\Unit::ofAccount()->base()->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name . ($u->code ? ' (' . $u->code . ')' : '')])->values()->all();
    }

    private function userOptions(): array
    {
        return User::orderBy('name')->get(['id', 'name'])->values()->all();
    }

    private function presentSummary(Asset $asset, bool $hideValue = false): array
    {
        return [
            'id' => $asset->id,
            'asset_number' => $asset->asset_number,
            'name' => $asset->name,
            'photo_url' => $asset->photo ? Storage::disk('assets')->url($asset->photo) : null,
            'category' => optional($asset->category)->name,
            'category_parent' => optional(optional($asset->category)->parent)->name,
            'asset_type' => $asset->asset_type,
            'warehouse' => optional($asset->currentWarehouse)->name,
            'location_label' => $asset->current_location_label,
            'responsible' => optional($asset->assignedUser)->name,
            'status' => $asset->status,
            'condition' => $asset->condition,
            'quantity' => (float) ($asset->quantity ?: 1),
            'unit' => optional($asset->unit)->name,
            // Omitted entirely (not just hidden client-side) for Site
            // Engineers on the list view — see index()'s comment.
            'acquisition_cost' => $hideValue ? null : (float) $asset->acquisition_cost,
            'total_capitalised_cost' => $hideValue ? null : (float) $asset->total_capitalised_cost,
            // the cost on the form is per item; the stored total is for the whole record (per item x quantity)
            'unit_price' => $hideValue ? null : round((float) $asset->total_capitalised_cost / max((float) ($asset->quantity ?: 1), 0.001), 2),
            'current_book_value' => $hideValue ? null : $asset->current_book_value,
            'deleted_at' => $asset->deleted_at,
        ];
    }

    private function presentDetail(Asset $asset): array
    {
        return array_merge($asset->toArray(), [
            'photo_url' => $asset->photo ? Storage::disk('assets')->url($asset->photo) : null,
            'photos' => $this->presentPhotos($asset),
            'created_by_name' => optional($asset->creator)->name,
            'operating_personnel' => $asset->operating_personnel_resolved,
            'current_book_value' => $asset->current_book_value,
            'current_appreciated_value' => $asset->current_appreciated_value,
            'warranty_expiring_soon' => $asset->warranty_expiring_soon,
            'insurance_expiring_soon' => $asset->insurance_expiring_soon,
            'is_vehicle_or_machinery' => $asset->is_vehicle_or_machinery,
            'category' => $asset->category,
            'supplier' => $asset->supplier,
            'current_warehouse' => $asset->currentWarehouse,
            'unit_name' => optional($asset->unit)->name,
            'placements' => AssetPlacements::list($asset)->all(),
            'cost_per_item' => round($this->capitalisedCostPerItem($asset->only(['acquisition_cost', 'transport_cost', 'installation_cost', 'other_capitalised_cost'])), 2),
            'assigned_user' => $asset->assignedUser,
            'movements' => $asset->relationLoaded('movements') ? $asset->movements : [],
            'maintenance_records' => $asset->relationLoaded('maintenanceRecords') ? $asset->maintenanceRecords : [],
            'depreciation_entries' => $asset->relationLoaded('depreciationEntries') ? $asset->depreciationEntries : [],
            'appreciation_entries' => $asset->relationLoaded('appreciationEntries') ? $asset->appreciationEntries : [],
            'disposal' => $asset->disposal,
            'attachments' => $asset->relationLoaded('attachments') ? $asset->attachments->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'group' => $a->group,
                'filesize' => $a->filesize,
                'is_image' => in_array(strtolower($a->filetype ?? ''), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true),
                'preview_url' => route('assets.attachments.download', [$asset->id, $a->id]) . '?disposition=inline',
                'download_url' => route('assets.attachments.download', [$asset->id, $a->id]),
                'delete_url' => route('assets.attachments.destroy', [$asset->id, $a->id]),
            ]) : [],
        ]);
    }

    /** Up to three {id, url} entries; falls back to the legacy single photo column when no rows exist yet. */
    private function presentPhotos(Asset $asset): array
    {
        $photos = $asset->photos->map(fn (AssetPhoto $p) => ['id' => $p->id, 'url' => $p->url])->values()->all();

        if (! $photos && $asset->photo) {
            $photos[] = ['id' => null, 'url' => Storage::disk('assets')->url($asset->photo)];
        }

        return $photos;
    }

    private function printData(Asset $asset, ?string $tab = null): array
    {
        $tab = in_array($tab, ['overview', 'movements', 'maintenance', 'depreciation', 'appreciation', 'documents', 'trails', 'disposal'], true) ? $tab : 'full';

        return [
            'tab' => $tab,
            'trails' => $tab === 'trails' ? $this->activityHistory($asset) : [],
            'asset' => $asset,
            'companyName' => optional(auth()->user())->account->name ?? 'RANS Company Limited',
            'qr' => \App\Support\PrintQrCode::dataUri(route('assets.show', $asset->id)),
        ];
    }
}
