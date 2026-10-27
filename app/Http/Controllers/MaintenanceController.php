<?php

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Models\CheckinItem;
use App\Models\Checkout;
use App\Models\CheckoutItem;
use App\Models\Stock;
use App\Models\StockTrail;
use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MaintenanceController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /**
     * Wipes every Check-in, Check-out and Transfer, clears the stock trail and sets all stock to zero.
     *
     * This used to run on a plain GET address, so anything that merely VISITED it (the Responsive Audit opening every page, a link preview, a
     * browser pre-loading the address) emptied the whole system. It now needs a POST, a Super Admin, the person's own password and the typed
     * words RESET STOCK, and it is recorded in the activity log.
     */
    public function resetStock(Request $request)
    {
        abort_unless($request->user() && $request->user()->hasRole('Super Admin'), 403);
        $request->validate(['password' => 'required|string', 'confirm' => 'required|string']);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password is incorrect.')]);
        }
        if (trim($request->input('confirm')) !== 'RESET STOCK') {
            throw ValidationException::withMessages(['confirm' => __('Type RESET STOCK exactly to confirm.')]);
        }
        log_activity(__('Stock reset: all check-ins, check-outs and transfers removed, stock zeroed'), ['by' => $request->user()->name], $request->user(), 'Maintenance');

        DB::transaction(function () {
            CheckoutItem::withoutGlobalScopes()->delete();
            Checkout::withoutGlobalScopes()->delete();
            CheckinItem::withoutGlobalScopes()->delete();
            Checkin::withoutGlobalScopes()->delete();
            StockTrail::withoutGlobalScopes()->delete();
            Stock::withoutGlobalScopes()->update(['quantity' => 0, 'weight' => 0]);
            Transfer::withoutGlobalScopes()->delete();
        });

        return response()->json([
            'status' => 'ok',
            'message' => 'All checkouts and checkins removed. Stock quantities zeroed.',
        ]);
    }
}
