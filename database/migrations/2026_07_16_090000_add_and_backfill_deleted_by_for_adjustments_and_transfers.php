<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('adjustments', 'deleted_by')) {
            Schema::table('adjustments', function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
            });
        }

        $this->backfillDeletedBy('adjustments', 'App\\Models\\Adjustment');
        $this->backfillDeletedBy('transfers', 'App\\Models\\Transfer');
    }

    public function down(): void
    {
        if (Schema::hasColumn('adjustments', 'deleted_by')) {
            Schema::table('adjustments', function (Blueprint $table) {
                $table->dropColumn('deleted_by');
            });
        }
    }

    private function backfillDeletedBy(string $table, string $subjectType): void
    {
        $rows = DB::table($table)
            ->whereNotNull('deleted_at')
            ->whereNull('deleted_by')
            ->get(['id']);

        foreach ($rows as $row) {
            $causerId = DB::table('activity_log')
                ->where('subject_type', $subjectType)
                ->where('subject_id', $row->id)
                ->where('event', 'deleted')
                ->orderByDesc('id')
                ->value('causer_id');

            if ($causerId) {
                DB::table($table)->where('id', $row->id)->update(['deleted_by' => $causerId]);
            }
        }
    }
};
