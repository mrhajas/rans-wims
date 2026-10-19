<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors material_request_workflow_steps exactly — see
 * App\Models\ProjectCertificateWorkflowStep for the same
 * softcoded-assignment pattern (Super Admin/Admin always allowed
 * regardless of these rows; everyone else per role_names/user_ids).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_certificate_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('step_key');
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'step_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_certificate_workflow_steps');
    }
};
