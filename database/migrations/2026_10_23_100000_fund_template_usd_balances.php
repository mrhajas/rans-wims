<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The two USD balances of the starting template become real USD balances (with the exchange rate), so Cash TZS and Cash USD are told apart. */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('fund_allocation_templates')->orderBy('id')->first();
        if (! $row) {
            return;
        }
        $d = json_decode($row->data, true);
        $rate = (float) ($d['usd_rate'] ?? 2470) ?: 2470;
        $changed = false;
        foreach ($d['balances'] ?? [] as $k => $b) {
            $label = strtoupper((string) ($b['label'] ?? ''));
            if (! isset($b['exchange_rate'])) {
                $d['balances'][$k]['exchange_rate'] = 1;
                $d['balances'][$k]['fund_account_id'] = null;
                $changed = true;
            }
            if (($b['currency'] ?? 'TZS') === 'TZS' && str_contains($label, 'USD')) {
                // only the untouched starting rows: "CASH BALANCE USD($18,700)" and "BANK USD BALANCE-4000"
                if (str_contains($label, '18,700') && (float) ($b['amount'] ?? 0) == 0) {
                    $d['balances'][$k]['amount'] = 18700;
                }
                $d['balances'][$k]['currency'] = 'USD';
                $d['balances'][$k]['exchange_rate'] = $rate;
                $changed = true;
            }
        }
        if ($changed) {
            DB::table('fund_allocation_templates')->where('id', $row->id)->update(['data' => json_encode($d), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // nothing to undo
    }
};
