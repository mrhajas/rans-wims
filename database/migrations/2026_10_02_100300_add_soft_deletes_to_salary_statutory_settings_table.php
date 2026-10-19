<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every model extending the app's base App\Models\Model gets SoftDeletes unconditionally — missed on the original create-table migration. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_statutory_settings', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('salary_statutory_settings', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
