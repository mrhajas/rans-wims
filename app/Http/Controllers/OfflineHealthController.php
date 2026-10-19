<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Deliberately lightweight: the offline-status poller in the browser hits
 * this every ~45s, so it must stay a single no-query response. Sitting
 * behind the same auth stack as the rest of the app (rather than being
 * public) is what lets the poller tell "server unreachable" (request
 * fails/times out) apart from "server's fine but your session expired"
 * (this responds, but authenticated=false) — see resources/js/Core/offlineStatus.js.
 */
class OfflineHealthController extends Controller
{
    public function check(Request $request)
    {
        return response()->json([
            'ok' => true,
            'authenticated' => (bool) $request->user(),
            'user_id' => $request->user()?->id,
            'account_id' => $request->user()?->account_id,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
