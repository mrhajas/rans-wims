<?php

use App\Support\RoleBlueprints;
use Illuminate\Database\Migrations\Migration;

/** Gives the Receptionist and Administration roles their Visitor Logs permissions (adds only: nothing a role already has is taken away). */
return new class extends Migration
{
    public function up(): void
    {
        RoleBlueprints::apply();
    }

    public function down(): void
    {
        // permissions granted to a role are left in place
    }
};
