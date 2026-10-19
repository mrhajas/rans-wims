<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auto logout: minutes of inactivity before a person is signed out. NULL = the default (30), 0 = never sign me out. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'idle_timeout_minutes')) {
            Schema::table('users', fn (Blueprint $t) => $t->unsignedSmallInteger('idle_timeout_minutes')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'idle_timeout_minutes')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('idle_timeout_minutes'));
        }
    }
};
