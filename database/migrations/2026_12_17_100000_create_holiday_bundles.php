<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holiday Greetings, bundles and automatic sending: a Greeting List Bundle is a named group of people taken from the greeting list (Staff, Clients...), so a greeting can go to
 * one bundle instead of everybody; a holiday can also be set to send itself a chosen time before it begins.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holiday_bundles')) {
            Schema::create('holiday_bundles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('name', 120);
                $t->string('note', 300)->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('holiday_bundle_contacts')) {
            Schema::create('holiday_bundle_contacts', function (Blueprint $t) {
                $t->unsignedBigInteger('bundle_id');
                $t->unsignedBigInteger('contact_id');
                $t->primary(['bundle_id', 'contact_id']);
                $t->index('contact_id');
            });
        }
        Schema::table('holiday_days', function (Blueprint $t) {
            if (! Schema::hasColumn('holiday_days', 'auto_plan')) {
                $t->json('auto_plan')->nullable();                       // {enabled, minutes, bundle_id}; null = follow the Settings
            }
            if (! Schema::hasColumn('holiday_days', 'auto_attempted_at')) {
                $t->timestamp('auto_attempted_at')->nullable();          // set when the automatic send ran, so it never runs twice
            }
        });
        Schema::table('holiday_deliveries', function (Blueprint $t) {
            if (! Schema::hasColumn('holiday_deliveries', 'bundle_name')) {
                $t->string('bundle_name', 120)->nullable();              // the bundle the greeting was sent to (a snapshot: the bundle may be renamed later)
            }
        });
    }

    public function down(): void
    {
        Schema::table('holiday_deliveries', fn (Blueprint $t) => Schema::hasColumn('holiday_deliveries', 'bundle_name') ? $t->dropColumn('bundle_name') : null);
        Schema::table('holiday_days', function (Blueprint $t) {
            foreach (['auto_plan', 'auto_attempted_at'] as $c) {
                if (Schema::hasColumn('holiday_days', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        Schema::dropIfExists('holiday_bundle_contacts');
        Schema::dropIfExists('holiday_bundles');
    }
};
