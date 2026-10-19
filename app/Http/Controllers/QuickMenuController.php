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
    public function track(Request $request)
    {
        $data = $request->validate(['section' => 'required|string|max:60']);

        $userId = $request->user()->id;

        DB::table('user_section_visits')->upsert(
            [[
                'user_id' => $userId,
                'section_key' => $data['section'],
                'visit_count' => 1,
                'last_visited_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id', 'section_key'],
            ['visit_count' => DB::raw('visit_count + 1'), 'last_visited_at' => now(), 'updated_at' => now()]
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
