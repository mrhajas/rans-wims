<?php

namespace App\Http\Controllers;

use App\Models\FaceDevice;
use App\Models\FaceTemplate;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Face\FaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Profile > Face login: a user enrols (or removes) their own face. Every route sits behind password confirmation. */
class FaceEnrolmentController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request)
    {
        abort_unless(FeatureManager::isEnabled('face_login'), 403, __('Face login is switched off.'));
        $request->validate(['samples' => 'required|array|size:'.FaceService::ENROL_SAMPLES, 'consent' => 'accepted', 'device_public_key' => 'nullable|string|max:400', 'device_id' => 'nullable|string|max:40']);

        $samples = [];
        foreach ((array) $request->input('samples') as $s) {
            $v = FaceService::clean($s);
            if (! $v) {
                throw ValidationException::withMessages(['samples' => __('The face scan was not valid. Please scan again.')]);
            }
            $samples[] = $v;
        }
        if (! FaceService::consistent($samples)) {
            throw ValidationException::withMessages(['samples' => __('The scans do not look like the same face. Stay in front of the camera on your own and scan again.')]);
        }

        $user = $request->user();
        $t = FaceTemplate::query()->firstOrNew(['user_id' => $user->id]);
        $t->setVectors($samples);
        $t->fill([
            'enrolled_at' => now(), 'enrolled_ip' => $request->ip(), 'enrolled_device' => mb_substr((string) $request->userAgent(), 0, 190),
            'failed_attempts' => 0, 'locked_until' => null, 'reset_by_user_id' => null,
        ])->save();
        // look-alike guard at enrolment too: a face that already belongs to somebody else cannot be enrolled onto this account
        if (FaceService::enrolledElsewhere($user, $samples)) {
            throw ValidationException::withMessages(['samples' => __('This face is already enrolled on another account. Face ID needs a face that belongs to one account only.')]);
        }
        log_activity(__('Face login enrolled.'), ['samples' => count($samples)], $user, 'Profile');

        // this device gets the right to use it: either it already holds a registered key, or the key it just made is registered now
        $device = $this->deviceFor($request, $user, $request->input('device_public_key'), $request->input('device_id'));

        $request->session()->forget('auth.password_confirmed_at'); // every change to Face ID needs the password again, never a leftover confirmation

        return response()->json(['ok' => true, 'device_id' => $device?->device_id]);
    }

    /** The device the browser says it is (kept when it still exists), or a new one made from the public key it sent. */
    private function deviceFor(Request $request, User $user, ?string $publicKey, ?string $deviceId): ?FaceDevice
    {
        if ($deviceId) {
            $existing = FaceDevice::query()->active()->where('user_id', $user->id)->where('device_id', $deviceId)->first();
            if ($existing) {
                return $existing;
            }
        }
        $key = $publicKey ? FaceService::cleanPublicKey($publicKey) : null;
        if (! $key) {
            throw ValidationException::withMessages(['device_public_key' => __('This browser cannot keep the Face ID key. Use a current Chrome, Edge, Safari or Firefox on https.')]);
        }
        $agent = (string) $request->userAgent();
        $device = FaceDevice::query()->create([
            'user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'public_key' => $key, 'label' => FaceService::deviceLabel($agent),
            'registered_ip' => $request->ip(), 'registered_agent' => mb_substr($agent, 0, 190),
        ]);
        log_activity(__('Face ID registered on a device.'), ['device' => $device->label], $user, 'Profile');

        return $device;
    }

    /** Registers this browser for an account that already has a face enrolled; the person must pass a fresh scan of that face. */
    public function addDevice(Request $request)
    {
        abort_unless(FeatureManager::isEnabled('face_login'), 403, __('Face login is switched off.'));
        $request->validate(['frames' => 'required|array|min:2|max:5', 'device_public_key' => 'required|string|max:400']);
        $user = $request->user();
        $t = FaceService::templateFor($user);
        abort_unless($t, 404);
        if (FaceDevice::query()->active()->where('user_id', $user->id)->count() >= 6) {
            throw ValidationException::withMessages(['device_public_key' => __('You already have 6 devices. Remove one first.')]);
        }

        $frames = [];
        foreach ((array) $request->input('frames') as $f) {
            $v = FaceService::clean($f);
            if (! $v) {
                throw ValidationException::withMessages(['frames' => __('The face scan was not valid. Please try again.')]);
            }
            $frames[] = $v;
        }
        $templates = $t->vectors();
        $distances = array_map(fn (array $f) => FaceService::best($f, $templates), $frames);
        if (max($distances) > FaceService::threshold() || ! FaceService::meanOk($distances) || ! FaceService::looksLive($frames) || FaceService::lookAlikeConflict($user, $frames, max($distances))) {
            throw ValidationException::withMessages(['frames' => __('That did not match your enrolled face, so this device was not added. Try again in better light.')]);
        }

        $device = $this->deviceFor($request, $user, $request->input('device_public_key'), null);

        $request->session()->forget('auth.password_confirmed_at'); // every change to Face ID needs the password again, never a leftover confirmation

        return response()->json(['ok' => true, 'device_id' => $device->device_id]);
    }

    public function removeDevice(Request $request, FaceDevice $device)
    {
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);
        $device->forceFill(['revoked_at' => now()])->save();
        log_activity(__('Face ID removed from a device.'), ['device' => $device->label], $request->user(), 'Profile');

        $request->session()->forget('auth.password_confirmed_at'); // every change to Face ID needs the password again, never a leftover confirmation

        return response()->json(['ok' => true]);
    }

    /** Lets the user check how well their enrolled face matches a fresh scan, without signing in. */
    public function check(Request $request)
    {
        $request->validate(['frames' => 'required|array|min:1|max:5']);
        $t = FaceService::templateFor($request->user());
        abort_unless($t, 404);

        $templates = $t->vectors();
        $best = INF;
        foreach ((array) $request->input('frames') as $f) {
            $v = FaceService::clean($f);
            if ($v) {
                $best = min($best, FaceService::best($v, $templates));
            }
        }
        if (! is_finite($best)) {
            throw ValidationException::withMessages(['frames' => __('The face scan was not valid. Please try again.')]);
        }

        return response()->json(['match' => $best <= FaceService::threshold(), 'score' => max(0, min(100, (int) round((1 - $best / 0.8) * 100)))]);
    }

    public function destroy(Request $request)
    {
        FaceTemplate::query()->where('user_id', $request->user()->id)->delete();
        FaceDevice::query()->where('user_id', $request->user()->id)->active()->update(['revoked_at' => now()]);
        log_activity(__('Face login removed.'), [], $request->user(), 'Profile');

        $request->session()->forget('auth.password_confirmed_at'); // every change to Face ID needs the password again, never a leftover confirmation

        return response()->json(['ok' => true]);
    }

    /** Super Admin: remove somebody's enrolment (lost device, wrong person enrolled, they left). */
    public function reset(Request $request, User $user)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        FaceTemplate::query()->where('user_id', $user->id)->delete();
        FaceDevice::query()->where('user_id', $user->id)->active()->update(['revoked_at' => now()]);
        log_activity(__('Face login reset for :n.', ['n' => $user->name]), [], $request->user(), 'Users');
        \App\Support\UserAudit::record($user, 'face_reset', [], $request->user()->id);
        \App\Services\UserNotifier::fire('face_login_reset', $user, __('Face Login Removed'), 'Face ID was removed for ' . $user->name . ' (' . $user->username . ') by ' . $request->user()->name . '.', $request->user());

        return back()->with('message', __('Face login was removed for :n.', ['n' => $user->name]));
    }
}
