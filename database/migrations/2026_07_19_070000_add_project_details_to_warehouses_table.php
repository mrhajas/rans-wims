<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->date('start_date')->nullable();
            $table->date('expected_end_date')->nullable();
            $table->string('for_who')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('external_body_personnel')->nullable();
            $table->decimal('site_budget', 15, 2)->nullable();
            $table->text('other_info')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'start_date',
                'expected_end_date',
                'for_who',
                'registration_number',
                'external_body_personnel',
                'site_budget',
                'other_info',
            ]);
        });
    }
};
