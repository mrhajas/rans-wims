<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransportSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransportSettingsController extends Controller
{
    /**
     * This is a settings page, not a CRUD resource — 'edit'/'update' would
     * otherwise auto-derive to 'update-transport-settings', which isn't a
     * registered permission (manage-transport-settings is). Disabling
     * Authorizable's auto-check here, same as DigitalAttendanceSettingsController.
     */
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function edit(Request $request)
    {
        $this->authorize('manage-transport-settings');

        return Inertia::render('TransportManagement/Settings', [
            'transport_settings' => transport_settings(),
        ]);
    }

    public function update(TransportSettingsRequest $request)
    {
        $this->authorize('manage-transport-settings');

        $data = $request->validated();
        $before = transport_settings();
        $accountId = getAccountId();

        foreach (['transport_default_price', 'transport_loading_daily_rate'] as $key) {
            Setting::updateOrCreate(
                ['tec_key' => $key, 'account_id' => $accountId],
                ['tec_value' => (string) $data[$key]]
            );
        }

        log_activity(__('Transport Settings updated.'), ['old_value' => $before, 'new_value' => $data], $request->user(), 'Transport Settings');

        \App\Services\TransportNotifier::notify('transport-settings-changed', __('Transport Settings Changed'), __('The default trip price is now :price and the Loading & Unloading daily rate :rate (changed by :user).', ['price' => number_format((float) $data['transport_default_price'], 2), 'rate' => number_format((float) $data['transport_loading_daily_rate'], 2), 'user' => $request->user()->name]), route('transport-settings.edit'));

        return back()->with('message', __('Transport Settings saved.'));
    }
}
