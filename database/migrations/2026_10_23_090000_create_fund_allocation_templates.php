<?php

use App\Models\Role;
use App\Support\FundAllocationTemplateSheet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Funds Allocation > Template: one saved template sheet (the monthly allocation as it is kept on paper) that can be changed and saved,
 * and turned into a Draft allocation sheet. Three abilities: open it, change and save it, generate a draft from it.
 * Super Admin and Admin get all three; every role that can already prepare sheets can open the template and generate from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fund_allocation_templates')) {
            Schema::create('fund_allocation_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name', 190);
                $table->longText('data');                       // JSON: title, date, usd_rate, note, balances[], sections[]
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! DB::table('fund_allocation_templates')->exists()) {
            DB::table('fund_allocation_templates')->insert(['name' => 'Funds allocation template', 'data' => json_encode(FundAllocationTemplateSheet::default()), 'created_at' => now(), 'updated_at' => now()]);
        }

        $names = ['read-fund-allocation-templates', 'update-fund-allocation-templates', 'generate-fund-allocation-templates'];
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->whereIn('name', ['Super Admin', 'Admin'])->get()->each(fn ($r) => $r->givePermissionTo($names));
        $prepare = Permission::query()->where('name', 'create-fund-allocations')->where('guard_name', 'web')->first();
        if ($prepare) {
            Role::query()->whereHas('permissions', fn ($q) => $q->where('permissions.id', $prepare->id))->get()->each(fn ($r) => $r->givePermissionTo(['read-fund-allocation-templates', 'generate-fund-allocation-templates']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_allocation_templates');
    }
};
