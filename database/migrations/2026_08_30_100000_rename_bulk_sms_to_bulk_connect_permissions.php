<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Bulk SMS is generalizing into a multi-channel "Bulk Connect" submodule
     * (WhatsApp/Meta/SMS). Renamed in place (UPDATE, not delete+recreate) so
     * the Super Admin/Admin grants already made to the old permission names
     * carry over automatically via the unchanged permission_id.
     */
    protected array $renames = [
        'read-wims-connect-bulk-sms' => 'read-wims-connect-bulk-connect',
        'send-wims-connect-bulk-sms' => 'send-wims-connect-bulk-connect',
        'print-wims-connect-bulk-sms' => 'print-wims-connect-bulk-connect',
    ];

    public function up(): void
    {
        foreach ($this->renames as $old => $new) {
            DB::table('permissions')->where('name', $old)->update(['name' => $new]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (array_flip($this->renames) as $new => $old) {
            DB::table('permissions')->where('name', $new)->update(['name' => $old]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
