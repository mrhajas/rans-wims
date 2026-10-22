<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\LoginLog;
use Inertia\Inertia;
use App\Models\Checkin;
use App\Models\Contact;
use App\Models\Activity;
use App\Models\Checkout;
use App\Models\DownloadPrintLog;
use App\Models\Transfer;
use App\Models\Adjustment;
use App\Models\MaterialRequest;
use Illuminate\Http\Request;
use App\Actions\Tec\ChartData;
use App\Http\Resources\ActivityCollection;
use App\Models\Warehouse;
use App\Models\Stock;
use App\Models\User;
use App\Services\DashboardPeriodResolver;
use App\Services\WeatherService;
use App\Services\WimsConnect\ChannelAvailability;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->requirePermissions(['activity' => 'read-activity']);
    }

    /**
     * Presence ping from the browser. Every signed-in user sends it (not only attendance users), but only while the tab
     * is visible and they have touched the page in the last few minutes — so an abandoned tab drifts to Idle instead of
     * looking online forever.
     */
    public function heartbeat(Request $request)
    {
        $request->user()->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json(['ok' => true]);
    }

    /** Seconds since the last heartbeat: <= ONLINE_SECONDS is "online", <= IDLE_SECONDS is "idle", anything older "offline". */
    private const ONLINE_SECONDS = 180;
    private const IDLE_SECONDS = 1800;

    /**
     * Everyone's presence, from the heartbeat the browser sends while the tab is visible and being used
     * (users.last_seen_at; cleared on logout):
     *   online  - heartbeat within the last 3 minutes (or it is you: you are looking at the page)
     *   idle    - last heartbeat 3-30 minutes ago (tab open but untouched / hidden)
     *   offline - older than 30 minutes, or never seen; shown with a "last seen" time
     * Gated by the view-online-users permission (on for every role by default).
     */
    public function onlineUsers(Request $request)
    {
        $me = $request->user();
        abort_unless($me->can('view-online-users'), 403);

        $photos = User::linkedStaffPhotos();
        $now = now();
        // who can be started a chat with from here: only people who use WIMSChat, and only when I may start chats myself (the list is cached for a minute)
        $chat = app(\App\Services\WimsChat\WimsChatService::class);
        $chatIds = ($me->can('view-wimschat') && $me->can('start-wimschat-conversations') && $chat->feature('wimschat_direct', $me))
            ? array_flip($chat->permittedUserIds((int) $me->account_id)) : [];
        $users = User::query()
            ->with('roles:id,name')
            ->where('is_active', true)
            ->orderByRaw('last_seen_at IS NULL')
            ->orderByDesc('last_seen_at')
            ->get(['id', 'name', 'username', 'profile_photo_path', 'last_seen_at']);

        $rows = $users->map(function (User $u) use ($photos, $me, $now, $chatIds) {
            $isMe = $u->id === $me->id;
            $age = $u->last_seen_at ? $u->last_seen_at->diffInSeconds($now, false) : null;
            $presence = $isMe || ($age !== null && $age <= self::ONLINE_SECONDS) ? 'online'
                : ($age !== null && $age <= self::IDLE_SECONDS ? 'idle' : 'offline');

            return [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'roles' => $u->roles->pluck('name')->values()->all(),
                'photo' => ($photos[$u->id] ?? null) || $u->profile_photo_path ? $u->profile_photo_url : null,
                'presence' => $presence,
                'last_seen_label' => $isMe && $presence === 'online' ? __('just now') : ($u->last_seen_at ? $u->last_seen_at->diffForHumans() : null),
                'is_me' => $isMe,
                'can_chat' => ! $isMe && isset($chatIds[$u->id]),
            ];
        })->sort(function ($a, $b) {
            // people with a profile photo first, then online > idle > offline; ties keep the query order (most recently seen first)
            $rank = ['online' => 0, 'idle' => 1, 'offline' => 2];

            return [$a['photo'] ? 0 : 1, $rank[$a['presence']]] <=> [$b['photo'] ? 0 : 1, $rank[$b['presence']]];
        })->values();

        return response()->json([
            'users' => $rows,
            'online' => $rows->where('presence', 'online')->count(),
            'idle' => $rows->where('presence', 'idle')->count(),
            'offline' => $rows->where('presence', 'offline')->count(),
        ]);
    }

    public function activity(Request $request)
    {
        $filters = $request->only('search', 'from', 'to', 'user_id', 'module');

        $query = Activity::filter($filters)->with('causer')->orderByDesc('id');

        if ($request->boolean('print')) {
            $activities = $query->limit(2000)->get();
            $userName = $request->filled('user_id')
                ? optional(User::find($request->input('user_id')))->name
                : null;

            return view('activity-print', [
                'activities' => $activities,
                'filters'    => $filters,
                'userName'   => $userName,
                'companyName' => optional(auth()->user()->account ?? null)->name,
                'generatedBy' => optional(auth()->user())->name,
                'generatedAt' => now(),
                'isPdf' => false,
            ]);
        }

        return Inertia::render('Activity/Index', [
            'filters'    => $filters,
            'activities' => new ActivityCollection($query->paginate()->withQueryString()),
            'users'      => User::orderBy('name')->get(['id', 'name']),
            'modules'    => \App\Support\ActivityModules::options(),
        ]);
    }

    public function form(Request $request)
    {
        return $request->validate([
            'month' => 'nullable|integer|date_format:n',
            'year'  => 'nullable|integer|date_format:Y',
        ]);
    }

    public function index(Request $request)
    {


        // $warehouses = Warehouse::all();

        // $listing = $warehouses->map(function ($warehouse) {
        //     // Query stock items for this warehouse
        //     $stockItems = Stock::where('warehouse_id', $warehouse->id)
        //         ->with('item') // Assuming Stock has a relationship with Item model
        //         ->get();

        //     // Calculate total amount in store for this warehouse
        //     $totalAmountInStore = $stockItems->sum(function ($stockItem) {
        //         return $stockItem->quantity * $stockItem->item->price; // Assuming price is in Item model
        //     });

        //     return [
        //         'name' => $warehouse->name, // Assuming Warehouse has a 'name' column
        //         'amount' => $totalAmountInStore,
        //     ];
        // });

        $listing = $this->buildWarehouseStockValueListing();



        $this->form($request);

        $resolver = new DashboardPeriodResolver();
        [$selectedMonth, $selectedYear] = $resolver->resolve($request);

        $selectedStart = Carbon::create($selectedYear, $selectedMonth, 1)->startOfMonth();
        $selectedEnd = $selectedStart->copy()->endOfMonth();
        $previousStart = $selectedStart->copy()->subMonth()->startOfMonth();
        $previousEnd = $selectedStart->copy()->subMonth()->endOfMonth();

        $selectedRange = [$selectedStart->copy()->startOfDay(), $selectedEnd->copy()->endOfDay()];
        $previousRange = [$previousStart->copy()->startOfDay(), $previousEnd->copy()->endOfDay()];

        $user = $request->user();
        $roleNames = $user ? $user->roles->pluck('name')->all() : [];
        $isSiteEngineer = ! empty(array_intersect(User::SITE_ENGINEER_ROLES, $roleNames));

        $assignedWarehouseIds = [];
        if ($user) {
            $assignedWarehouseIds = $user->warehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id)->all();
            if (empty($assignedWarehouseIds) && $user->warehouse_id) {
                $assignedWarehouseIds = [(int) $user->warehouse_id];
            }
        }

        // For Site Engineer, respect top-bar warehouse selection:
        // selected warehouse => that one only, All Warehouses => assigned warehouses.
        $siteEngineerWarehouseScopeIds = $isSiteEngineer ? warehouse_scope_ids() : null;
        if ($isSiteEngineer && is_array($siteEngineerWarehouseScopeIds)) {
            $siteEngineerWarehouseScopeIds = array_values(array_map('intval', $siteEngineerWarehouseScopeIds));
        }
        if ($isSiteEngineer && ! is_array($siteEngineerWarehouseScopeIds)) {
            $siteEngineerWarehouseScopeIds = $assignedWarehouseIds;
        }

        $applyWarehouseScope = function ($query, string $column = 'warehouse_id') use ($isSiteEngineer, $siteEngineerWarehouseScopeIds) {
            if (! $isSiteEngineer) {
                return $query;
            }

            if (empty($siteEngineerWarehouseScopeIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn($column, $siteEngineerWarehouseScopeIds);
        };

        $applyTransferScope = function ($query) use ($isSiteEngineer, $siteEngineerWarehouseScopeIds) {
            if (! $isSiteEngineer) {
                return $query;
            }

            if (empty($siteEngineerWarehouseScopeIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function ($q) use ($siteEngineerWarehouseScopeIds) {
                $q->whereIn('from_warehouse_id', $siteEngineerWarehouseScopeIds)
                    ->orWhereIn('to_warehouse_id', $siteEngineerWarehouseScopeIds);
            });
        };

        $scopeUserId = $isSiteEngineer ? optional($user)->id : null;

        // $topProducts = StockTrail::select(['item_id'])->selectRaw('SUM(quantity) as y')
        //     ->whereBetween('created_at', [$selectedStart, $selectedEnd])
        //     ->with('item:id,code')->orderByRaw('SUM(quantity) desc')->groupBy('item_id')
        //     ->take(10)->get()->transform(fn ($i) => ['name' => $i->item->code, 'y' => +$i->y]);
        $data = new \stdClass();

        $itemsQuery = Stock::query()->where('quantity', '>', 0);
        $applyWarehouseScope($itemsQuery);
        $data->items = $isSiteEngineer ? $itemsQuery->count() : Item::count();

        $contactsQuery = Contact::query();
        if ($isSiteEngineer) {
            if (empty($siteEngineerWarehouseScopeIds)) {
                $contactsQuery->whereRaw('1 = 0');
            } else {
                $contactsQuery->where(function ($q) use ($siteEngineerWarehouseScopeIds) {
                    $q->whereHas('checkins', function ($qq) use ($siteEngineerWarehouseScopeIds) {
                        $qq->withoutGlobalScope('mine')->whereIn('warehouse_id', $siteEngineerWarehouseScopeIds);
                    })->orWhereHas('checkouts', function ($qq) use ($siteEngineerWarehouseScopeIds) {
                        $qq->withoutGlobalScope('mine')->whereIn('warehouse_id', $siteEngineerWarehouseScopeIds);
                    });
                });
            }
        }
        $data->contacts = $contactsQuery->count();

        $checkinsQuery = Checkin::active()->whereBetween('date', $selectedRange);
        $applyWarehouseScope($checkinsQuery);
        if ($isSiteEngineer) {
            $checkinsQuery->withoutGlobalScope('mine');
        }
        $data->checkins = $checkinsQuery->count();

        $checkoutsQuery = Checkout::active()->whereBetween('date', $selectedRange);
        $applyWarehouseScope($checkoutsQuery);
        if ($isSiteEngineer) {
            $checkoutsQuery->withoutGlobalScope('mine');
        }
        $data->checkouts = $checkoutsQuery->count();

        $transfersQuery = Transfer::active()->whereBetween('date', $selectedRange);
        $applyTransferScope($transfersQuery);
        if ($isSiteEngineer) {
            $transfersQuery->withoutGlobalScope('mine');
        }
        $data->transfers = $transfersQuery->count();

        $adjustmentsQuery = Adjustment::active()->whereBetween('date', $selectedRange);
        $applyWarehouseScope($adjustmentsQuery);
        if ($isSiteEngineer) {
            $adjustmentsQuery->withoutGlobalScope('mine');
        }
        $data->adjustments = $adjustmentsQuery->count();

        $materialRequestsQuery = MaterialRequest::whereBetween('date_requested', $selectedRange);
        $applyWarehouseScope($materialRequestsQuery);
        if ($user) {
            \App\Support\MaterialRequestVisibility::scopeQueryFor($materialRequestsQuery, $user);
        }
        $data->material_requests = $materialRequestsQuery->count();

        $previousCheckinsQuery = Checkin::active()->whereBetween('date', $previousRange);
        $applyWarehouseScope($previousCheckinsQuery);
        if ($isSiteEngineer) {
            $previousCheckinsQuery->withoutGlobalScope('mine');
        }
        $data->previous_checkins = $previousCheckinsQuery->count();

        $previousCheckoutsQuery = Checkout::active()->whereBetween('date', $previousRange);
        $applyWarehouseScope($previousCheckoutsQuery);
        if ($isSiteEngineer) {
            $previousCheckoutsQuery->withoutGlobalScope('mine');
        }
        $data->previous_checkouts = $previousCheckoutsQuery->count();

        $data->login_logs = LoginLog::whereBetween('attempted_at', $selectedRange)
            ->when($scopeUserId, fn ($q, $userId) => $q->where('user_id', $userId))
            ->count();

        $data->system_logs = Activity::whereBetween('created_at', $selectedRange)
            ->when($scopeUserId, function ($q, $userId) {
                $q->where('causer_type', User::class)->where('causer_id', $userId);
            })
            ->count();

        $data->download_print_logs = DownloadPrintLog::whereBetween('performed_at', $selectedRange)
            ->when($scopeUserId, fn ($q, $userId) => $q->where('user_id', $userId))
            ->count();

        $deletedCheckins = Checkin::onlyTrashed()->withoutGlobalScope('mine')->whereBetween('deleted_at', $selectedRange);
        $applyWarehouseScope($deletedCheckins);

        $deletedCheckouts = Checkout::onlyTrashed()->withoutGlobalScope('mine')->whereBetween('deleted_at', $selectedRange);
        $applyWarehouseScope($deletedCheckouts);

        $deletedTransfers = Transfer::onlyTrashed()->withoutGlobalScope('mine')->whereBetween('deleted_at', $selectedRange);
        $applyTransferScope($deletedTransfers);

        $deletedAdjustments = Adjustment::onlyTrashed()->withoutGlobalScope('mine')->whereBetween('deleted_at', $selectedRange);
        $applyWarehouseScope($deletedAdjustments);

        $deletedItems = Item::onlyTrashed()->whereBetween('deleted_at', $selectedRange)
            ->when($scopeUserId, fn ($q, $userId) => $q->where('deleted_by', $userId));

        $data->deleted_records = $deletedCheckins->count()
            + $deletedCheckouts->count()
            + $deletedTransfers->count()
            + $deletedAdjustments->count()
            + $deletedItems->count();
        $data->total_records = collect([
            $data->checkins,
            $data->checkouts,
            $data->transfers,
            $data->adjustments,
            $data->material_requests,
        ])->sum();
        $chart = new ChartData($request->get('month'), $request->get('year'));

        return Inertia::render('Dashboard/Index', [
            'canViewOnlineUsers' => $request->user()->can('view-online-users'),
            // Staff contracts that have run out or end within 60 days (the number on the contracts button of the first card)
            'contractsDue' => fn () => $request->user()->can('read-staff-contracts')
                ? \App\Models\StaffContract::query()->where('status', \App\Models\StaffContract::STATUS_ACTIVE)->whereNotNull('end_date')
                    ->whereDate('end_date', '<=', now()->addDays(\App\Models\StaffContract::SOON_DAYS)->toDateString())->count()
                : 0,
            'data'         => $data,
            'top_products' => $chart->topProducts(),
            'listing' => $listing,
            'chart'        => ['year' => $chart->year(), 'month' => $chart->month()],
            'weather'      => WeatherService::current(),
            'shortcutCardOrder' => $request->user()?->extra_attributes['dashboard_shortcut_card_order'] ?? [],
            'channelLive' => ChannelAvailability::liveMap(),
        ]);
    }

    /**
     * Persists this user's own drag-and-drop reordering of the dashboard
     * shortcut cards — stored on their own extra_attributes (per-user, like
     * SupplierDetails does per-Contact), not the shared Setting table, since
     * this is a personal display preference, not an account-wide setting.
     */
    public function updateShortcutCardOrder(Request $request)
    {
        $data = $request->validate([
            'order' => 'required|array',
            'order.*' => 'string',
        ]);

        $user = $request->user();
        $user->extra_attributes = array_merge((array) $user->extra_attributes, [
            'dashboard_shortcut_card_order' => $data['order'],
        ]);
        $user->save();

        return back();
    }

    /** The most shortcut cards one person can keep on their dashboard (two rows of five). */
    public const MAX_CARDS = 10;

    /**
     * Profile > Dashboard cards: the person's own stack of dashboard shortcut cards, in order. Needs the "Dashboard Cards" ability.
     * The keys name main modules / dashboards (see resources/js/Core/dashboardCards.js); the dashboard only shows a card whose module the
     * person can open, so a key can never give access to anything, and a key for a module they lose later simply stops showing.
     */
    public function updateCards(Request $request)
    {
        abort_unless($request->user()->can('customize-dashboard-cards'), 403);
        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:'.self::MAX_CARDS],
            'keys.*' => ['string', 'distinct', 'regex:/^[a-z0-9][a-z0-9._-]{0,60}$/'],
        ]);

        $user = $request->user();
        $user->extra_attributes = array_merge((array) $user->extra_attributes, ['dashboard_card_keys' => array_values($data['keys'])]);
        $user->save();
        log_activity(__('Dashboard cards changed.'), ['cards' => count($data['keys'])], $user, 'Profile');

        return back()->with('message', __('Your dashboard cards were saved.'));
    }

    /** Back to the standard stack for the person's role. */
    public function resetCards(Request $request)
    {
        abort_unless($request->user()->can('customize-dashboard-cards'), 403);
        $user = $request->user();
        $extra = (array) $user->extra_attributes;
        unset($extra['dashboard_card_keys']);
        $user->extra_attributes = $extra ?: null;
        $user->save();

        return back()->with('message', __('Your dashboard cards were reset to the standard ones.'));
    }

    public function warehouseStockValuePdf(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('create-users'), 403);

        $search = trim((string) $request->input('search', ''));
        return Pdf::loadView('reports.pdf', $this->warehouseStockValueReportPayload($request, $search, false))
            ->setPaper('a4', 'portrait')
            ->download('warehouse-stock-value-' . now()->format('Ymd-His') . '.pdf');
    }

    public function warehouseStockValuePrint(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('create-users'), 403);

        $search = trim((string) $request->input('search', ''));

        return view('reports.pdf', $this->warehouseStockValueReportPayload($request, $search, true));
    }

    private function buildWarehouseStockValueListing()
    {
        $warehouses = Warehouse::visible()->get();

        // Lean queries (no Eloquent hydration, no per-warehouse query). The value of each warehouse is summed by the database over every stock line
        // (so it still counts all of them), and only the lines with stock on hand are brought over to be listed: about a fifth of the ~25k rows.
        $ids = $warehouses->pluck('id');
        $stocks = Stock::query()->whereIn('warehouse_id', $ids)->where('quantity', '>', 0)
            ->select('warehouse_id', 'item_id', 'quantity')->toBase()->get()->groupBy('warehouse_id');
        $amounts = \Illuminate\Support\Facades\DB::table('stocks')
            ->leftJoin('items', fn ($j) => $j->on('items.id', '=', 'stocks.item_id')->whereNull('items.deleted_at'))
            ->whereNull('stocks.deleted_at')->where('stocks.account_id', getAccountId())->whereIn('stocks.warehouse_id', $ids)->where('stocks.quantity', '<>', 0) // an empty line adds nothing
            ->selectRaw('stocks.warehouse_id, SUM(stocks.quantity * COALESCE(items.price, 0)) AS amount')->groupBy('stocks.warehouse_id')->pluck('amount', 'warehouse_id');
        $itemRows = \App\Models\Item::query()->select('id', 'name', 'code', 'price', 'unit_id')->toBase()->get()->keyBy('id');
        $units = \App\Models\Unit::query()->select('id', 'code', 'name')->toBase()->get()->keyBy('id');

        // highest stock value first (the dashboard then groups them and keeps this order inside each group)
        return $warehouses->map(function ($warehouse) use ($stocks, $amounts, $itemRows, $units) {
            $items = ($stocks->get($warehouse->id) ?? collect())->map(function ($row) use ($itemRows, $units) {
                $it = $itemRows->get($row->item_id);
                $unit = $it ? $units->get($it->unit_id) : null;
                $price = $it->price ?? 0;

                return [
                    'name' => $it->name ?? 'N/A',
                    'code' => $it->code ?? 'N/A',
                    'quantity' => $row->quantity,
                    'unit' => ($unit->code ?? null) ?: ($unit->name ?? null),
                    'price' => $price,
                    'total_amount' => $row->quantity * $price,
                ];
            })->values();

            // Main Warehouses are "Warehouses"; Private and Government are "Projects"; every other category is "Others"
            $status = mb_strtolower(trim((string) $warehouse->status));
            $group = in_array($status, ['main warehouse', 'main warehouses'], true) ? 'warehouse' : (in_array($status, ['private', 'government'], true) ? 'project' : 'other');

            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'status' => $warehouse->status,
                'group' => $group,
                'amount' => (float) ($amounts[$warehouse->id] ?? 0), // the sum of every stock line, done by the database
                'completion_percentage' => $warehouse->completion_percentage,
                // only lines with stock on hand are listed (most of a warehouse's catalogue lines are empty)
                'items' => $items,
            ];
        })->sortByDesc(fn ($row) => (float) $row['amount'])->values();
    }

    private function filterWarehouseStockValueListing($listing, string $search)
    {
        if ($search === '') {
            return $listing;
        }

        $needle = mb_strtolower($search);

        return $listing->filter(function ($row) use ($needle) {
            $name = mb_strtolower((string) ($row['name'] ?? ''));
            $amount = (float) ($row['amount'] ?? 0);
            $amountFormatted = mb_strtolower(number_format($amount, 2));
            $amountRaw = mb_strtolower((string) $amount);

            return str_contains($name, $needle)
                || str_contains($amountFormatted, $needle)
                || str_contains($amountRaw, $needle);
        })->values();
    }

    private function warehouseStockValueReportPayload(Request $request, string $search, bool $autoPrint): array
    {
        $listing = $this->filterWarehouseStockValueListing($this->buildWarehouseStockValueListing(), $search);

        // grouped like the dashboard: Warehouses (main), Projects (private / government), Others; biggest value first inside each
        $order = ['warehouse' => 0, 'project' => 1, 'other' => 2];
        $labels = ['warehouse' => 'Warehouses', 'project' => 'Projects', 'other' => 'Others'];
        $listing = $listing->sort(fn ($a, $b) => [$order[$a['group'] ?? 'other'], -(float) $a['amount']] <=> [$order[$b['group'] ?? 'other'], -(float) $b['amount']])->values();

        $rows = $listing->values()->map(fn ($row, $index) => [
            $index + 1,
            $labels[$row['group'] ?? 'other'] . ': ' . (string) ($row['name'] ?? '-'),
            number_format((float) ($row['amount'] ?? 0), 2) . ' Tsh',
            $row['completion_percentage'] === null ? '-' : $row['completion_percentage'] . '%',
        ])->all();

        return [
            'title' => 'Project(s) / Warehouse(s) Stock Value Summary',
            'companyName' => optional($request->user()->account ?? null)->name,
            'columns' => ['SN', 'Project(s) / Warehouse(s) Name', 'Stock Value', 'Completion %'],
            'rows' => $rows,
            'meta' => array_filter([
                'Generated By' => optional($request->user())->name,
                'Generated Date' => now()->format('Y-m-d H:i'),
                'Search' => $search ?: null,
                'Project(s) / Warehouse(s) Listed' => (string) count($rows),
            ], fn ($value) => filled($value)),
            'autoPrint' => $autoPrint,
        ];
    }
}
