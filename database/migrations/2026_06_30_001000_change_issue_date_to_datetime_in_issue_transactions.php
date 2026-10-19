<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE issue_transactions MODIFY issue_date DATETIME NOT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE issue_transactions ALTER COLUMN issue_date TYPE timestamp(0) without time zone USING issue_date::timestamp');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE issue_transactions MODIFY issue_date DATE NOT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE issue_transactions ALTER COLUMN issue_date TYPE date USING issue_date::date');
        }
    }
};
