<?php

use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['wims_connect_bulk_connect'];

        // On a fresh install the earlier seed migration already inserted the registry's current keys, so the new key
        // may exist: then the old row is simply redundant.
        if (DB::table('system_features')->where('key', 'wims_connect_bulk_connect')->exists()) {
            DB::table('system_features')->where('key', 'wims_connect_bulk_sms')->delete();

            return;
        }

        DB::table('system_features')->where('key', 'wims_connect_bulk_sms')->update([
            'key' => 'wims_connect_bulk_connect',
            'name' => $feature['name'],
            'description' => $feature['description'],
            'route_prefixes' => json_encode($feature['route_prefixes']),
            'depends_on' => $feature['depends_on'] ?? null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'wims_connect_bulk_connect')->update([
            'key' => 'wims_connect_bulk_sms',
            'updated_at' => now(),
        ]);
    }
};
