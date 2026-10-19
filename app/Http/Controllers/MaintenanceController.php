<?php

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Models\CheckinItem;
use App\Models\Checkout;
use App\Models\CheckoutItem;
use App\Models\Stock;
use App\Models\StockTrail;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends Controller
{
    public function resetStock()
    {
        abort_unless(auth()->user() && auth()->user()->hasRole('Super Admin'), 403);

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
