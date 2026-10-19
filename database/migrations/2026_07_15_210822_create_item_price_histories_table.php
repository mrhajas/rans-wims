<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_price_histories')) {
            return;
        }

        Schema::create('item_price_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('item_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->decimal('price', 15, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_price_histories');
    }
};
