<?php

namespace App\Http\Controllers;

use App\Models\Licence;
use App\Models\LicenceEvent;
use App\Models\User;
use App\Services\Licensing\LicenceRejected;
use App\Services\Licensing\LicenceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * "WIMS Licence": the licence this system runs on, for Super Admins (permission read-wims-licence). They can apply a new
 * licence by pasting its 128-character key or uploading the encrypted licence file from the Licence Manager.
 */
class WimsLicenceController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** Dr Hajas needs no permission here: he can always read (and apply) licences, whatever roles his account holds. */
    private function allowRead(Request $request): void
    {
        if (! LicenceService::isManager($request->user())) {
            $this->authorize('read-wims-licence');
        }
    }

    private function allowUpdate(Request $request): void
    {
        if (! LicenceService::isManager($request->user())) {
            $this->authorize('update-wims-licence');
        }
    }

    /** The Licence Manager can switch the Licence page off for a company ("Allow Licence View Icon"). */
    private function ensureVisible(Request $request): void
    {
        abort_unless(LicenceService::viewAllowed($request->user()), 403, __('The Licence page is not available for your company. Please contact your administrator.'));
    }

    public function show(Request $request)
    {
        $this->allowRead($request);
        $this->ensureVisible($request);
        $current = LicenceService::current(true);
        $isManager = LicenceService::isManager($request->user());

        return Inertia::render('Licence/Show', [
            'licence' => $current ? $this->present($current, true) : null,
            'history' => Licence::query()->whereIn('status', [Licence::ACTIVE, Licence::SUPERSEDED, Licence::DEACTIVATED])->orderByDesc('applied_at')->orderByDesc('id')->limit(20)->get()
                ->map(fn (Licence $l): array => $this->present($l, false))->values(),
            'events' => $current ? LicenceEvent::query()->where('licence_id', $current->id)->with('user:id,name')->latest('id')->limit(200)->get()->map(fn (LicenceEvent $e): array => [
                'id' => $e->id, 'event' => $e->event, 'note' => $e->note, 'by' => $e->user?->name ?? 'System', 'at' => $e->created_at?->toIso8601String(),
            ])->values() : [],
            'can' => ['update' => $isManager || $request->user()->can('update-wims-licence'), 'manager' => $isManager],
            'contact' => LicenceService::CONTACT,
            'serverNow' => now()->toIso8601String(),
        ]);
    }

    /** Apply a licence: pasted key / pasted file text, or an uploaded .wimslic file. */
    public function apply(Request $request)
    {
        $this->allowUpdate($request);
        $this->ensureVisible($request);
        $request->validate(['licence_text' => 'nullable|string|max:20000', 'licence_file' => 'nullable|file|max:512']);

        $input = trim((string) $request->input('licence_text'));
        if ($request->hasFile('licence_file')) {
            $input = (string) file_get_contents($request->file('licence_file')->getRealPath());
        }

        try {
            if ($request->hasFile('licence_file') && ! str_contains($input, LicenceService::FILE_HEADER) && strlen(preg_replace('/[^A-Za-z0-9]/', '', $input)) !== LicenceService::KEY_LENGTH) {
                throw LicenceRejected::because('unreadable', __('This is not a WIMS licence file. Upload the original .wimslic file exactly as the Licence Manager sent it.'));
            }
            $licence = LicenceService::resolve($input, $request->user());
            if ($licence->status === Licence::ACTIVE && $licence->id === LicenceService::current(true)?->id) {
                throw LicenceRejected::because('same', __(':n is already active on this system, valid until :to. Nothing was changed.', ['n' => $licence->licence_number, 'to' => $licence->expires_at->format('d M Y')]));
            }
            LicenceService::activate($licence, $request->user());
        } catch (LicenceRejected $e) {
            log_activity(__('Licence import refused (:k).', ['k' => $e->kind]), ['by' => $request->hasFile('licence_file') ? 'file' : 'key'], $request->user(), 'Licence');

            return back()->with('licence_result', ['kind' => $e->kind, 'text' => $e->errors()['licence'][0], 'at' => now()->toIso8601String()]);
        }
        log_activity(__('Licence :n applied.', ['n' => $licence->licence_number]), ['by' => $request->hasFile('licence_file') ? 'file' : 'key'], $request->user(), 'Licence');

        return back()->with('licence_result', ['kind' => 'applied', 'text' => __('Licence :n is now active, valid until :to.', ['n' => $licence->licence_number, 'to' => $licence->expires_at->format('d M Y')]), 'at' => now()->toIso8601String()]);
    }

    public function print(Request $request)
    {
        $this->allowRead($request);
        $this->ensureVisible($request);
        $licence = LicenceService::current(true) ?? abort(404, 'There is no licence to print.');
        $licence = Licence::findOrFail($licence->id);
        LicenceService::event($licence, 'printed', $request->user(), __('Licence report printed.'));

        return view('licence-report', self::reportData($licence, $request->user(), LicenceService::isManager($request->user())));
    }

    /** @return array<string, mixed> shared by both print routes */
    public static function reportData(Licence $licence, User $by, bool $showKey): array
    {
        return [
            'licence' => $licence->load(['issuedBy:id,name', 'appliedBy:id,name', 'deactivatedBy:id,name']),
            'events' => LicenceEvent::query()->where('licence_id', $licence->id)->with('user:id,name')->latest('id')->limit(4)->get(),
            'showKey' => $showKey,
            'printedBy' => $by->name,
            'printedAt' => now(),
            'company' => (string) (get_settings('name') ?: $licence->licensee),
            'appName' => (string) (get_settings('app_name') ?: 'Workflow Integrated Management System'),
            'version' => (string) (get_settings('version_label') ?: 'V7.0'),
            'contact' => LicenceService::CONTACT,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Licence $l, bool $withKey): array
    {
        $l->loadMissing(['issuedBy:id,name', 'appliedBy:id,name', 'deactivatedBy:id,name']);

        return [
            'id' => $l->id, 'number' => $l->licence_number, 'licensee' => $l->licensee, 'edition' => $l->edition, 'type' => $l->licence_type,
            'status' => $l->status, 'state' => $l->state, 'starts_at' => $l->starts_at->toDateString(), 'expires_at' => $l->expires_at->toDateString(),
            'ends_at_moment' => $l->endsAtMoment()->toIso8601String(), 'days_left' => $l->days_left, 'duration' => $l->duration_label, 'elapsed' => $l->elapsed_percent,
            'max_users' => $l->max_users, 'support_level' => $l->meta['support_level'] ?? null, 'modules' => $l->meta['modules'] ?? null, 'notes' => $l->notes,
            'issued_at' => optional($l->issued_at)->toIso8601String(), 'issued_by' => $l->issuedBy?->name,
            'applied_at' => optional($l->applied_at)->toIso8601String(), 'applied_by' => $l->appliedBy?->name,
            'deactivated_at' => optional($l->deactivated_at)->toIso8601String(), 'deactivation_reason' => $l->deactivation_reason,
            // The full key is shown only to the Licence Manager; Super Admins see its start and end so they can recognise it.
            'key_preview' => substr((string) $l->getRawOriginal('key'), 0, 8).str_repeat('•', 8).substr((string) $l->getRawOriginal('key'), -8),
            'key' => $withKey ? $l->getRawOriginal('key') : null,
            'key_blocks' => $withKey ? $l->keyBlocks() : null,
        ];
    }
}
