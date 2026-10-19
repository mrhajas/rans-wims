<?php

namespace App\Http\Controllers;

use App\Services\Security\DeviceLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Devices the signed-in person is on. The "Too many devices" page (shown when a new sign-in hits the limit) and the sign-out actions used there and on the Profile page.
 * Every action works only on the person's own sessions; a device is named by an opaque handle, never by its session id.
 */
class DeviceController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** The page a held sign-in lands on. */
    public function show(Request $request)
    {
        if ($request->session()->get('device_limit_state') !== 'pending') {
            return redirect()->route('dashboard');
        }
        $devices = collect(DeviceLimit::devices($request, true));

        return Inertia::render('Auth/DeviceLimit', [
            'limit' => DeviceLimit::limit(),
            'devices' => $devices->where('current', false)->values(),
            'here' => $devices->firstWhere('current', true),
            'userName' => $request->user()->name,
        ]);
    }

    private function after(Request $request, string $message)
    {
        // a held sign-in is let in as soon as there is room
        if ($request->session()->get('device_limit_state') === 'pending' && DeviceLimit::admit($request, $request->user()) === 'ok') {
            return redirect()->route('dashboard')->with('message', $message.' '.__('You are signed in.'));
        }

        return back()->with('message', $message);
    }

    public function signOut(Request $request, string $token)
    {
        $ok = DeviceLimit::signOut($request, $token);

        return $ok ? $this->after($request, __('That device was signed out.')) : back()->withErrors(['device' => __('That device is not signed in any more.')]);
    }

    public function signOutOthers(Request $request)
    {
        $n = DeviceLimit::signOutOthers($request);

        return $this->after($request, trans_choice('{0} No other device was signed in.|{1} :n other device was signed out.|[2,*] :n other devices were signed out.', $n, ['n' => $n]));
    }

    /** Sign out everywhere, this device too. */
    public function signOutAll(Request $request)
    {
        DeviceLimit::signOutAll($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('You were signed out of all devices.'));
    }
}
