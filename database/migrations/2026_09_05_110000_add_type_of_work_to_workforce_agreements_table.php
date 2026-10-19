<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_agreements', function (Blueprint $table) {
            $table->string('type_of_work')->nullable()->after('payee_name');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_agreements', function (Blueprint $table) {
            $table->dropColumn('type_of_work');
        });
    }
};
