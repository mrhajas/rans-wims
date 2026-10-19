<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_section_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('section_key');
            $table->unsignedInteger('visit_count')->default(0);
            $table->timestamp('last_visited_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_section_visits');
    }
};
