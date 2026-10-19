<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Supplier Records Index page sorts by its own date column
 * (orderByDesc('date_issued'), etc.) — foreign keys and status columns were
 * already indexed, but the date columns driving that sort were not. Cheap,
 * behavior-neutral addition now while tables are small; pays off as
 * transaction volume grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->index('date_requested');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->index('date');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('date_issued');
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->index('date_received');
        });
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->index('invoice_date');
            $table->index('due_date');
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->index('date_requested');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropIndex(['date_requested']);
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['date_issued']);
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropIndex(['date_received']);
        });
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropIndex(['invoice_date']);
            $table->dropIndex(['due_date']);
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropIndex(['date_requested']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['paid_on']);
        });
    }
};
