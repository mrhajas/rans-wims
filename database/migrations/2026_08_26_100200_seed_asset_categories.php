<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the 11 top-level asset category groups from the spec, each with
     * a handful of example sub-types as children. Admin-editable afterward
     * via the Asset Categories screen — this is only the starting set.
     */
    private const GROUPS = [
        'Land & Buildings' => ['Land', 'Buildings', 'Offices', 'Site Buildings', 'Stores / Warehouses'],
        'Vehicles' => ['Cars', 'Pickups', 'Trucks', 'Lorries', 'Buses', 'Motorcycles', 'Trailers'],
        'Construction Machinery' => ['Excavators', 'Loaders', 'Bulldozers', 'Rollers', 'Cranes', 'Concrete Mixers', 'Compressors', 'Generators', 'Compactors', 'Pumps'],
        'Tools & Equipment' => ['Power Tools', 'Hand Tools', 'Survey Equipment', 'Welding Equipment', 'Safety Equipment', 'Ladders', 'Scaffolding Equipment'],
        'IT & Electronics' => ['Laptops', 'Desktop Computers', 'Monitors', 'Printers', 'Servers', 'Network Equipment', 'Phones', 'Tablets', 'CCTV Equipment'],
        'Office Equipment' => ['Desks', 'Chairs', 'Cabinets', 'Meeting Equipment', 'Air Conditioners', 'Office Appliances'],
        'Furniture & Fixtures' => [],
        'Plant & Equipment' => [],
        'Electrical Equipment' => [],
        'Communication Equipment' => [],
        'Other Assets' => [],
    ];

    public function up(): void
    {
        foreach (self::GROUPS as $group => $children) {
            $parentId = DB::table('asset_categories')->where('name', $group)->whereNull('parent_id')->value('id');

            if (! $parentId) {
                $parentId = DB::table('asset_categories')->insertGetId([
                    'name' => $group,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($children as $child) {
                DB::table('asset_categories')->updateOrInsert(
                    ['name' => $child, 'parent_id' => $parentId],
                    ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        $parentIds = DB::table('asset_categories')->whereIn('name', array_keys(self::GROUPS))->whereNull('parent_id')->pluck('id');
        DB::table('asset_categories')->whereIn('parent_id', $parentIds)->delete();
        DB::table('asset_categories')->whereIn('id', $parentIds)->delete();
    }
};
