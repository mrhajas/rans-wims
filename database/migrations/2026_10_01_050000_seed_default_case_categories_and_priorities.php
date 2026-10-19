<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Case Tracking's Category and Priority dropdowns have always been fed from
 * `case_categories`/`case_priorities`, but no seeder for either ever
 * existed — the tables have been empty since the module was built. This
 * seeds a construction-company-relevant starter list for each (sort_order
 * preserves this list's own order for categories; priority `level` runs
 * low-to-high so a future "sort by urgency" view has something to sort by).
 * Both inserts are keyed on the table's unique `name` column, so re-running
 * this migration (or a user later typing one of these exact names into the
 * "add new" picker) never creates a duplicate row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $categories = [
            'Safety Incident', 'Equipment Failure', 'Material Shortage', 'Quality Issue',
            'Schedule Delay', 'Site Access', 'Documentation', 'Permit & Compliance',
            'Subcontractor Issue', 'Client Communication', 'Design Change', 'Weather Delay',
            'Logistics', 'Procurement', 'Workmanship', 'HR & Labour',
            'Finance & Billing', 'IT & Systems', 'Environmental', 'Security',
            'Inspection', 'Warranty Claim',
        ];

        foreach ($categories as $index => $name) {
            DB::table('case_categories')->insertOrIgnore([
                'name' => $name,
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // level 1 = lowest urgency, 20 = highest. Colors reuse the four
        // tokens Index.vue's .ct-priority-* CSS already defines (slate/
        // amber/rose/red) rather than introducing new ones unseeded CSS
        // wouldn't style — bucketed roughly low/mid/high/critical.
        $priorities = [
            ['Trivial', 1, 'slate'], ['Cosmetic', 2, 'slate'], ['Low', 3, 'slate'],
            ['Minor', 4, 'slate'], ['Routine', 5, 'slate'],
            ['Planned', 6, 'amber'], ['Scheduled', 7, 'amber'], ['Normal', 8, 'amber'],
            ['Medium', 9, 'amber'], ['Deferred', 10, 'amber'],
            ['High', 11, 'rose'], ['Major', 12, 'rose'], ['Escalated', 13, 'rose'],
            ['Expedited', 14, 'rose'], ['On Hold', 15, 'rose'],
            ['Urgent', 16, 'red'], ['Critical', 17, 'red'], ['Blocker', 18, 'red'],
            ['Emergency', 19, 'red'], ['Immediate', 20, 'red'],
        ];

        foreach ($priorities as [$name, $level, $color]) {
            DB::table('case_priorities')->insertOrIgnore([
                'name' => $name,
                'level' => $level,
                'color' => $color,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: these are starter lookup values a Super
        // Admin may have since edited, renamed, or built real cases against
        // (case_categories/case_priorities have no cascading delete onto
        // case_tickets) — rolling back silently deleting them would be more
        // destructive than leaving them in place.
    }
};
