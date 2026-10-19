<?php

namespace App\Http\Controllers\ExternalStock;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\GatePassController;
use App\Models\Asset;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\ExternalStockItem;
use App\Models\ExternalStockMovement;
use App\Models\GatePass;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\ExternalStock\ExternalStockLedger;
use App\Services\ExternalStock\ExternalStockNotifier;
use App\Services\ExternalStock\ExternalStockRoute;
use App\Support\PrintQrCode;
use App\Support\ReferenceLock;
use App\Traits\GuardsCompletedWarehouse;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tecdiary\Laravel\Attachments\Attachment;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * External Stock movements. External Personnel (contacts in that category) keep stock in our warehouses: they take some (Stock Out), bring more
 * (Stock In) and may already hold stock when the module starts (opening stock). Every movement is approval based; only an approved one changes their
 * balance. A Stock Out records who collects it, the driver, the vehicle and the mobile. The company's own stock figures are never touched.
 */
class MovementController extends Controller
{
    use GuardsCompletedWarehouse;

    public function __construct()
    {
        $this->setAbilities([]); // every action is checked below, by name
    }

    private function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    private function visible()
    {
        return ExternalStockMovement::query()->visibleTo(auth()->user());
    }

    public function index(Request $request)
    {
        $this->need('read-external-stock');
        $f = ['search' => trim((string) $request->query('search', '')), 'kind' => (string) $request->query('kind', ''), 'status' => (string) $request->query('status', ''), 'contact_id' => (string) $request->query('contact_id', ''),
            'warehouse_id' => (string) $request->query('warehouse_id', ''), 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', '')];
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $base = $this->visible()
            ->when(array_key_exists($f['kind'], ExternalStockMovement::KINDS), fn ($q) => $q->where('kind', $f['kind']))
            ->when(in_array($f['status'], ExternalStockMovement::STATUSES, true), fn ($q) => $q->where('status', $f['status']))
            ->when(ctype_digit($f['contact_id']) && $f['contact_id'] !== '', fn ($q) => $q->where('contact_id', (int) $f['contact_id']))
            ->when(ctype_digit($f['warehouse_id']) && $f['warehouse_id'] !== '', fn ($q) => $q->where('warehouse_id', (int) $f['warehouse_id']))
            ->when($f['from'] !== '' && strtotime($f['from']), fn ($q) => $q->whereDate('date', '>=', $f['from']))->when($f['to'] !== '' && strtotime($f['to']), fn ($q) => $q->whereDate('date', '<=', $f['to']))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($x) => $x->where('reference', 'like', $like($f['search']))->orWhere('collector_name', 'like', $like($f['search']))->orWhere('collector_phone', 'like', $like($f['search']))
                ->orWhere('driver_name', 'like', $like($f['search']))->orWhere('vehicle_plate', 'like', $like($f['search']))->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like($f['search'])))));

        $rows = (clone $base)->with(['contact:id,name,phone', 'warehouse:id,name', 'user:id,name', 'items.item:id,name,code', 'items.unit:id,name'])->orderByDesc('id')->paginate(20)->withQueryString();
        $passes = GatePassController::statesFor($rows->getCollection(), GatePass::SOURCE_EXTERNAL);
        $notes = DeliveryNoteController::statesFor($rows->getCollection(), DeliveryNote::SOURCE_EXTERNAL);
        $routes = ExternalStockRoute::statesFor($rows->getCollection(), $passes);
        $rows->through(fn ($m) => $this->row($m) + ['gate_pass' => $passes[$m->id] ?? null, 'delivery_note' => $notes[$m->id] ?? null, 'route' => $routes[$m->id] ?? null]);

        $counts = $this->visible()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $me = auth()->user();

        return Inertia::render('ExternalStock/Index', [
            'rows' => $rows, 'filters' => $f, 'kinds' => collect(ExternalStockMovement::KINDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'statuses' => collect(ExternalStockMovement::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(),
            'people' => $this->people(), 'warehouses' => $this->warehouses(),
            'stats' => ['total' => (int) $counts->sum(), 'pending' => (int) ($counts[ExternalStockMovement::PENDING] ?? 0), 'approved' => (int) ($counts[ExternalStockMovement::APPROVED] ?? 0), 'rejected' => (int) ($counts[ExternalStockMovement::REJECTED] ?? 0), 'cancelled' => (int) ($counts[ExternalStockMovement::CANCELLED] ?? 0)],
            'can' => ['create' => $me->can('create-external-stock'), 'print' => $me->can('print-external-stock'), 'approve' => $me->can('approve-external-stock')],
        ]);
    }

    public function create(Request $request)
    {
        $this->need('create-external-stock');

        return Inertia::render('ExternalStock/Form', $this->formProps(null, in_array($request->query('kind'), array_keys(ExternalStockMovement::KINDS), true) ? $request->query('kind') : 'withdrawal'));
    }

    public function store(Request $request)
    {
        $this->need('create-external-stock');
        $d = $this->validated($request, null);
        if ($response = $this->rejectIfWarehouseCompleted($d['warehouse_id'])) {
            return $response;
        }
        $m = ReferenceLock::run('external_stock', fn () => DB::transaction(function () use ($d) {
            $this->shortfallCheck($d, null);
            $m = ExternalStockMovement::create(collect($d)->except('lines')->all() + ['reference' => ExternalStockMovement::nextReference(), 'status' => ExternalStockMovement::PENDING]);
            foreach ($d['lines'] as $l) {
                ExternalStockItem::create(['movement_id' => $m->id, 'item_id' => $l['item_id'], 'unit_id' => $l['unit_id'], 'quantity' => $l['quantity'], 'note' => $l['note'] ?? null]);
            }

            return $m;
        }));
        $added = $this->saveFiles($m, $request);
        log_activity('External Stock ' . $m->reference . ' recorded', ['kind' => $m->kind, 'contact' => $m->contact_id, 'warehouse' => $m->warehouse_id, 'lines' => count($d['lines']), 'files' => $added], $m, 'External Stock');
        ExternalStockNotifier::fire('approval_needed', $m, $request->user());

        return redirect()->route('external-stock.show', $m->id)->with('message', __(':r saved. It needs approval before the balance changes.', ['r' => $m->reference]));
    }

    public function show(ExternalStockMovement $movement)
    {
        $this->need('read-external-stock');
        $this->authorizeView($movement);
        $movement->load(['contact', 'warehouse', 'user:id,name', 'decidedBy:id,name', 'items.item:id,name,code', 'items.unit:id,name']);
        $me = auth()->user();
        $own = (int) $movement->user_id === (int) $me->id;
        $pending = $movement->status === ExternalStockMovement::PENDING;
        $pass = GatePassController::stateFor($movement, GatePass::SOURCE_EXTERNAL);

        return Inertia::render('ExternalStock/Show', [
            'm' => $this->row($movement) + [
                'purpose' => $movement->purpose, 'collector_id_no' => $movement->collector_id_no, 'decision_remarks' => $movement->decision_remarks, 'decided_by' => optional($movement->decidedBy)->name, 'decided_at' => optional($movement->decided_at)->toDateTimeString(),
                'cancel_reason' => $movement->cancel_reason, 'cancelled_at' => optional($movement->cancelled_at)->toDateTimeString(), 'created_at' => $movement->created_at->toDateTimeString(),
                'contact_phone' => optional($movement->contact)->phone, 'driver' => $this->driverLabel($movement), 'vehicle' => $this->vehicleLabel($movement),
            ],
            'positions' => $movement->items->mapWithKeys(fn ($l) => [$l->item_id => ExternalStockLedger::position($movement->contact_id, $movement->warehouse_id, $l->item_id)])->all(),
            'attachments' => $this->filesOf($movement),
            'gate_pass' => $pass, 'delivery_note' => DeliveryNoteController::stateFor($movement, DeliveryNote::SOURCE_EXTERNAL), 'transport_route' => ExternalStockRoute::stateFor($movement, $pass),
            'can' => [
                'edit' => $pending && ($own || $me->can('update-external-stock')) && $me->can('create-external-stock') || ($pending && $me->can('update-external-stock')),
                'approve' => $pending && $me->can('approve-external-stock') && (! $own || $me->hasRole('Super Admin')),
                'reject' => $pending && $me->can('approve-external-stock'),
                'cancel' => ($pending && ($own || $me->can('cancel-external-stock') || $me->can('approve-external-stock'))) || ($movement->status === ExternalStockMovement::APPROVED && $me->can('cancel-external-stock')),
                'print' => $me->can('print-external-stock'),
                'attach' => $this->mayAttach($movement), 'remove_file' => $pending && $this->mayEdit($movement),
            ],
        ]);
    }

    public function edit(ExternalStockMovement $movement)
    {
        $this->authorizeEdit($movement);
        $movement->load(['items']);

        return Inertia::render('ExternalStock/Form', $this->formProps($movement, $movement->kind));
    }

    public function update(Request $request, ExternalStockMovement $movement)
    {
        $this->authorizeEdit($movement);
        $d = $this->validated($request, $movement);
        if ($response = $this->rejectIfWarehouseCompleted($d['warehouse_id'])) {
            return $response;
        }
        DB::transaction(function () use ($movement, $d) {
            ExternalStockMovement::withoutGlobalScopes()->whereKey($movement->id)->lockForUpdate()->first();
            abort_unless($movement->fresh()->status === ExternalStockMovement::PENDING, 422, __('Only a movement that is still waiting for approval can be changed.'));
            $this->shortfallCheck($d, $movement->id);
            $movement->update(collect($d)->except(['lines', 'kind'])->all());
            $movement->items()->forceDelete();
            foreach ($d['lines'] as $l) {
                ExternalStockItem::create(['movement_id' => $movement->id, 'item_id' => $l['item_id'], 'unit_id' => $l['unit_id'], 'quantity' => $l['quantity'], 'note' => $l['note'] ?? null]);
            }
        });
        $added = $this->saveFiles($movement, $request);
        log_activity('External Stock ' . $movement->reference . ' updated', ['lines' => count($d['lines']), 'files' => $added], $movement, 'External Stock');

        return redirect()->route('external-stock.show', $movement->id)->with('message', __('Saved. It still needs approval.'));
    }

    public function approve(Request $request, ExternalStockMovement $movement)
    {
        $this->need('approve-external-stock');
        $this->authorizeView($movement);
        $me = $request->user();
        if ((int) $movement->user_id === (int) $me->id && ! $me->hasRole('Super Admin')) {
            return back()->with('error', __('You recorded this movement, so someone else must approve it.'));
        }
        $remarks = trim((string) $request->input('remarks', ''));
        $error = null;
        DB::transaction(function () use ($movement, $me, $remarks, &$error) {
            // everything of this person in this warehouse is locked while the balance is checked
            ExternalStockMovement::withoutGlobalScopes()->where('contact_id', $movement->contact_id)->where('warehouse_id', $movement->warehouse_id)->lockForUpdate()->get(['id']);
            $m = ExternalStockMovement::withoutGlobalScopes()->with('items')->find($movement->id);
            if ($m->status !== ExternalStockMovement::PENDING) {
                $error = __('This movement has already been decided.');

                return;
            }
            if ($m->kind === 'withdrawal') {
                $short = ExternalStockLedger::shortfalls($m->contact_id, $m->warehouse_id, $m->items->map(fn ($l) => ['item_id' => $l->item_id, 'quantity' => $l->quantity])->all(), $m->id, false);
                if ($short) {
                    $error = implode(' ', $short);

                    return;
                }
            }
            $m->forceFill(['status' => ExternalStockMovement::APPROVED, 'decided_by_user_id' => $me->id, 'decided_at' => now(), 'decision_remarks' => $remarks ?: null])->save();
        });
        if ($error) {
            return back()->with('error', e($error));
        }
        $movement->refresh();
        log_activity('External Stock ' . $movement->reference . ' approved', ['kind' => $movement->kind], $movement, 'External Stock');
        ExternalStockNotifier::fire('approved', $movement, $me);
        if ($movement->kind === 'withdrawal') {
            ExternalStockNotifier::fire('withdrawn', $movement, $me);
        }

        return back()->with('message', __(':r approved. The balance has changed.', ['r' => $movement->reference]));
    }

    public function reject(Request $request, ExternalStockMovement $movement)
    {
        $this->need('approve-external-stock');
        $this->authorizeView($movement);
        $reason = trim($request->validate(['reason' => 'required|string|max:1000'])['reason']);
        $done = DB::transaction(function () use ($movement, $request, $reason) {
            $m = ExternalStockMovement::withoutGlobalScopes()->lockForUpdate()->find($movement->id);
            if ($m->status !== ExternalStockMovement::PENDING) {
                return false;
            }
            $m->forceFill(['status' => ExternalStockMovement::REJECTED, 'decided_by_user_id' => $request->user()->id, 'decided_at' => now(), 'decision_remarks' => $reason])->save();

            return true;
        });
        if (! $done) {
            return back()->with('error', __('This movement has already been decided.'));
        }
        $movement->refresh();
        log_activity('External Stock ' . $movement->reference . ' rejected', ['reason' => $reason], $movement, 'External Stock');
        ExternalStockNotifier::fire('rejected', $movement, $request->user(), $reason);

        return back()->with('message', __(':r rejected. Nothing changed.', ['r' => $movement->reference]));
    }

    public function cancel(Request $request, ExternalStockMovement $movement)
    {
        $this->authorizeView($movement);
        $me = $request->user();
        $reason = trim($request->validate(['reason' => 'required|string|max:1000'])['reason']);
        $own = (int) $movement->user_id === (int) $me->id;
        $error = null;
        DB::transaction(function () use ($movement, $me, $reason, $own, &$error) {
            ExternalStockMovement::withoutGlobalScopes()->where('contact_id', $movement->contact_id)->where('warehouse_id', $movement->warehouse_id)->lockForUpdate()->get(['id']);
            $m = ExternalStockMovement::withoutGlobalScopes()->with('items')->find($movement->id);
            if ($m->status === ExternalStockMovement::PENDING) {
                if (! ($own || $me->can('cancel-external-stock') || $me->can('approve-external-stock'))) {
                    $error = __('You may not cancel this movement.');

                    return;
                }
            } elseif ($m->status === ExternalStockMovement::APPROVED) {
                if (! $me->can('cancel-external-stock')) {
                    $error = __('You may not cancel an approved movement.');

                    return;
                }
                // taking an approved Stock In away must not leave the person with negative stock
                if ($m->kind !== 'withdrawal') {
                    foreach ($m->items as $l) {
                        $p = ExternalStockLedger::position($m->contact_id, $m->warehouse_id, $l->item_id);
                        if ($p['available'] - (float) $l->quantity < -0.00005) {
                            $error = __('This cannot be cancelled: the stock it added has already been taken out or promised.');

                            return;
                        }
                    }
                }
            } else {
                $error = __('This movement is already :s.', ['s' => strtolower(__($m->status))]);

                return;
            }
            $m->forceFill(['status' => ExternalStockMovement::CANCELLED, 'cancelled_by_user_id' => $me->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        });
        if ($error) {
            return back()->with('error', e($error));
        }
        $movement->refresh();
        log_activity('External Stock ' . $movement->reference . ' cancelled', ['reason' => $reason], $movement, 'External Stock');
        ExternalStockNotifier::fire('cancelled', $movement, $me, $reason);

        return back()->with('message', __(':r cancelled.', ['r' => $movement->reference]));
    }

    /** JSON for the form: what this person holds, and could still take, of an item in a warehouse. */
    public function availability(Request $request)
    {
        $this->need('create-external-stock');
        $r = $request->validate(['contact_id' => 'required|integer', 'warehouse_id' => 'nullable|integer', 'item_id' => 'required|integer', 'except' => 'nullable|integer']);
        // no warehouse chosen (a Stock Out may leave it open): what the person holds across all warehouses
        $p = ! empty($r['warehouse_id'])
            ? ExternalStockLedger::position((int) $r['contact_id'], (int) $r['warehouse_id'], (int) $r['item_id'], $r['except'] ?? null)
            : ExternalStockLedger::positionAnywhere((int) $r['contact_id'], (int) $r['item_id'], $r['except'] ?? null);
        $item = Item::with('unit:id,name')->find((int) $r['item_id']);

        return response()->json($p + ['item' => $item ? ['id' => $item->id, 'name' => $item->name, 'code' => $item->code, 'unit' => optional($item->unit)->name, 'unit_id' => $item->unit_id, 'blocked' => (bool) ($item->has_variants || $item->has_serials)] : null]);
    }

    // ---- attachments, print and download ----

    private const FILE_TYPES = 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt';

    private function mayEdit(ExternalStockMovement $m): bool
    {
        $me = auth()->user();

        return $me->can('update-external-stock') || ((int) $m->user_id === (int) $me->id && $me->can('create-external-stock'));
    }

    /** Supporting documents can be added while the movement waits for approval, and later by someone who may change or approve records. */
    private function mayAttach(ExternalStockMovement $m): bool
    {
        $me = auth()->user();
        if ($m->status === ExternalStockMovement::PENDING) {
            return $this->mayEdit($m);
        }

        return $m->status === ExternalStockMovement::APPROVED && ($me->can('update-external-stock') || $me->can('approve-external-stock'));
    }

    private function filesOf(ExternalStockMovement $m)
    {
        return $m->attachments()->orderBy('id')->get()->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'size' => (int) $a->filesize, 'mime' => $a->filetype, 'at' => optional($a->created_at)->toDateTimeString()])->values();
    }

    /** Saves the files of an upload; returns how many. */
    private function saveFiles(ExternalStockMovement $m, Request $request): int
    {
        $n = 0;
        foreach ((array) $request->file('attachments', []) as $f) {
            if ($f && $f->isValid()) {
                $m->attach($f, ['title' => mb_substr($f->getClientOriginalName(), 0, 90), 'disk' => 'local', 'group' => 'external-stock']);
                $n++;
            }
        }

        return $n;
    }

    public function attach(Request $request, ExternalStockMovement $movement)
    {
        $this->need('read-external-stock');
        $this->authorizeView($movement);
        abort_unless($this->mayAttach($movement), 403);
        $request->validate(['attachments' => 'required|array|min:1|max:10', 'attachments.*' => 'file|max:8192|mimes:' . self::FILE_TYPES], ['attachments.required' => __('Choose at least one file.')]);
        $n = $this->saveFiles($movement, $request);
        log_activity('External Stock ' . $movement->reference . ' document added', ['files' => $n], $movement, 'External Stock');

        return back()->with('message', trans_choice(':n document attached.|:n documents attached.', $n, ['n' => $n]));
    }

    private function fileOf(ExternalStockMovement $m, int $file): Attachment
    {
        $a = $m->attachments()->whereKey($file)->first();
        abort_unless($a, 404);

        return $a;
    }

    public function download(ExternalStockMovement $movement, int $file)
    {
        $this->need('read-external-stock');
        $this->authorizeView($movement);
        $a = $this->fileOf($movement, $file);
        $inline = request()->boolean('inline');
        log_activity('External Stock ' . $movement->reference . ' document ' . ($inline ? 'viewed' : 'downloaded'), ['file' => $a->title], $movement, 'External Stock');
        // ?inline=1 shows an image or a PDF in the browser (the preview); anything else is a download
        if ($inline && in_array(strtolower(pathinfo($a->title, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            $mime = strtolower(pathinfo($a->title, PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : ($a->filetype ?: 'application/octet-stream');

            return response()->file(Storage::disk($a->disk ?: 'local')->path($a->filepath), ['Content-Type' => $mime, 'Content-Disposition' => 'inline; filename="' . addslashes($a->title) . '"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"]);
        }

        return Storage::disk($a->disk ?: 'local')->download($a->filepath, $a->title, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function removeFile(ExternalStockMovement $movement, int $file)
    {
        $this->need('read-external-stock');
        $this->authorizeView($movement);
        abort_unless($movement->status === ExternalStockMovement::PENDING && $this->mayEdit($movement), 403);
        $a = $this->fileOf($movement, $file);
        $title = $a->title;
        $a->delete();
        log_activity('External Stock ' . $movement->reference . ' document removed', ['file' => $title], $movement, 'External Stock');

        return back()->with('message', __('Document removed.'));
    }

    /** The record on paper, in the WIMS report layout. Print opens the same PDF that Download saves; both are logged. */
    public function print(ExternalStockMovement $movement)
    {
        return $this->document($movement, false);
    }

    public function pdf(ExternalStockMovement $movement)
    {
        return $this->document($movement, true);
    }

    private function document(ExternalStockMovement $movement, bool $download)
    {
        $this->need('read-external-stock');
        $this->need('print-external-stock');
        $this->authorizeView($movement);
        $movement->load(['contact', 'warehouse', 'user:id,name', 'decidedBy:id,name', 'items.item:id,name,code', 'items.unit:id,name']);
        $name = 'stock-' . ($movement->kind === 'withdrawal' ? 'out' : ($movement->kind === 'deposit' ? 'in' : 'opening')) . '-' . $movement->reference . '.pdf';
        log_activity('External Stock ' . $movement->reference . ($download ? ' downloaded' : ' printed'), ['kind' => $movement->kind, 'format' => 'pdf'], $movement, 'External Stock');
        $pdf = Pdf::loadView('external-stock.print', [
            'm' => $movement, 'kindLabel' => __($movement->kindLabel()), 'driver' => $this->driverLabel($movement), 'vehicle' => $this->vehicleLabel($movement), 'isPdf' => true,
            'companyName' => \App\Models\PaymentVoucher::COMPANY_NAME, 'qr' => PrintQrCode::dataUri(route('external-stock.show', $movement->id)), 'printedBy' => auth()->user()->name,
            'files' => $movement->attachments()->orderBy('id')->pluck('title')->all(),
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('external_stock', $movement),
        ])->setPaper('a4', 'portrait');

        return $download ? $pdf->download($name) : $pdf->stream($name);
    }

    // ---------------------------------------------------------------------------------------------

    private function authorizeView(ExternalStockMovement $m): void
    {
        abort_unless($this->visible()->whereKey($m->id)->exists(), 404);
    }

    private function authorizeEdit(ExternalStockMovement $m): void
    {
        $me = auth()->user();
        $this->authorizeView($m);
        abort_unless($m->status === ExternalStockMovement::PENDING && ($me->can('update-external-stock') || ((int) $m->user_id === (int) $me->id && $me->can('create-external-stock'))), 403);
    }

    private function people()
    {
        return Contact::query()->where('category', Contact::EXTERNAL_CATEGORY)->orderBy('name')->get(['id', 'name', 'phone', 'location'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ($c->phone ? ' (' . $c->phone . ')' : ''), 'location' => $c->location]);
    }

    private function warehouses()
    {
        $ids = accessible_warehouse_ids();

        return Warehouse::ofAccount()->active()->visible()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name]);
    }

    private function driverLabel(ExternalStockMovement $m): ?string
    {
        $c = $m->driver_contact_id ? Contact::withTrashed()->find($m->driver_contact_id) : null;
        $name = $c ? $c->name : $m->driver_name;
        $phone = $c ? $c->phone : $m->driver_phone;

        return $name ? trim($name . ($phone ? ' (' . $phone . ')' : '')) : null;
    }

    private function vehicleLabel(ExternalStockMovement $m): ?string
    {
        $a = $m->asset_id ? Asset::withTrashed()->find($m->asset_id) : null;
        if ($a) {
            return trim(($a->registration_number ?: $a->name) . ' ' . collect([$a->manufacturer, $a->model, $a->color])->filter()->implode(' '));
        }

        return $m->vehicle_plate ? trim($m->vehicle_plate . ' ' . $m->vehicle_details) : null;
    }

    private function row(ExternalStockMovement $m): array
    {
        $lines = $m->items;

        return ['id' => $m->id, 'reference' => $m->reference, 'kind' => $m->kind, 'kind_label' => __($m->kindLabel()), 'date' => $m->date->toDateString(), 'status' => $m->status, 'status_label' => __($m->status),
            'contact_id' => $m->contact_id, 'contact' => optional($m->contact)->name, 'location' => $m->location ?: optional($m->contact)->location, 'warehouse_id' => $m->warehouse_id, 'warehouse' => optional($m->warehouse)->name, 'by' => optional($m->user)->name,
            'collector_name' => $m->collector_name, 'collector_phone' => $m->collector_phone, 'driver' => $this->driverLabel($m), 'vehicle' => $this->vehicleLabel($m),
            'lines' => $lines->map(fn ($l) => ['id' => $l->id, 'item_id' => $l->item_id, 'name' => optional($l->item)->name, 'code' => optional($l->item)->code, 'unit' => optional($l->unit)->name, 'unit_id' => $l->unit_id, 'quantity' => (float) $l->quantity, 'note' => $l->note])->values(),
            'total_qty' => round((float) $lines->sum('quantity'), 4)];
    }

    private function formProps(?ExternalStockMovement $m, string $kind): array
    {
        return [
            'm' => $m ? [
                'id' => $m->id, 'reference' => $m->reference, 'kind' => $m->kind, 'date' => $m->date->toDateString(), 'contact_id' => $m->contact_id, 'location' => $m->location, 'warehouse_id' => $m->warehouse_id, 'purpose' => $m->purpose,
                'collector_name' => $m->collector_name, 'collector_phone' => $m->collector_phone, 'collector_id_no' => $m->collector_id_no, 'driver_contact_id' => $m->driver_contact_id, 'driver_name' => $m->driver_name, 'driver_phone' => $m->driver_phone,
                'asset_id' => $m->asset_id, 'vehicle_plate' => $m->vehicle_plate, 'vehicle_details' => $m->vehicle_details,
                'lines' => $m->items->map(fn ($l) => ['item_id' => $l->item_id, 'name' => optional($l->item)->name, 'code' => optional($l->item)->code, 'unit' => optional($l->unit)->name, 'unit_id' => $l->unit_id, 'quantity' => (float) $l->quantity, 'note' => $l->note])->values(),
            ] : null,
            'kind' => $kind, 'kinds' => collect(ExternalStockMovement::KINDS)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(), 'people' => $this->people(), 'warehouses' => $this->warehouses(),
            'drivers' => Contact::query()->where('category', 'Drivers')->orderBy('name')->get(['id', 'name', 'phone'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ($c->phone ? ' (' . $c->phone . ')' : '')]),
            'vehicles' => Asset::vehicles()->orderBy('name')->get(['id', 'name', 'registration_number', 'manufacturer', 'model', 'color'])->map(fn ($a) => ['value' => $a->id, 'label' => trim(($a->registration_number ?: $a->name) . ' ' . collect([$a->manufacturer, $a->model, $a->color])->filter()->implode(' '))]),
            'today' => now()->toDateString(), 'attachments' => $m ? $this->filesOf($m) : [], 'newPersonUrl' => route('contacts.create', ['category' => Contact::EXTERNAL_CATEGORY]),
        ];
    }

    /** The checks for a new or changed movement. A Stock Out must say who collects it, the driver and the vehicle (so everything is on record). */
    private function validated(Request $request, ?ExternalStockMovement $existing): array
    {
        $kind = $existing ? $existing->kind : (string) $request->input('kind');
        $out = $kind === 'withdrawal';
        $req = $out ? 'required' : 'nullable';
        $d = $request->validate([
            'kind' => [$existing ? 'nullable' : 'required', 'in:withdrawal,deposit,opening'], 'date' => 'required|date', 'contact_id' => 'required|integer', 'warehouse_id' => $out ? 'nullable|integer' : 'required|integer', 'purpose' => 'nullable|string|max:2000',
            'collector_name' => [$req, 'nullable', 'string', 'max:190'], 'collector_phone' => [$req, 'nullable', 'string', 'max:60'], 'collector_id_no' => 'nullable|string|max:80',
            'driver_contact_id' => 'nullable|integer', 'driver_name' => ['nullable', 'string', 'max:190'], 'driver_phone' => 'nullable|string|max:60',
            'asset_id' => 'nullable|integer', 'vehicle_plate' => ['nullable', 'string', 'max:60'], 'vehicle_details' => 'nullable|string|max:190',
            'lines' => 'required|array|min:1|max:200', 'lines.*.item_id' => 'required|integer|exists:items,id', 'lines.*.quantity' => 'required|numeric|min:0.0001|max:99999999', 'lines.*.note' => 'nullable|string|max:190',
            'attachments' => 'nullable|array|max:10', 'attachments.*' => 'file|max:8192|mimes:' . self::FILE_TYPES,
        ], ['collector_name.required' => __('Who is collecting the stock? Their name is required.'), 'collector_phone.required' => __('The mobile number of the person collecting is required.')]);

        $errors = [];
        $person = Contact::query()->where('id', $d['contact_id'])->where('category', Contact::EXTERNAL_CATEGORY)->first();
        if (! $person) {
            $errors['contact_id'] = __('Choose a person from the External Personnel list.');
        }
        $allowed = accessible_warehouse_ids();
        $warehouseId = ! empty($d['warehouse_id']) ? (int) $d['warehouse_id'] : null;
        if ($warehouseId && (! Warehouse::ofAccount()->active()->whereKey($warehouseId)->exists() || ($allowed !== null && ! in_array($warehouseId, $allowed, true)))) {
            $errors['warehouse_id'] = __('You may not use this warehouse.');
        }
        // the registered driver / vehicle wins over typed text, so a record never carries two answers
        $driverId = ! empty($d['driver_contact_id']) ? (int) $d['driver_contact_id'] : null;
        if ($driverId && ! Contact::query()->where('id', $driverId)->where('category', 'Drivers')->exists()) {
            $errors['driver_contact_id'] = __('Choose a driver from the list.');
        }
        $assetId = ! empty($d['asset_id']) ? (int) $d['asset_id'] : null;
        if ($assetId && ! Asset::vehicles()->whereKey($assetId)->exists()) {
            $errors['asset_id'] = __('Choose a vehicle from the list.');
        }
        if ($out && ! $driverId && trim((string) ($d['driver_name'] ?? '')) === '') {
            $errors['driver_name'] = __('Select the driver, or type the driver\'s name.');
        }
        if ($out && ! $assetId && trim((string) ($d['vehicle_plate'] ?? '')) === '') {
            $errors['vehicle_plate'] = __('Select the vehicle, or type its registration number.');
        }
        // lines: the same item twice is added together; variants / serials are not supported here
        $items = Item::query()->whereIn('id', collect($d['lines'])->pluck('item_id'))->get()->keyBy('id');
        $merged = [];
        foreach ($d['lines'] as $i => $l) {
            $item = $items[$l['item_id']] ?? null;
            if ($item && ($item->has_variants || $item->has_serials)) {
                $errors["lines.$i.item_id"] = __(':item has variants or serial numbers, which External Stock does not handle yet.', ['item' => $item->name]);
                continue;
            }
            $k = (int) $l['item_id'];
            $merged[$k] = ['item_id' => $k, 'unit_id' => $item ? $item->unit_id : null, 'quantity' => round(($merged[$k]['quantity'] ?? 0) + (float) $l['quantity'], 4), 'note' => $l['note'] ?? ($merged[$k]['note'] ?? null)];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        // A Stock Out may leave Project(s) / Warehouse(s) open: it then comes from the warehouse that holds this person's stock for every line
        if (! $warehouseId && $out) {
            $warehouseId = ExternalStockLedger::resolveWarehouse((int) $d['contact_id'], array_values($merged), $allowed, $existing ? $existing->id : null);
            if (! $warehouseId) {
                throw ValidationException::withMessages(['warehouse_id' => __('No single warehouse holds all of this stock for this person. Choose the Project(s) / Warehouse(s), or ask for less.')]);
            }
        }
        $clean = fn ($v) => ($v = is_string($v) ? trim($v) : $v) === '' ? null : $v;

        return [
            'kind' => $kind, 'date' => $d['date'], 'contact_id' => (int) $d['contact_id'], 'location' => $clean($person->location), 'warehouse_id' => (int) $warehouseId, 'purpose' => $clean($d['purpose'] ?? null),
            'collector_name' => $clean($d['collector_name'] ?? null), 'collector_phone' => $clean($d['collector_phone'] ?? null), 'collector_id_no' => $clean($d['collector_id_no'] ?? null),
            'driver_contact_id' => $driverId, 'driver_name' => $driverId ? null : $clean($d['driver_name'] ?? null), 'driver_phone' => $driverId ? null : $clean($d['driver_phone'] ?? null),
            'asset_id' => $assetId, 'vehicle_plate' => $assetId ? null : $clean($d['vehicle_plate'] ?? null), 'vehicle_details' => $assetId ? null : $clean($d['vehicle_details'] ?? null),
            'lines' => array_values($merged),
        ];
    }

    /** A Stock Out may not ask for more than the person holds (and has not already promised in another waiting Stock Out). */
    private function shortfallCheck(array $d, ?int $exceptId): void
    {
        if ($d['kind'] !== 'withdrawal') {
            return;
        }
        $short = ExternalStockLedger::shortfalls($d['contact_id'], $d['warehouse_id'], $d['lines'], $exceptId);
        if ($short) {
            $errors = [];
            foreach ($d['lines'] as $i => $l) {
                if (isset($short[$l['item_id']])) {
                    $errors["lines.$i.quantity"] = $short[$l['item_id']];
                }
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
