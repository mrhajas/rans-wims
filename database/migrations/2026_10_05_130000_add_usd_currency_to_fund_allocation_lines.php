<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // USD on a funds-allocation sheet is a CURRENCY, not a payment method: the planner chooses to pay a part of an item
    // in USD and gives the exchange rate (TZS per 1 USD); the USD to pay is the allocated TZS value / rate.
    //  - fund_payment_methods.currency   : 'TZS' (default) or 'USD' - a method flagged USD is the "pay in USD" option
    //  - fund_allocation_item_lines.exchange_rate / usd_amount : the rate used and the resulting USD amount
    // `amount` stays the TZS value of the line, so sheet totals and reconciliation are unchanged.
    public function up(): void
    {
        if (! Schema::hasColumn('fund_payment_methods', 'currency')) {
            Schema::table('fund_payment_methods', function (Blueprint $table) {
                $table->string('currency', 3)->default('TZS')->after('short_label');
            });
        }

        if (! Schema::hasColumn('fund_allocation_item_lines', 'exchange_rate')) {
            Schema::table('fund_allocation_item_lines', function (Blueprint $table) {
                $table->decimal('exchange_rate', 18, 6)->nullable()->after('amount');
                $table->decimal('usd_amount', 18, 2)->nullable()->after('exchange_rate');
            });
        }

        DB::table('fund_payment_methods')->where('short_label', 'USD')->update(['currency' => 'USD']);

        // Lines that were already planned as "USD" before this change: use the rate of the sheet's own USD balance
        // (when it has one) so they show a USD amount too. Lines on sheets without a USD balance stay unrated and
        // ask for a rate the next time the sheet is edited.
        $usdIds = DB::table('fund_payment_methods')->where('currency', 'USD')->pluck('id');
        if ($usdIds->isNotEmpty()) {
            $rates = DB::table('fund_allocation_balances')->where('currency', 'USD')->where('exchange_rate', '>', 0)->whereNull('deleted_at')
                ->orderBy('id')->pluck('exchange_rate', 'fund_allocation_id');
            $lines = DB::table('fund_allocation_item_lines as l')->join('fund_allocation_items as i', 'i.id', '=', 'l.fund_allocation_item_id')
                ->whereIn('l.fund_payment_method_id', $usdIds)->whereNull('l.exchange_rate')->get(['l.id', 'l.amount', 'i.fund_allocation_id']);
            foreach ($lines as $l) {
                $rate = (float) ($rates[$l->fund_allocation_id] ?? 0);
                if ($rate > 0) {
                    DB::table('fund_allocation_item_lines')->where('id', $l->id)->update(['exchange_rate' => $rate, 'usd_amount' => round((float) $l->amount / $rate, 2)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('fund_allocation_item_lines', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'usd_amount']);
        });
        Schema::table('fund_payment_methods', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
