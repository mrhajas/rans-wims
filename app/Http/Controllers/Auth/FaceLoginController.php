<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SystemLockdown;
use App\Models\FaceDevice;
use App\Models\FaceTemplate;
use App\Models\LoginLog;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\Face\FaceService;
use App\Services\FeatureManager;
use App\Services\Licensing\LicenceService;
use App\Services\TwoFactorOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sign in with a face. Three things must all be true, and the server (not the browser) decides each one:
 *  1. DEVICE: the sign-in is signed with the private key of a device the person registered for Face ID (the key cannot be copied out of
 *     the browser). A photo of somebody, or a stolen face template, is useless from any other device.
 *  2. LIVENESS: the server asks for blinks on cue, after random waits it chose, and checks the browser's report of every wait, blink
 *     delay and head turn (plus the total time). Unusual sign-ins (new network, recent failed scans, a device not used for weeks) must
 *     also turn the head once and blink a third time.
 *  3. FACE: three frames, all and on average close enough to that person's enrolled templates, not suspiciously perfect, not too alike
 *     and not too different from each other, and not closer to somebody else's enrolled face (look-alike guard).
 * Anyone with two-step codes still has to enter them afterwards.
 */
class FaceLoginController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function challenge(Request $request)
    {
        $request->validate(['username' => 'required|string|max:190', 'device_ids' => 'nullable|array|max:20', 'device_ids.*' => 'string|max:40']);
        if (! FeatureManager::isEnabled('face_login')) {
            return response()->json(['message' => __('Face login is switched off. Sign in with your password.')], 403);
        }
        if (RateLimiter::tooManyAttempts('face-challenge|'.$request->ip(), 25)) {
            return response()->json(['message' => __('Too many tries. Wait a few minutes, or sign in with your password.')], 429);
        }
        RateLimiter::hit('face-challenge|'.$request->ip(), 600);

        // Somebody with no Face ID enrolled is told so before the camera opens. An unknown username gets exactly the same answer,
        // so this cannot be used to tell which usernames exist (it only shows that a username has no Face ID, which is rate limited).
        $user = User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower(trim((string) $request->input('username')))])->first();
        $template = $user ? FaceService::templateFor($user) : null;
        if (! $user || ! $template) {
            return response()->json(['code' => 'no_face', 'message' => __('Face ID is not set up for this account. Sign in with your password, then set it up from your profile.')], 422);
        }
        $ids = array_values(array_filter((array) $request->input('device_ids'), 'is_string'));
        $device = $ids ? FaceDevice::query()->active()->where('user_id', $user->id)->whereIn('device_id', $ids)->orderByDesc('last_used_at')->first() : null;
        if (! $device) {
            return response()->json(['code' => 'no_device', 'message' => __('Face ID is not set up on this device. Sign in with your password, then open your profile > Face Login and press "Use Face ID on this device".')], 422);
        }
        if ($template->locked_until && $template->locked_until->isFuture()) {
            return response()->json(['code' => 'locked', 'message' => __('Face login is paused after too many failed scans (until :t). Sign in with your password.', ['t' => $template->locked_until->timezone(config('app.timezone'))->format('H:i')])], 423);
        }

        $elevated = FaceService::elevated($user, $device, $template, (string) $request->ip());
        $plan = FaceService::plan($elevated);

        return response()->json(['nonce' => FaceService::makeNonce((string) $request->input('username'), $device->device_id, $plan, (string) $request->userAgent()), 'plan' => $plan, 'device_id' => $device->device_id]);
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:190', 'nonce' => 'required|string|max:2000', 'device_id' => 'required|string|max:40',
            'payload' => 'required|string|max:24000', 'signature' => 'required|string|max:300', 'remember' => 'sometimes|boolean',
        ]);
        abort_unless(FeatureManager::isEnabled('face_login'), 403, __('Face login is switched off.'));

        $username = mb_strtolower(trim($data['username']));
        $limiter = 'face-login|'.$username.'|'.$request->ip();
        $ipLimiter = 'face-verify|'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiter, 6) || RateLimiter::tooManyAttempts($ipLimiter, 25)) {
            return $this->fail($request, null, $username, __('Too many tries. Wait a few minutes, or sign in with your password.'), 429);
        }
        RateLimiter::hit($limiter, 60);
        RateLimiter::hit($ipLimiter, 600);

        $expired = __('That scan expired. Please try again.');
        $user = User::query()->whereRaw('LOWER(username) = ?', [$username])->first();
        $template = $user ? FaceService::templateFor($user) : null;
        $device = $user ? FaceDevice::query()->active()->where('user_id', $user->id)->where('device_id', $data['device_id'])->first() : null;

        // The token must be genuine, fresh, for this username, device and browser, and used only once.
        $nonce = FaceService::readNonce($data['nonce']);
        if (! $nonce || $nonce['u'] !== $username || $nonce['d'] !== $data['device_id'] || ! FaceService::sameBrowser($nonce, (string) $request->userAgent())
            || ! Cache::add('face-nonce|'.$nonce['r'], 1, (int) config('face.nonce_ttl', 120) + 30)) {
            return $this->fail($request, $user, $username, $expired, 422, 'Expired or reused scan token');
        }
        if (! $user || ! $template || ! $device) {
            return $this->fail($request, $user, $username, __('Face not recognised. Try again, or sign in with your password.'), 422, 'No face or device');
        }
        if ($template->locked_until && $template->locked_until->isFuture()) {
            return $this->fail($request, $user, $username, __('Face login is paused after too many failed scans. Sign in with your password.'), 423, 'Face login locked');
        }

        // 1. DEVICE: signed with the registered device's private key. A bad signature is not counted against the person: anybody can send one.
        if (! FaceService::signatureValid($device, 'wims-face-v1|'.$data['nonce'].'|'.$username.'|'.$data['payload'], $data['signature'])) {
            return $this->fail($request, $user, $username, __('Face not recognised. Try again, or sign in with your password.'), 422, 'Device signature not valid');
        }
        $payload = json_decode($data['payload'], true, 8);
        if (! is_array($payload) || ! is_array($payload['frames'] ?? null) || count($payload['frames']) !== FaceService::LOGIN_FRAMES || ! is_array($payload['telemetry'] ?? null)) {
            return $this->penalise($request, $user, $template, __('The face scan was not valid. Please try again.'), 'Scan not valid');
        }

        // 2. LIVENESS: what the server asked for, in the order and timing it asked for.
        $problem = FaceService::telemetryError($payload['telemetry'], $nonce['p'], (int) $nonce['i']);
        if ($problem === 'virtual_camera') {
            return $this->penalise($request, $user, $template, __('A real camera is needed. Close any camera software (virtual cameras, streaming or filter apps) and try again.'), 'Virtual camera: '.mb_substr((string) ($payload['telemetry']['camera'] ?? ''), 0, 60));
        }
        if ($problem) {
            return $this->penalise($request, $user, $template, __('The face check was not completed. Please try again.'), 'Liveness check failed ('.$problem.')');
        }

        // 3. FACE
        $frames = [];
        foreach ($payload['frames'] as $f) {
            $v = FaceService::clean($f);
            if (! $v) {
                return $this->penalise($request, $user, $template, __('The face scan was not valid. Please try again.'), 'Scan not valid');
            }
            $frames[] = $v;
        }
        $templates = $template->vectors();
        $distances = array_map(fn (array $f) => FaceService::best($f, $templates), $frames);
        $own = max($distances);

        if ($own > FaceService::threshold() || ! FaceService::meanOk($distances)) {
            return $this->penalise($request, $user, $template, __('Face not recognised. Try again, or sign in with your password.'), 'Face did not match');
        }
        if (! FaceService::looksLive($frames) || FaceService::tooPerfect($frames, $templates)) {
            return $this->penalise($request, $user, $template, __('Face not recognised. Try again, or sign in with your password.'), 'Frames not like a live camera');
        }
        if (FaceService::lookAlikeConflict($user, $frames, $own)) {
            return $this->penalise($request, $user, $template, __('Face not recognised. Try again, or sign in with your password.'), 'Face too close to another person');
        }

        if ($user->is_active === false) {
            return $this->fail($request, $user, $username, __('Your account has been put on hold. Please contact your administrator.'), 403, 'Account disabled - login blocked');
        }
        if (SystemLockdown::blocks($user)) {
            return $this->fail($request, $user, $username, __('System is currently under maintenance. Access is restricted except for Admin, Super Admin, and Project Manager.'), 403, 'Maintenance lock');
        }
        if (! LicenceService::usable() && ! LicenceService::isManager($user)) {
            return $this->fail($request, $user, $username, LicenceService::blockMessage(), 403, 'Licence not active');
        }

        if ($lock = \App\Services\Prayer\PrayerService::lockFor($user)) {
            return $this->fail($request, $user, $username, __('Prayer break: WIMS is paused for :p prayer. You can sign in again at :t.', ['p' => $lock['label'], 't' => $lock['unlock_at']->format('h:i A')]), 403, 'Prayer break');
        }

        $template->forceFill(['failed_attempts' => 0, 'strikes' => 0, 'locked_until' => null, 'last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();
        $device->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();
        RateLimiter::clear($limiter);

        // Face stands in for the password only: two-step codes (authenticator, Email, SMS) are still asked for.
        if (! empty($user->two_factor_secret) || app(TwoFactorOtp::class)->hasAnyMethod($user)) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => (bool) ($data['remember'] ?? false)]);

            return response()->json(['redirect' => route('two-factor.login')]);
        }

        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();
        log_activity(__('Signed in with face.'), ['device' => $device->label], $user, 'Login');

        return response()->json(['redirect' => url()->to(config('fortify.home', '/'))]);
    }

    /**
     * A failed scan from a registered device: counted, and after too many the face login is paused (15 min, then 30, 60 ... up to a day)
     * and the person is told on the bell. Their password keeps working.
     */
    private function penalise(Request $request, User $user, FaceTemplate $template, string $message, string $why)
    {
        $failed = $template->failed_attempts + 1;
        $fill = ['failed_attempts' => $failed, 'last_failed_at' => now()];
        $locking = $failed >= (int) config('face.max_failures', FaceService::MAX_FAILURES);
        if ($locking) {
            $fill = ['failed_attempts' => 0, 'last_failed_at' => now(), 'locked_until' => now()->addMinutes(FaceService::lockMinutes($template->strikes)), 'strikes' => $template->strikes + 1];
        }
        $template->forceFill($fill)->save();

        if ($locking) {
            try {
                $user->notify(new AppNotification([
                    'module' => 'security_reminders', 'type' => 'face_login_locked', 'title' => __('Face login paused'),
                    'message' => __('Several face sign-in scans for your account did not pass, so Face ID is paused until :t. If this was not you, change your password.', ['t' => $template->locked_until->timezone(config('app.timezone'))->format('H:i')]),
                    'url' => route('profile.show'), 'created_at' => now()->toDateTimeString(),
                ]));
                // the Users workflow page can also tell administrators (switched off to start with)
                \App\Services\UserNotifier::fire('face_login_paused', $user, __('Face login paused'), 'Face ID is paused for ' . $user->name . ' (' . $user->username . ') after several scans did not pass.', $user, ['until' => $template->locked_until->timezone(config('app.timezone'))->format('H:i')]);
            } catch (\Throwable) {
                // a missing notification must never change the answer
            }
        }

        return $this->fail($request, $user, mb_strtolower((string) $user->username), $message, 422, $why);
    }

    private function fail(Request $request, ?User $user, string $username, string $message, int $status, ?string $why = null)
    {
        if ($why && $user) {
            $agent = user_agent_info((string) $request->userAgent());
            LoginLog::query()->create([
                'account_id' => $user->account_id ?: getAccountId(1), 'user_id' => $user->id, 'username' => $user->username ?: $user->email,
                'role' => optional($user->roles->first())->name, 'status' => 'failed', 'ip_address' => $request->ip(),
                'device' => $agent['device'], 'os' => $agent['os'], 'browser' => $agent['browser'], 'location' => geo_from_ip($request->ip()),
                'user_agent' => (string) $request->userAgent(), 'error_message' => 'Face login: '.$why, 'attempted_at' => now(),
            ]);
        }

        return response()->json(['message' => $message], $status);
    }
}
