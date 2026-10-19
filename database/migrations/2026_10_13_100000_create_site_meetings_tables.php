<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site Meetings: the management's weekly meeting schedule per site, and the minutes document the site engineers fill in
 * for each meeting. The minutes' layout (sections / columns) is a setting; each meeting keeps its own snapshot of it, so
 * changing the layout later never rewrites an old record. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('reference', 40)->unique();
            $table->unsignedInteger('meeting_no')->default(1);
            $table->unsignedBigInteger('warehouse_id');
            $table->string('title', 190)->nullable();
            $table->date('meeting_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('venue', 190)->nullable();
            $table->unsignedBigInteger('chairperson_id')->nullable();
            $table->unsignedBigInteger('recorder_id')->nullable();
            $table->string('status', 20)->default('Scheduled');
            $table->text('status_reason')->nullable();
            $table->date('postponed_to')->nullable();
            $table->text('agenda')->nullable();
            $table->text('info')->nullable();
            $table->text('remarks')->nullable();
            $table->json('template')->nullable();
            $table->string('report_status', 20)->default('Not started');
            $table->timestamp('report_started_at')->nullable();
            $table->unsignedBigInteger('report_started_by')->nullable();
            $table->timestamp('report_submitted_at')->nullable();
            $table->unsignedBigInteger('report_submitted_by')->nullable();
            $table->timestamp('report_finalised_at')->nullable();
            $table->unsignedBigInteger('report_finalised_by')->nullable();
            $table->string('series_uuid', 40)->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'meeting_date']);
            $table->index(['warehouse_id', 'meeting_date']);
        });

        Schema::create('site_meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_meeting_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name', 190);
            $table->string('designation', 190)->nullable();
            $table->string('organisation', 190)->nullable();
            $table->string('attendance', 20)->default('Invited');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('site_meeting_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_meeting_id');
            $table->string('section_key', 60);
            $table->unsignedInteger('sort')->default(0);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['site_meeting_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_meeting_rows');
        Schema::dropIfExists('site_meeting_attendees');
        Schema::dropIfExists('site_meetings');
    }
};
