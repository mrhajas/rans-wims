<?php

namespace App\Http\Controllers;

use App\Actions\Tec\PrepareOrder;
use App\Models\Item;
use App\Models\MultiTransfer;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\Warehouse;
use App\Services\MultiTransferNotifier;
use App\Services\TransferApproval;
use App\Services\TransferNotifier;
use App\Support\PrintQrCode;
use App\Support\ReferenceLock;
use App\Traits\GuardsCompletedWarehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Multi Transfer: items from SEVERAL warehouses to ONE destination in a single request. Saving it creates an ORDINARY Transfer for each source
 * warehouse, every one held for approval (no stock moves until it is approved), and keeps them together under one reference. Everything after that is
 * the normal transfer: its own approval, delivery note, gate pass, receiving and reports. The request page approves / cancels them together.
 */
class MultiTransferController extends Controller
{
    use GuardsCompletedWarehouse;

    public const STATUS = ['pending' => 'Waiting for approval', 'partial' => 'Partly approved', 'approved' => 'Approved', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    public function index(Request $request)
    {
        $q = trim((string) $request->query('search', ''));
        $rows = MultiTransfer::query()->visibleTo($request->user())->with(['transfers.fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name'])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'like', "%$q%")->orWhereHas('transfers', fn ($t) => $t->withoutGlobalScope('mine')->where('reference', 'like', "%$q%"))->orWhereHas('toWarehouse', fn ($t) => $t->where('name', 'like', "%$q%"))))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        $all = MultiTransfer::query()->visibleTo($request->user())->with('transfers')->get();
        $stats = ['total' => $all->count()] + array_map(fn ($k) => $all->filter(fn ($m) => $m->status() === $k)->count(), ['pending' => 'pending', 'partial' => 'partial', 'approved' => 'approved', 'cancelled' => 'cancelled']);

        return Inertia::render('MultiTransfer/Index', [
            'rows' => $rows->through(fn (MultiTransfer $m) => $this->summary($m)),
            'stats' => $stats,
            'filters' => ['search' => $q],
            'can' => ['create' => auth()->user()->can('create-multi-transfers')],
        ]);
    }

    public function create()
    {
        return Inertia::render('MultiTransfer/Form', [
            'warehouses' => Warehouse::ofAccount()->active()->visible()->get(['id', 'name', 'code', 'status']),
        ]);
    }

    /** Which warehouses hold an item, and how much (only the ones this person may take stock from): the source choice of a line. */
    public function availability(Request $request)
    {
        abort_unless($request->user()->can('create-multi-transfers'), 403);
        $item = Item::with('unit.subunits')->findOrFail((int) $request->query('item_id'));
        $rows = Stock::main()->where('item_id', $item->id)->where('quantity', '>', 0)->get(['warehouse_id', 'quantity']);
        $allowed = accessible_warehouse_ids();
        $names = Warehouse::withoutGlobalScopes()->whereNull('deleted_at')->where('active', 1)->whereIn('id', $rows->pluck('warehouse_id'))->pluck('name', 'id');

        return response()->json([
            'item' => ['id' => $item->id, 'name' => $item->name, 'code' => $item->code, 'has_variants' => (bool) $item->has_variants, 'has_serials' => (bool) $item->has_serials,
                'unit' => $item->unit ? ['id' => $item->unit->id, 'name' => $item->unit->name, 'subunits' => $item->unit->subunits->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()] : null],
            'sources' => $rows->filter(fn ($r) => isset($names[$r->warehouse_id]) && ($allowed === null || in_array((int) $r->warehouse_id, $allowed, true)))
                ->map(fn ($r) => ['id' => (int) $r->warehouse_id, 'name' => $names[$r->warehouse_id], 'quantity' => (float) $r->quantity])->sortBy('name')->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date', 'to_warehouse_id' => 'required|exists:warehouses,id', 'details' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1', 'lines.*.item_id' => 'required|exists:items,id', 'lines.*.unit_id' => 'nullable|exists:units,id',
            'lines.*.quantity' => 'required|numeric|min:0.0001|max:99999999', 'lines.*.from_warehouse_id' => 'required|exists:warehouses,id',
        ]);
        $to = (int) $data['to_warehouse_id'];
        $lines = collect($data['lines'])->values();
        $sources = $lines->pluck('from_warehouse_id')->map(fn ($i) => (int) $i)->unique()->values();

        if ($sources->contains($to)) {
            throw ValidationException::withMessages(['to_warehouse_id' => __('A source warehouse cannot also be the destination.')]);
        }
        if ($response = $this->rejectIfWarehouseBlocked($to, ...$sources->all())) {
            return $response;
        }
        $allowed = accessible_warehouse_ids();
        $items = Item::with('unit.subunits')->whereIn('id', $lines->pluck('item_id'))->get()->keyBy('id');
        $errors = [];
        foreach ($lines as $i => $line) {
            $from = (int) $line['from_warehouse_id'];
            $item = $items[$line['item_id']] ?? null;
            if ($allowed !== null && ! in_array($from, $allowed, true)) {
                $errors["lines.$i.from_warehouse_id"] = __('You may not take stock from this warehouse.');
            }
            if (! $item) {
                $errors["lines.$i.item_id"] = __('This item no longer exists.');
            } elseif ($item->has_variants || $item->has_serials) {
                $errors["lines.$i.item_id"] = __(':item has variants or serial numbers. Use a normal transfer for it.', ['item' => $item->name]);
            } elseif (! empty($line['unit_id']) && (int) $line['unit_id'] !== (int) $item->unit_id && ! $item->unit?->subunits->contains('id', (int) $line['unit_id'])) {
                // a unit outside the item's own unit family would be counted as plain quantity here and converted differently when the stock moves
                $errors["lines.$i.unit_id"] = __('This unit does not belong to :item.', ['item' => $item->name]);
            }
        }
        // enough stock at each source for everything asked from it (the same item may appear in several lines)
        if (! $errors && ! get_settings('over_selling')) {
            $asked = [];
            foreach ($lines as $i => $line) {
                $item = $items[$line['item_id']];
                if (! $item->track_quantity) {
                    continue;
                }
                $unit = ! empty($line['unit_id']) ? $item->unit->subunits->firstWhere('id', (int) $line['unit_id']) : null;
                $key = $line['from_warehouse_id'] . '|' . $item->id;
                $asked[$key] = ($asked[$key] ?? 0) + ($unit ? convert_to_base_quantity((float) $line['quantity'], $unit) : (float) $line['quantity']);
                $asked["last|$key"] = $i;
            }
            foreach ($asked as $key => $need) {
                if (str_starts_with($key, 'last|')) {
                    continue;
                }
                [$from, $itemId] = explode('|', $key);
                $have = (float) Stock::main()->where('item_id', $itemId)->where('warehouse_id', $from)->value('quantity');
                if (round($need, 4) > round($have, 4)) {
                    $errors['lines.' . $asked["last|$key"] . '.quantity'] = __(':item: :need are needed from :from, only :have are in stock there.', ['item' => $items[$itemId]->name, 'need' => $need + 0, 'from' => Warehouse::withoutGlobalScopes()->whereKey($from)->value('name'), 'have' => $have + 0]);
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $user = $request->user();
        $mt = DB::transaction(function () use ($data, $lines, $user, $to) {
            $mt = ReferenceLock::run('multi_transfers', function () use ($data, $to) {
                $max = (int) DB::table('multi_transfers')->selectRaw("MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m")->where('reference', 'like', 'MTR-%')->value('m');

                return MultiTransfer::create(['reference' => 'MTR-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT), 'date' => $data['date'], 'to_warehouse_id' => $to, 'details' => $data['details'] ?? null]);
            });
            // one ordinary transfer per source, held for approval exactly like a transfer that leaves a main warehouse
            foreach ($lines->groupBy('from_warehouse_id') as $from => $group) {
                $payload = [
                    'date' => $data['date'], 'from_warehouse_id' => (int) $from, 'to_warehouse_id' => $to, 'details' => trim(($data['details'] ?? '') . ' [' . $mt->reference . ']'),
                    'draft' => true, 'multi_transfer_id' => $mt->id,
                    'items' => $group->values()->map(fn ($l) => ['item_id' => (int) $l['item_id'], 'quantity' => (float) $l['quantity'], 'unit_id' => $l['unit_id'] ?? null, 'weight' => null, 'selected' => ['serials' => [], 'variations' => []]])->all(),
                ];
                $transfer = ReferenceLock::run('transfers', function () use ($payload) {
                    $max = (int) DB::table('transfers')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as max_suffix')->where('reference', 'like', 'RST-%')->value('max_suffix');
                    $payload['reference'] = 'RST-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);

                    return (new PrepareOrder($payload, null, new Transfer()))->process()->save();
                });
                $transfer->forceFill(['approval_status' => Transfer::APPROVAL_PENDING, 'multi_transfer_id' => $mt->id])->save();
            }

            return $mt;
        });

        $mt->load(['transfers.fromWarehouse:id,name', 'toWarehouse:id,name']);
        foreach ($mt->transfers as $transfer) {
            event(new \App\Events\TransferEvent($transfer, 'created'));
            log_activity('Transfer Created', ['reference' => $transfer->reference, 'from_warehouse_id' => $transfer->from_warehouse_id, 'to_warehouse_id' => $transfer->to_warehouse_id, 'multi_transfer' => $mt->reference], $transfer, 'Transfer');
            TransferNotifier::fire('approval_needed', $transfer, __('Transfer awaiting approval'), $transfer->reference . ': ' . TransferNotifier::route($transfer), $user->id, ['skip_actor' => true]);
        }
        log_activity('Multi Transfer Created', ['reference' => $mt->reference, 'to_warehouse_id' => $to, 'transfers' => $mt->transfers->pluck('reference')->all()], $mt, 'Multi Transfer');
        $text = $mt->reference . ': ' . $mt->transfers->count() . ' ' . __('transfers to') . ' ' . optional($mt->toWarehouse)->name;
        MultiTransferNotifier::fire('created', $mt, __('Multi Transfer created'), $text, $user->id);
        MultiTransferNotifier::fire('approval_needed', $mt, __('Multi Transfer awaiting approval'), $text, $user->id, ['skip_actor' => true]);

        return redirect()->route('multi-transfers.show', $mt->id)->with('message', __('Multi Transfer :ref saved. :n transfers were created and are waiting for approval; no stock moves until they are approved.', ['ref' => $mt->reference, 'n' => $mt->transfers->count()]));
    }

    public function show(int $multiTransfer)
    {
        $multiTransfer = $this->find($multiTransfer);
        $mt = $multiTransfer->load(['transfers.items.item:id,code,name', 'transfers.items.unit:id,name', 'transfers.fromWarehouse:id,name', 'transfers.approver:id,name', 'transfers.canceller:id,name', 'toWarehouse:id,name', 'user:id,name']);
        $u = auth()->user();
        $pending = $mt->transfers->where('approval_status', Transfer::APPROVAL_PENDING)->count();

        return Inertia::render('MultiTransfer/Show', [
            'multi' => array_merge($this->summary($mt), ['details' => $mt->details, 'user' => optional($mt->user)->name, 'date' => optional($mt->date)->format('Y-m-d'),
                'transfers' => $mt->transfers->map(fn (Transfer $t) => [
                    'id' => $t->id, 'reference' => $t->reference, 'from' => optional($t->fromWarehouse)->name, 'status' => $this->childStatus($t), 'received_at' => $t->received_at,
                    'approved_by' => optional($t->approver)->name, 'approved_at' => $t->approved_at, 'cancelled_by' => optional($t->canceller)->name, 'cancel_reason' => $t->cancel_reason,
                    'lines' => $t->items->map(fn ($l) => ['item' => optional($l->item)->name, 'code' => optional($l->item)->code, 'quantity' => (float) $l->quantity, 'unit' => optional($l->unit)->name])->values(),
                    'can_open' => $u->can('read-transfers'),
                ])->values()]),
            'can' => ['approve' => $pending > 0 && $u->can('approve-multi-transfers'), 'cancel' => $pending > 0 && $u->can('cancel-multi-transfers'), 'print' => $u->can('print-multi-transfers'), 'pending' => $pending],
        ]);
    }

    /** Approve every transfer of the request that is still waiting: stock moves for each source that has enough; any that cannot are named and stay waiting. */
    public function approveAll(Request $request, int $multiTransfer)
    {
        abort_unless($request->user()->can('approve-multi-transfers'), 403);
        $multiTransfer = $this->find($multiTransfer);
        $done = 0;
        $problems = [];
        foreach ($multiTransfer->transfers()->where('approval_status', Transfer::APPROVAL_PENDING)->get() as $transfer) {
            if ($this->isAnyWarehouseCompleted($transfer->from_warehouse_id, $transfer->to_warehouse_id)) {
                $problems[] = $transfer->reference . ': ' . $this->completedWarehouseMessage();

                continue;
            }
            if ($halt = \App\Support\StockHalt::blocking($transfer->from_warehouse_id, $transfer->to_warehouse_id)) {
                $problems[] = $transfer->reference . ': ' . \App\Support\StockHalt::message($halt);

                continue;
            }
            $error = TransferApproval::approve($transfer, $request->user());
            $error ? $problems[] = $transfer->reference . ': ' . $error : $done++;
        }
        log_activity('Multi Transfer Approved', ['reference' => $multiTransfer->reference, 'approved' => $done, 'problems' => $problems], $multiTransfer, 'Multi Transfer');
        if ($done && $problems) {
            MultiTransferNotifier::fire('partly_approved', $multiTransfer->refresh(), __('Multi Transfer partly approved'), $multiTransfer->reference . ': ' . $done . ' ' . __('approved') . ', ' . count($problems) . ' ' . __('could not be approved') . ' (' . implode(' ', $problems) . ')', $request->user()->id, ['skip_actor' => true]);
        }

        return back()->with($problems ? 'error' : 'message', trim(($done ? __(':n transfer(s) approved. Stock has moved.', ['n' => $done]) . ' ' : '') . implode(' ', $problems)) ?: __('Nothing was waiting for approval.'));
    }

    public function cancelAll(Request $request, int $multiTransfer)
    {
        abort_unless($request->user()->can('cancel-multi-transfers'), 403);
        $multiTransfer = $this->find($multiTransfer);
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;
        $done = 0;
        foreach ($multiTransfer->transfers()->where('approval_status', Transfer::APPROVAL_PENDING)->get() as $transfer) {
            if (! TransferApproval::cancel($transfer, $request->user(), $reason)) {
                $done++;
            }
        }
        log_activity('Multi Transfer Cancelled', ['reference' => $multiTransfer->reference, 'cancelled' => $done, 'reason' => $reason], $multiTransfer, 'Multi Transfer');

        return back()->with('message', $done ? __(':n transfer(s) cancelled. Nothing had moved.', ['n' => $done]) : __('Nothing was waiting for approval.'));
    }

    public function print(int $multiTransfer)
    {
        abort_unless(auth()->user()->can('print-multi-transfers'), 403);

        return view('multi-transfers.print', $this->printData($this->find($multiTransfer), false));
    }

    public function pdf(int $multiTransfer)
    {
        abort_unless(auth()->user()->can('print-multi-transfers'), 403);

        $mtModel = $this->find($multiTransfer);

        return Pdf::loadView('multi-transfers.print', $this->printData($mtModel, true))->setPaper('a4', 'portrait')->download('multi-transfer-' . $mtModel->reference . '.pdf');
    }

    private function printData(MultiTransfer $mt, bool $isPdf): array
    {
        $mt->load(['transfers.items.item:id,code,name', 'transfers.items.unit:id,name', 'transfers.fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name']);

        return [
            'mt' => $mt, 'status' => self::STATUS[$mt->status()], 'isPdf' => $isPdf, 'companyName' => \App\Models\PaymentVoucher::COMPANY_NAME,
            'qr' => PrintQrCode::dataUri(route('multi-transfers.show', $mt->id)), 'printedBy' => auth()->user()->name,
        ];
    }

    private function find(int $id): MultiTransfer
    {
        return MultiTransfer::query()->visibleTo(auth()->user())->findOrFail($id);
    }

    private function childStatus(Transfer $t): string
    {
        return $t->approval_status === Transfer::APPROVAL_CANCELLED ? 'cancelled' : ($t->approval_status === Transfer::APPROVAL_PENDING ? 'pending' : ($t->received_at ? 'received' : 'approved'));
    }

    private function summary(MultiTransfer $m): array
    {
        $status = $m->status();

        return [
            'id' => $m->id, 'reference' => $m->reference, 'date' => optional($m->date)->format('Y-m-d'), 'to' => optional($m->toWarehouse)->name, 'by' => optional($m->user)->name,
            'status' => $status, 'status_label' => self::STATUS[$status], 'sources' => $m->transfers->map(fn ($t) => optional($t->fromWarehouse)->name)->filter()->unique()->values(),
            'transfers' => $m->transfers->count(), 'transfers_count' => $m->transfers->count(), 'approved' => $m->transfers->where('approval_status', Transfer::APPROVAL_APPROVED)->count(),
        ];
    }
}
