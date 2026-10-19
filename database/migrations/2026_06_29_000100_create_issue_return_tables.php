<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('issue_number')->unique();
            $table->date('issue_date');
            $table->string('project');
            $table->string('issued_to');
            $table->string('contact_number')->nullable();
            $table->string('department')->nullable();
            $table->date('expected_return_date')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('total_quantity', 15, 4)->default(0);
            $table->decimal('returned_quantity', 15, 4)->default(0);
            $table->decimal('balance_quantity', 15, 4)->default(0);
            $table->unsignedInteger('total_items')->default(0);
            $table->string('status')->default('open');
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });

        Schema::create('issue_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('issue_transaction_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('issued_quantity', 15, 4);
            $table->decimal('returned_quantity', 15, 4)->default(0);
            $table->decimal('damaged_quantity', 15, 4)->default(0);
            $table->decimal('lost_quantity', 15, 4)->default(0);
            $table->decimal('balance_quantity', 15, 4);
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('issue_transaction_id')->references('id')->on('issue_transactions')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('unit_id')->references('id')->on('units');
        });

        Schema::create('issue_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('issue_transaction_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('return_date');
            $table->string('returned_by');
            $table->string('received_by');
            $table->string('contact_number')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('issue_transaction_id')->references('id')->on('issue_transactions')->onDelete('cascade');
        });

        Schema::create('issue_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('issue_return_id');
            $table->unsignedBigInteger('issue_transaction_item_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('issued_qty', 15, 4);
            $table->decimal('previously_returned_qty', 15, 4)->default(0);
            $table->decimal('balance_qty', 15, 4);
            $table->decimal('returning_qty', 15, 4);
            $table->string('condition')->default('good');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('issue_return_id')->references('id')->on('issue_returns')->onDelete('cascade');
            $table->foreign('issue_transaction_item_id')->references('id')->on('issue_transaction_items')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('unit_id')->references('id')->on('units');
        });

        Schema::create('issue_return_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('issue_transaction_id')->nullable();
            $table->unsignedBigInteger('issue_return_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('action');
            $table->ipAddress('ip_address')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();

            $table->index(['issue_transaction_id']);
            $table->index(['issue_return_id']);
            $table->index(['warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_return_logs');
        Schema::dropIfExists('issue_return_items');
        Schema::dropIfExists('issue_returns');
        Schema::dropIfExists('issue_transaction_items');
        Schema::dropIfExists('issue_transactions');
    }
};
