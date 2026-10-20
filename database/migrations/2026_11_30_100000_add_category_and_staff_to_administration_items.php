<?php

use App\Services\Administration\AdminCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administration items get a category (Fuel, Cleaning, Allowance...) and an optional staff member, and every line keeps both as they were when it was made, so the
 * reports can follow fuel, a category, or one person month by month. Existing items get a guessed category and are linked to the staff member of an exact name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_items') && ! Schema::hasColumn('admin_items', 'category')) {
            Schema::table('admin_items', function (Blueprint $t) {
                $t->string('category', 60)->nullable()->after('name_key')->index();
                $t->unsignedBigInteger('staff_id')->nullable()->after('category')->index();
            });
        }
        if (Schema::hasTable('admin_document_items') && ! Schema::hasColumn('admin_document_items', 'category')) {
            Schema::table('admin_document_items', function (Blueprint $t) {
                $t->string('category', 60)->nullable()->after('beneficiary_user_id')->index();
                $t->unsignedBigInteger('staff_id')->nullable()->after('category')->index();
            });
        }
        if (Schema::hasTable('admin_items')) {
            AdminCategories::backfill();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admin_document_items', 'category')) {
            Schema::table('admin_document_items', fn (Blueprint $t) => $t->dropColumn(['category', 'staff_id']));
        }
        if (Schema::hasColumn('admin_items', 'category')) {
            Schema::table('admin_items', fn (Blueprint $t) => $t->dropColumn(['category', 'staff_id']));
        }
    }
};
