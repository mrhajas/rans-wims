<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The calculation engine's data: each row is one line in the certificate's
 * build-up. `operands` shape depends on `type` — see
 * App\Services\ProjectCertificates\CertificateCalculationEngine's docblock
 * for the exact contract per type. No formula-string/eval() involved —
 * every "formula" is structured data referencing other lines by line_key,
 * which is what makes cycle detection and a no-code UI possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_certificate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained('project_certificates')->cascadeOnDelete();
            $table->string('line_key');
            $table->string('label');
            $table->enum('type', ['input', 'addition', 'deduction', 'percentage', 'reference', 'formula', 'subtotal', 'total']);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('operands')->nullable();
            // The last-computed result — cached so the UI/PDF don't need to
            // re-run the engine just to display a value, but always
            // recomputed server-side on any save via CertificateCalculationEngine.
            $table->decimal('value', 18, 2)->default(0);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['certificate_id', 'line_key']);
            $table->index(['certificate_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_certificate_lines');
    }
};
