<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_agreements', function (Blueprint $table) {
            // Carried-over balance from work done before this agreement/system
            // tracked it. Never changes agreed_amount — it only adds into the
            // live outstandingBalance()/paymentStatus() calculation, so every
            // existing agreement (default 0) behaves exactly as before.
            $table->decimal('opening_balance', 15, 2)->default(0)->after('agreed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('workforce_agreements', function (Blueprint $table) {
            $table->dropColumn('opening_balance');
        });
    }
};
