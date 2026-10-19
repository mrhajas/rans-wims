<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A loading / unloading day can be worked at a Project / site OR at some other place that is not a project. The other
 * places are a small growing list: a few are provided, and whatever a user types is kept for the next time.
 */
return new class extends Migration
{
    private const DEFAULTS = ['Head Office', 'Workshop', 'Port', 'Supplier Premises', 'Client Premises'];

    public function up(): void
    {
        Schema::create('transport_loading_places', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('transport_loading_attendances', function (Blueprint $table) {
            $table->string('other_place', 80)->nullable()->after('warehouse_id');
        });

        foreach (self::DEFAULTS as $name) {
            DB::table('transport_loading_places')->insertOrIgnore(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('transport_loading_attendances', function (Blueprint $table) {
            $table->dropColumn('other_place');
        });
        Schema::dropIfExists('transport_loading_places');
    }
};
