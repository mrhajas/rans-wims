<?php

namespace App\Http\Controllers;

use App\Services\Security\AccessGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Login Logs > Blacklist / Whitelist. A Super Admin blocks or clears an address by hand, lifts an automatic block, or lifts a block on sending sign-in codes.
 * The rules themselves live in App\Services\Security\AccessGuard (limits in config/security.php). Super Admin only: nobody else sees or reaches these pages.
 */
class LoginLogAddressController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user() && $request->user()->hasRole('Super Admin'), 403);
        abort_unless(AccessGuard::ready(), 404);
    }

    public function index(Request $request, string $tab)
    {
        $this->guard($request);
        $rule = fn ($r) => ['id' => $r->id, 'ip' => $r->ip, 'type' => $r->type, 'reason' => $r->reason, 'expires_at' => $r->expires_at, 'hits' => (int) $r->hits, 'last_hit_at' => $r->last_hit_at, 'created_at' => (string) $r->created_at, 'by' => $r->created_by ? \App\Models\User::withoutGlobalScopes()->where('id', $r->created_by)->value('name') : null];
        $live = fn ($q) => $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        $rules = $live(DB::table('security_ip_rules')->where('type', $tab === 'whitelist' ? 'white' : 'black'))->orderByDesc('id')->get()->map($rule)->all();

        $auto = [];
        $otp = [];
        if ($tab === 'blacklist') {
            $auto = DB::table('security_counters')->where('scope', 'login_fail')->where('blocked_until', '>', now())->orderByDesc('blocked_until')->get()->map(fn ($c) => [
                'id' => $c->id, 'ip' => substr($c->subject, 3), 'until' => $c->blocked_until, 'level' => (int) $c->level, 'blocks' => (int) $c->blocks_total, 'hits' => (int) $c->hits, 'note' => $c->note,
            ])->all();
            $rows = DB::table('security_counters')->where('scope', 'otp_send')->where('blocked_until', '>', now())->orderByDesc('blocked_until')->get();
            $users = \App\Models\User::withoutGlobalScopes()->whereIn('id', $rows->map(fn ($c) => (int) explode(':', $c->subject)[1])->all())->get(['id', 'name', 'username'])->keyBy('id');
            $otp = $rows->map(function ($c) use ($users) {
                $u = $users[(int) explode(':', $c->subject)[1]] ?? null;

                return ['id' => $c->id, 'user' => $u ? $u->name : $c->subject, 'username' => $u->username ?? '', 'until' => $c->blocked_until, 'sent' => (int) $c->count, 'hits' => (int) $c->hits];
            })->all();
        }

        return Inertia::render('LoginLog/Addresses', [
            'tab' => $tab, 'rules' => $rules, 'auto' => $auto, 'otp' => $otp, 'myIp' => (string) $request->ip(),
            'counts' => [
                'black' => $live(DB::table('security_ip_rules')->where('type', 'black'))->count() + DB::table('security_counters')->whereIn('scope', ['login_fail', 'otp_send'])->where('blocked_until', '>', now())->count(),
                'white' => $live(DB::table('security_ip_rules')->where('type', 'white'))->count(),
            ],
            'limits' => [
                'login' => config('security.login'), 'otp' => config('security.otp'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $d = $request->validate(['ip' => ['required', 'ip'], 'type' => 'required|in:black,white', 'reason' => 'nullable|string|max:190', 'expires_at' => 'nullable|date|after:now']);
        $ip = $d['ip'];
        if ($d['type'] === 'black' && $ip === (string) $request->ip()) {
            return back()->with('error', __('You cannot blacklist the address you are using now.'));
        }
        $row = ['type' => $d['type'], 'reason' => $d['reason'] ?? null, 'expires_at' => $d['expires_at'] ?? null, 'created_by' => $request->user()->id, 'updated_at' => now()];
        $exists = DB::table('security_ip_rules')->where('ip', $ip)->exists();
        $exists ? DB::table('security_ip_rules')->where('ip', $ip)->update($row + ['hits' => 0, 'last_hit_at' => null]) : DB::table('security_ip_rules')->insert($row + ['ip' => $ip, 'created_at' => now()]);
        if ($d['type'] === 'white') {
            AccessGuard::unblock($ip);              // a whitelisted address starts clean
        }
        log_activity('Login security: ' . $ip . ' ' . ($d['type'] === 'white' ? 'whitelisted' : 'blacklisted'), ['reason' => $d['reason'] ?? null, 'expires_at' => $d['expires_at'] ?? null], null, 'LoginSecurity');

        return back()->with('message', $d['type'] === 'white' ? __(':ip is whitelisted: it is never counted or blocked.', ['ip' => $ip]) : __(':ip is blacklisted: nobody can sign in from it.', ['ip' => $ip]));
    }

    public function destroy(Request $request, int $id)
    {
        $this->guard($request);
        $r = DB::table('security_ip_rules')->where('id', $id)->first();
        abort_unless($r, 404);
        DB::table('security_ip_rules')->where('id', $id)->delete();
        log_activity('Login security: ' . $r->ip . ' removed from the ' . ($r->type === 'white' ? 'whitelist' : 'blacklist'), [], null, 'LoginSecurity');

        return back()->with('message', __(':ip was removed from the list.', ['ip' => $r->ip]));
    }

    /** lifts an automatic block (wrong sign-ins) or a block on sending sign-in codes */
    public function clearBlock(Request $request, int $id)
    {
        $this->guard($request);
        $c = DB::table('security_counters')->where('id', $id)->first();
        abort_unless($c, 404);
        DB::table('security_counters')->where('id', $id)->delete();
        log_activity('Login security: block lifted for ' . $c->subject, ['scope' => $c->scope], null, 'LoginSecurity');

        return back()->with('message', __('The block was lifted.'));
    }
}
