<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ScanShortcutController extends Controller
{
    /**
     * Authorizable::callAction() would auto-derive update-scan (never
     * registered — this is a personal preference every authenticated user
     * sets for themselves, not a permission-gated module), 403ing everyone
     * but Super Admin from customizing their own scan shortcut.
     */
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'ctrl' => 'required|boolean',
            'meta' => 'required|boolean',
            'shift' => 'required|boolean',
            'alt' => 'required|boolean',
            'key' => 'required|string|max:20',
        ]);

        abort_unless($data['ctrl'] || $data['meta'] || $data['shift'] || $data['alt'], 422, 'Shortcut must include at least one modifier key.');

        $user = $request->user();
        $extra = $user->extra_attributes ?? [];
        $extra['scan_shortcut'] = $data;
        $user->extra_attributes = $extra;
        $user->save();

        return response()->json(['shortcut' => $data]);
    }
}
