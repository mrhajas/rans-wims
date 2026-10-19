<?php

namespace App\Http\Controllers\Signatories;

use App\Http\Controllers\Controller;
use App\Models\Signatory;
use App\Models\SignatureVersion;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Signatories\SignatorySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Shared checks for every Signatories controller: ability + Global Feature Control, same account, and the password re-check for sensitive changes. */
abstract class SignatoryBaseController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** The signed-in user, after the module and (optionally) one function are confirmed on and the ability is held. */
    protected function allow(Request $request, string $ability, ?string $feature = null): User
    {
        $user = $request->user();
        if (! (FeatureManager::canAccess('signatories', $user) && ($feature === null || FeatureManager::canAccess($feature, $user)) && $user->can($ability))) {
            log_activity('Signatories access refused', ['route' => (string) $request->route()?->getName(), 'ability' => $ability, 'method' => $request->method()], null, 'Signatories');
            abort(403);
        }

        return $user;
    }

    protected function feature(string $key, User $user): bool
    {
        return FeatureManager::canAccess($key, $user);
    }

    protected function inAccount(User $user, Signatory $s): Signatory
    {
        abort_unless((int) $s->account_id === (int) $user->account_id, 404);

        return $s;
    }

    protected function versionOf(User $user, int $id): SignatureVersion
    {
        $v = SignatureVersion::query()->with('signatory')->findOrFail($id);
        abort_unless($v->signatory && (int) $v->signatory->account_id === (int) $user->account_id, 404);

        return $v;
    }

    /** May this person see (or work with) the signature of $subject? Their own always; others need the "other signatures" ability. */
    protected function canSee(User $user, int $subjectUserId): bool
    {
        return (int) $user->id === $subjectUserId ? $user->can('view-own-signature') || $user->can('view-signatories') : $user->can('view-other-signatures');
    }

    protected function settings(User $user): array
    {
        return SignatorySettings::get((int) $user->account_id);
    }

    /** Sensitive changes ask for the person's own password again (setting). */
    protected function reauth(Request $request, User $user): void
    {
        if (! $this->settings($user)['reauth_sensitive']) {
            return;
        }
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => __('The password is not correct.')]);
        }
    }
}
