<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assets are always kept in TZS. When an asset is bought in another currency the person enters an exchange rate and every cost amount is
 * converted to TZS when it is saved; these columns remember what was originally entered (the currency, the rate and the per-item
 * acquisition cost in that currency). Additive only: nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (! Schema::hasColumn('assets', 'original_currency')) {
                $table->string('original_currency', 8)->nullable()->after('currency');
            }
            if (! Schema::hasColumn('assets', 'exchange_rate')) {
                $table->decimal('exchange_rate', 18, 6)->nullable()->after('original_currency');
            }
            if (! Schema::hasColumn('assets', 'original_acquisition_cost')) {
                $table->decimal('original_acquisition_cost', 15, 2)->nullable()->after('exchange_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            foreach (['original_acquisition_cost', 'exchange_rate', 'original_currency'] as $c) {
                if (Schema::hasColumn('assets', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
