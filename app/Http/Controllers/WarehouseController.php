<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Contact;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Http\Requests\WarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Http\Resources\WarehouseCollection;
use App\Models\Stock;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;
use Tecdiary\Laravel\Attachments\Attachment;

class WarehouseController extends Controller
{
    public function create(Request $request)
    {
        return Inertia::render('Warehouse/Form', [
            'stageSuggestions' => $this->stageSuggestions(),
            'canViewCertificates' => $request->user()->can('view-project-certificates'),
            'clients' => Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name']),
            'projectManagers' => $this->projectManagerOptions(),
        ]);
    }

    /**
     * Users eligible to be picked as a project's "Project Manager / Site
     * Engineer" — no single role covers both titles, so both role families
     * (and their singular/plural variants, matching how the rest of the app
     * already checks these two roles together, e.g.
     * WorkPackageController::store()'s assignableUsers) are queried.
     */
    private function projectManagerOptions()
    {
        // whereHas (not the role() scope) — role() throws RoleDoesNotExist
        // if any name in the list isn't a real role in this account's guard,
        // and "Project Managers"/"Site Engineers" plural variants aren't
        // guaranteed to exist everywhere the singular ones do.
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', [
            'Project Manager', 'Project Managers', ...User::SITE_ENGINEER_ROLES,
        ]))->orderBy('name')->get(['id', 'name']);
    }

    public function destroy(Request $request, Warehouse $warehouse)
    {
        $this->abortIfLockedForNonSuperAdmin($request, $warehouse);

        if ($warehouse->del()) {
            \App\Services\ProjectNotifier::fire('deleted', $warehouse, __('Project deleted'), $warehouse->name.' '.__('was deleted by :n.', ['n' => $request->user()->name]));

            return redirect()->route('warehouses.index')->with('message', __choice('action_text', ['record' => 'Warehouse', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Request $request, Warehouse $warehouse)
    {
        $this->abortIfLockedForNonSuperAdmin($request, $warehouse);

        if ($warehouse->delP()) {
            // "Deleted for good" is its own event; while it is switched off the older "Project deleted" notice is sent for a permanent delete too, as before.
            $permanent = \App\Services\WorkflowGate::on('projects', 'permanently_deleted', (int) ($request->user()->account_id ?? 1));
            \App\Services\ProjectNotifier::fire($permanent ? 'permanently_deleted' : 'deleted', $warehouse, $permanent ? __('Project deleted for good') : __('Project deleted'), $warehouse->name.' '.__('was deleted for good by :n.', ['n' => $request->user()->name]));

            return redirect()->route('warehouses.index')->with('message', __choice('action_text', ['record' => 'Warehouse', 'action' => 'permanently deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function edit(Request $request, Warehouse $warehouse)
    {
        $this->abortIfLockedForNonSuperAdmin($request, $warehouse);

        $warehouse->load('attachments', 'completedBy');

        return Inertia::render('Warehouse/Form', [
            'edit' => new WarehouseResource($warehouse),
            'stageSuggestions' => $this->stageSuggestions(),
            // The "Project Details" card mirrors this project's Contract
            // Setup (see ProjectCertificateContractController) read-only —
            // actual editing only ever happens on that page. Hidden
            // entirely from users who can't see certificates at all.
            'canViewCertificates' => $request->user()->can('view-project-certificates'),
            'certificateContract' => $this->certificateContractForForm($request, $warehouse),
            'clients' => Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name']),
            'projectManagers' => $this->projectManagerOptions(),
        ]);
    }

    /**
     * Read-only mirror of this project's Contract Setup, for the "Project
     * Details" card. Dates are pre-formatted here (not left as raw
     * timestamps) since the 'date' cast serializes to a UTC instant —
     * slicing that string client-side can land on the wrong calendar day
     * once the app's Africa/Dar_es_Salaam offset is applied.
     */
    private function certificateContractForForm(Request $request, Warehouse $warehouse)
    {
        if (! $request->user()->can('view-project-certificates')) {
            return null;
        }

        $contract = $warehouse->certificateContract()->with(['client:id,name', 'consultant:id,name'])->first();

        if (! $contract) {
            return null;
        }

        return array_merge($contract->toArray(), [
            'contract_start_date' => $contract->contract_start_date?->format('Y-m-d'),
            'contract_end_date' => $contract->contract_end_date?->format('Y-m-d'),
        ]);
    }

    private function abortIfLockedForNonSuperAdmin(Request $request, Warehouse $warehouse): void
    {
        $user = $request->user();
        abort_unless(! $warehouse->is_completed || ($user && $user->hasRole('Super Admin')), 403);
    }

    private function stageSuggestions(): array
    {
        $baseStages = [
            'Mobilization',
            'Site Clearance',
            'Surveying & Setting Out',
            'Excavation',
            'Earthworks & Filling',
            'Foundation Works',
            'Substructure',
            'Superstructure',
            'Concrete Works',
            'Masonry & Blockwork',
            'Structural Steel Works',
            'Roofing Works',
            'Plumbing & Drainage',
            'Electrical Installation',
            'ICT & ELV Systems',
            'HVAC & Fire Protection',
            'Doors, Windows & Glazing',
            'Plastering & Rendering',
            'Ceiling Works',
            'Floor Finishes & Tiling',
            'Painting & Decoration',
            'External Works & Landscaping',
            'Testing, Commissioning & Snagging',
            'Handover, Demobilization & Closeout',
        ];

        $customStages = Warehouse::query()
            ->whereNotNull('stage')
            ->where('stage', '!=', '')
            ->distinct()
            ->orderBy('stage')
            ->pluck('stage')
            ->reject(fn ($stage) => in_array($stage, $baseStages, true))
            ->values()
            ->all();

        return array_merge($baseStages, $customStages);
    }

    private function buildDetailedListing()
    {
        return Warehouse::visible()->withCount('attachments')->orderBy('name')->get()->map(function ($warehouse) {
            return [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'status' => $warehouse->status,
                'stage' => $warehouse->stage,
                'completion_percentage' => $warehouse->completion_percentage,
                'start_date' => optional($warehouse->start_date)->format('Y-m-d'),
                'expected_end_date' => optional($warehouse->expected_end_date)->format('Y-m-d'),
                'for_who' => $warehouse->for_who,
                'registration_number' => $warehouse->registration_number,
                'external_body_personnel' => $warehouse->external_body_personnel,
                'site_budget' => $warehouse->site_budget,
                'phone' => $warehouse->phone,
                'email' => $warehouse->email,
                'address' => $warehouse->address,
                'has_attachments' => $warehouse->attachments_count > 0,
            ];
        })->values();
    }

    public function detailsPrint(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('read-warehouses'), 403);

        return view('reports.pdf', $this->detailsReportPayload($request, true));
    }

    public function detailsPdf(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('read-warehouses'), 403);

        $pdf = Pdf::loadView('reports.pdf', $this->detailsReportPayload($request, false))
            ->setPaper('a4', 'landscape');

        return $pdf->download('project-warehouse-details-' . now()->format('Ymd-His') . '.pdf');
    }

    private function detailsReportPayload(Request $request, bool $autoPrint): array
    {
        $listing = $this->buildDetailedListing();

        $rows = $listing->values()->map(fn ($row, $index) => [
            $index + 1,
            (string) ($row['name'] ?? '-'),
            (string) ($row['status'] ?? '-'),
            (string) ($row['stage'] ?? '-'),
            (string) ($row['phone'] ?: $row['email'] ?: '-'),
            (string) ($row['address'] ?: '-'),
            $row['completion_percentage'] === null ? '-' : $row['completion_percentage'] . '%',
            (string) ($row['start_date'] ?? '-'),
            (string) ($row['expected_end_date'] ?? '-'),
            (string) ($row['for_who'] ?: '-'),
            (string) ($row['registration_number'] ?: '-'),
            (string) ($row['external_body_personnel'] ?: '-'),
            $row['site_budget'] === null ? '-' : number_format((float) $row['site_budget'], 2) . ' Tsh',
            $row['has_attachments'] ? 'Yes' : 'No',
        ])->all();

        return [
            'title' => 'Project(s) / Warehouse(s) Details',
            'companyName' => optional($request->user()->account ?? null)->name,
            'columns' => [
                'No', 'Name', 'Type', 'Stage', 'Contact', 'Location', 'Completion %', 'Start Date', 'Expected End Date',
                'For Who', 'Registration Number', 'External Body / Personnel', 'Site Budget', 'Attachments',
            ],
            'rows' => $rows,
            'meta' => array_filter([
                'Generated By' => optional($request->user())->name,
                'Generated Date' => now()->format('Y-m-d H:i'),
                'Project(s) / Warehouse(s) Listed' => (string) count($rows),
            ], fn ($value) => filled($value)),
            'autoPrint' => $autoPrint,
        ];
    }

    /** The rows of the Warehouses table as filtered on screen (search, category, stage, status, trashed), in the same category sections. */
    private function listPayload(Request $request, bool $autoPrint): array
    {
        $filters = $request->all('search', 'trashed', 'category', 'stage', 'state');
        $order = ['Main Warehouse', 'Rans Warehouse', 'Directors', 'Private', 'Government', 'General'];
        $list = Warehouse::filter(array_map(fn ($v) => is_string($v) ? $v : null, $filters))->visible()->orderBy('name')->get()->groupBy(fn ($w) => $w->status ?: 'No category')
            ->sortKeysUsing(fn ($a, $b) => ((($i = array_search($a, $order, true)) === false ? 99 : $i) <=> (($j = array_search($b, $order, true)) === false ? 99 : $j)) ?: strcmp($a, $b));
        $rows = [];
        $n = 0;
        foreach ($list as $category => $items) {
            $rows[] = ['__section' => $category . ' (' . $items->count() . ')'];
            foreach ($items as $w) {
                $rows[] = [++$n, (string) $w->name, (string) $w->code, (string) ($w->stage ?: '-'), (string) ($w->phone ?: $w->email ?: '-'), (string) ($w->address ?: '-'), $w->completed_at ? 'Completed' : ($w->active ? 'Active' : 'Inactive')];
            }
        }
        $applied = collect(['search' => 'Search', 'category' => 'Category', 'stage' => 'Stage', 'state' => 'Status', 'trashed' => 'Trashed'])
            ->map(fn ($label, $k) => is_string($filters[$k] ?? null) && $filters[$k] !== '' ? $label . ': ' . ($filters[$k] === '__none' ? 'No stage set' : $filters[$k]) : null)->filter()->implode('; ');

        return [
            'title' => 'Project(s) / Warehouse(s)',
            'companyName' => optional($request->user()->account ?? null)->name,
            'columns' => ['No', 'Name', 'Code', 'Stage', 'Contact', 'Address', 'Status'],
            'rows' => $rows,
            'meta' => array_filter(['Filters' => $applied ?: 'None (all records)', 'Records Listed' => (string) $n], fn ($v) => filled($v)),
            'autoPrint' => $autoPrint,
        ];
    }

    public function listPrint(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('read-warehouses'), 403);

        return view('reports.pdf', $this->listPayload($request, true));
    }

    public function listPdf(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('read-warehouses'), 403);

        return Pdf::loadView('reports.pdf', $this->listPayload($request, false))->setPaper('a4', 'landscape')->download('project-warehouse-list-' . now()->format('Ymd-His') . '.pdf');
    }

    public function index(Request $request)
    {
        $filters = $request->all('search', 'trashed', 'category', 'stage', 'state');

        $warehouses = Warehouse::visible()->get();

        // One query for every warehouse's stock instead of one query per
        // warehouse (was N+1 — each warehouse re-ran this query inside the
        // map() below), grouped in PHP afterward.
        // Lean (non-Eloquent) queries: the page only needs a few columns of ~20k stock rows.
        $stockByWarehouse = Stock::query()
            ->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->select('warehouse_id', 'item_id', 'quantity')
            ->toBase()->get()->groupBy('warehouse_id');
        $itemRows = \App\Models\Item::query()->select('id', 'name', 'code', 'price', 'unit_id')->toBase()->get()->keyBy('id');
        $unitNames = \App\Models\Unit::query()->select('id', 'name')->toBase()->get()->pluck('name', 'id');

        $listing = $warehouses->map(function ($warehouse) use ($stockByWarehouse, $itemRows, $unitNames) {
            $items = ($stockByWarehouse->get($warehouse->id) ?? collect())->map(function ($row) use ($itemRows, $unitNames) {
                $it = $itemRows->get($row->item_id);
                $price = (float) ($it->price ?? 0);

                return [
                    'name' => $it->name ?? 'N/A',
                    'code' => $it->code ?? 'N/A',
                    'unit' => $it ? ($unitNames[$it->unit_id] ?? 'N/A') : 'N/A',
                    'quantity' => $row->quantity,
                    'price' => $price,
                    'total_amount' => $row->quantity * $price,
                ];
            })->values();

            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'amount' => $items->sum('total_amount'),
                // The page only ever shows lines with stock on hand, so the empty (zero or negative) ones are not sent:
                // most of a warehouse's ~1000 catalogue lines are empty. 'amount' above still counts every line.
                'items' => $items->filter(fn ($row) => $row['quantity'] > 0)->values(),
            ];
        });

        return Inertia::render('Warehouse/Index', [
            'filters'    => $filters,
            'listing' => $listing,
            'categories' => Warehouse::visible()->whereNotNull('status')->where('status', '!=', '')->distinct()->orderBy('status')->pluck('status')->values(),
            'stages' => Warehouse::visible()->whereNotNull('stage')->where('stage', '!=', '')->distinct()->orderBy('stage')->pluck('stage')->values(),
            'categoryCounts' => Warehouse::visible()->whereNull('deleted_at')->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'stats' => $this->warehouseStats(),
            'warehouses' => new WarehouseCollection(
                Warehouse::filter($filters)->visible()->orderBy('name')->paginate(500)->withQueryString()
            ),
        ]);
    }

    /**
     * Header tile numbers; same visibility scope as the list (visible() hides
     * completed projects from everyone but Super Admin, trashed rows excluded).
     */
    private function warehouseStats(): array
    {
        $row = Warehouse::visible()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END), 0) as active_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) IN ('main warehouse', 'main warehouses') THEN 1 ELSE 0 END), 0) as main_count")
            ->selectRaw('COALESCE(SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END), 0) as completed_count')
            ->first();

        $total = (int) $row->total_count;
        $main = (int) $row->main_count;

        return [
            'total' => $total,
            'active' => (int) $row->active_count,
            'main' => $main,
            'projects' => $total - $main,
            'completed' => (int) $row->completed_count,
        ];
    }

    public function myWarehouses(Request $request)
    {
        abort_unless($request->user() && $request->user()->hasRole(User::SITE_ENGINEER_ROLES), 403);

        $warehouses = $request->user()->warehouses()->where('active', 1)->visible()->orderBy('name')->get();

        $payload = $warehouses->map(function ($warehouse) {
            $stockItems = Stock::where('warehouse_id', $warehouse->id)
                ->where('quantity', '>', 0)
                ->with(['item.unit:id,name', 'item:id,name,code,price,unit_id'])
                ->get();

            $items = $stockItems->filter(fn ($stockItem) => $stockItem->item)
                ->map(function ($stockItem) {
                    return [
                        'id' => $stockItem->item->id,
                        'name' => $stockItem->item->name ?? 'N/A',
                        'code' => $stockItem->item->code ?? 'N/A',
                        'unit' => optional($stockItem->item->unit)->name ?? 'N/A',
                        'quantity' => $stockItem->quantity,
                    ];
                })->values();

            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'active' => (bool) $warehouse->active,
                'completed_at' => $warehouse->completed_at,
                'assigned_users' => $warehouse->users()->orderBy('name')->pluck('name')->implode(', '),
                'items' => $items,
            ];
        })->values();

        $selectedWarehouseId = $request->query('warehouse_id') ?: ($payload->first()['id'] ?? null);
        $selectedWarehouse = $payload->firstWhere('id', (int) $selectedWarehouseId) ?: $payload->first();

        return Inertia::render('Warehouse/MyWarehouses', [
            'warehouses' => $payload,
            'userName' => $request->user()->name,
            'initialWarehouseId' => $selectedWarehouse['id'] ?? null,
        ]);
    }

    public function printMyWarehouses(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user() && $request->user()->hasRole(User::SITE_ENGINEER_ROLES), 403);
        abort_unless($request->user()->manages($warehouse->id), 403);

        $report = $this->myWarehouseReportPayload($request->user(), $warehouse);
        $logoPath = $this->warehouseReportLogo();

        return view('my-warehouses-print', [
            'warehouse' => $report['warehouse'],
            'userName' => $report['userName'],
            'printedAt' => $report['printedAt'],
            'printedBy' => $report['printedBy'],
            'logoPath' => $logoPath,
        ]);
    }

    public function pdfMyWarehouses(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user() && $request->user()->hasRole(User::SITE_ENGINEER_ROLES), 403);
        abort_unless($request->user()->manages($warehouse->id), 403);

        $report = $this->myWarehouseReportPayload($request->user(), $warehouse);
        $logoPath = $this->warehouseReportLogo();

        $pdf = Pdf::loadView('my-warehouses-print', [
            'warehouse' => $report['warehouse'],
            'userName' => $report['userName'],
            'printedAt' => $report['printedAt'],
            'printedBy' => $report['printedBy'],
            'logoPath' => $logoPath,
        ]);

        return $pdf->download('my-warehouses-report-' . $warehouse->id . '.pdf');
    }

    public function printWarehouse(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user() && $request->user()->can('read-warehouses'), 403);

        $report = $this->warehouseStockReportPayload($warehouse, $request->user());

        return view('warehouse-stock-print', [
            'warehouse' => $report['warehouse'],
            'printedAt' => $report['printedAt'],
            'printedBy' => $report['printedBy'],
            'logoPath' => $this->warehouseReportLogo(),
            'autoPrint' => true,
        ]);
    }

    public function pdfWarehouse(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user() && $request->user()->can('read-warehouses'), 403);

        $report = $this->warehouseStockReportPayload($warehouse, $request->user());

        $pdf = Pdf::loadView('warehouse-stock-print', [
            'warehouse' => $report['warehouse'],
            'printedAt' => $report['printedAt'],
            'printedBy' => $report['printedBy'],
            'logoPath' => $this->warehouseReportLogo(),
            'autoPrint' => false,
        ]);

        return $pdf->download('warehouse-stock-report-' . $warehouse->id . '.pdf');
    }

    protected function myWarehouseReportPayload($user, Warehouse $warehouse)
    {
        $stockItems = Stock::where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with(['item.unit:id,name', 'item:id,name,code,price,unit_id'])
            ->get();

        $items = $stockItems->filter(fn ($stockItem) => $stockItem->item)
            ->map(function ($stockItem) {
                return [
                    'id' => $stockItem->item->id,
                    'name' => $stockItem->item->name ?? 'N/A',
                    'code' => $stockItem->item->code ?? 'N/A',
                    'unit' => optional($stockItem->item->unit)->name ?? 'N/A',
                    'quantity' => $stockItem->quantity,
                ];
            })->values();

        return [
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'assigned_users' => $warehouse->users()->orderBy('name')->pluck('name')->implode(', '),
                'items' => $items,
            ],
            'userName' => $user->name,
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $user->name,
        ];
    }

    protected function warehouseStockReportPayload(Warehouse $warehouse, $user)
    {
        $stockItems = Stock::where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->with(['item.unit:id,name', 'item:id,name,code,price,unit_id'])
            ->get();

        $items = $stockItems->filter(fn ($stockItem) => $stockItem->item)
            ->map(function ($stockItem) {
                $price = (float) ($stockItem->item->price ?? 0);
                $quantity = (float) $stockItem->quantity;

                return [
                    'code' => $stockItem->item->code ?? 'N/A',
                    'name' => $stockItem->item->name ?? 'N/A',
                    'unit' => optional($stockItem->item->unit)->name ?? 'N/A',
                    'price' => $price,
                    'quantity' => $quantity,
                    'total_amount' => $quantity * $price,
                ];
            })->values();

        return [
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'items' => $items,
                'total_amount' => $items->sum('total_amount'),
            ],
            'printedAt' => now()->format('Y-m-d H:i:s'),
            'printedBy' => $user->name,
        ];
    }

    protected function warehouseReportLogo()
    {
        $logo = public_path('images/logo.png');

        if (! file_exists($logo)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($logo));
    }

    public function restore(Warehouse $warehouse)
    {
        abort_unless(auth()->user()->can('delete-warehouses'), 403);

        $warehouse->restore();
        $warehouse->stock()->restore();
        \App\Services\ProjectNotifier::fire('restored', $warehouse, __('Project restored'), $warehouse->name.' '.__('was restored by :n.', ['n' => auth()->user()->name]));

        return back()->with('message', __choice('action_text', ['record' => 'Warehouse', 'action' => 'restored']));
    }

    public function toggleActive(Request $request, Warehouse $warehouse)
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyRole(['Super Admin', 'Admin'])) {
            throw ValidationException::withMessages([
                'active' => 'Only Admin or Super Admin can change a project/warehouse\'s active status.',
            ]);
        }

        $rules = ['active' => 'required|boolean'];
        if (! $request->boolean('active')) {
            $rules['password'] = 'required|string';
        }
        $validated = $request->validate($rules);

        if (! $validated['active'] && ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The provided password is incorrect.',
            ]);
        }

        $warehouse->active = $validated['active'];
        $warehouse->save();
        \App\Services\ProjectNotifier::fire($warehouse->active ? 'activated' : 'deactivated', $warehouse, $warehouse->active ? __('Project activated') : __('Project deactivated'), $warehouse->name.' '.($warehouse->active ? __('was activated by :n.', ['n' => $user->name]) : __('was deactivated by :n.', ['n' => $user->name])));

        log_activity(
            __choice('action_text', ['record' => 'Warehouse', 'action' => $validated['active'] ? 'activated' : 'deactivated']),
            ['active' => $validated['active']],
            $warehouse,
            'Warehouse'
        );

        return response()->json(['success' => true, 'active' => (bool) $warehouse->active]);
    }

    public function completeProject(Request $request, Warehouse $warehouse)
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('Super Admin')) {
            throw ValidationException::withMessages([
                'password' => 'Only Super Admin can mark a project/warehouse as complete.',
            ]);
        }

        $validated = $request->validate(['password' => 'required|string']);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The provided password is incorrect.',
            ]);
        }

        $warehouse->update(['completed_at' => now(), 'completed_by' => $user->id]);
        \App\Services\ProjectNotifier::fire('completed', $warehouse, __('Project completed'), $warehouse->name.' '.__('was marked as completed by :n.', ['n' => $user->name]));

        log_activity(
            __choice('action_text', ['record' => 'Warehouse', 'action' => 'marked as completed']),
            ['completed_at' => $warehouse->completed_at],
            $warehouse,
            'Warehouse'
        );

        return response()->json([
            'success' => true,
            'is_completed' => true,
            'completed_at' => $warehouse->completed_at->format('Y-m-d H:i'),
            'completed_by_name' => $user->name,
        ]);
    }

    public function reopenProject(Request $request, Warehouse $warehouse)
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('Super Admin')) {
            throw ValidationException::withMessages([
                'password' => 'Only Super Admin can re-open a project/warehouse.',
            ]);
        }

        $validated = $request->validate(['password' => 'required|string']);

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The provided password is incorrect.',
            ]);
        }

        $warehouse->update(['completed_at' => null, 'completed_by' => null]);
        \App\Services\ProjectNotifier::fire('reopened', $warehouse, __('Project re-opened'), $warehouse->name.' '.__('was re-opened by :n.', ['n' => $user->name]));

        log_activity(
            __choice('action_text', ['record' => 'Warehouse', 'action' => 're-opened']),
            [],
            $warehouse,
            'Warehouse'
        );

        return response()->json(['success' => true, 'is_completed' => false]);
    }

    public function store(WarehouseRequest $request)
    {
        $warehouse = Warehouse::create($request->validated());

        $warehouse->saveAttachments($request->file('drawings'), 'drawings');
        $warehouse->saveAttachments($request->file('photos'), 'photos');
        $warehouse->saveAttachments($request->file('boq_attachment'), 'boq');
        \App\Services\ProjectNotifier::fire('created', $warehouse, __('Project created'), $warehouse->name.' '.__('was created by :n.', ['n' => $request->user()->name]));

        return redirect()->route('warehouses.index')->with('message', __choice('action_text', ['record' => 'Warehouse', 'action' => 'created']));
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse)
    {
        $this->abortIfLockedForNonSuperAdmin($request, $warehouse);

        // Active status is changed only via toggleActive(), which enforces the
        // Admin/Super Admin + password requirement for deactivation.
        $warehouse->update(collect($request->validated())->except('active')->all());

        $warehouse->saveAttachments($request->file('drawings'), 'drawings');
        $warehouse->saveAttachments($request->file('photos'), 'photos');
        $warehouse->saveAttachments($request->file('boq_attachment'), 'boq');
        \App\Services\ProjectNotifier::fire('updated', $warehouse, __('Project updated'), $warehouse->name.' '.__('was updated by :n.', ['n' => $request->user()->name]));

        return back()->with('message', __choice('action_text', ['record' => 'Warehouse', 'action' => 'updated']));
    }

    public function downloadAttachment(Request $request, Warehouse $warehouse, Attachment $attachment)
    {
        abort_unless($request->user() && $request->user()->can('read-warehouses'), 403);

        if ((int) $attachment->model_id !== (int) $warehouse->id || $attachment->model_type !== Warehouse::class) {
            abort(404);
        }

        if ($request->boolean('preview')) {
            return Storage::disk($attachment->disk)->response($attachment->filepath, $attachment->title);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->title);
    }

    public function destroyAttachment(Request $request, Warehouse $warehouse, Attachment $attachment)
    {
        abort_unless($request->user() && $request->user()->can('update-warehouses'), 403);
        $this->abortIfLockedForNonSuperAdmin($request, $warehouse);

        if ((int) $attachment->model_id !== (int) $warehouse->id || $attachment->model_type !== Warehouse::class) {
            abort(404);
        }

        $attachment->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Attachment deleted successfully.')]);
        }

        return back()->with('message', __('Attachment deleted successfully.'));
    }
}
