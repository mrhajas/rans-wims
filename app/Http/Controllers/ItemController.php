<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Unit;
use Inertia\Inertia;
use App\Models\Category;
use App\Models\Warehouse;
use App\Services\AdminNotificationService;
use App\Services\ItemPhotoFetcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ItemResource;
use App\Http\Resources\ItemCollection;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\StockTrailCollection;

class ItemController extends Controller
{
    public function barcodes(Request $request)
    {
        abort_unless($request->user()->can('read-items'), 403);
        return Inertia::render('Item/Barcodes', [
            'items'   => $this->barcodeQuery($request)->get(['id', 'code', 'name', 'symbology']),
            'filters' => ['search' => $request->input('search'), 'categories' => $this->barcodeCategoryIds($request)],
            'categoryList' => Category::ofAccount()->orderBy('name')->get(['id', 'name']),
            'prefs'   => $this->barcodePrefs($request->user()),
        ]);
    }

    /** The items of the Print Barcodes page: the ones picked (ids) or the search result, or all of them. */
    private function barcodeQuery(Request $request)
    {
        $ids = collect(explode(',', (string) $request->input('ids')))->filter()->values();
        $categories = $this->barcodeCategoryIds($request);

        $query = Item::query()->orderBy('name');
        if ($ids->isNotEmpty()) {
            $query->whereIn('id', $ids);
        }
        if ($request->filled('search')) {
            $query->search(trim((string) $request->input('search'))); // item name, code, SKU and category
        }
        if ($categories) {
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categories)); // any of the chosen categories
        }

        return $query;
    }

    /** The categories chosen on the Print Barcodes page (comma separated ids). */
    private function barcodeCategoryIds(Request $request): array
    {
        return collect(explode(',', (string) $request->input('categories')))->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
    }

    /** Print Barcodes as a WIMS report: the same labels as the page shows (style, size, name, copies), printed by / date / time, logged. */
    public function barcodesPrint(Request $request)
    {
        abort_unless($request->user()->can('read-items'), 403);
        $opts = $request->validate([
            'style'     => 'required|in:barcode,qr',
            'size'      => 'required|in:sm,md,lg',
            'show_name' => 'required|boolean',
            'copies'    => 'required|integer|min:1|max:100',
        ]);
        $items = $this->barcodeQuery($request)->get(['id', 'code', 'name', 'symbology']);
        $max = 1500; // one printed document stays a sensible size
        $labels = [];
        $note = null;
        foreach ($items as $item) {
            $code = (string) $item->code;
            $svg = $opts['style'] === 'qr'
                ? \Illuminate\Support\Facades\Cache::remember('qrart:v1:' . md5($code), now()->addDays(30), fn () => \App\Support\QrArt::svg($code)) // drawing a code is slow, the same code is the same picture
                : \App\Support\ItemLabelBarcode::svg($code, 1.8, ['sm' => 38, 'md' => 52, 'lg' => 70][$opts['size']]);
            for ($i = 0; $i < (int) $opts['copies']; $i++) {
                if (count($labels) >= $max) {
                    $note = __('Only the first :n labels are printed in one document. Print the rest with a smaller selection.', ['n' => $max]);
                    break 2;
                }
                $labels[] = ['name' => $item->name, 'code' => $code, 'svg' => $svg];
            }
        }
        log_activity(__('Item labels printed'), ['labels' => count($labels), 'items' => $items->count(), 'style' => $opts['style'], 'size' => $opts['size']], null, 'Item');

        return view('print.document', [
            'doc' => \App\Support\PrintDocuments::itemLabels($labels, $opts + ['show_name' => (bool) $opts['show_name']], $items->count(), $request->user(), $note),
            'isPdf' => false, 'companyName' => optional($request->user()->account)->name,
            'qr' => \App\Support\PrintQrCode::dataUri(route('items.barcodes')), 'pdfUrl' => null,
        ]);
    }

    private const BARCODE_PREF_DEFAULTS = ['style' => 'barcode', 'size' => 'md', 'show_name' => true, 'copies' => 1];

    /** What this person chose last time on the Print Barcodes page (kept in their own profile record, so it follows them to any device). */
    private function barcodePrefs($user): array
    {
        $raw = DB::table('users')->where('id', $user->id)->value('extra_attributes');
        $saved = is_string($raw) ? (json_decode($raw, true)['barcode_page'] ?? []) : [];

        return array_merge(self::BARCODE_PREF_DEFAULTS, array_intersect_key((array) $saved, self::BARCODE_PREF_DEFAULTS));
    }

    public function saveBarcodePrefs(Request $request)
    {
        abort_unless($request->user()->can('read-items'), 403);
        $data = $request->validate([
            'style'     => 'required|in:barcode,qr',
            'size'      => 'required|in:sm,md,lg',
            'show_name' => 'required|boolean',
            'copies'    => 'required|integer|min:1|max:100',
        ]);

        $raw = DB::table('users')->where('id', $request->user()->id)->value('extra_attributes');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $all['barcode_page'] = $data;
        DB::table('users')->where('id', $request->user()->id)->update(['extra_attributes' => json_encode($all)]);

        return response()->json(['saved' => true]);
    }

    public function create()
    {
        return Inertia::render('Item/Form', [
            'units'      => Unit::ofAccount()->base()->get(),
            'categories' => Category::ofAccount()->with('children')->get(),
        ]);
    }

    /** Items workflow page (Who Gets Notified / Messages): the "deleted" notice (the item is gone, so no record is passed). */
    private function alertItem(string $title, string $message): void
    {
        \App\Services\ItemNotifier::fire('deleted', $title, $message, null, ['url' => route('deleted.index', ['type' => 'items'])]);
    }

    public function destroy(Item $item)
    {
        $code = $item->code;
        $name = $item->name;

        if ($item->del()) {
            $this->alertItem(__('Item Deleted'), ($code ?: $name) . ' was deleted.');

            return redirect()->route('items.index')->with('message', __choice('action_text', ['record' => 'Item', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Item $item)
    {
        $code = $item->code;
        $name = $item->name;

        if ($item->delP()) {
            // "Deleted for good" is its own event; while it is switched off the older "Item Deleted" notice is sent for a permanent delete too, as before.
            if (\App\Services\WorkflowGate::on('items', 'permanently_deleted', (int) (auth()->user()?->account_id ?? 1))) {
                \App\Services\ItemNotifier::fire('permanently_deleted', __('Item Deleted for Good'), ($code ?: $name) . ' was deleted for good' . \App\Services\ItemNotifier::by() . '.', null, ['url' => route('items.index'), 'skip_actor' => true], ['item' => (string) $name, 'code' => (string) $code]);
            } else {
                $this->alertItem(__('Item Permanently Deleted'), ($code ?: $name) . ' was permanently deleted.');
            }

            return redirect()->route('items.index')->with('message', __choice('action_text', ['record' => 'Item', 'action' => 'permanently deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPhoto(Item $item)
    {
        abort_unless(auth()->user()->can('update-items'), 403);
        if ($item->photo && Storage::disk('assets')->delete($item->photo)) {
            $item->update(['photo' => null]);

            return back()->with('message', __choice('action_text', ['record' => 'Item photo', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyGalleryPhoto(Item $item, \App\Models\ItemPhoto $photo)
    {
        abort_unless(auth()->user()->can('update-items') && $photo->item_id === $item->id, 403);
        Storage::disk('assets')->delete($photo->path);
        $photo->delete();

        return back()->with('message', __choice('action_text', ['record' => 'Item photo', 'action' => 'deleted']));
    }

    /** The BinCard window: the current BinCard with its date and who set it, and the earlier ones. */
    public function bincard(Item $item)
    {
        abort_unless(auth()->user()->can('read-items'), 403);

        return response()->json(\App\Support\BinCard::details($item) + ['can_update' => auth()->user()->can('update-items')]);
    }

    /** The BinCard as a WIMS report (print view). The route name has "print" in it, so every use is in the Download / Print log; it is also written to the activity log. */
    public function bincardPrint(Request $request, Item $item)
    {
        return view('print.document', $this->bincardDocument($request, $item, false));
    }

    public function bincardPdf(Request $request, Item $item)
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('print.document', $this->bincardDocument($request, $item, true))->setPaper('a4')->download('bincard-' . ($item->code ?: $item->id) . '.pdf');
    }

    private function bincardDocument(Request $request, Item $item, bool $isPdf): array
    {
        abort_unless($request->user()->can('read-items'), 403);
        $photo = $item->bincardPhoto;
        abort_unless($photo && Storage::disk('assets')->exists($photo->path), 404);
        // the picture travels inside the document (a PDF cannot fetch it), made a little smaller so the file stays light
        [$bytes, $ext] = \App\Support\ItemImage::compress(Storage::disk('assets')->get($photo->path), 1600, pathinfo($photo->path, PATHINFO_EXTENSION));
        $src = 'data:image/' . ($ext === 'jpg' ? 'jpeg' : $ext) . ';base64,' . base64_encode($bytes);
        log_activity($isPdf ? __('BinCard downloaded as PDF') : __('BinCard printed'), ['item' => $item->name, 'code' => $item->code, 'photo' => $photo->id], $item, 'Item');

        return [
            'doc' => \App\Support\PrintDocuments::binCard($item, $src, $request->user()), 'isPdf' => $isPdf, 'companyName' => optional($request->user()->account)->name,
            'qr' => \App\Support\PrintQrCode::dataUri(route('items.edit', $item->id)), 'pdfUrl' => route('items.bincard.pdf', $item->id),
        ];
    }

    /** The background of one of the item's saved photos was removed in the browser: the cut-out replaces the file. */
    public function cutoutPhoto(Request $request, Item $item, \App\Models\ItemPhoto $photo)
    {
        abort_unless($request->user()->can('update-items') && $photo->item_id === $item->id && $photo->kind === 'photo', 403);
        $request->validate(['photo' => 'required|image|mimes:png,webp|max:15360']);
        [$path] = \App\Support\ItemImage::storeInfo($request->file('photo'));
        Storage::disk('assets')->delete($photo->path);
        $photo->forceFill(['path' => $path, 'cutout' => true])->save();
        log_activity(__('Item photo background removed'), ['item' => $item->name, 'photo' => $photo->id], $item, 'Item');

        return back()->with('message', __('The background was removed.'));
    }

    /** Refresh BinCard: one new photo becomes the current BinCard and its date and time. */
    public function refreshBincard(Request $request, Item $item)
    {
        abort_unless($request->user()->can('update-items'), 403);
        $request->validate(['photo' => 'required|image|mimes:png,jpg,jpeg,webp|max:15360']);
        \App\Support\BinCard::refresh($item, $request->file('photo'), $request->user());

        return back()->with('message', __('BinCard refreshed.'));
    }

    /** One of the item's extra photos becomes the BinCard. */
    public function assignBincard(Item $item, \App\Models\ItemPhoto $photo)
    {
        abort_unless(auth()->user()->can('update-items') && $photo->item_id === $item->id && $photo->kind === 'photo', 403);
        \App\Support\BinCard::assign($item, $photo, auth()->user());

        return back()->with('message', __('The photo is now the BinCard of the item.'));
    }

    /** Takes the BinCard off the item (the photos stay in the history). */
    public function removeBincard(Item $item)
    {
        abort_unless(auth()->user()->can('update-items'), 403);
        $item->forceFill(['bincard_photo_id' => null, 'bincard_at' => null, 'bincard_by' => null])->saveQuietly();
        log_activity(__('BinCard removed'), ['item' => $item->name, 'code' => $item->code], $item, 'Item');

        return back()->with('message', __('The BinCard was removed from the item.'));
    }

    /** Makes one of the extra photos the face of the item: it swaps places with the current main photo. */
    public function makeGalleryPhotoMain(Item $item, \App\Models\ItemPhoto $photo)
    {
        abort_unless(auth()->user()->can('update-items') && $photo->item_id === $item->id, 403);

        DB::transaction(function () use ($item, $photo) {
            $old = $item->photo;
            $item->update(['photo' => $photo->path]);
            if ($old && Storage::disk('assets')->exists($old)) {
                $photo->update(['path' => $old]);
            } else {
                $photo->delete();
            }
        });

        return back()->with('message', __('The photo is now the face of the item.'));
    }

    /**
     * "fetchPhotoDownload" isn't in Authorizable's default ability map, so
     * callAction() derives no ability for it and never calls authorize()
     * automatically — explicit check below, reusing the same
     * create-items/update-items abilities the rest of this controller
     * already relies on for the create/edit form this is called from.
     */
    public function fetchPhotoDownload(Request $request, ItemPhotoFetcher $fetcher)
    {
        abort_unless($request->user()->can('create-items') || $request->user()->can('update-items'), 403);

        $data = $request->validate(['url' => 'required|url']);

        $downloaded = $fetcher->downloadAndResize($data['url']);

        if (! $downloaded) {
            return response()->json(['success' => false, 'message' => __('Failed to download the selected photo.')], 422);
        }

        return response($downloaded['contents'], 200)
            ->header('Content-Type', $downloaded['mime'])
            ->header('Content-Disposition', 'inline; filename="' . Str::slug($request->input('name', 'item')) . '.' . $downloaded['extension'] . '"');
    }

    public function edit(Item $item)
    {
        $item->load('categories:id,code,name', 'photos', 'bincardPhoto');

        return Inertia::render('Item/Form', [
            'edit'       => new ItemResource($item),
            'units'      => Unit::ofAccount()->base()->get(),
            'categories' => Category::ofAccount()->with('children')->get(),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('search', 'trashed', 'category', 'type');
        // The summary tiles above the table filter the list: consumable / used / low / out (same definitions as the tile counts).
        $type = in_array($filters['type'] ?? null, ['consumable', 'used', 'low', 'out'], true) ? $filters['type'] : null;
        $filters['type'] = $type;

        // Clickable sortable headers on Item/Index.vue — restricted to
        // columns that resolve to one real orderable value per row. The
        // Relations and Options columns are left unsortable since they're
        // each multiple independent values (categories list; several
        // boolean flags), with no single sensible order.
        $sortable = [
            'name' => 'items.name',
            'type' => 'items.is_used',
            'price' => 'items.price',
            'quantity' => 'stock_sum_quantity',
        ];
        $sort = $request->get('sort');
        $direction = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        $query = Item::with(['categories:id,code,name', 'bincardPhoto:id,item_id,path', 'photos:id,item_id,path,kind,cutout,position', 'stock' => fn ($q) => $q->without('variation')->where(fn ($w) => $w->where('quantity', '!=', 0)->orWhere('weight', '!=', 0))])
            ->withSum('stock', 'quantity')
            ->filter($filters)
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->whereHas('stock', fn ($s) => $s->whereIn('warehouse_id', warehouse_scope_ids())));

        if ($type === 'consumable') {
            $query->where('items.is_used', 0);
        } elseif ($type === 'used') {
            $query->where('items.is_used', 1);
        } elseif ($type === 'low' || $type === 'out') {
            // Same total the "stock_sum_quantity" column shows (main stock, this account), written as a WHERE so paging counts stay valid.
            $total = '(select coalesce(sum(stocks.quantity), 0) from stocks where stocks.item_id = items.id and stocks.variation_id is null and stocks.deleted_at is null and stocks.account_id = ?)';
            $query->where('items.track_quantity', 1);
            if ($type === 'low') {
                $query->where('items.alert_quantity', '>', 0)->whereRaw("$total > 0", [getAccountId()])->whereRaw("$total <= items.alert_quantity", [getAccountId()]);
            } else {
                $query->whereRaw("$total <= 0", [getAccountId()]);
            }
        }

        if (isset($sortable[$sort])) {
            $query->orderBy($sortable[$sort], $direction);
        } else {
            $sort = null;
            $query->orderByDesc('id');
        }

        // Headline numbers for the tiles above the table: whole catalogue (within the user's warehouse scope),
        // independent of the search / sort / page the table is showing.
        $all = Item::query()
            ->withSum('stock', 'quantity')
            ->when(warehouse_scope_ids() !== null, fn ($q) => $q->whereHas('stock', fn ($s) => $s->whereIn('warehouse_id', warehouse_scope_ids())))
            ->get(['items.id', 'items.is_used', 'items.track_quantity', 'items.alert_quantity']);
        $tracked = $all->filter(fn ($i) => (int) $i->track_quantity === 1);
        $stats = [
            'total'      => $all->count(),
            'consumable' => $all->where('is_used', 0)->count(),
            'used'       => $all->where('is_used', 1)->count(),
            'out'        => $tracked->filter(fn ($i) => (float) $i->stock_sum_quantity <= 0)->count(),
            'low'        => $tracked->filter(fn ($i) => (float) $i->alert_quantity > 0 && (float) $i->stock_sum_quantity > 0 && (float) $i->stock_sum_quantity <= (float) $i->alert_quantity)->count(),
        ];

        return Inertia::render('Item/Index', [
            'stats'      => $stats,
            'filters'    => array_merge($filters, ['sort' => $sort, 'direction' => $sort ? $direction : null]),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(),
            'items'      => new ItemCollection($query->paginate()->withQueryString()),
        ]);
    }

    public function restore(Item $item)
    {
        abort_unless(auth()->user()->can('delete-items'), 403);

        $item->restore();
        \App\Services\ItemNotifier::fire('restored', __('Item Restored'), \App\Services\ItemNotifier::label($item) . ' was restored from the deleted records' . \App\Services\ItemNotifier::by() . '.', $item, ['skip_actor' => true]);

        return back()->with('message', __choice('action_text', ['record' => 'Item', 'action' => 'restored']));
    }

    public function show(Request $request, Item $item)
    {
        $item->load(['categories:id,code,name', 'allStock.warehouse:id,code,name']);
        $item->setRelation('stock', $item->allStock->groupBy('warehouse_id'));

        return $request->json ? new ItemResource($item) : Inertia::render('Item/Show', ['item' => new ItemResource($item)]);
    }

    public function store(ItemRequest $request)
    {
        $data = $request->validated();

        // Item::create($data)->addVariations()->saveRelations($data);

        $created = null;
        \App\Support\ReferenceLock::run('items', function () use ($data, &$created) {
            $maxSuffix = (int) DB::table('items')
                ->selectRaw("MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_suffix")
                ->where('code', 'like', 'RSI-%')
                ->value('max_suffix');

            $data['code'] = 'RSI-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT);

            $created = Item::create($data);
            $created->addVariations()->saveRelations($data);
            \App\Support\ItemPhotoGallery::add($created, $request->file('extra_photos', []), $request->filled('bincard_index') ? (int) $request->input('bincard_index') : null, $request->user());
        });
        if ($created) {
            \App\Services\ItemNotifier::fire('created', __('Item Added'), \App\Services\ItemNotifier::label($created) . ' was added' . \App\Services\ItemNotifier::by() . '.', $created, ['skip_actor' => true]);
        }

        return redirect()->route('items.index')->with('message', __choice('action_text', ['record' => 'Item', 'action' => 'created']));
    }

    /**
     * "Create USED": a second catalogue entry for the used / second-hand stock of an item — same name with "- USED"
     * added, same unit, price, category and tracking settings, flagged as a Used Item / Material, new item code, no stock.
     * Created once only: if the USED copy already exists it is opened instead of making another.
     */
    public function createUsed(Request $request, Item $item)
    {
        abort_unless($request->user()->can('create-used-items'), 403);
        if ($item->is_used || preg_match('/-\s*USED$/i', trim($item->name))) {
            return back()->with('error', __('This item is already a Used Item / Material.'));
        }

        $name = mb_strtoupper(trim($item->name)) . ' - USED';
        $existing = Item::where('name', $name)->first();
        if ($existing) {
            return redirect()->route('items.edit', $existing->id)->with('error', __('The USED copy of this item already exists (:code). Opened it for you.', ['code' => $existing->code]));
        }

        $item->loadMissing('categories:id');
        $categoryIds = $item->categories->pluck('id')->values();

        $copy = \App\Support\ReferenceLock::run('items', function () use ($item, $name, $categoryIds) {
            $maxSuffix = (int) DB::table('items')
                ->selectRaw("MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_suffix")
                ->where('code', 'like', 'RSI-%')
                ->value('max_suffix');

            $photo = null;
            if ($item->photo && Storage::disk('assets')->exists($item->photo)) {
                $photo = 'items/' . Str::uuid() . '.' . pathinfo($item->photo, PATHINFO_EXTENSION);
                Storage::disk('assets')->copy($item->photo, $photo);
            }

            $copy = Item::create([
                'name'           => $name,
                'code'           => 'RSI-' . str_pad($maxSuffix + 1, 4, '0', STR_PAD_LEFT),
                'symbology'      => $item->symbology,
                'price'          => $item->price,
                'unit_id'        => $item->unit_id,
                'details'        => $item->details,
                'alert_quantity' => $item->alert_quantity,
                'rack_location'  => $item->rack_location,
                'track_quantity' => $item->track_quantity,
                'track_weight'   => $item->track_weight,
                'has_serials'    => $item->has_serials,
                'has_variants'   => $item->has_variants,
                'variants'       => $item->variants,
                'is_used'        => true,
                'photo'          => $photo,
            ]);

            return $copy->addVariations()->saveRelations([
                'category_id'       => $categoryIds[0] ?? null,
                'child_category_id' => $categoryIds[1] ?? null,
            ]);
        });

        log_activity('Used copy created', ['from' => $item->code, 'to' => $copy->code], $copy, 'Item');
        \App\Services\ItemNotifier::fire('used_copy_created', __('Used Copy Created'), \App\Services\ItemNotifier::label($copy) . ' was created as the used copy of ' . \App\Services\ItemNotifier::label($item) . \App\Services\ItemNotifier::by() . '.', $copy, ['skip_actor' => true]);

        return redirect()->route('items.edit', $copy->id)
            ->with('message', __('Created :name (:code) and marked it as a Used Item / Material. Set its price, location or details if they differ.', ['name' => $copy->name, 'code' => $copy->code]));
    }

    /**
     * Super Admin: change the Type of several CONSUMABLE items to Used in one go. The Super Admin's own password is checked here (not trusted from
     * the page), only consumable items are touched, and each change is written to the activity log.
     */
    public function bulkUsed(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('Super Admin'), 403);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'], 'password' => ['required', 'string']]);
        if (! \Illuminate\Support\Facades\Hash::check($data['password'], (string) $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['password' => __('That password is incorrect.')]);
        }

        $items = Item::query()->whereIn('id', array_unique($data['ids']))->where('is_used', false)->get();
        if ($items->isEmpty()) {
            return back()->with('error', __('None of the selected items is a consumable item any more.'));
        }
        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                // "NAME - USED" (once; and with the code if another item already has that exact name)
                $old = $item->name;
                $name = trim($old);
                if (! preg_match('/-\s*USED$/i', $name)) {
                    $name .= ' - USED'; // the name is kept as written, so converting back restores it exactly
                    if (Item::withTrashed()->where('name', $name)->where('id', '<>', $item->id)->exists()) {
                        $name .= ' (' . $item->code . ')';
                    }
                }
                $item->forceFill(['is_used' => true, 'name' => $name])->save();
                log_activity('Type changed to Used', ['code' => $item->code, 'from' => 'Consumable', 'to' => 'Used', 'name_was' => $old, 'name_now' => $name, 'bulk' => true], $item, 'Item');
            }
        });

        return back()->with('message', __(':n item(s) changed from Consumable to Used.', ['n' => $items->count()]));
    }

    /**
     * Super Admin: the way back. Used items become Consumable again: the Type changes and " - USED" is taken off the name (and the "(CODE)" that was
     * added when another item already had the plain name). If another item has exactly the plain name, the code is added so the two stay apart.
     */
    public function bulkConsumable(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('Super Admin'), 403);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'], 'password' => ['required', 'string']]);
        if (! \Illuminate\Support\Facades\Hash::check($data['password'], (string) $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['password' => __('That password is incorrect.')]);
        }

        $items = Item::query()->whereIn('id', array_unique($data['ids']))->where('is_used', true)->get();
        if ($items->isEmpty()) {
            return back()->with('error', __('None of the selected items is a Used item any more.'));
        }
        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $old = $item->name;
                $name = trim(preg_replace('/\s*-\s*USED(\s*\([^)]*\))?\s*$/i', '', $old));
                if ($name === '') {
                    $name = $old;
                }
                if ($name !== $old && Item::withTrashed()->where('name', $name)->where('id', '<>', $item->id)->exists()) {
                    $name .= ' (' . $item->code . ')';
                }
                $item->forceFill(['is_used' => false, 'name' => $name])->save();
                log_activity('Type changed to Consumable', ['code' => $item->code, 'from' => 'Used', 'to' => 'Consumable', 'name_was' => $old, 'name_now' => $name, 'bulk' => true], $item, 'Item');
            }
        });

        return back()->with('message', __(':n item(s) changed from Used to Consumable.', ['n' => $items->count()]));
    }

    public function trail(Item $item)
    {
        abort_unless(auth()->user()->can('read-items'), 403);
        $subjectRouteMap = [
            \App\Models\CheckinItem::class => ['relation' => 'checkin', 'route' => 'checkins.show'],
            \App\Models\CheckoutItem::class => ['relation' => 'checkout', 'route' => 'checkouts.show'],
            \App\Models\TransferItem::class => ['relation' => 'transfer', 'route' => 'transfers.show'],
            \App\Models\AdjustmentItem::class => ['relation' => 'adjustment', 'route' => 'adjustments.show'],
        ];

        $trails = $item->stockTrails()
            ->with(['subject' => function ($morphTo) use ($subjectRouteMap) {
                $morphTo->withTrashed()->morphWith(collect($subjectRouteMap)->mapWithKeys(function ($meta, $class) {
                    return [$class => [$meta['relation'] => fn ($q) => $q->withTrashed()->with('user:id,name')]];
                })->all());
            }])
            ->orderByDesc('id')
            ->paginate();

        // A trail row stores the BALANCE after the movement, not the movement itself, so the
        // change is the difference to the previous balance of the same warehouse/variation.
        $previous = [];
        $deltas = [];
        $item->stockTrails()->orderBy('id')->get(['id', 'warehouse_id', 'variation_id', 'quantity'])
            ->each(function ($row) use (&$previous, &$deltas) {
                $key = $row->warehouse_id . ':' . ($row->variation_id ?? 0);
                $deltas[$row->id] = (float) $row->quantity - ($previous[$key] ?? 0);
                $previous[$key] = (float) $row->quantity;
            });

        $trails->getCollection()->transform(function ($trail) use ($subjectRouteMap, $deltas) {
            $meta = $subjectRouteMap[$trail->subject_type] ?? null;
            $parent = $meta && $trail->subject ? $trail->subject->{$meta['relation']} : null;

            $trail->done_by = optional(optional($parent)->user)->name;
            $trail->transaction_route = $parent ? $meta['route'] : null;
            $trail->transaction_id = optional($parent)->id;
            $trail->transaction_reference = optional($parent)->reference;
            // The document this movement came from no longer exists (permanently deleted).
            $trail->source_missing = $meta && ! $trail->subject;
            $trail->change = round($deltas[$trail->id] ?? 0, 4);
            $trail->unsetRelation('subject');

            return $trail;
        });

        $currentStock = $item->stock()->with('warehouse:id,name')->get()
            ->groupBy('warehouse_id')
            ->map(fn ($rows) => [
                'warehouse' => optional($rows->first()->warehouse)->name,
                'quantity'  => (float) $rows->sum('quantity'),
            ])->values();

        return Inertia::render('Item/Trail', [
            'item'   => $item->only('id', 'code', 'name'),
            'trails' => new StockTrailCollection($trails),
            'currentStock' => $currentStock,
        ]);
    }

    public function update(ItemRequest $request, Item $item)
    {
        $data = $request->validated();
        $item->update($data);
        $item->addVariations()->saveRelations($data);
        \App\Support\ItemPhotoGallery::add($item, $request->file('extra_photos', []), $request->filled('bincard_index') ? (int) $request->input('bincard_index') : null, $request->user());
        \App\Services\ItemNotifier::fire('updated', __('Item Edited'), \App\Services\ItemNotifier::label($item) . ' was edited' . \App\Services\ItemNotifier::by() . '.', $item, ['skip_actor' => true]);
        session()->flash('message', __choice('action_text', ['record' => 'Item', 'action' => 'updated']));

        return $request->listing == 'yes' ? redirect()->route('items.index') : back();
    }
}
