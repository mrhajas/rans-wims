<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\FundAllocation;
use App\Models\FundAllocationItem;
use App\Models\FundCategory;
use App\Models\FundPaymentMethod;
use App\Models\Item;
use App\Models\PaymentVoucher;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts DRAFT sample records in the LOCAL database so the "remove the draft status and approve" prompt can be tried: two each of Purchase Request, Purchase Order,
 * Payment Voucher and Fund Allocation (the second sheet is deliberately incomplete, so submitting it is refused), plus one Quotation. Every sample carries
 * "SAMPLE" in its reference, so --fresh removes exactly these and nothing else. They are written straight to the tables (no controllers), so nothing is
 * sent: no notification, SMS or email.
 */
class DraftSamplesProcurement extends Command
{
    protected $signature = 'draft-samples:procurement {--fresh : remove the previous samples first}';

    protected $description = 'Add Draft sample records (Purchase Request, Purchase Order, Payment Voucher, Fund Allocation, Quotation) to try "remove draft and approve"';

    public function handle(): int
    {
        $user = User::find(1);
        if (! $user) {
            $this->error('User 1 was not found.');

            return self::FAILURE;
        }
        auth()->setUser($user);

        if ($this->option('fresh')) {
            $this->removeSamples();
        }

        $warehouse = Warehouse::withoutGlobalScopes()->whereIn('status', ['Government', 'Private'])->orderBy('id')->first() ?: Warehouse::withoutGlobalScopes()->orderBy('id')->first();
        $supplier = Contact::where('category', 'Suppliers')->orderBy('id')->first();
        $items = Item::query()->with('unit:id,name,code')->orderBy('id')->limit(3)->get();
        $category = FundCategory::orderBy('sort_order')->first();
        $method = FundPaymentMethod::orderBy('sort_order')->first();
        if (! $warehouse || ! $supplier || $items->count() < 3 || ! $category || ! $method) {
            $this->error('The database needs a warehouse, a supplier contact, three items, a fund category and a payment method.');

            return self::FAILURE;
        }

        $made = [];
        DB::transaction(function () use ($user, $warehouse, $supplier, $items, $category, $method, &$made) {
            $made = array_merge(
                $this->purchaseRequests($user, $warehouse, $items),
                $this->purchaseOrders($user, $warehouse, $supplier, $items),
                $this->paymentVouchers($user, $warehouse, $supplier),
                $this->fundAllocations($user, $warehouse, $category, $method),
                $this->quotations($user, $warehouse, $supplier, $items),
            );
        });

        foreach ($made as $text) {
            $this->line($text);
        }
        $this->info(count($made) . ' sample record(s) ready (Draft). Run with --fresh to start over.');

        return self::SUCCESS;
    }

    private function unit($item): string
    {
        return (string) ($item->unit->code ?? $item->unit->name ?? 'pcs');
    }

    private function purchaseRequests(User $user, Warehouse $warehouse, $items): array
    {
        $out = [];
        foreach ([1 => 'complete, ready to approve', 2 => 'second sample, also complete'] as $n => $note) {
            $ref = 'PR-SAMPLE-' . $n;
            if (PurchaseRequest::withTrashed()->where('reference', $ref)->exists()) {
                $out[] = "Purchase Request  $ref  (already there)";
                continue;
            }
            $pr = new PurchaseRequest();
            $pr->forceFill([
                'reference' => $ref, 'warehouse_id' => $warehouse->id, 'user_id' => $user->id, 'status' => PurchaseRequest::STATUS_DRAFT,
                'date_requested' => now()->toDateString(), 'reason' => 'SAMPLE draft purchase request (' . $note . '). Safe to delete.',
            ])->save();
            foreach ($items->take($n === 1 ? 3 : 2) as $i => $item) {
                $pr->items()->create(['item_id' => $item->id, 'item_name' => $item->name, 'item_code' => $item->code, 'unit_label' => $this->unit($item), 'quantity' => 10 * ($i + 1), 'remarks' => 'SAMPLE']);
            }
            $out[] = "Purchase Request  $ref  Draft  ($note)";
        }

        return $out;
    }

    private function purchaseOrders(User $user, Warehouse $warehouse, Contact $supplier, $items): array
    {
        $out = [];
        foreach ([1 => 'complete, ready to approve', 2 => 'second sample, also complete'] as $n => $note) {
            $ref = 'PO-SAMPLE-' . $n;
            if (PurchaseOrder::withTrashed()->where('reference', $ref)->exists()) {
                $out[] = "Purchase Order  $ref  (already there)";
                continue;
            }
            $lines = $items->take($n === 1 ? 3 : 2)->values()->map(fn ($item, $i) => ['item' => $item, 'qty' => 5 * ($i + 1), 'price' => 12500 * ($i + 1)]);
            $subtotal = $lines->sum(fn ($l) => $l['qty'] * $l['price']);
            $tax = round($subtotal * 0.18, 2);
            $po = new PurchaseOrder();
            $po->forceFill([
                'reference' => $ref, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'user_id' => $user->id, 'status' => PurchaseOrder::STATUS_DRAFT,
                'date_issued' => now()->toDateString(), 'expected_delivery_date' => now()->addDays(14)->toDateString(), 'currency' => 'TZS',
                'tax_included' => 1, 'tax_percentage' => 18, 'subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $subtotal + $tax,
                'terms' => 'Payment within 30 days.', 'remarks' => 'SAMPLE draft purchase order (' . $note . '). Safe to delete.',
            ])->save();
            foreach ($lines as $l) {
                $po->items()->create(['item_id' => $l['item']->id, 'item_name' => $l['item']->name, 'item_code' => $l['item']->code, 'unit_label' => $this->unit($l['item']), 'quantity' => $l['qty'], 'unit_price' => $l['price']]);
            }
            $out[] = "Purchase Order  $ref  Draft  ($note)";
        }

        return $out;
    }

    private function paymentVouchers(User $user, Warehouse $warehouse, Contact $supplier): array
    {
        $out = [];
        foreach ([1 => ['complete, ready to approve', [['Being paid for : SAMPLE cement supply', 850000], ['Being paid for : SAMPLE delivery charges', 150000]]], 2 => ['second sample, also complete', [['Being paid for : SAMPLE site materials', 420000]]]] as $n => [$note, $lines]) {
            $no = 'PV-SAMPLE-' . $n;
            if (PaymentVoucher::withTrashed()->where('pv_number', $no)->exists()) {
                $out[] = "Payment Voucher  $no  (already there)";
                continue;
            }
            $total = array_sum(array_column($lines, 1));
            $pv = new PaymentVoucher();
            $pv->forceFill([
                'pv_number' => $no, 'payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL, 'type' => 'Rans Transportation', 'payee_contact_id' => $supplier->id, 'payee_name' => $supplier->name,
                'warehouse_id' => $warehouse->id, 'voucher_date' => now()->toDateString(), 'total_amount' => $total, 'amount_in_words' => strtoupper(number_format($total)) . ' SHILLINGS ONLY (SAMPLE)',
                'status' => PaymentVoucher::STATUS_DRAFT, 'prepared_by_user_id' => $user->id, 'remarks' => 'SAMPLE draft payment voucher (' . $note . '). Safe to delete.',
            ])->save();
            foreach ($lines as $i => [$text, $amount]) {
                $pv->items()->create(['line_number' => $i + 1, 'particulars' => $text, 'amount' => $amount]);
            }
            $out[] = "Payment Voucher  $no  Draft  ($note)";
        }

        return $out;
    }

    private function fundAllocations(User $user, Warehouse $warehouse, FundCategory $category, FundPaymentMethod $method): array
    {
        $out = [];
        $specs = [
            1 => ['SAMPLE Funds allocation (complete, ready to approve)', 'complete, ready to approve', [[1200000, 1200000], [800000, 800000]], true],
            // the split of the first item (300,000) does not equal what is allocated to it (500,000): submitting the sheet is refused until it is fixed
            2 => ['SAMPLE Funds allocation (incomplete, submitting is refused)', 'incomplete: payment split does not match the allocation', [[500000, 300000]], false],
        ];
        foreach ($specs as $n => [$title, $note, $rows, $ok]) {
            $ref = 'FA-SAMPLE-' . $n;
            if (FundAllocation::withTrashed()->where('reference', $ref)->exists()) {
                $out[] = "Fund Allocation  $ref  (already there)";
                continue;
            }
            $a = new FundAllocation();
            $a->forceFill([
                'reference' => $ref, 'allocation_date' => now()->toDateString(), 'title' => $title, 'notes' => 'SAMPLE draft allocation sheet. Safe to delete.',
                'status' => FundAllocation::STATUS_DRAFT, 'prepared_by_user_id' => $user->id,
            ])->save();
            foreach ($rows as $i => [$allocated, $split]) {
                $item = new FundAllocationItem();
                $item->forceFill([
                    'fund_allocation_id' => $a->id, 'fund_category_id' => $category->id, 'sort_order' => $i + 1, 'description' => 'SAMPLE item ' . ($i + 1),
                    'beneficiary' => 'SAMPLE beneficiary', 'warehouse_id' => $warehouse->id, 'request_amount' => $allocated, 'allocated_amount' => $allocated, 'notes' => 'SAMPLE',
                ])->save();
                $item->lines()->create(['fund_payment_method_id' => $method->id, 'amount' => $split, 'is_paid' => 0]);
            }
            $out[] = "Fund Allocation  $ref  Draft  ($note)";
        }

        return $out;
    }

    private function quotations(User $user, Warehouse $warehouse, Contact $supplier, $items): array
    {
        $ref = 'QT-SAMPLE-1';
        if (Quotation::withTrashed()->where('reference', $ref)->exists()) {
            return ["Quotation  $ref  (already there)"];
        }
        $lines = $items->take(2)->values()->map(fn ($item, $i) => ['item' => $item, 'qty' => 8 * ($i + 1), 'price' => 9000 * ($i + 1)]);
        $q = new Quotation();
        $q->forceFill([
            'reference' => $ref, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'status' => Quotation::STATUS_DRAFT, 'total' => $lines->sum(fn ($l) => $l['qty'] * $l['price']), 'remarks' => 'SAMPLE draft quotation. Safe to delete.', 'created_by' => $user->id,
        ])->save();
        foreach ($lines as $l) {
            $q->items()->create(['item_id' => $l['item']->id, 'item_name' => $l['item']->name, 'item_code' => $l['item']->code, 'unit_label' => $this->unit($l['item']), 'quantity' => $l['qty'], 'unit_price' => $l['price']]);
        }

        return ["Quotation  $ref  Draft"];
    }

    /** Takes out only what this command made (references containing SAMPLE), together with their lines. Nothing sent, nothing else touched. */
    private function removeSamples(): void
    {
        $removed = 0;

        $prs = PurchaseRequest::withTrashed()->where('reference', 'like', '%-SAMPLE-%')->pluck('id');
        DB::table('purchase_request_items')->whereIn('purchase_request_id', $prs)->delete();
        $removed += PurchaseRequest::withTrashed()->whereIn('id', $prs)->forceDelete();

        $pos = PurchaseOrder::withTrashed()->where('reference', 'like', '%-SAMPLE-%')->pluck('id');
        DB::table('purchase_order_items')->whereIn('purchase_order_id', $pos)->delete();
        $removed += PurchaseOrder::withTrashed()->whereIn('id', $pos)->forceDelete();

        $pvs = PaymentVoucher::withTrashed()->where('pv_number', 'like', '%-SAMPLE-%')->pluck('id');
        DB::table('payment_voucher_items')->whereIn('payment_voucher_id', $pvs)->delete();
        $removed += PaymentVoucher::withTrashed()->whereIn('id', $pvs)->forceDelete();

        $fas = FundAllocation::withTrashed()->where('reference', 'like', '%-SAMPLE-%')->pluck('id');
        $faItems = DB::table('fund_allocation_items')->whereIn('fund_allocation_id', $fas)->pluck('id');
        DB::table('fund_allocation_item_lines')->whereIn('fund_allocation_item_id', $faItems)->delete();
        DB::table('fund_allocation_items')->whereIn('id', $faItems)->delete();
        DB::table('fund_allocation_payers')->whereIn('fund_allocation_id', $fas)->delete();
        DB::table('fund_allocation_balances')->whereIn('fund_allocation_id', $fas)->delete();
        $removed += FundAllocation::withTrashed()->whereIn('id', $fas)->forceDelete();

        $qts = Quotation::withTrashed()->where('reference', 'like', '%-SAMPLE-%')->pluck('id');
        DB::table('quotation_items')->whereIn('quotation_id', $qts)->delete();
        $removed += Quotation::withTrashed()->whereIn('id', $qts)->forceDelete();

        $this->info("Previous samples removed ($removed records).");
    }
}
