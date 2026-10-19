<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Inertia\Inertia;
use Illuminate\Http\Request;
use App\Http\Requests\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Http\Resources\UnitCollection;

class UnitController extends Controller
{
    /** Units workflow page (Who Gets Notified / Messages): which event happened; the page decides who is told. */
    private function alertUnit(string $event, string $title, string $message, $unit): void
    {
        \App\Services\Workflow\WorkflowMessenger::dispatch('units', $event, null, auth()->user(), [
            'account_id' => auth()->user()?->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('units.index'),
            'dedupe' => 'units|' . $event . '|' . ($unit->id ?? 0) . '|' . intdiv(time(), 20),
            'values' => ['name' => (string) $unit->name, 'code' => (string) ($unit->code ?? ''), 'baseunit' => (string) optional($unit->baseUnit)->name],
        ]);
    }

    public function create()
    {
        return Inertia::render('Unit/Form', ['base_units' => Unit::base()->get()]);
    }

    public function destroy(Unit $unit)
    {
        if ($unit->del()) {
            $this->alertUnit('deleted', __('Unit Deleted'), $unit->name . ' was deleted.', $unit);

            return redirect()->route('units.index')->with('message', __choice('action_text', ['record' => 'Unit', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Unit $unit)
    {
        if ($unit->delP()) {
            $this->alertUnit('deleted', __('Unit Permanently Deleted'), $unit->name . ' was permanently deleted.', $unit);

            return redirect()->route('units.index')->with('message', __choice('action_text', ['record' => 'Unit', 'action' => 'permanently deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function edit(Unit $unit)
    {
        return Inertia::render('Unit/Form', [
            'base_units' => Unit::base()->get(),
            'edit'       => new UnitResource($unit),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('search', 'trashed');

        return Inertia::render('Unit/Index', [
            'filters' => $filters,
            'stats'   => [
                'total'   => Unit::count(),
                'base'    => Unit::whereNull('base_unit_id')->count(),
                'derived' => Unit::whereNotNull('base_unit_id')->count(),
            ],
            'units'   => new UnitCollection(
                Unit::filter($filters)->orderByDesc('id')->paginate()->withQueryString()
            ),
        ]);
    }

    public function restore(Unit $unit)
    {
        abort_unless(auth()->user()->can('delete-units'), 403);

        $unit->restore();
        $this->alertUnit('restored', __('Unit Restored'), $unit->name . ' was restored.', $unit);

        return back()->with('message', __choice('action_text', ['record' => 'Unit', 'action' => 'restored']));
    }

    public function store(UnitRequest $request)
    {
        $unit = Unit::create($request->validated());
        $this->alertUnit('created', __('Unit Created'), $unit->name . ' was created.', $unit);

        return redirect()->route('units.index')->with('message', __choice('action_text', ['record' => 'Unit', 'action' => 'created']));
    }

    public function update(UnitRequest $request, Unit $unit)
    {
        $unit->update($request->validated());
        $this->alertUnit('updated', __('Unit Edited'), $unit->name . ' was edited.', $unit);

        return back()->with('message', __choice('action_text', ['record' => 'Unit', 'action' => 'updated']));
    }
}
