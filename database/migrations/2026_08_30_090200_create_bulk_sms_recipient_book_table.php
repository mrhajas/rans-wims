<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Autocomplete/history store — grows automatically every time a Bulk SMS
     * is sent to a given number, independent of the WIMS Contacts module, so
     * a name/number typed once suggests itself on the next compose without
     * requiring a formal Contact record.
     */
    public function up(): void
    {
        Schema::create('bulk_sms_recipient_book', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone');
            $table->unsignedInteger('use_count')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_sms_recipient_book');
    }
};
