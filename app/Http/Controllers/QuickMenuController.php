<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuickMenuController extends Controller
{
    // Deliberately not named `store`/`update` — Authorizable::callAction would map
    // those to permissions (e.g. `create-quick-menu`) that don't exist for any
    // non-Super-Admin role, silently 403ing everyone else. Same footgun already
    // hit and worked around in NotificationPreferenceController.
    /** Counts visits of sections: one (`section`) or several at once (`visits`: key => how many times), as the page sends them together. */
    public function track(Request $request)
    {
        $data = $request->validate([
            'section' => 'nullable|string|max:60', 'visits' => 'nullable|array|max:25', 'visits.*' => 'integer|min:1|max:200',
        ]);
        $visits = [];
        foreach ((array) ($data['visits'] ?? []) as $key => $n) {
            if (is_string($key) && $key !== '' && strlen($key) <= 60 && preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
                $visits[$key] = (int) $n;
            }
        }
        if (! empty($data['section'])) {
            $visits[$data['section']] = ($visits[$data['section']] ?? 0) + 1;
        }
        abort_if(! $visits, 422, __('Nothing to count.'));

        $userId = $request->user()->id;
        $now = now();
        DB::table('user_section_visits')->upsert(
            array_map(fn ($key, $n) => ['user_id' => $userId, 'section_key' => $key, 'visit_count' => $n, 'last_visited_at' => $now, 'created_at' => $now, 'updated_at' => $now], array_keys($visits), $visits),
            ['user_id', 'section_key'],
            ['visit_count' => DB::raw('visit_count + values(visit_count)'), 'last_visited_at' => $now, 'updated_at' => $now]
        );

        return response()->json(['ok' => true]);
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate(['quick_menu_enabled' => 'required|boolean']);

        $user = $request->user();
        $user->update(['quick_menu_enabled' => $data['quick_menu_enabled']]);

        return response()->json(['quick_menu_enabled' => (bool) $user->fresh()->quick_menu_enabled]);
    }
}
