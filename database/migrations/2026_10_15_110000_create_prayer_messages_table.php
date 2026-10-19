<?php

use App\Services\Prayer\PrayerContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verses and sayings shown at prayer time. Editable in Prayer Times > Messages.
        Schema::create('prayer_messages', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10)->default('ayah');          // ayah | hadith
            $table->string('prayer', 10)->default('any');         // any | fajr | dhuhr | asr | maghrib | isha
            $table->text('arabic')->nullable();
            $table->text('text_en');
            $table->text('text_sw')->nullable();
            $table->string('reference', 190)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_custom')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('prayer_messages')->insert(array_map(fn ($m) => $m + ['text_sw' => null, 'is_active' => true, 'is_custom' => false, 'created_at' => $now, 'updated_at' => $now], PrayerContent::defaults()));
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_messages');
    }
};
