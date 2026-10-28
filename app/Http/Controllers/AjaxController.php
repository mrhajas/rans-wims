<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Inertia\Inertia;
use App\Notifications\AppNotification;
use App\Models\Stock;
use App\Models\Checkin;
use App\Models\Contact;
use App\Models\Checkout;
use App\Models\Transfer;
use App\Models\Warehouse;
use App\Models\Adjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use App\Http\Resources\ItemCollection;
use Illuminate\Support\Facades\Storage;
use Tecdiary\Laravel\Attachments\Attachment;

class AjaxController extends Controller
{
    /**
     * Authorizable::callAction() would otherwise auto-derive "delete-media"
     * for delete() (from the media.delete route name) — a permission that
     * was never registered in PermissionRegistry, so it silently 403'd every
     * non-Super-Admin user. Disabling it in favor of the ownership check
     * inside delete() itself, which mirrors the real per-module edit
     * permission (via each attachment's owning model's own Policy) instead
     * of a single blanket ability shared by every attachable module.
     */
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function alerts()
    {
        // Background polling (every 20 s from every open tab) only needs the unread number to ring the bell: skip the
        // 150 notification rows and the four "done today" counts, which the open panel fetches on its own.
        if (request()->boolean('light')) {
            return response()
                ->json(['unread_count' => auth()->check() ? $this->alertsNotificationQuery(auth()->user())->count() : 0])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $todayCards = [
            [
                'key' => 'transfers',
                'title' => 'Transfers Done Today',
                'count' => Transfer::query()->whereBetween('created_at', [$todayStart, $todayEnd])->count(),
                'url' => route('transfers.index'),
            ],
            [
                'key' => 'checkins',
                'title' => 'Checkins Done Today',
                'count' => Checkin::query()->whereBetween('created_at', [$todayStart, $todayEnd])->count(),
                'url' => route('checkins.index'),
            ],
            [
                'key' => 'checkouts',
                'title' => 'Checkouts Done Today',
                'count' => Checkout::query()->whereBetween('created_at', [$todayStart, $todayEnd])->count(),
                'url' => route('checkouts.index'),
            ],
            [
                'key' => 'adjustments',
                'title' => 'Adjustments Done Today',
                'count' => Adjustment::query()->whereBetween('created_at', [$todayStart, $todayEnd])->count(),
                'url' => route('adjustments.index'),
            ],
        ];

        $notifications = [];
        $unreadCount = 0;
        if (auth()->check()) {
            $noticeNotifications = $this->alertsNotificationQuery(auth()->user());
            $unreadCount = (clone $noticeNotifications)->count();

            // Enough history for per-module bundling to be meaningful (the panel groups and expands these client-side).
            $notifications = (clone $noticeNotifications)
                ->latest()
                ->limit(150)
                ->get()
                ->map(function ($n) {
                    $module = (string) data_get($n->data, 'module', '');
                    $meta = AppNotification::moduleMeta($module);

                    return [
                        'id' => $n->id,
                        'module' => $module,
                        'module_label' => $meta['label'],
                        'module_icon' => $meta['icon'],
                        'module_accent' => $meta['accent'],
                        'title' => data_get($n->data, 'title', 'Notice Board'),
                        'message' => data_get($n->data, 'message'),
                        'url' => data_get($n->data, 'url'),
                        'created_at' => optional($n->created_at)->toDateTimeString(),
                        'unread' => is_null($n->read_at),
                    ];
                })->values();
        }

        return response()
            ->json(['notifications' => $notifications, 'unread_count' => $unreadCount, 'today_cards' => $todayCards])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
    }

    public function readAlertNotification(Request $request, string $notification)
    {
        abort_unless($request->user(), 403);
        \Illuminate\Support\Facades\Cache::forget('unread-alerts:' . $request->user()->id); // the page badge is kept for 10 seconds; reading an alert must show at once

        $record = $this->alertsNotificationQuery($request->user())
            ->whereKey($notification)
            ->firstOrFail();

        if (is_null($record->read_at)) {
            $record->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'unread_count' => $this->alertsNotificationQuery($request->user())->count(),
        ]);
    }

    /** Marks every unread alert of ONE module as read (the "Mark read" button on a module bundle). */
    public function readModuleAlertNotifications(Request $request, string $module)
    {
        abort_unless($request->user(), 403);
        \Illuminate\Support\Facades\Cache::forget('unread-alerts:' . $request->user()->id); // the page badge is kept for 10 seconds; reading an alert must show at once
        abort_unless(in_array($module, AppNotification::alertModules(), true), 404);

        $this->alertsNotificationQuery($request->user())
            ->where('data->module', $module)
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'unread_count' => $this->alertsNotificationQuery($request->user())->count(),
        ]);
    }

    public function readAllAlertNotifications(Request $request)
    {
        abort_unless($request->user(), 403);
        \Illuminate\Support\Facades\Cache::forget('unread-alerts:' . $request->user()->id); // the page badge is kept for 10 seconds; reading an alert must show at once

        $this->alertsNotificationQuery($request->user())->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'unread_count' => 0,
        ]);
    }

    protected function alertsNotificationQuery($user)
    {
        return $user->unreadNotifications()
            ->whereIn('data->module', AppNotification::alertModules());
    }

    public function contacts(Request $request)
    {
        if ($request->input('id')) {
            return Contact::whereId($request->input('id'))->get();
        }

        return Contact::search($request->input('search'))->take(10)->get();
    }

    public function delete($attachment)
    {
        $attachment = Attachment::findOrFail($attachment);

        // The owning record's own Policy is the real gate (e.g. EditPolicy
        // for Checkin/Checkout/Transfer/Adjustment, MaterialReallocationPolicy
        // for MRRs) — same "can I edit this record" check that shows/hides
        // the Remove button on the record's own form. Denies by default if
        // the parent was deleted or its model type has no registered Policy.
        abort_unless($attachment->model && request()->user()->can('update', $attachment->model), 403);

        $attachment->delete();

        return back()->with('message', __choice('action_text', ['record' => 'Attachment', 'action' => 'deleted']));
    }

    public function download($attachment)
    {
        $attachment = Attachment::findOrFail($attachment);

        // Same owning-record Policy gate as delete() above, using 'view'
        // rather than 'update' — downloading is a read, not a mutation, so
        // anyone who can see the record should be able to see its
        // attachments, not only whoever can edit it. Previously this had NO
        // check at all: any authenticated user could download any
        // attachment in the system by guessing/enumerating its ID.
        abort_unless($attachment->model && request()->user()->can('view', $attachment->model), 403);

        if (request()->boolean('preview')) {
            return Storage::response($attachment->filepath, $attachment->title);
        }

        return Storage::download($attachment->filepath, $attachment->title);
    }

    public function items(Request $request)
    {
        if ($request->input('id')) {
            return Item::with('variations')->whereId($request->input('id'))->get();
        }

        $query = Item::with('variations')->search($request->input('search'));

        // the Transfer form lists items as "CODE - name", alphabetically (other pickers keep the plain item as before)
        if ($request->boolean('labelled')) {
            return $query->orderBy('name')->take(25)->get()->each(fn ($item) => $item->setAttribute('label', $item->code.' - '.$item->name));
        }

        return $query->take(25)->get();
    }

    public function scan(Request $request)
    {
        $code = trim((string) $request->input('code'));
        if ($code === '') {
            return response()->json(['found' => false]);
        }

        $cleaned = str_replace(["\x1D", "\u{001D}"], '', $code);
        $withoutPrefix = preg_replace('/^\][A-Za-z0-9]{2}/', '', $cleaned);
        $compact = preg_replace('/\s+/', '', $withoutPrefix ?: $cleaned);
        $withoutLeadingZeros = ltrim((string) $compact, '0');

        $candidates = collect([$code, $cleaned, $withoutPrefix, $compact, $withoutLeadingZeros])
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values();

        $item = Item::query()
            ->whereIn('code', $candidates)
            ->orWhereIn('sku', $candidates)
            ->first();

        if (! $item) {
            foreach ($candidates as $candidate) {
                $item = Item::search($candidate)->first();
                if ($item) {
                    break;
                }
            }
        }

        return $item
            ? response()->json(['found' => true, 'id' => $item->id])
            : response()->json(['found' => false]);
    }

    public function checkStock(Request $request)
    {
        $errors = [];
        $warehouse_id = $request->input('warehouse_id');
        if ($warehouse_id && ! get_settings('over_selling')) {
            $errors = (new \App\Helpers\CheckOverSelling())->check($request->input('items', []), $warehouse_id);
        }

        return response()->json(['errors' => (object) $errors]);
    }

    public function language($language)
    {
        $langFiles = collect(json_decode(File::get(base_path('lang/languages.json')))->available)->pluck('value')->all();
        if (! in_array($language, $langFiles)) {
            return back()->with('error', __('Language is not available yet.'));
        }
        app()->setlocale($language);
        session(['language' => $language]);

        return back()->with('message', __('Language has been changed.'));
    }

    public function setWarehouse(Request $request)
    {
        $request->validate(['warehouse_id' => 'nullable|exists:warehouses,id']);

        $warehouseId = $request->input('warehouse_id');

        if (! $warehouseId) {
            session()->forget('warehouse_id');

            return back();
        }

        abort_unless(auth()->user()->manages($warehouseId), 403, __('You are not assigned to this warehouse.'));
        abort_unless(
            auth()->user()->hasRole('Super Admin') || Warehouse::whereKey($warehouseId)->whereNull('completed_at')->exists(),
            403,
            __('This project has been completed and is no longer available.')
        );

        session(['warehouse_id' => (int) $warehouseId]);

        return back();
    }

    public function warehouse(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('read-warehouses') || $request->user()->can('read-items'), 403);
        $filters = $request->all('search', 'trashed');

        return Inertia::render('Warehouse/Alerts', [
            'filters'   => $filters,
            'warehouse' => $warehouse,
            'items'     => new ItemCollection(
                Item::where('track_quantity', 1)
                    ->with(['stock' => fn ($q) => $q->where('warehouse_id', $warehouse->id)])
                    ->whereHas(
                        'stock',
                        fn ($q) => $q->where('warehouse_id', $warehouse->id)->whereColumn('alert_quantity', '>=', 'quantity')
                    )->orderBy(
                        Stock::select('quantity')->whereColumn('stocks.item_id', 'items.id')->where('warehouse_id', $warehouse->id)->whereNull('variation_id')
                    )->filter($filters)->paginate()->withQueryString()
            ),
        ]);
    }
}
