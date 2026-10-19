<?php

use App\Models\Account;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Account::all(['id'])->each(function ($account) {
            Setting::firstOrCreate(
                ['tec_key' => 'impersonation_expiry_minutes', 'account_id' => $account->id],
                ['tec_value' => (string) config('impersonation.default_expiry_minutes', 30)]
            );
        });
    }

    public function down(): void
    {
        Setting::where('tec_key', 'impersonation_expiry_minutes')->delete();
    }
};
