<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the default Work Performed bank in a fixed, deliberate order —
     * "Construction" first so it wins the tie-break (orderByDesc('use_count'))
     * and reads as the sensible generic default. The list otherwise grows
     * purely from what users type on the form (see LabourController::rememberWorkItem()).
     */
    private const ITEMS = [
        'Construction', 'Mobilization', 'Site Preparation', 'Site Clearing', 'Excavation', 'Earthworks',
        'Backfilling', 'Compaction', 'Levelling', 'Demolition', 'Foundation Works', 'Concrete Works',
        'Reinforcement Works', 'Formwork', 'BlockWork', 'Brickwork', 'Masonry Works', 'Plastering', 'Rendering',
        'Roofing Works', 'Waterproofing', 'Carpentry Works', 'Steel Works', 'Welding Works', 'Painting Works',
        'Tiling Works', 'Flooring Works', 'Ceiling Works', 'Doors & Windows Installation', 'Electrical Works',
        'Plumbing Works', 'Drainage Works', 'HVAC Works', 'Road Works', 'Paving Works', 'Kerbing Works',
        'Fencing Works', 'Landscaping Works', 'Material Handling', 'Loading & Offloading', 'Equipment Assistance',
        'Site Cleaning', 'General Site Works', 'Maintenance & Repair', 'Inspection & Testing', 'Finishing Works',
        'Other Approved Work',
    ];

    public function up(): void
    {
        foreach (self::ITEMS as $text) {
            DB::table('labour_work_items')->updateOrInsert(
                ['text' => $text],
                ['use_count' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('labour_work_items')->whereIn('text', self::ITEMS)->delete();
    }
};
