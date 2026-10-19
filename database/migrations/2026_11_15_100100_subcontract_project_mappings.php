<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Project Name Mapping: the project / location names typed in the owner's Excel (work group names) matched to WIMS projects (warehouses). A mapping is a draft until it is confirmed. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subcontract_project_mappings')) {
            return;
        }
        Schema::create('subcontract_project_mappings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id')->default(1);
            $t->string('name_key', 190)->unique();            // upper-cased, whitespace collapsed Excel name
            $t->string('excel_name', 190);                    // the name as typed (most frequent spelling)
            $t->unsignedBigInteger('warehouse_id')->nullable()->index();
            $t->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->string('note', 500)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcontract_project_mappings');
    }
};
