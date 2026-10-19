<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected string $previousName = 'RAI';

    protected string $previousDescription = 'RAI — the natural-language data assistant.';

    public function up(): void
    {
        $feature = SystemFeatureRegistry::features()['ai_assistant'];

        DB::table('system_features')->where('key', 'ai_assistant')->update([
            'name' => $feature['name'],
            'description' => $feature['description'],
            'updated_at' => now(),
        ]);

        FeatureManager::flushCache();
    }

    public function down(): void
    {
        DB::table('system_features')->where('key', 'ai_assistant')->update([
            'name' => $this->previousName,
            'description' => $this->previousDescription,
            'updated_at' => now(),
        ]);

        FeatureManager::flushCache();
    }
};
