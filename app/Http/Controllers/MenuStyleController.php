<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** The look of the main menu is a personal choice (Classic or Pro): kept on the person's own settings, so every device shows the same menu. */
class MenuStyleController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function update(Request $request)
    {
        $d = $request->validate(['style' => 'required|in:classic,pro']);
        $user = $request->user();
        // the Pro menu needs the feature switched on and the permission (Classic is always allowed)
        abort_if($d['style'] === 'pro' && ! ($user->can('use-pro-menu') && \App\Services\FeatureManager::canAccess('pro_menu', $user)), 403, __('The Pro menu is not available to you.'));
        $extra = $user->extra_attributes ?? [];
        $extra['menu_style'] = $d['style'];
        $user->extra_attributes = $extra;
        $user->save();

        return response()->json(['style' => $d['style']]);
    }
}
