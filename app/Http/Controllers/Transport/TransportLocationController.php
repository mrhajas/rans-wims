<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransportLocationRequest;
use App\Http\Resources\TransportLocationCollection;
use App\Models\TransportLocation;
use App\Models\TransportRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransportLocationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-transport-locations'), 403);

        $filters = $request->only('search', 'location_type');

        $locations = TransportLocation::with('creator:id,name')
            ->filter($filters)
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('TransportManagement/Locations/Index', [
            'filters' => $filters,
            'locations' => new TransportLocationCollection($locations),
            'locationTypes' => TransportLocation::LOCATION_TYPES,
            'canCreate' => $request->user()->can('create-transport-locations'),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('create-transport-locations'), 403);

        return Inertia::render('TransportManagement/Locations/Form', [
            'locationTypes' => TransportLocation::LOCATION_TYPES,
        ]);
    }

    public function store(TransportLocationRequest $request)
    {
        abort_unless($request->user()->can('create-transport-locations'), 403);

        $location = TransportLocation::create([
            ...$request->validated(),
            'created_by_user_id' => $request->user()->id,
        ]);

        log_activity(__('Transport Location created.'), ['name' => $location->name, 'type' => $location->location_type], $location, 'Transport Location');
        \App\Services\TransportNotifier::notify('transport-location-changed', __('Location Added'), __(':l (:t) was added by :user.', ['l' => $location->name, 't' => $location->location_type, 'user' => $request->user()->name]), route('transport-locations.index'), $location);

        if ($request->expectsJson()) {
            return response()->json($location);
        }

        return redirect()->route('transport-locations.index')->with('message', __('Location created.'));
    }

    public function edit(Request $request, TransportLocation $transport_location)
    {
        abort_unless($request->user()->can('update-transport-locations'), 403);

        return Inertia::render('TransportManagement/Locations/Form', [
            'location' => $transport_location,
            'locationTypes' => TransportLocation::LOCATION_TYPES,
        ]);
    }

    public function update(TransportLocationRequest $request, TransportLocation $transport_location)
    {
        abort_unless($request->user()->can('update-transport-locations'), 403);

        $transport_location->update($request->validated());

        log_activity(__('Transport Location updated.'), ['name' => $transport_location->name], $transport_location, 'Transport Location');
        \App\Services\TransportNotifier::notify('transport-location-changed', __('Location Edited'), __(':l was edited by :user.', ['l' => $transport_location->name, 'user' => $request->user()->name]), route('transport-locations.index'), $transport_location);

        return redirect()->route('transport-locations.index')->with('message', __('Location updated.'));
    }

    public function destroy(Request $request, TransportLocation $transport_location)
    {
        abort_unless($request->user()->can('delete-transport-locations'), 403);
        abort_if(TransportRecord::where('transport_location_id', $transport_location->id)->exists(), 422, __('This location is used by Transport Records and cannot be deleted, deactivate it instead.'));

        $transport_location->deleted_by = $request->user()->id;
        $transport_location->save();
        $transport_location->delete();

        log_activity(__('Transport Location deleted.'), ['name' => $transport_location->name], $transport_location, 'Transport Location');
        \App\Services\TransportNotifier::notify('transport-location-changed', __('Location Deleted'), __(':l was deleted by :user.', ['l' => $transport_location->name, 'user' => $request->user()->name]), route('transport-locations.index'), $transport_location);

        return redirect()->route('transport-locations.index')->with('message', __('Location deleted.'));
    }
}
