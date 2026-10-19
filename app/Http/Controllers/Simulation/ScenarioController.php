<?php

namespace App\Http\Controllers\Simulation;

use App\Http\Controllers\Controller;
use App\Services\Simulation\SimulationCheckpoints as C;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Scenarios and checkpoints of the simulation. Nothing here can reach production. */
class ScenarioController extends Controller
{
    use GuardsSimulation;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index()
    {
        $this->need('manage-simulation-scenarios');
        $cur = C::ensureCurrent(auth()->id());
        $scenarios = DB::table('simulation_scenarios')->orderByDesc('is_current')->orderBy('name')->get();
        $cps = DB::table('simulation_checkpoints')->orderByDesc('id')->get()->groupBy('scenario_id');
        $users = DB::table('users')->pluck('name', 'id');

        return Inertia::render('Simulation/Scenarios', [
            'current' => $cur->id,
            'scenarios' => $scenarios->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'status' => $s->status, 'current' => (bool) $s->is_current, 'created_by' => $users[$s->created_by] ?? null, 'created_at' => (string) $s->created_at,
                'checkpoints' => ($cps[$s->id] ?? collect())->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'tables' => $c->tables, 'created_at' => (string) $c->created_at, 'by' => $users[$c->created_by] ?? null])->values()->all()])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->need('manage-simulation-scenarios');
        $d = $request->validate(['name' => 'required|string|max:120', 'description' => 'nullable|string|max:500', 'from' => 'nullable|integer']);
        $id = C::create($d['name'], $d['description'] ?? null, auth()->id(), $d['from'] ?? null);
        log_activity('Simulation scenario created', ['scenario' => $d['name']], null, 'Simulation');

        return back()->with('message', __('Scenario created. Switch to it to work in it.'));
    }

    public function switch(Request $request, int $id)
    {
        $this->need('manage-simulation-scenarios');
        C::switchTo($id, auth()->id());
        log_activity('Simulation scenario opened', ['scenario' => $id], null, 'Simulation');

        return back()->with('message', __('Scenario opened. The one you left was saved first.'));
    }

    public function checkpoint(Request $request, int $id)
    {
        $this->need('manage-simulation-scenarios');
        $d = $request->validate(['name' => 'required|string|max:120']);
        $cur = C::ensureCurrent(auth()->id());
        if ($cur->id !== $id) {
            throw ValidationException::withMessages(['name' => __('Only the open scenario can be saved. Open it first.')]);
        }
        C::save($id, $d['name'], 'manual', auth()->id());
        log_activity('Simulation checkpoint saved', ['scenario' => $id, 'name' => $d['name']], null, 'Simulation');

        return back()->with('message', __('Checkpoint saved.'));
    }

    public function restore(Request $request, int $checkpoint)
    {
        $this->need('manage-simulation-scenarios');
        $this->confirmed($request, 'RESTORE');
        $cp = DB::table('simulation_checkpoints')->find($checkpoint) ?? abort(404);
        $cur = C::ensureCurrent(auth()->id());
        abort_unless($cp->scenario_id === $cur->id, 422, 'Open that scenario first.');
        C::save($cur->id, 'Before restoring ' . $cp->name, 'auto', auth()->id());
        C::restore($checkpoint);
        log_activity('Simulation checkpoint restored', ['checkpoint' => $checkpoint], null, 'Simulation');

        return back()->with('message', __('Checkpoint restored. What was there before was saved first.'));
    }

    public function reset(Request $request)
    {
        $this->need('manage-simulation-scenarios');
        $this->confirmed($request, 'RESET');
        C::resetToBaseline(auth()->id());
        log_activity('Simulation scenario reset to its baseline', [], null, 'Simulation');

        return back()->with('message', __('The scenario is back at its baseline. What was there before was saved first.'));
    }

    public function baseline(Request $request)
    {
        $this->need('manage-simulation-scenarios');
        $this->confirmed($request, 'BASELINE');
        C::saveBaseline(auth()->id());
        log_activity('Simulation baseline saved', [], null, 'Simulation');

        return back()->with('message', __('The current state is now this scenario\'s baseline.'));
    }

    public function archive(int $id)
    {
        $this->need('manage-simulation-scenarios');
        $s = DB::table('simulation_scenarios')->find($id) ?? abort(404);
        abort_if($s->is_current, 422, 'Open another scenario first.');
        DB::table('simulation_scenarios')->where('id', $id)->update(['status' => $s->status === 'archived' ? 'active' : 'archived', 'updated_at' => now()]);

        return back()->with('message', __('Done.'));
    }

    public function destroy(Request $request, int $id)
    {
        $this->need('manage-simulation-scenarios');
        $this->confirmed($request, 'DELETE');
        $s = DB::table('simulation_scenarios')->find($id) ?? abort(404);
        abort_if($s->is_current, 422, 'Open another scenario first.');
        foreach (DB::table('simulation_checkpoints')->where('scenario_id', $id)->pluck('id') as $cp) {
            C::dropCheckpoint($cp);
        }
        DB::table('simulation_scenarios')->where('id', $id)->delete();
        log_activity('Simulation scenario deleted', ['scenario' => $s->name], null, 'Simulation');

        return back()->with('message', __('Scenario deleted. Production was not touched.'));
    }

    public function deleteCheckpoint(Request $request, int $checkpoint)
    {
        $this->need('manage-simulation-scenarios');
        $this->confirmed($request, 'DELETE');
        $cp = DB::table('simulation_checkpoints')->find($checkpoint) ?? abort(404);
        abort_if($cp->kind === 'baseline', 422, 'A baseline cannot be deleted.');
        C::dropCheckpoint($checkpoint);

        return back()->with('message', __('Checkpoint deleted.'));
    }

    /** headline figures of two states side by side: a checkpoint id, or 0 for what is live now */
    public function compare(Request $request)
    {
        $this->need('manage-simulation-scenarios');
        $d = $request->validate(['a' => 'required|integer|min:0', 'b' => 'required|integer|min:0']);

        return response()->json(['a' => C::figures($d['a'] ?: null), 'b' => C::figures($d['b'] ?: null)]);
    }
}
