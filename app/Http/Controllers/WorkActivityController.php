<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\WorkActivity;
use Illuminate\Http\Request;
use App\Http\Requests\WorkActivityRequest;
use App\Http\Resources\WorkActivityResource;
use App\Http\Resources\WorkActivityCollection;

class WorkActivityController extends Controller
{
    // Activities are a shared lookup managed by check-out users; not gated by per-resource permissions.
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function create()
    {
        return Inertia::render('WorkActivity/Form');
    }

    public function destroy(WorkActivity $activity)
    {
        if ($activity->del()) {
            return redirect()->route('activities.index')->with('message', __choice('action_text', ['record' => 'Activity', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(WorkActivity $activity)
    {
        if ($activity->delP()) {
            return redirect()->route('activities.index')->with('message', __choice('action_text', ['record' => 'Activity', 'action' => 'permanently deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function edit(WorkActivity $activity)
    {
        return Inertia::render('WorkActivity/Form', ['edit' => new WorkActivityResource($activity)]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('search', 'trashed');

        return Inertia::render('WorkActivity/Index', [
            'filters'    => $filters,
            'stats'      => [
                'total'  => WorkActivity::count(),
                'in_use' => WorkActivity::whereHas('checkouts')->count(),
                'unused' => WorkActivity::whereDoesntHave('checkouts')->count(),
            ],
            'activities' => new WorkActivityCollection(
                WorkActivity::filter($filters)->orderByDesc('id')->paginate()->withQueryString()
            ),
        ]);
    }

    public function restore(WorkActivity $activity)
    {
        $activity->restore();

        return back()->with('message', __choice('action_text', ['record' => 'Activity', 'action' => 'restored']));
    }

    public function store(WorkActivityRequest $request)
    {
        WorkActivity::create($request->validated());

        return redirect()->route('activities.index')->with('message', __choice('action_text', ['record' => 'Activity', 'action' => 'created']));
    }

    public function update(WorkActivityRequest $request, WorkActivity $activity)
    {
        $activity->update($request->validated());

        return back()->with('message', __choice('action_text', ['record' => 'Activity', 'action' => 'updated']));
    }
}
