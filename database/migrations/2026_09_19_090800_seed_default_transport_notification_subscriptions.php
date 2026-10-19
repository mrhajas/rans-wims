<?php

use App\Models\EmailFunctionSubscription;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Subscribes every current Super Admin to the 5 new transport-*
 * function keys by default, so Transport notifications aren't silently
 * empty until someone visits Email Notifications to configure them —
 * anyone can still be added/removed there afterward.
 */
return new class extends Migration
{
    private function functionKeys(): array
    {
        return [
            'transport-trip-created',
            'transport-trip-verified',
            'transport-trip-marked-paid',
            'transport-payment-voucher-created',
            'transport-trip-cancelled',
        ];
    }

    public function up(): void
    {
        $superAdmins = User::role('Super Admin')->get(['id', 'account_id']);

        foreach ($superAdmins as $user) {
            foreach ($this->functionKeys() as $functionKey) {
                EmailFunctionSubscription::query()->firstOrCreate(
                    ['user_id' => $user->id, 'function_key' => $functionKey],
                    ['account_id' => $user->account_id]
                );
            }
        }
    }

    public function down(): void
    {
        EmailFunctionSubscription::query()->whereIn('function_key', $this->functionKeys())->delete();
    }
};
