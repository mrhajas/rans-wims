<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    // Archive Tree: folder levels (category > subcategory, source > organisation, project > category, year > category)
    // are GROUP BYs over these indexes, and the dashboard's folder/size breakdowns read the covering ones.
    public function up(): void
    {
        Schema::table('archive_records', function (Blueprint $table) {
            $table->index(['category', 'subcategory', 'deleted_at'], 'archive_records_tree_category');
            $table->index(['source_type', 'organization', 'deleted_at'], 'archive_records_tree_source');
            $table->index(['warehouse_id', 'category', 'deleted_at'], 'archive_records_tree_project');
            $table->index(['document_date', 'category', 'deleted_at'], 'archive_records_tree_year');
            $table->index(['category', 'deleted_at', 'files_count', 'storage_bytes'], 'archive_records_cat_totals');
            $table->index(['source_type', 'deleted_at', 'files_count', 'storage_bytes'], 'archive_records_src_totals');
        });

        Permission::query()->firstOrCreate(['name' => 'read-archive-tree', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('archive_records', function (Blueprint $table) {
            foreach (['archive_records_tree_category', 'archive_records_tree_source', 'archive_records_tree_project', 'archive_records_tree_year', 'archive_records_cat_totals', 'archive_records_src_totals'] as $i) {
                $table->dropIndex($i);
            }
        });

        Permission::query()->where('name', 'read-archive-tree')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
