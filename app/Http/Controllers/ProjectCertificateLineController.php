<?php

namespace App\Http\Controllers;

use App\Models\ProjectCertificate;
use App\Models\ProjectCertificateLine;
use App\Services\ProjectCertificates\CertificateCalculationEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A single "replace the whole line set" endpoint rather than incremental
 * per-line CRUD — matches how the Builder UI actually works (the QS edits
 * an in-memory array of lines through the wizard, then saves it as one
 * unit), and keeps cycle validation simple (always validated against the
 * complete proposed set, never a partial one).
 */
class ProjectCertificateLineController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function sync(Request $request, ProjectCertificate $certificate)
    {
        $this->authorize('update', $certificate);
        abort_if($certificate->is_locked, 422, 'This certificate has been issued and its lines are locked. Use Revise to make changes.');

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.line_key' => 'required|string|max:100',
            'lines.*.label' => 'required|string|max:190',
            'lines.*.type' => 'required|in:' . implode(',', ProjectCertificateLine::types()),
            'lines.*.sort_order' => 'required|integer',
            'lines.*.operands' => 'nullable|array',
            'lines.*.value' => 'nullable|numeric',
        ])['lines'];

        $keys = collect($data)->pluck('line_key');
        abort_if($keys->count() !== $keys->unique()->count(), 422, 'Line keys must be unique within a certificate.');

        // Validate the proposed set (cycle/reference checks) BEFORE writing
        // anything — never leave the certificate in a half-saved, broken state.
        app(CertificateCalculationEngine::class)->validateAndOrder($data);

        DB::transaction(function () use ($certificate, $data) {
            $certificate->lines()->delete();
            foreach ($data as $line) {
                ProjectCertificateLine::create([
                    'certificate_id' => $certificate->id,
                    'line_key' => $line['line_key'],
                    'label' => $line['label'],
                    'type' => $line['type'],
                    'sort_order' => $line['sort_order'],
                    'operands' => $line['operands'] ?? null,
                    'value' => $line['value'] ?? 0,
                ]);
            }
        });

        app(CertificateCalculationEngine::class)->evaluate($certificate);

        log_activity('Project Certificate Calculation Updated', ['certificate_number' => $certificate->certificate_number, 'line_count' => count($data)], $certificate, 'Project Certificate');

        \App\Services\CertificateNotifier::certificate('calculation_saved', $certificate->fresh(), $request->user(), __('Certificate Calculation Saved'), $certificate->reference . ': the calculation was saved by ' . $request->user()->name . '.');

        return back()->with('message', __('Calculation saved.'));
    }
}
