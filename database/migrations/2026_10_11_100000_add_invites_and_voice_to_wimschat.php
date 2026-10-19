<?php

use App\Services\FeatureManager;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group invitations (a pending invite keeps left_at set, so every existing "is a member" query ignores it until the
 * person accepts), the length of voice notes, and the Global Feature Control row for voice notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wimschat_members', function (Blueprint $table) {
            $table->string('invite_status', 10)->nullable()->after('left_at'); // pending | declined
            $table->timestamp('invited_at')->nullable()->after('invite_status');
            $table->index(['user_id', 'invite_status'], 'wc_member_invite_idx');
        });
        Schema::table('wimschat_attachments', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('height'); // voice notes
        });

        $f = SystemFeatureRegistry::features()['wimschat_voice'];
        DB::table('system_features')->updateOrInsert(['key' => 'wimschat_voice'], [
            'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'], 'is_enabled' => true,
            'sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'route_prefixes' => json_encode($f['route_prefixes']),
            'depends_on' => $f['depends_on'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        FeatureManager::flushCache();
    }

    public function down(): void
    {
        Schema::table('wimschat_members', function (Blueprint $table) {
            $table->dropIndex('wc_member_invite_idx');
            $table->dropColumn(['invite_status', 'invited_at']);
        });
        Schema::table('wimschat_attachments', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });
        DB::table('system_features')->where('key', 'wimschat_voice')->delete();
        FeatureManager::flushCache();
    }
};
