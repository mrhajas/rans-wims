<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adhan recordings added by the owner (the two built-in ones live in code). Chosen at Prayer Times > Settings > Adhan.
        Schema::create('prayer_adhans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('file', 255);               // path under public/, e.g. audio/adhan/custom/xxxx.mp3
            $table->string('original_name', 190)->nullable();
            $table->string('mime', 80)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_adhans');
    }
};
