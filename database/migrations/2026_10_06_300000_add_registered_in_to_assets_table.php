<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicles used to be one shared list: the Asset Register and Transport Vehicles both showed every asset in the
 * Vehicles category. `registered_in` records which module an asset belongs to, so each module shows only its own:
 *   'assets'    - registered through the Asset Register (shown there only)
 *   'transport' - registered through Transport Vehicles (shown there only)
 * Existing data keeps appearing where it did before, as far as that can be told: every asset in the Vehicles tree stays
 * in Transport (it was listed there until now), except those that were clearly created through the Asset form (an
 * "Asset has been created" entry in the activity log with no "Vehicle registered" entry). Everything else is an
 * Asset Register asset. Additive only: one column, no rows deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('assets', 'registered_in')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->string('registered_in', 12)->default('assets')->after('asset_category_id')->index();
            });
        }

        $vehicleCategoryIds = DB::table('asset_categories')
            ->where('name', 'Vehicles')
            ->orWhereIn('parent_id', DB::table('asset_categories')->where('name', 'Vehicles')->pluck('id'))
            ->pluck('id');

        if ($vehicleCategoryIds->isEmpty()) {
            return;
        }

        $createdViaAssetForm = collect();
        if (Schema::hasTable('activity_log')) {
            $registered = DB::table('activity_log')->where('subject_type', 'like', '%Asset')->where('description', 'Vehicle registered.')->pluck('subject_id');
            $created = DB::table('activity_log')->where('subject_type', 'like', '%Asset')->where('description', 'like', 'Asset has been created%')->pluck('subject_id');
            $createdViaAssetForm = $created->diff($registered);
        }

        DB::table('assets')
            ->whereIn('asset_category_id', $vehicleCategoryIds)
            ->when($createdViaAssetForm->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $createdViaAssetForm))
            ->update(['registered_in' => 'transport']);
    }

    public function down(): void
    {
        // Additive and harmless to keep.
    }
};
