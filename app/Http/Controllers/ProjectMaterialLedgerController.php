<?php

namespace App\Http\Controllers;

use App\Http\Resources\CheckinResource;
use App\Http\Resources\CheckoutResource;
use App\Http\Resources\TransferResource;
use App\Models\BoqMaterialCompliance;
use App\Models\Category;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\Item;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * A single, auditable ledger of every material movement tied to one
 * project/site: Checked In, Checked Out, Transfer In, Transfer Out.
 *
 * This app has no separate "Project" model — a project/site IS a Warehouse
 * record (warehouses.status distinguishes 'Main Warehouse' from site
 * categories like 'Government'/'Private'/etc, confirmed via
 * Warehouse::scopeMainWarehouses()). "Select a project" below therefore
 * means "select a non-Main-Warehouse warehouse" — there is no second,
 * separate "Site/Warehouse" level underneath it in the real schema, so this
 * report does not invent one.
 *
 * The four movement types are normalised into one shape via a real SQL
 * UNION ALL (same technique ReportController::itemTransactional() already
 * uses for a single item, generalised here to a whole project), then every
 * view (main ledger, summary cards, item summary, source/destination
 * summary, supplier summary) is a separate aggregate query against that
 * union rather than pulling every row into PHP and summing there.
 *
 * Value formula matches MovementValueReportController / MaterialValueController:
 * checkin/checkout use their own line's `price` (a real recorded cost at the
 * time of the transaction) falling back to the item's current price;
 * transfer lines carry no price of their own, so they use the item's
 * current price (a known, documented limitation — transfers cannot recover
 * a historical value that was never stored).
 *
 * BOQ "used vs planned" reuses ReportController::boqMaterialCompliance()'s
 * existing formula verbatim (Checkout quantities only) so this report's BOQ
 * numbers never disagree with the existing BOQ Material Compliance report.
 *
 * Stock Reconciliation deliberately does NOT read StockTrail: that table is
 * only ever populated by one-off data-import console commands
 * (ApplyStoreDataStock*.php), not by the live Checkin/Checkout/Transfer
 * flows, so it is stale/incomplete for real transactions. Instead, opening
 * stock is derived by working backwards from the current, live Stock
 * balance minus this report's own (real, live) movement sum for everything
 * after the opening date — no second inventory system, just arithmetic over
 * data that is actually kept up to date.
 */
class ProjectMaterialLedgerController extends Controller
{
    private const ALLOWED_ROLES = [
        'Super Admin',
        'Admin',
        'Procurement Manager',
        'Procurement Manger',
        'Project Manager',
        'Project Managers',
    ];

    public function ledger(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403, 'You are not allowed to view this report.');

        $canViewAllProjects = $user->hasRole(['Super Admin', 'Admin']);
        $projects = $this->accessibleProjects($user, $canViewAllProjects);
        $projectIds = $projects->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $selectedProjectId = $request->filled('project_warehouse_id') ? (int) $request->input('project_warehouse_id') : null;
        if (! $selectedProjectId && $projects->count() === 1) {
            $selectedProjectId = (int) $projects->first()->id;
        }
        if ($selectedProjectId && ! in_array($selectedProjectId, $projectIds, true)) {
            abort(403, 'You are not assigned to the selected project.');
        }

        $filters = [
            'project_warehouse_id' => $selectedProjectId,
            'item_id' => $request->filled('item_id') ? (int) $request->input('item_id') : null,
            'category_id' => $request->filled('category_id') ? (int) $request->input('category_id') : null,
            'transaction_type' => in_array($request->input('transaction_type'), ['checkin', 'checkout', 'transfer_in', 'transfer_out'], true) ? $request->input('transaction_type') : null,
            'user_id' => $request->filled('user_id') ? (int) $request->input('user_id') : null,
            'contact_id' => $request->filled('contact_id') ? (int) $request->input('contact_id') : null,
            'status' => in_array($request->input('status'), ['completed', 'draft'], true) ? $request->input('status') : null,
            'date_from' => $request->input('date_from') ?: null,
            'date_to' => $request->input('date_to') ?: null,
            'entire_project' => filter_var($request->input('entire_project'), FILTER_VALIDATE_BOOLEAN),
            'search' => $request->input('search') ?: null,
            'sort_by' => $request->input('sort_by') ?: 'date',
            'sort_dir' => $request->input('sort_dir') === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) $request->input('page', 1)),
            'view' => in_array($request->input('view'), ['ledger', 'item_summary', 'movement_summary', 'supplier_summary', 'reconciliation', 'boq'], true) ? $request->input('view') : 'ledger',
        ];

        $itemIds = $this->resolveItemIds($filters);

        $union = $selectedProjectId ? $this->buildUnion($user, $selectedProjectId, $filters, $itemIds) : null;

        $output = $request->query('output');
        if ($output === 'print' || $output === 'pdf') {
            return $this->export($request, $union, $filters, $projects, $itemIds, $output);
        }

        $data = [
            'filters' => $filters,
            'projects' => $projects,
            'availableItems' => Item::ofAccount()->orderBy('name')->get(['id', 'name', 'code']),
            'availableCategories' => Category::ofAccount()->orderBy('name')->get(['id', 'name']),
            'availableUsers' => $selectedProjectId ? $this->usersInvolved($union) : [],
            'availableContacts' => $selectedProjectId ? $this->contactsInvolved($union) : [],
            'summary' => $selectedProjectId ? $this->buildSummary($union) : null,
            'ledger' => $selectedProjectId ? $this->buildLedgerPage($union, $filters) : null,
            'itemSummary' => $selectedProjectId ? $this->buildItemSummary($union) : [],
            'movementSummary' => $selectedProjectId ? $this->buildMovementSummary($union) : [],
            'supplierSummary' => $selectedProjectId ? $this->buildSupplierSummary($union) : [],
            'reconciliation' => $selectedProjectId ? $this->buildReconciliation($user, $selectedProjectId, $itemIds, $filters) : [],
            'boq' => $selectedProjectId ? $this->buildBoqTieIn($user, $selectedProjectId, $itemIds) : [],
        ];

        return Inertia::render('ProjectMaterialLedger/Index', $data);
    }

    public function transaction(Request $request, string $transactionType, int $transactionId)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403, 'You are not allowed to view this report.');

        $canViewAllProjects = $user->hasRole(['Super Admin', 'Admin']);
        $allowedWarehouseIds = $this->accessibleProjects($user, $canViewAllProjects)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        // Main warehouses are also legitimate "from"/"to" endpoints for a
        // transfer, so the detail lookup must allow those too, not just
        // project-type warehouses.
        $allowedWarehouseIds = array_values(array_unique(array_merge(
            $allowedWarehouseIds,
            $canViewAllProjects ? [] : Warehouse::where('account_id', $user->account_id)->mainWarehouses()->pluck('id')->map(fn ($id) => (int) $id)->all()
        )));

        $key = mb_strtolower(trim($transactionType));

        if ($key === 'checkin') {
            $checkin = Checkin::query()
                ->where('id', $transactionId)
                ->where('account_id', $user->account_id)
                ->whereNull('deleted_at')
                ->when(! $canViewAllProjects, fn ($q) => $q->whereIn('warehouse_id', $allowedWarehouseIds))
                ->firstOrFail();

            $checkin->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,photo', 'contact', 'warehouse', 'items.unit:id,code,name', 'user:id,name,username', 'attachments']);

            return Inertia::render('ProjectMaterialLedger/Detail', [
                'transactionType' => 'checkin',
                'transaction' => new CheckinResource($checkin),
                'history' => $this->activityHistory(Checkin::class, $checkin->id),
            ]);
        }

        if ($key === 'checkout') {
            $checkout = Checkout::query()
                ->where('id', $transactionId)
                ->where('account_id', $user->account_id)
                ->whereNull('deleted_at')
                ->when(! $canViewAllProjects, fn ($q) => $q->whereIn('warehouse_id', $allowedWarehouseIds))
                ->firstOrFail();

            $checkout->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,photo', 'contact', 'warehouse', 'workActivity', 'items.unit:id,code,name', 'user:id,name,username', 'attachments']);

            return Inertia::render('ProjectMaterialLedger/Detail', [
                'transactionType' => 'checkout',
                'transaction' => new CheckoutResource($checkout),
                'history' => $this->activityHistory(Checkout::class, $checkout->id),
            ]);
        }

        if ($key === 'transfer_in' || $key === 'transfer_out' || $key === 'transfer') {
            $transfer = Transfer::query()
                ->where('id', $transactionId)
                ->where('account_id', $user->account_id)
                ->whereNull('deleted_at')
                ->when(! $canViewAllProjects, function ($q) use ($allowedWarehouseIds) {
                    $q->where(function ($inner) use ($allowedWarehouseIds) {
                        $inner->whereIn('from_warehouse_id', $allowedWarehouseIds)->orWhereIn('to_warehouse_id', $allowedWarehouseIds);
                    });
                })
                ->firstOrFail();

            $transfer->load(['items.variations', 'items.item:id,code,name,track_quantity,track_weight,price', 'fromWarehouse', 'toWarehouse', 'items.unit:id,code,name', 'user:id,name,username', 'receivedBy:id,name,username', 'attachments']);

            return Inertia::render('ProjectMaterialLedger/Detail', [
                'transactionType' => 'transfer',
                'transaction' => new TransferResource($transfer),
                'history' => $this->activityHistory(Transfer::class, $transfer->id),
            ]);
        }

        abort(404);
    }

    private function canView(?User $user): bool
    {
        return (bool) $user && $user->hasRole(self::ALLOWED_ROLES);
    }

    private function accessibleProjects(User $user, bool $canViewAllProjects)
    {
        $query = Warehouse::where('account_id', $user->account_id)
            ->where('active', 1)
            ->visible()
            ->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses']);

        $accessibleIds = accessible_warehouse_ids();
        if (! $canViewAllProjects && $accessibleIds !== null) {
            $query->whereIn('id', $accessibleIds);
        }

        return $query->orderBy('name')->get(['id', 'name', 'status']);
    }

    private function resolveItemIds(array $filters): ?array
    {
        if ($filters['item_id']) {
            return [$filters['item_id']];
        }

        if ($filters['category_id']) {
            return Item::ofAccount()
                ->whereHas('categories', fn ($q) => $q->where('categories.id', $filters['category_id']))
                ->pluck('id')
                ->all();
        }

        return null;
    }

    /**
     * Builds the 4-branch UNION ALL (checkin, checkout, transfer_in,
     * transfer_out) scoped to exactly one project warehouse. Every other
     * method in this controller runs its own aggregate query against this
     * same subquery rather than re-deriving rows in PHP.
     */
    private function buildUnion(User $user, int $projectId, array $filters, ?array $itemIds)
    {
        $accountId = $user->account_id;
        $nonDraft = fn ($q, $col) => $q->where(fn ($i) => $i->whereNull($col)->orWhere($col, 0));

        $checkin = DB::table('checkins')
            ->join('checkin_items', 'checkins.id', '=', 'checkin_items.checkin_id')
            ->join('items', 'checkin_items.item_id', '=', 'items.id')
            ->leftJoin('units', 'checkin_items.unit_id', '=', 'units.id')
            ->leftJoin('contacts', 'checkins.contact_id', '=', 'contacts.id')
            ->leftJoin('warehouses', 'checkins.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('users', 'checkins.user_id', '=', 'users.id')
            ->where('checkins.account_id', $accountId)
            ->where('checkins.warehouse_id', $projectId)
            ->whereNull('checkins.deleted_at')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('checkin_items.item_id', $itemIds))
            ->when($filters['status'] === 'completed', fn ($q) => $nonDraft($q, 'checkins.draft'))
            ->when($filters['status'] === 'draft', fn ($q) => $q->where('checkins.draft', 1))
            ->when($filters['contact_id'], fn ($q, $v) => $q->where('checkins.contact_id', $v))
            ->when($filters['user_id'], fn ($q, $v) => $q->where('checkins.user_id', $v))
            ->when(! $filters['entire_project'] && $filters['date_from'], fn ($q) => $q->whereDate('checkins.date', '>=', $filters['date_from']))
            ->when(! $filters['entire_project'] && $filters['date_to'], fn ($q) => $q->whereDate('checkins.date', '<=', $filters['date_to']))
            ->selectRaw(
                "'checkin' as transaction_key,
                'Checked In' as transaction_type,
                checkins.id as transaction_id,
                checkins.reference as reference,
                DATE(checkins.date) as tx_date,
                checkin_items.item_id as item_id,
                items.name as item_name,
                items.code as item_code,
                checkin_items.quantity as quantity,
                COALESCE(units.name, units.code, '-') as unit,
                COALESCE(NULLIF(checkin_items.price, 0), items.price, 0) as unit_value,
                checkin_items.quantity * COALESCE(NULLIF(checkin_items.price, 0), items.price, 0) as total_value,
                COALESCE(contacts.name, '-') as from_label,
                COALESCE(warehouses.name, '-') as to_label,
                COALESCE(users.name, '-') as responsible_person,
                checkins.user_id as responsible_user_id,
                checkins.contact_id as contact_id,
                CASE WHEN COALESCE(checkins.draft, 0) = 1 THEN 'Draft' ELSE 'Completed' END as status"
            );

        $checkout = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->leftJoin('units', 'checkout_items.unit_id', '=', 'units.id')
            ->leftJoin('contacts', 'checkouts.contact_id', '=', 'contacts.id')
            ->leftJoin('warehouses', 'checkouts.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('users', 'checkouts.user_id', '=', 'users.id')
            ->where('checkouts.account_id', $accountId)
            ->where('checkouts.warehouse_id', $projectId)
            ->whereNull('checkouts.deleted_at')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('checkout_items.item_id', $itemIds))
            ->when($filters['status'] === 'completed', fn ($q) => $nonDraft($q, 'checkouts.draft'))
            ->when($filters['status'] === 'draft', fn ($q) => $q->where('checkouts.draft', 1))
            ->when($filters['contact_id'], fn ($q, $v) => $q->where('checkouts.contact_id', $v))
            ->when($filters['user_id'], fn ($q, $v) => $q->where('checkouts.user_id', $v))
            ->when(! $filters['entire_project'] && $filters['date_from'], fn ($q) => $q->whereDate('checkouts.date', '>=', $filters['date_from']))
            ->when(! $filters['entire_project'] && $filters['date_to'], fn ($q) => $q->whereDate('checkouts.date', '<=', $filters['date_to']))
            ->selectRaw(
                "'checkout' as transaction_key,
                'Checked Out' as transaction_type,
                checkouts.id as transaction_id,
                checkouts.reference as reference,
                DATE(checkouts.date) as tx_date,
                checkout_items.item_id as item_id,
                items.name as item_name,
                items.code as item_code,
                checkout_items.quantity as quantity,
                COALESCE(units.name, units.code, '-') as unit,
                COALESCE(NULLIF(checkout_items.price, 0), items.price, 0) as unit_value,
                checkout_items.quantity * COALESCE(NULLIF(checkout_items.price, 0), items.price, 0) as total_value,
                COALESCE(warehouses.name, '-') as from_label,
                COALESCE(contacts.name, 'Consumed on site') as to_label,
                COALESCE(users.name, '-') as responsible_person,
                checkouts.user_id as responsible_user_id,
                checkouts.contact_id as contact_id,
                CASE WHEN COALESCE(checkouts.draft, 0) = 1 THEN 'Draft' ELSE 'Completed' END as status"
            );

        $transferIn = DB::table('transfers')
            ->join('transfer_items', 'transfers.id', '=', 'transfer_items.transfer_id')
            ->join('items', 'transfer_items.item_id', '=', 'items.id')
            ->leftJoin('units', 'transfer_items.unit_id', '=', 'units.id')
            ->leftJoin('warehouses as from_wh', 'transfers.from_warehouse_id', '=', 'from_wh.id')
            ->leftJoin('warehouses as to_wh', 'transfers.to_warehouse_id', '=', 'to_wh.id')
            ->leftJoin('users', 'transfers.user_id', '=', 'users.id')
            ->where('transfers.account_id', $accountId)
            ->where('transfers.to_warehouse_id', $projectId)
            ->whereNull('transfers.deleted_at')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('transfer_items.item_id', $itemIds))
            ->when($filters['status'] === 'completed', fn ($q) => $nonDraft($q, 'transfers.draft'))
            ->when($filters['status'] === 'draft', fn ($q) => $q->where('transfers.draft', 1))
            ->when($filters['user_id'], fn ($q, $v) => $q->where('transfers.user_id', $v))
            ->when(! $filters['entire_project'] && $filters['date_from'], fn ($q) => $q->whereDate('transfers.date', '>=', $filters['date_from']))
            ->when(! $filters['entire_project'] && $filters['date_to'], fn ($q) => $q->whereDate('transfers.date', '<=', $filters['date_to']))
            ->selectRaw(
                "'transfer_in' as transaction_key,
                'Transfer In' as transaction_type,
                transfers.id as transaction_id,
                transfers.reference as reference,
                DATE(transfers.date) as tx_date,
                transfer_items.item_id as item_id,
                items.name as item_name,
                items.code as item_code,
                transfer_items.quantity as quantity,
                COALESCE(units.name, units.code, '-') as unit,
                COALESCE(items.price, 0) as unit_value,
                transfer_items.quantity * COALESCE(items.price, 0) as total_value,
                COALESCE(from_wh.name, '-') as from_label,
                COALESCE(to_wh.name, '-') as to_label,
                COALESCE(users.name, '-') as responsible_person,
                transfers.user_id as responsible_user_id,
                NULL as contact_id,
                CASE
                    WHEN COALESCE(transfers.draft, 0) = 1 THEN 'Draft'
                    WHEN transfers.received_at IS NOT NULL THEN 'Received'
                    ELSE 'Approved'
                END as status"
            );

        $transferOut = DB::table('transfers')
            ->join('transfer_items', 'transfers.id', '=', 'transfer_items.transfer_id')
            ->join('items', 'transfer_items.item_id', '=', 'items.id')
            ->leftJoin('units', 'transfer_items.unit_id', '=', 'units.id')
            ->leftJoin('warehouses as from_wh', 'transfers.from_warehouse_id', '=', 'from_wh.id')
            ->leftJoin('warehouses as to_wh', 'transfers.to_warehouse_id', '=', 'to_wh.id')
            ->leftJoin('users', 'transfers.user_id', '=', 'users.id')
            ->where('transfers.account_id', $accountId)
            ->where('transfers.from_warehouse_id', $projectId)
            ->whereNull('transfers.deleted_at')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('transfer_items.item_id', $itemIds))
            ->when($filters['status'] === 'completed', fn ($q) => $nonDraft($q, 'transfers.draft'))
            ->when($filters['status'] === 'draft', fn ($q) => $q->where('transfers.draft', 1))
            ->when($filters['user_id'], fn ($q, $v) => $q->where('transfers.user_id', $v))
            ->when(! $filters['entire_project'] && $filters['date_from'], fn ($q) => $q->whereDate('transfers.date', '>=', $filters['date_from']))
            ->when(! $filters['entire_project'] && $filters['date_to'], fn ($q) => $q->whereDate('transfers.date', '<=', $filters['date_to']))
            ->selectRaw(
                "'transfer_out' as transaction_key,
                'Transfer Out' as transaction_type,
                transfers.id as transaction_id,
                transfers.reference as reference,
                DATE(transfers.date) as tx_date,
                transfer_items.item_id as item_id,
                items.name as item_name,
                items.code as item_code,
                transfer_items.quantity as quantity,
                COALESCE(units.name, units.code, '-') as unit,
                COALESCE(items.price, 0) as unit_value,
                transfer_items.quantity * COALESCE(items.price, 0) as total_value,
                COALESCE(from_wh.name, '-') as from_label,
                COALESCE(to_wh.name, '-') as to_label,
                COALESCE(users.name, '-') as responsible_person,
                transfers.user_id as responsible_user_id,
                NULL as contact_id,
                CASE
                    WHEN COALESCE(transfers.draft, 0) = 1 THEN 'Draft'
                    WHEN transfers.received_at IS NOT NULL THEN 'Received'
                    ELSE 'Approved'
                END as status"
            );

        return $checkin->unionAll($checkout)->unionAll($transferIn)->unionAll($transferOut);
    }

    private function filteredSub($union, array $filters)
    {
        return DB::query()
            ->fromSub($union, 'tx')
            ->when($filters['transaction_type'], fn ($q, $v) => $q->where('tx.transaction_key', $v))
            ->when($filters['search'], function ($q) use ($filters) {
                $search = trim((string) $filters['search']);
                $q->where(function ($inner) use ($search) {
                    $inner->where('tx.reference', 'like', "%{$search}%")
                        ->orWhere('tx.item_name', 'like', "%{$search}%")
                        ->orWhere('tx.from_label', 'like', "%{$search}%")
                        ->orWhere('tx.to_label', 'like', "%{$search}%")
                        ->orWhere('tx.responsible_person', 'like', "%{$search}%");
                });
            });
    }

    private const SORTABLE_COLUMNS = [
        'date' => 'tx_date',
        'item_name' => 'item_name',
        'quantity' => 'quantity',
        'total_value' => 'total_value',
        'transaction_type' => 'transaction_type',
    ];

    private function buildLedgerPage($union, array $filters): array
    {
        $perPage = 25;
        $base = $this->filteredSub($union, $filters);

        $total = (clone $base)->count();

        $sortColumn = self::SORTABLE_COLUMNS[$filters['sort_by'] ?? ''] ?? 'tx_date';
        $sortDir = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $rows = $base
            ->orderBy($sortColumn, $sortDir)
            ->orderByDesc('transaction_id')
            ->forPage($filters['page'], $perPage)
            ->get()
            ->map(fn ($r) => [
                'transaction_key' => $r->transaction_key,
                'transaction_type' => $r->transaction_type,
                'transaction_id' => (int) $r->transaction_id,
                'reference' => $r->reference,
                'date' => $r->tx_date,
                'item_id' => (int) $r->item_id,
                'item_name' => $r->item_name,
                'item_code' => $r->item_code,
                'quantity' => (float) $r->quantity,
                'unit' => $r->unit,
                'unit_value' => (float) $r->unit_value,
                'total_value' => (float) $r->total_value,
                'from' => $r->from_label,
                'to' => $r->to_label,
                'responsible_person' => $r->responsible_person,
                'status' => $r->status,
            ])->values();

        return [
            'data' => $rows,
            'current_page' => $filters['page'],
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Summary cards: total quantity BY UNIT (never combined across
     * incompatible units) and total value, per transaction type, plus a net
     * movement per (item, unit) pair.
     */
    private function buildSummary($union): array
    {
        $byType = DB::query()->fromSub($union, 'tx')
            ->select('transaction_key', 'unit', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(total_value) as value'))
            ->groupBy('transaction_key', 'unit')
            ->get();

        $buckets = ['checkin' => [], 'checkout' => [], 'transfer_in' => [], 'transfer_out' => []];
        $bucketValue = ['checkin' => 0.0, 'checkout' => 0.0, 'transfer_in' => 0.0, 'transfer_out' => 0.0];

        foreach ($byType as $row) {
            $buckets[$row->transaction_key][] = ['unit' => $row->unit, 'quantity' => (float) $row->qty];
            $bucketValue[$row->transaction_key] += (float) $row->value;
        }

        // Transfer Out is material that has already left this project for
        // another one — it's shown as its own card for visibility, but
        // doesn't belong in "how much material value this project
        // represents" any more than stock sitting at the destination
        // project would. Checked In, Checked Out (consumed/used here), and
        // Transfer In are all still this project's own material history.
        $grandTotal = $bucketValue['checkin'] + $bucketValue['checkout'] + $bucketValue['transfer_in'];

        $netByUnit = DB::query()->fromSub($union, 'tx')
            ->select(
                'unit',
                DB::raw("SUM(CASE WHEN transaction_key IN ('checkin','transfer_in') THEN quantity WHEN transaction_key IN ('checkout','transfer_out') THEN -quantity ELSE 0 END) as net_qty")
            )
            ->groupBy('unit')
            ->get()
            ->map(fn ($r) => ['unit' => $r->unit, 'net_quantity' => (float) $r->net_qty])
            ->values();

        return [
            'checked_in' => ['by_unit' => $buckets['checkin'], 'value' => $bucketValue['checkin']],
            'checked_out' => ['by_unit' => $buckets['checkout'], 'value' => $bucketValue['checkout']],
            'transfer_in' => ['by_unit' => $buckets['transfer_in'], 'value' => $bucketValue['transfer_in']],
            'transfer_out' => ['by_unit' => $buckets['transfer_out'], 'value' => $bucketValue['transfer_out']],
            'grand_total_value' => $grandTotal,
            'net_movement' => $netByUnit,
        ];
    }

    private function buildItemSummary($union): array
    {
        return DB::query()->fromSub($union, 'tx')
            ->select(
                'item_id',
                'item_name',
                'item_code',
                'unit',
                DB::raw("SUM(CASE WHEN transaction_key = 'checkin' THEN quantity ELSE 0 END) as checked_in"),
                DB::raw("SUM(CASE WHEN transaction_key = 'checkout' THEN quantity ELSE 0 END) as checked_out"),
                DB::raw("SUM(CASE WHEN transaction_key = 'transfer_in' THEN quantity ELSE 0 END) as transfer_in"),
                DB::raw("SUM(CASE WHEN transaction_key = 'transfer_out' THEN quantity ELSE 0 END) as transfer_out"),
                DB::raw('SUM(total_value) as total_value')
            )
            ->groupBy('item_id', 'item_name', 'item_code', 'unit')
            ->orderBy('item_name')
            ->get()
            ->map(fn ($r) => [
                'item_id' => (int) $r->item_id,
                'item_name' => $r->item_name,
                'item_code' => $r->item_code,
                'unit' => $r->unit,
                'checked_in' => (float) $r->checked_in,
                'checked_out' => (float) $r->checked_out,
                'transfer_in' => (float) $r->transfer_in,
                'transfer_out' => (float) $r->transfer_out,
                'net_movement' => (float) $r->checked_in + (float) $r->transfer_in - (float) $r->checked_out - (float) $r->transfer_out,
                'total_value' => (float) $r->total_value,
            ])
            ->values()
            ->all();
    }

    /**
     * One row per underlying transaction/item — not aggregated across
     * references — so each row can carry a clickable reference number and
     * the item it actually moved, per the "add Reference Number Column
     * which is Clickable to open that transaction and Add ITEM Column"
     * requirement. Each union row is already the finest grain (one
     * transaction, one item), so no GROUP BY/SUM is needed here.
     */
    private function buildMovementSummary($union): array
    {
        return DB::query()->fromSub($union, 'tx')
            ->select(
                'transaction_key', 'transaction_type', 'transaction_id', 'reference',
                'item_id', 'item_name', 'item_code',
                'from_label', 'to_label', 'unit', 'quantity', 'total_value'
            )
            ->orderByDesc('total_value')
            ->get()
            ->map(fn ($r) => [
                'transaction_key' => $r->transaction_key,
                'transaction_type' => $r->transaction_type,
                'transaction_id' => (int) $r->transaction_id,
                'reference' => $r->reference,
                'item_id' => (int) $r->item_id,
                'item_name' => $r->item_name,
                'item_code' => $r->item_code,
                'from' => $r->from_label,
                'to' => $r->to_label,
                'unit' => $r->unit,
                'quantity' => (float) $r->quantity,
                'value' => (float) $r->total_value,
            ])
            ->values()
            ->all();
    }

    /**
     * The group total (e.g. "18 Tons" for 3 different steel items) doesn't
     * say how much of each item made up that number, and a hover-only
     * tooltip can't be printed. So each group also carries an "items"
     * breakdown — one entry per item with its own quantity/value — that the
     * UI renders as always-visible sub-rows instead of hidden-on-hover text.
     */
    private function buildSupplierSummary($union): array
    {
        $itemBreakdown = DB::query()->fromSub($union, 'tx')
            ->where('transaction_key', 'checkin')
            ->whereNotNull('contact_id')
            ->select(
                'contact_id', 'unit', 'item_id', 'item_name', 'item_code',
                DB::raw('SUM(quantity) as qty'), DB::raw('SUM(total_value) as value')
            )
            ->groupBy('contact_id', 'unit', 'item_id', 'item_name', 'item_code')
            ->orderByDesc('qty')
            ->get()
            ->groupBy(fn ($r) => $r->contact_id.'|'.$r->unit);

        return DB::query()->fromSub($union, 'tx')
            ->where('transaction_key', 'checkin')
            ->whereNotNull('contact_id')
            ->select(
                'contact_id', 'from_label as supplier_name',
                DB::raw('COUNT(DISTINCT item_id) as item_count'),
                'unit', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(total_value) as value')
            )
            ->groupBy('contact_id', 'from_label', 'unit')
            ->orderByDesc('value')
            ->get()
            ->map(function ($r) use ($itemBreakdown) {
                $items = ($itemBreakdown->get($r->contact_id.'|'.$r->unit) ?? collect())
                    ->map(fn ($i) => [
                        'item_id' => (int) $i->item_id,
                        'item_name' => $i->item_name,
                        'item_code' => $i->item_code,
                        'quantity' => (float) $i->qty,
                        'value' => (float) $i->value,
                    ])
                    ->values()
                    ->all();

                return [
                    'contact_id' => (int) $r->contact_id,
                    'supplier_name' => $r->supplier_name,
                    'item_count' => (int) $r->item_count,
                    'items' => $items,
                    'unit' => $r->unit,
                    'quantity' => (float) $r->qty,
                    'value' => (float) $r->value,
                ];
            })
            ->values()
            ->all();
    }

    private function usersInvolved($union): array
    {
        return DB::query()->fromSub($union, 'tx')
            ->whereNotNull('responsible_user_id')
            ->select('responsible_user_id as id', 'responsible_person as name')
            ->distinct()
            ->orderBy('responsible_person')
            ->get()
            ->all();
    }

    private function contactsInvolved($union): array
    {
        // The contact's real name sits in a different column depending on
        // transaction type: checkin's "from" IS the supplier contact, but
        // checkout's "from" is the source warehouse — checkout's contact
        // (who it was issued to) is in "to" instead. Pairing contact_id with
        // from_label unconditionally (the previous version) mislabelled
        // every checkout row with the project's own warehouse name, so the
        // same handful of real contacts appeared to be "duplicated" under
        // whatever warehouse name each checkout happened to carry.
        return DB::query()->fromSub($union, 'tx')
            ->whereNotNull('contact_id')
            ->selectRaw("contact_id as id, MIN(CASE WHEN transaction_key = 'checkout' THEN to_label ELSE from_label END) as name")
            ->groupBy('contact_id')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * Opening stock (as of date_from) = current live Stock balance minus
     * this same report's own movement sum for everything from date_from
     * onward. Expected closing = opening + movement within [date_from,
     * date_to]. Variance is only meaningful when date_to reaches "now" (a
     * report for a past window can't be compared to today's live balance),
     * so it's omitted otherwise.
     */
    private function buildReconciliation(User $user, int $projectId, ?array $itemIds, array $filters): array
    {
        // Scoped to items that actually have a stock record at this project
        // (same convention as MaterialValueController::availableItems()) —
        // otherwise this pulls every item in the entire account catalog and
        // shows hundreds of all-zero rows for materials never seen here.
        $itemsQuery = Item::ofAccount()
            ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))
            ->whereHas('allStock', fn ($q) => $q->where('warehouse_id', $projectId));
        $items = $itemsQuery->orderBy('name')->get(['id', 'name', 'code', 'unit_id'])->load('unit:id,name,code');

        if ($items->isEmpty()) {
            return [];
        }

        $currentStocks = Stock::where('warehouse_id', $projectId)
            ->whereIn('item_id', $items->pluck('id'))
            ->pluck('quantity', 'item_id');

        $noDateFilter = $filters['entire_project'] || ! $filters['date_from'];
        $today = now()->toDateString();
        $dateTo = $filters['date_to'] ?: $today;
        $canShowVariance = $dateTo >= $today;

        // Movement strictly since date_from (or all-time when unbounded) —
        // used both as "this period's movement" and, subtracted from the
        // current balance, to derive the opening balance.
        $sinceFromUnion = $this->buildUnion($user, $projectId, [
            'status' => null, 'contact_id' => null, 'user_id' => null,
            'entire_project' => $noDateFilter, 'date_from' => $filters['date_from'], 'date_to' => null,
        ], $itemIds);

        $sinceFrom = DB::query()->fromSub($sinceFromUnion, 'tx')
            ->select('item_id', DB::raw("SUM(CASE WHEN transaction_key IN ('checkin','transfer_in') THEN quantity WHEN transaction_key IN ('checkout','transfer_out') THEN -quantity ELSE 0 END) as net_qty"))
            ->groupBy('item_id')
            ->pluck('net_qty', 'item_id');

        $periodUnion = $this->buildUnion($user, $projectId, [
            'status' => null, 'contact_id' => null, 'user_id' => null,
            'entire_project' => $noDateFilter, 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
        ], $itemIds);

        $periodMovement = DB::query()->fromSub($periodUnion, 'tx')
            ->select('item_id', DB::raw("SUM(CASE WHEN transaction_key IN ('checkin','transfer_in') THEN quantity WHEN transaction_key IN ('checkout','transfer_out') THEN -quantity ELSE 0 END) as net_qty"))
            ->groupBy('item_id')
            ->pluck('net_qty', 'item_id');

        return $items->map(function ($item) use ($currentStocks, $sinceFrom, $periodMovement, $canShowVariance) {
            $current = (float) ($currentStocks[$item->id] ?? 0);
            $movementSinceFrom = (float) ($sinceFrom[$item->id] ?? 0);
            $opening = $current - $movementSinceFrom;
            $periodNet = (float) ($periodMovement[$item->id] ?? 0);
            $expectedClosing = $opening + $periodNet;

            return [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'item_code' => $item->code,
                'unit' => $item->unit?->name ?? $item->unit?->code ?? '-',
                'opening_stock' => $opening,
                'period_movement' => $periodNet,
                'expected_closing' => $expectedClosing,
                'actual_current_stock' => $canShowVariance ? $current : null,
                'variance' => $canShowVariance ? round($current - $expectedClosing, 4) : null,
            ];
        })
            // Skip items with nothing to reconcile (never stocked, never
            // moved here) — otherwise this shows a row for every item in
            // the whole account catalog, most of them entirely zero.
            ->filter(fn ($row) => abs($row['opening_stock']) > 0.0001 || abs($row['period_movement']) > 0.0001 || abs($row['expected_closing']) > 0.0001)
            ->values()
            ->all();
    }

    /**
     * Reuses ReportController::boqMaterialCompliance()'s exact "issued
     * quantity" formula (Checkout only, quantity * COALESCE(line price,
     * item price, 0)) so these numbers never disagree with that existing
     * report.
     */
    private function buildBoqTieIn(User $user, int $projectId, ?array $itemIds): array
    {
        if (! Schema::hasTable('boq_material_compliances')) {
            return [];
        }

        $boqLines = BoqMaterialCompliance::query()
            ->where('account_id', $user->account_id)
            ->where('project_warehouse_id', $projectId)
            ->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
            ->get(['item_id', 'boq_quantity', 'unit_price'])
            ->keyBy('item_id');

        if ($boqLines->isEmpty()) {
            return [];
        }

        $issued = DB::table('checkouts')
            ->join('checkout_items', 'checkouts.id', '=', 'checkout_items.checkout_id')
            ->join('items', 'checkout_items.item_id', '=', 'items.id')
            ->where('checkouts.account_id', $user->account_id)
            ->where('checkouts.warehouse_id', $projectId)
            ->whereNull('checkouts.deleted_at')
            ->where(fn ($q) => $q->whereNull('checkouts.draft')->orWhere('checkouts.draft', 0))
            ->whereIn('checkout_items.item_id', $boqLines->keys())
            ->groupBy('checkout_items.item_id')
            ->select('checkout_items.item_id', DB::raw('SUM(checkout_items.quantity) as issued_quantity'), DB::raw('SUM(checkout_items.quantity * COALESCE(checkout_items.price, items.price, 0)) as issued_cost'))
            ->get()
            ->keyBy('item_id');

        $items = Item::whereIn('id', $boqLines->keys())->get(['id', 'name', 'code', 'unit_id'])->load('unit:id,name,code')->keyBy('id');

        return $boqLines->map(function ($boq, $itemId) use ($issued, $items) {
            $item = $items->get($itemId);
            $usedQty = (float) optional($issued->get($itemId))->issued_quantity;

            return [
                'item_id' => (int) $itemId,
                'item_name' => $item?->name,
                'item_code' => $item?->code,
                'unit' => $item?->unit?->name ?? $item?->unit?->code ?? '-',
                'boq_quantity' => (float) $boq->boq_quantity,
                'used_quantity' => $usedQty,
                'remaining_quantity' => (float) $boq->boq_quantity - $usedQty,
                'unit_price' => (float) $boq->unit_price,
                'material_value' => (float) optional($issued->get($itemId))->issued_cost,
            ];
        })->values()->all();
    }

    private function activityHistory(string $modelClass, int $modelId): array
    {
        if (! class_exists(\Spatie\Activitylog\Models\Activity::class)) {
            return [];
        }

        return \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', $modelClass)
            ->where('subject_id', $modelId)
            ->with('causer:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'description' => $a->description,
                'causer' => $a->causer?->name ?? __('System'),
                'created_at' => $a->created_at?->toDateTimeString(),
                'properties' => $a->properties,
            ])
            ->values()
            ->all();
    }

    /**
     * Print/Download must reflect whichever tab is on screen, not always the
     * raw Ledger listing — Print/Download previously ignored filters['view']
     * entirely, so switching to e.g. Supplier Summary and printing still
     * produced the Ledger table. Each view branch below reuses the exact
     * same builder method the Inertia page itself uses, so the printout can
     * never drift from what's on screen.
     */
    private function export(Request $request, $union, array $filters, $projects, ?array $itemIds, string $output)
    {
        $user = $request->user();
        $project = $filters['project_warehouse_id'] ? $projects->firstWhere('id', $filters['project_warehouse_id']) : null;

        $view = in_array($filters['view'] ?? null, ['ledger', 'item_summary', 'movement_summary', 'supplier_summary', 'reconciliation', 'boq'], true)
            ? $filters['view']
            : 'ledger';

        $viewLabels = [
            'ledger' => __('Ledger'),
            'item_summary' => __('Item Summary'),
            'movement_summary' => __('Source / Destination'),
            'supplier_summary' => __('Supplier Summary'),
            'reconciliation' => __('Stock Reconciliation'),
            'boq' => __('BOQ'),
        ];

        $viewData = [
            'title' => __('Project Material Ledger'),
            'viewLabel' => $viewLabels[$view],
            'view' => $view,
            'companyName' => optional($user->account)->name,
            'projectName' => $project?->name,
            'siteName' => $project?->name,
            'periodFrom' => $filters['entire_project'] ? __('Entire Project') : $filters['date_from'],
            'periodTo' => $filters['entire_project'] ? '' : $filters['date_to'],
            'generatedBy' => $user->name,
            'generatedAt' => now(),
            'autoPrint' => $output === 'print',
            'summary' => $union ? $this->buildSummary($union) : null,
            'rows' => collect(),
            'itemSummary' => [],
            'movementSummary' => [],
            'supplierSummary' => [],
            'reconciliation' => [],
            'boq' => [],
        ];

        switch ($view) {
            case 'item_summary':
                $viewData['itemSummary'] = $union ? $this->buildItemSummary($union) : [];
                $recordCount = count($viewData['itemSummary']);
                break;
            case 'movement_summary':
                $viewData['movementSummary'] = $union ? $this->buildMovementSummary($union) : [];
                $recordCount = count($viewData['movementSummary']);
                break;
            case 'supplier_summary':
                $viewData['supplierSummary'] = $union ? $this->buildSupplierSummary($union) : [];
                $recordCount = count($viewData['supplierSummary']);
                break;
            case 'reconciliation':
                $viewData['reconciliation'] = $filters['project_warehouse_id'] ? $this->buildReconciliation($user, $filters['project_warehouse_id'], $itemIds, $filters) : [];
                $recordCount = count($viewData['reconciliation']);
                break;
            case 'boq':
                $viewData['boq'] = $filters['project_warehouse_id'] ? $this->buildBoqTieIn($user, $filters['project_warehouse_id'], $itemIds) : [];
                $recordCount = count($viewData['boq']);
                break;
            default:
                $sortColumn = self::SORTABLE_COLUMNS[$filters['sort_by'] ?? ''] ?? 'tx_date';
                $sortDir = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
                $viewData['rows'] = $union ? $this->filteredSub($union, $filters)
                    ->orderBy($sortColumn, $sortDir)
                    ->orderByDesc('transaction_id')
                    ->limit(5000)
                    ->get() : collect();
                $recordCount = $viewData['rows']->count();
        }

        log_activity(
            'Project Material Ledger ' . ($output === 'pdf' ? 'Downloaded' : 'Printed') . ' (' . $viewLabels[$view] . ')',
            ['project_warehouse_id' => $filters['project_warehouse_id'], 'view' => $view, 'count' => $recordCount],
            $user,
            'Project Material Ledger'
        );

        if ($output === 'pdf') {
            return Pdf::loadView('reports.project-material-ledger-pdf', $viewData)
                ->setPaper('a4', 'landscape')
                ->download('project-material-ledger-' . $view . '-' . now()->format('Ymd-His') . '.pdf');
        }

        return view('reports.project-material-ledger-pdf', $viewData);
    }
}
