<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_types', function (Blueprint $table) {
            $table->id();
            $table->string('text')->unique();
            $table->unsignedInteger('use_count')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('compliance_issuing_authorities', function (Blueprint $table) {
            $table->id();
            $table->string('text')->unique();
            $table->unsignedInteger('use_count')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_types');
        Schema::dropIfExists('compliance_issuing_authorities');
    }
};
