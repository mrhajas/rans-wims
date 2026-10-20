<?php

namespace App\Http\Middleware;

use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherBatch;
use Closure;
use Illuminate\Http\Request;

/**
 * A person assigned to projects only opens or acts on the Payment Vouchers (and batches) of those projects, the company-wide ones and the ones they prepared.
 * Applied to every route that carries a voucher / batch id, so opening, printing, editing, submitting, approving, paying, cancelling and deleting are all covered.
 */
class EnsurePaymentVoucherInScope
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $v = $request->route('payment_voucher');
        if ($v !== null) {
            $voucher = $v instanceof PaymentVoucher ? $v : PaymentVoucher::withTrashed()->find($v);
            abort_if($voucher && ! $voucher->visibleToUser($user), 403, __('This voucher belongs to a project you are not assigned to.'));
        }

        $b = $request->route('payment_voucher_batch');
        if ($b !== null) {
            $batch = $b instanceof PaymentVoucherBatch ? $b : PaymentVoucherBatch::withTrashed()->find($b);
            abort_if($batch && ! $batch->visibleToUser($user), 403, __('This batch belongs to a project you are not assigned to.'));
        }

        return $next($request);
    }
}
