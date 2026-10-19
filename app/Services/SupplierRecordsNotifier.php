<?php

namespace App\Services;

use App\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Model;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Hands a Supplier Records event to the Supplier Records workflow page's settings (Who Gets Notified / Messages). Events:
 * purchase_request_submitted / _approved / _rejected, payment_request_submitted / _approved / _rejected, quotation_received / _selected / _rejected.
 * All of them start switched off, so nothing is sent until a Super Admin turns an event on.
 */
class SupplierRecordsNotifier
{
    public static function purchaseRequest(string $event, PurchaseRequest $pr, ?User $actor): bool
    {
        try {
            return self::purchaseRequestNow($event, $pr, $actor);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Supplier Records notice failed', ['event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function purchaseRequestNow(string $event, PurchaseRequest $pr, ?User $actor): bool
    {
        $pr->loadMissing(['warehouse:id,name', 'user:id,name']);
        $verb = ['submitted' => 'submitted', 'approved' => 'approved', 'rejected' => 'rejected'][$event];

        return self::send('purchase_request_' . $event, $pr, $actor, 'Purchase Request ' . ucfirst($verb), $pr->reference . ' ' . $verb . '.', (int) $pr->user_id, [
            'reference' => $pr->reference, 'supplier' => '', 'project' => optional($pr->warehouse)->name ?? '', 'amount' => '', 'status' => $pr->status, 'requester' => optional($pr->user)->name ?? '',
        ], (string) $pr->approval_remarks, [__('Reference') => $pr->reference, __('Project') => optional($pr->warehouse)->name, __('Raised by') => optional($pr->user)->name, __('Reason') => $pr->reason], route('supplier-records.purchase-requests.show', $pr->id));
    }

    public static function paymentRequest(string $event, PaymentRequest $req, ?User $actor): bool
    {
        try {
            return self::paymentRequestNow($event, $req, $actor);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Supplier Records notice failed', ['event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function paymentRequestNow(string $event, PaymentRequest $req, ?User $actor): bool
    {
        $req->loadMissing(['supplier:id,name', 'warehouse:id,name', 'requestedBy:id,name']);
        $verb = ['submitted' => 'submitted', 'approved' => 'approved', 'rejected' => 'rejected'][$event];
        $amount = 'TZS ' . number_format((float) $req->amount, 0);

        return self::send('payment_request_' . $event, $req, $actor, 'Payment Request ' . ucfirst($verb), $req->reference . ' (' . $amount . ') ' . $verb . '.', (int) $req->requested_by_user_id, [
            'reference' => $req->reference, 'supplier' => optional($req->supplier)->name ?? '', 'project' => optional($req->warehouse)->name ?? '', 'amount' => $amount, 'status' => $req->status, 'requester' => optional($req->requestedBy)->name ?? '',
        ], (string) $req->approval_remarks, [__('Reference') => $req->reference, __('Supplier') => optional($req->supplier)->name, __('Amount') => $amount, __('Raised by') => optional($req->requestedBy)->name], route('supplier-records.payment-requests.show', $req->id));
    }

    /** $event: received, selected or rejected */
    public static function quotation(string $event, Quotation $q, ?User $actor): bool
    {
        try {
            return self::quotationNow($event, $q, $actor);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Supplier Records notice failed', ['event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function quotationNow(string $event, Quotation $q, ?User $actor): bool
    {
        $q->loadMissing(['supplier:id,name', 'warehouse:id,name', 'createdBy:id,name']);
        $amount = 'TZS ' . number_format((float) $q->total, 0);

        return self::send('quotation_' . $event, $q, $actor, 'Quotation ' . ucfirst($event), $q->reference . ' (' . $amount . ') ' . $event . '.', (int) $q->created_by, [
            'reference' => $q->reference, 'supplier' => optional($q->supplier)->name ?? '', 'project' => optional($q->warehouse)->name ?? '', 'amount' => $amount, 'status' => $q->status, 'requester' => optional($q->createdBy)->name ?? '',
        ], '', [__('Reference') => $q->reference, __('Supplier') => optional($q->supplier)->name, __('Total') => $amount, __('Recorded by') => optional($q->createdBy)->name], route('supplier-records.quotations.show', $q->id));
    }

    /**
     * Any other Supplier Records action (purchase orders, goods receipts, payments, invoices, ledger, reconciliations, supplier details,
     * edits and deletions). The reference, supplier, project, amount and owner are read from the record itself.
     *
     * @param string $what the record in words ("Purchase Order"), $action what was done to it ("submitted for approval")
     */
    public static function record(string $event, ?Model $record, ?User $actor, string $what, string $action, string $remarks = ''): bool
    {
        try {
            if (! $record) {
                return false;
            }
            $actor ??= auth()->user();
            $po = method_exists($record, 'purchaseOrder') ? $record->purchaseOrder : null;
            $supplier = $record instanceof \App\Models\Contact ? $record : (method_exists($record, 'supplier') ? $record->supplier : ($po?->supplier));
            $warehouse = method_exists($record, 'warehouse') ? $record->warehouse : ($po?->warehouse);
            $reference = (string) (($record->reference ?? null) ?: ($record->name ?? null) ?: ($supplier?->name ?? ''));
            $amountRaw = $record->total ?? $record->amount ?? null;
            $amount = $amountRaw !== null ? 'TZS ' . number_format((float) $amountRaw, 0) : '';
            $ownerId = (int) ($record->user_id ?? $record->created_by ?? $record->requested_by_user_id ?? $record->received_by_user_id ?? $record->paid_by_user_id ?? 0);
            $requester = $ownerId ? (string) User::whereKey($ownerId)->value('name') : '';
            $message = trim($what . ' ' . $reference . ' ' . $action . ' by ' . ($actor?->name ?? '') . '.' . ($remarks !== '' ? ' ' . $remarks : ''));

            return WorkflowMessenger::dispatch('supplier_records', $event, $record, $actor, [
                'account_id' => $actor?->account_id ?? 1, 'skip_actor' => true,
                'title' => $what . ' ' . ucfirst($action), 'message' => $message, 'url' => self::url($record), 'owner_user_id' => $ownerId,
                'warehouse_ids' => ($record->warehouse_id ?? $po?->warehouse_id) ? [$record->warehouse_id ?? $po?->warehouse_id] : null,
                'remarks' => $remarks,
                'dedupe' => $event . '|' . $record->getKey() . '|' . md5($message) . '|' . intdiv(time(), 20),
                'details' => array_filter([__('Reference') => $reference, __('Supplier') => $supplier?->name, __('Project') => $warehouse?->name, __('Amount') => $amount, __('Done by') => $actor?->name], fn ($v) => filled($v)),
                'values' => ['reference' => $reference, 'supplier' => (string) ($supplier?->name ?? ''), 'project' => (string) ($warehouse?->name ?? ''), 'amount' => $amount, 'status' => (string) ($record->status ?? ''), 'requester' => $requester],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Supplier Records notice failed', ['event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private static function url(Model $record): ?string
    {
        try {
            return match (true) {
                $record instanceof \App\Models\PurchaseRequest => route('supplier-records.purchase-requests.show', $record->id),
                $record instanceof Quotation => route('supplier-records.quotations.show', $record->id),
                $record instanceof \App\Models\PurchaseOrder => route('supplier-records.purchase-orders.show', $record->id),
                $record instanceof \App\Models\GoodsReceipt => route('supplier-records.goods-receipts.show', $record->id),
                $record instanceof PaymentRequest => route('supplier-records.payment-requests.show', $record->id),
                $record instanceof \App\Models\Payment => route('supplier-records.payments.show', $record->id),
                $record instanceof \App\Models\SupplierInvoice => route('supplier-records.supplier-invoices.show', $record->id),
                $record instanceof \App\Models\Contact => route('supplier-records.suppliers.show', $record->id),
                $record instanceof \App\Models\SupplierLedgerEntry, $record instanceof \App\Models\SupplierReconciliation => route($record instanceof \App\Models\SupplierLedgerEntry ? 'supplier-records.suppliers.ledger.show' : 'supplier-records.suppliers.reconciliations.index', $record->supplier_id),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private static function send(string $event, $record, ?User $actor, string $title, string $message, int $ownerId, array $values, string $remarks, array $details, string $url): bool
    {
        return WorkflowMessenger::dispatch('supplier_records', $event, $record, $actor, [
            'account_id' => $actor?->account_id ?? 1,
            'title' => $title, 'message' => $message, 'url' => $url, 'owner_user_id' => $ownerId, 'skip_actor' => true,
            'remarks' => $remarks, 'values' => $values, 'details' => $details,
        ]);
    }
}
