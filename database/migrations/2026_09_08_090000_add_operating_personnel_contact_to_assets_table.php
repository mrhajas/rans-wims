<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('operating_personnel_type', 16)->default('Staff')->after('operating_personnel_staff_id');
            $table->foreignId('operating_personnel_contact_id')->nullable()->after('operating_personnel_type')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operating_personnel_contact_id');
            $table->dropColumn('operating_personnel_type');
        });
    }
};
