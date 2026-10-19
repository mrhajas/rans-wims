<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issue_transaction_items', function (Blueprint $table) {
            if (! Schema::hasColumn('issue_transaction_items', 'account_id')) {
                $table->unsignedBigInteger('account_id')->nullable()->after('issue_transaction_id');
                $table->index('account_id');
            }
        });

        Schema::table('issue_return_items', function (Blueprint $table) {
            if (! Schema::hasColumn('issue_return_items', 'account_id')) {
                $table->unsignedBigInteger('account_id')->nullable()->after('issue_return_id');
                $table->index('account_id');
            }
        });

        DB::table('issue_transaction_items as iti')
            ->join('issue_transactions as it', 'it.id', '=', 'iti.issue_transaction_id')
            ->whereNull('iti.account_id')
            ->update(['iti.account_id' => DB::raw('it.account_id')]);

        DB::table('issue_return_items as iri')
            ->join('issue_returns as ir', 'ir.id', '=', 'iri.issue_return_id')
            ->whereNull('iri.account_id')
            ->update(['iri.account_id' => DB::raw('ir.account_id')]);
    }

    public function down(): void
    {
        Schema::table('issue_return_items', function (Blueprint $table) {
            if (Schema::hasColumn('issue_return_items', 'account_id')) {
                $table->dropIndex(['account_id']);
                $table->dropColumn('account_id');
            }
        });

        Schema::table('issue_transaction_items', function (Blueprint $table) {
            if (Schema::hasColumn('issue_transaction_items', 'account_id')) {
                $table->dropIndex(['account_id']);
                $table->dropColumn('account_id');
            }
        });
    }
};
