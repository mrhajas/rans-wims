<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The social-security fund is shown as ZSSF: relabel the contribution lines already saved on salary records. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('salary_record_items')->where('label', 'like', 'NSSF%')->update(['label' => DB::raw("REPLACE(label, 'NSSF', 'ZSSF')")]);
    }

    public function down(): void
    {
        DB::table('salary_record_items')->where('label', 'like', 'ZSSF%')->update(['label' => DB::raw("REPLACE(label, 'ZSSF', 'NSSF')")]);
    }
};
