<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared "Who Sees What" and "Who Gets Notified / SMS & Email" settings behind the workflow pages of Labour Lists, Project Certificates
 * and Salary (the same four tabs as the Material Request workflow page). `module` is the workflow's key ('labour', 'certificates',
 * 'salary'). Additive only: nothing exists until a Super Admin saves something on those pages, and until then each module behaves as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_notification_rules')) {
            Schema::create('workflow_notification_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('module', 32);
                $table->string('event_key', 64);
                $table->json('role_names')->nullable();
                $table->json('user_ids')->nullable();
                $table->boolean('notify_owner')->default(false);       // the record's creator (or, for Salary, the employee)
                $table->boolean('notify_step_owner')->default(false);  // whoever holds the next workflow step
                $table->boolean('sms_enabled')->default(false);
                $table->boolean('email_enabled')->default(false);
                $table->text('sms_template')->nullable();
                $table->string('email_subject')->nullable();
                $table->text('email_body')->nullable();
                $table->boolean('email_show_details')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['account_id', 'module', 'event_key'], 'wf_notif_unique');
            });
        }

        if (! Schema::hasTable('workflow_visibility_rules')) {
            Schema::create('workflow_visibility_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('module', 32);
                $table->string('role_name', 100);
                $table->json('statuses')->nullable();
                $table->boolean('own_only')->default(false);
                $table->boolean('assigned_only')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['account_id', 'module', 'role_name'], 'wf_vis_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_visibility_rules');
        Schema::dropIfExists('workflow_notification_rules');
    }
};
