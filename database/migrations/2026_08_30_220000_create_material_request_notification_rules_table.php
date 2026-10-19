<?php

use App\Models\MaterialRequestNotificationRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_request_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('event_key', 50);
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->boolean('notify_requester')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'event_key']);
        });

        // Seeded to reproduce today's exact notification behaviour (plus the
        // two previously-missing Rejected/Closed requester notices) — nothing
        // changes for anyone until a Super Admin edits a rule.
        \App\Models\Account::all(['id'])->each(function ($account) {
            // Only the columns this migration created: the model's defaults also carry columns added by later migrations.
            $columns = array_flip(Schema::getColumnListing('material_request_notification_rules'));
            foreach (MaterialRequestNotificationRule::defaults() as $eventKey => $rule) {
                $rule = array_intersect_key($rule, $columns);
                foreach (['role_names', 'user_ids'] as $json) {
                    if (array_key_exists($json, $rule) && is_array($rule[$json])) {
                        $rule[$json] = json_encode($rule[$json]);
                    }
                }
                \Illuminate\Support\Facades\DB::table('material_request_notification_rules')->updateOrInsert(
                    ['account_id' => $account->id, 'event_key' => $eventKey],
                    $rule + ['created_at' => now(), 'updated_at' => now()]
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_notification_rules');
    }
};
