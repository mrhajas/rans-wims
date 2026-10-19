<?php

namespace App\Http\Controllers\Signatories;

use App\Models\Signatory;
use App\Models\SignatureMissing;
use App\Models\SignatureVersion;
use App\Models\Staff;
use App\Models\User;
use App\Services\Signatories\SignatoryException;
use App\Services\Signatories\SignatoryService;
use App\Services\Signatories\SignatureStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Signatories: the dashboard and list, a person's profile, registering a signature (preview, confirm), verifying, suspending and revoking,
 * and the guarded image endpoint. No signature picture is ever put in a page prop or a public folder: previews come back as data in the
 * response of the person who may see them, and stored pictures are only streamed by imageOf() after the checks.
 */
class SignatoryController extends SignatoryBaseController
{
    public function __construct(private SignatoryService $service, private SignatureStore $store)
    {
        parent::__construct();
    }

    // ------------------------------------------------------------------------------------------------------------------------ pages

    public function index(Request $request)
    {
        $user = $this->allow($request, 'view-signatories');
        $acc = (int) $user->account_id;
        $rows = DB::table('signatories as s')->join('users as u', 'u.id', '=', 's.user_id')->leftJoin('staffs as st', 'st.id', '=', 's.staff_id')
            ->where('s.account_id', $acc)->orderBy('u.name')
            ->get(['s.id', 's.user_id', 's.status', 's.registered_at', 's.verified_at', 's.updated_at', 's.active_version_id', 'u.name', 'u.username', 'st.position', 'st.staff_number as staff_no']);
        $pendingVersions = SignatureVersion::query()->whereIn('signatory_id', $rows->pluck('id'))->where('status', SignatureVersion::PENDING)->pluck('signatory_id')->flip();
        $registered = $rows->pluck('user_id')->flip();
        $activeUsers = User::query()->where('account_id', $acc)->where('is_active', true)->count();
        $stats = [
            'registered' => $rows->count(), 'active' => $rows->where('status', Signatory::ACTIVE)->count(),
            'pending' => $rows->filter(fn ($r) => $r->status === Signatory::PENDING || isset($pendingVersions[$r->id]))->count(),
            'suspended' => $rows->whereIn('status', [Signatory::SUSPENDED, Signatory::REVOKED])->count(),
            'without' => max(0, $activeUsers - $rows->count()),
            'recent' => $rows->filter(fn ($r) => $r->updated_at && now()->diffInDays($r->updated_at) <= 30)->count(),
            'documents' => \App\Models\SigningMapping::query()->where('account_id', $acc)->where('is_active', true)->distinct()->count('document_type'),
            'missing' => SignatureMissing::query()->where('account_id', $acc)->where('seen_at', '>=', now()->subDays(30))->count(),
        ];

        return Inertia::render('Signatories/Index', [
            'stats' => $stats,
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id, 'user_id' => $r->user_id, 'name' => $r->name, 'username' => $r->username, 'position' => $r->position, 'staff_no' => $r->staff_no, 'status' => $r->status,
                'has_pending' => isset($pendingVersions[$r->id]), 'registered_at' => $r->registered_at, 'verified_at' => $r->verified_at, 'updated_at' => $r->updated_at,
            ])->values(),
            'people' => $user->can('register-signatories') ? User::query()->where('account_id', $acc)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'username'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name . ' (' . $u->username . ')', 'registered' => isset($registered[$u->id])])->values() : [],
            'can' => $this->abilities($user),
        ]);
    }

    /** The signed-in person's own signature page (register or replace, see the status). */
    public function mine(Request $request)
    {
        $user = $this->allow($request, 'view-own-signature');
        $sig = Signatory::query()->where('account_id', $user->account_id)->where('user_id', $user->id)->first();

        return Inertia::render('Signatories/Show', $this->profile($user, $user, $sig, true));
    }

    public function show(Request $request, int $signatory)
    {
        $user = $this->allow($request, 'view-signatories');
        $sig = $this->inAccount($user, Signatory::query()->findOrFail($signatory));
        abort_unless($this->canSee($user, (int) $sig->user_id) || $user->can('view-signatories'), 403);
        $subject = User::query()->withTrashed()->findOrFail($sig->user_id);

        return Inertia::render('Signatories/Show', $this->profile($user, $subject, $sig, (int) $subject->id === (int) $user->id));
    }

    /** A person who has no signature yet, opened by somebody who may register for them. */
    public function register(Request $request, int $userId)
    {
        $user = $this->allow($request, 'view-signatories');
        $subject = User::query()->where('account_id', $user->account_id)->findOrFail($userId);
        abort_unless((int) $subject->id === (int) $user->id || $user->can('register-signatories'), 403);
        $sig = Signatory::query()->where('account_id', $user->account_id)->where('user_id', $subject->id)->first();

        return Inertia::render('Signatories/Show', $this->profile($user, $subject, $sig, (int) $subject->id === (int) $user->id));
    }

    private function profile(User $viewer, User $subject, ?Signatory $sig, bool $self): array
    {
        $s = $this->settings($viewer);
        $staff = Staff::query()->where('user_id', $subject->id)->first();
        $own = (int) $viewer->id === (int) $subject->id;
        $seeImages = $own || $viewer->can('view-other-signatures');
        $versions = $sig && ($own || $viewer->can('view-signature-history') || $viewer->can('view-other-signatures'))
            ? $sig->versions()->get()->sortBy([fn ($a, $b) => (($b->status === 'active') <=> ($a->status === 'active')), fn ($a, $b) => $b->version_no <=> $a->version_no])->values()->map(fn ($v) => [
                'id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status, 'method' => $v->method, 'width' => $v->width, 'height' => $v->height, 'uploaded_at' => $v->uploaded_at,
                'uploaded_by' => $v->uploaded_by ? (string) User::query()->withTrashed()->where('id', $v->uploaded_by)->value('name') : null,
                'consent_at' => $v->consent_at, 'consent_by' => $v->consent_by ? (string) User::query()->withTrashed()->where('id', $v->consent_by)->value('name') : null,
                'verified_at' => $v->verified_at, 'verified_by' => $v->verified_by ? (string) User::query()->withTrashed()->where('id', $v->verified_by)->value('name') : null,
                'activated_at' => $v->activated_at, 'ended_at' => $v->ended_at, 'ended_reason' => $v->ended_reason, 'has_original' => (bool) ($v->original_path && ! $v->original_purged_at), 'can_use' => $v->status === 'replaced' && (bool) $v->verified_at && $sig->status !== 'revoked' && $this->service->mayChoose($viewer, $sig),
                'consented_by_owner' => (int) $v->consent_by === (int) $subject->id,
                'image_url' => $seeImages ? route('signatories.image', [$v->id, 'thumb']) : null,
            ])->values() : [];

        return [
            'subject' => ['id' => $subject->id, 'name' => $subject->name, 'username' => $subject->username, 'email' => $subject->email, 'position' => $staff?->position, 'staff_no' => $staff->staff_number ?? null, 'photo' => null, 'is_self' => $own],
            'signatory' => $sig ? [
                'id' => $sig->id, 'status' => $sig->status, 'registered_at' => $sig->registered_at, 'verified_at' => $sig->verified_at, 'verified_by' => $sig->verified_by ? (string) User::query()->withTrashed()->where('id', $sig->verified_by)->value('name') : null,
                'suspended_at' => $sig->suspended_at, 'suspend_reason' => $sig->suspend_reason, 'revoked_at' => $sig->revoked_at, 'revoke_reason' => $sig->revoke_reason, 'active_version_id' => $sig->active_version_id,
            ] : null,
            'versions' => $versions,
            'prefs' => ['camera' => (bool) $s['camera_enabled'] && $this->feature('signatories_camera', $viewer), 'draw' => (bool) $s['draw_enabled'] && $this->feature('signatories_camera', $viewer), 'upload' => $this->feature('signatories_upload', $viewer),
                'formats' => $s['allowed_formats'], 'max_mb' => (int) $s['max_upload_mb'], 'consent' => (bool) $s['consent_required'], 'waive' => $this->service->mayWaiveConsent($viewer), 'verification' => (bool) $s['verification_required'], 'reauth' => (bool) $s['reauth_sensitive'], 'ink' => $s['ink_color']],
            'can' => $this->abilities($viewer) + [
                'register_here' => $own ? $viewer->can('register-own-signature') : $viewer->can('register-signatories'),
                'see_images' => $seeImages, 'see_originals' => $viewer->can('view-signature-originals'),
            ],
            'audit' => $sig && $viewer->can('view-signatory-audit') ? $this->auditFor($sig) : [],
        ];
    }

    private function auditFor(Signatory $sig): array
    {
        return DB::table('activity_log')->where('log_name', 'Signatories')->where('account_id', $sig->account_id)->where('properties', 'like', '%"subject_user_id":' . $sig->user_id . '%')
            ->orderByDesc('id')->limit(60)->get(['description', 'created_at', 'causer_id'])->map(fn ($a) => [
                'what' => $a->description, 'at' => $a->created_at, 'by' => $a->causer_id ? (string) User::query()->withTrashed()->where('id', $a->causer_id)->value('name') : null,
            ])->all();
    }

    private function abilities(User $u): array
    {
        return [
            'register' => $u->can('register-signatories'), 'register_own' => $u->can('register-own-signature'), 'verify' => $u->can('verify-signatures') && $this->feature('signatories_verification', $u),
            'activate' => $u->can('activate-signatures') && $this->feature('signatories_activation', $u), 'suspend' => $u->can('suspend-signatures') && $this->feature('signatories_activation', $u),
            'revoke' => $u->can('revoke-signatures') && $this->feature('signatories_activation', $u), 'history' => $u->can('view-signature-history') && $this->feature('signatories_history', $u),
            'roles' => $u->can('manage-signing-roles') && $this->feature('signatories_roles', $u), 'mappings' => $u->can('manage-signing-mappings') && $this->feature('signatories_mappings', $u),
            'settings' => $u->can('manage-signatory-settings') && $this->feature('signatories_settings', $u), 'reports' => $u->can('export-signatory-reports') && $this->feature('signatories_reports', $u),
            'audit' => $u->can('view-signatory-audit'), 'workflow' => $u->hasRole('Super Admin'),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------------------ registering

    /** Step one: the picture (a file, a camera photo or a drawing) goes in, the processed result comes back to look at. Nothing becomes a signature yet. */
    public function preview(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'], 'method' => ['required', 'in:upload,camera,draw'], 'sensitivity' => ['nullable', 'numeric', 'between:0.5,1.8'],
            'token' => ['nullable', 'string', 'size:40'], 'image' => ['nullable', 'file'],
        ]);
        $subject = $this->subjectFor($request, $user, (int) ($data['user_id'] ?? $user->id));
        $method = $data['method'];
        $feature = ['upload' => 'signatories_upload', 'camera' => 'signatories_camera', 'draw' => 'signatories_camera'][$method];
        $ability = $method === 'upload' ? 'upload-signatures' : 'capture-signatures';
        abort_unless($this->feature($feature, $user) && $user->can($ability) && $user->can('preview-signatures') && $this->feature('signatories_processing', $user), 403);
        $s = $this->settings($user);
        if ($method === 'camera' && ! $s['camera_enabled'] || $method === 'draw' && ! $s['draw_enabled']) {
            abort(403);
        }

        $token = $data['token'] ?? null;
        $sens = (float) ($data['sensitivity'] ?? 1.0);
        if ($token) {
            $stash = $this->store->stashed((int) $user->id, $token);
            abort_unless($stash && (int) ($stash[1]['subject_user_id'] ?? 0) === (int) $subject->id, 422, __('That picture is no longer waiting. Upload it again.'));
            $binary = $stash[0];
        } else {
            $file = $request->file('image');
            abort_unless($file && $file->isValid(), 422, __('Choose a picture first.'));
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension());
            if ($method === 'upload' && ! in_array($ext, array_map('strtolower', $s['allowed_formats']), true)) {
                return response()->json(['ok' => false, 'reasons' => [__('This file type is not allowed. Use: :t.', ['t' => implode(', ', $s['allowed_formats'])])]], 422);
            }
            if ($file->getSize() > (int) $s['max_upload_mb'] * 1024 * 1024) {
                return response()->json(['ok' => false, 'reasons' => [__('The picture is larger than :n MB.', ['n' => $s['max_upload_mb']])]], 422);
            }
            $binary = (string) file_get_contents($file->getRealPath());
        }
        $r = $this->service->preview($user, $subject, $binary, $method, $sens, $token);

        return response()->json($r);
    }

    /** Step two: the person looked at the processed picture and confirms it. */
    public function confirm(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['user_id' => ['nullable', 'integer'], 'token' => ['required', 'string', 'size:40'], 'sensitivity' => ['nullable', 'numeric', 'between:0.5,1.8'], 'consent' => ['nullable', 'boolean']]);
        $subject = $this->subjectFor($request, $user, (int) ($data['user_id'] ?? $user->id));
        abort_unless($this->feature('signatories_registration', $user), 403);
        try {
            $version = $this->service->confirm($user, $subject, $data['token'], (float) ($data['sensitivity'] ?? 1.0), (bool) ($data['consent'] ?? false));
        } catch (SignatoryException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $sig = $version->signatory;

        return response()->json(['ok' => true, 'version_id' => $version->id, 'signatory_id' => $sig->id, 'redirect' => (int) $subject->id === (int) $user->id ? route('signatories.mine') : route('signatories.show', $sig->id)]);
    }

    /** Who a registration is for: yourself (own-signature ability) or somebody else (register-signatories). Same account only. */
    private function subjectFor(Request $request, User $user, int $subjectId): User
    {
        abort_unless($this->feature('signatories', $user), 403);
        if ($subjectId === (int) $user->id) {
            abort_unless($user->can('register-own-signature') && ($this->settings($user)['self_registration'] === 'self' || $user->can('register-signatories')), 403);

            return $user;
        }
        abort_unless($user->can('register-signatories'), 403);

        return User::query()->where('account_id', $user->account_id)->findOrFail($subjectId);
    }

    // ------------------------------------------------------------------------------------------------------------------------ verification and status

    public function consent(Request $request, int $version)
    {
        $user = $this->allow($request, 'register-own-signature');
        $v = $this->versionOf($user, $version);
        try {
            $this->service->consent($user, $v);
        } catch (SignatoryException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with('message', __('Thank you. The signature is confirmed as yours.'));
    }

    /** Use an earlier, already verified signature again (it moves to the top and becomes the one in use). */
    public function useVersion(Request $request, int $version)
    {
        $user = $request->user();
        abort_unless($this->feature('signatories', $user), 403);
        $v = $this->versionOf($user, $version);
        abort_unless($this->service->mayChoose($user, $v->signatory), 403);
        $this->reauth($request, $user);
        try {
            $this->service->useVersion($user, $v);
        } catch (SignatoryException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with('message', __('Version :n is now the signature in use.', ['n' => $v->version_no]));
    }

    public function verify(Request $request, int $version)
    {
        $user = $this->allow($request, 'verify-signatures', 'signatories_verification');
        abort_unless($user->can('activate-signatures') && $this->feature('signatories_activation', $user), 403);
        $v = $this->versionOf($user, $version);
        $this->reauth($request, $user);
        try {
            $this->service->verifyAndActivate($user, $v);
        } catch (SignatoryException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with('message', __('The signature is verified and active.'));
    }

    public function reject(Request $request, int $version)
    {
        $user = $this->allow($request, 'verify-signatures', 'signatories_verification');
        $v = $this->versionOf($user, $version);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->reauth($request, $user);
        try {
            $this->service->reject($user, $v, $data['reason']);
        } catch (SignatoryException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with('message', __('The signature was rejected.'));
    }

    public function suspend(Request $request, int $signatory)
    {
        $user = $this->allow($request, 'suspend-signatures', 'signatories_activation');
        $sig = $this->inAccount($user, Signatory::query()->findOrFail($signatory));
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->reauth($request, $user);
        try {
            $this->service->suspend($user, $sig, $data['reason']);
        } catch (SignatoryException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with('message', __('The signature is suspended. It will not be used on new reports.'));
    }

    public function reinstate(Request $request, int $signatory)
    {
        $user = $this->allow($request, 'suspend-signatures', 'signatories_activation');
        $sig = $this->inAccount($user, Signatory::query()->findOrFail($signatory));
        $this->reauth($request, $user);
        try {
            $sig->status === Signatory::REVOKED ? $this->service->reinstateRevoked($user, $sig) : $this->service->reinstate($user, $sig);
        } catch (SignatoryException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with('message', __('Done.'));
    }

    public function revoke(Request $request, int $signatory)
    {
        $user = $this->allow($request, 'revoke-signatures', 'signatories_activation');
        $sig = $this->inAccount($user, Signatory::query()->findOrFail($signatory));
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->reauth($request, $user);
        try {
            $this->service->revoke($user, $sig, $data['reason']);
        } catch (SignatoryException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with('message', __('The signature is revoked.'));
    }

    // ------------------------------------------------------------------------------------------------------------------------ images

    /** The guarded picture endpoint: processed, thumb or original, only for a person allowed to see that signature, and the view is logged. */
    public function image(Request $request, int $version, string $kind)
    {
        $user = $request->user();
        abort_unless(in_array($kind, ['processed', 'thumb', 'original'], true) && $this->feature('signatories', $user), 404);
        $v = $this->versionOf($user, $version);
        $ownerId = (int) $v->signatory->user_id;
        $own = (int) $user->id === $ownerId;
        $allowed = $kind === 'original' ? $user->can('view-signature-originals') : ($own ? $user->can('view-own-signature') || $user->can('view-signatories') : $user->can('view-other-signatures'));
        if (! $allowed) {
            log_activity('Signature picture request refused', ['subject_user_id' => $ownerId, 'version' => $v->version_no, 'kind' => $kind], null, 'Signatories');
            abort(403);
        }
        $bin = $kind === 'original' ? $this->store->original($v) : ($kind === 'thumb' ? $this->store->thumb($v) : $this->store->png($v));
        abort_unless($bin !== null, 404);
        if ($kind === 'original' || ! $own) {
            log_activity($kind === 'original' ? 'Original signature picture viewed' : 'Signature picture viewed', ['subject_user_id' => $ownerId, 'version' => $v->version_no, 'kind' => $kind], null, 'Signatories');
        }
        $mime = $kind === 'original' ? (finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bin) ?: 'application/octet-stream') : 'image/png';

        return response($bin, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="signature.png"']);
    }
}
