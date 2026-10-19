<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TermsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /**
     * Open to every authenticated user (read-terms is in Authorizable's
     * $allowed list) — everyone using WIMS needs to be able to read this,
     * not just users with a specific permission.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $version = (int) (get_settings('terms_version') ?: 1);

        // "log the Terms and Conditions once read by every user" — logged
        // once per version, not on every single visit: a user re-opening a
        // page they've already acknowledged this version of isn't a new
        // read event worth an activity-log row.
        if ($user && (int) $user->terms_accepted_version !== $version) {
            $user->forceFill([
                'terms_accepted_version' => $version,
                'terms_accepted_at' => now(),
            ])->save();

            log_activity(
                __(':user read the Terms & Conditions (version :version).', ['user' => $user->name, 'version' => $version]),
                ['version' => $version],
                $user,
                'Terms'
            );
        }

        return Inertia::render('Terms/Show', [
            'content' => get_settings('terms_content') ?: '',
            'version' => $version,
            'acceptedAt' => optional($user?->terms_accepted_at)->toIso8601String(),
        ]);
    }

    public function print(Request $request)
    {
        return view('terms.print', $this->printData(false));
    }

    public function pdf(Request $request)
    {
        return Pdf::loadView('terms.print', $this->printData(true))
            ->setPaper('a4')
            ->download('wims-terms-and-conditions.pdf');
    }

    private function printData(bool $isPdf): array
    {
        return [
            'content' => get_settings('terms_content') ?: '',
            'version' => (int) (get_settings('terms_version') ?: 1),
            'companyName' => optional(auth()->user()->account ?? null)->name,
            'generatedBy' => optional(auth()->user())->name,
            'isPdf' => $isPdf,
        ];
    }
}
