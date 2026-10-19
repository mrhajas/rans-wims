<?php

namespace App\Http\Controllers\Simulation;

use App\Http\Controllers\Controller;
use App\Services\Simulation\SimulationCatalog;
use App\Services\Simulation\SimulationImporter;
use App\Services\Simulation\SimulationProvenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Which simulation records came from production, which were changed since, and which were made here. */
class ProvenanceController extends Controller
{
    use GuardsSimulation;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index()
    {
        $this->need('read-simulation-provenance');
        $sum = SimulationProvenance::summary();
        $modules = collect(SimulationCatalog::modules())->map(function ($m, $k) use ($sum) {
            $t = $m['table'];
            $conn = SimulationImporter::isAccounts($t) ? 'accounts' : 'mysql';
            $all = (int) DB::connection($conn)->table($t)->when(\Illuminate\Support\Facades\Schema::connection($conn)->hasColumn($t, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->count();
            $s = $sum[$t] ?? [];
            $imported = (int) ($s['imported'] ?? 0);
            $modified = (int) ($s['modified'] ?? 0);

            return ['key' => $k, 'table' => $t, 'label' => __($m['label']), 'total' => $all, 'imported' => $imported, 'modified' => $modified, 'removed' => (int) ($s['removed'] ?? 0), 'synthetic' => max(0, $all - $imported - $modified)];
        })->values();

        return Inertia::render('Simulation/Provenance', ['modules' => $modules, 'can' => ['restore' => auth()->user()->can('manage-simulation-scenarios')]]);
    }

    /** the records of one module with their state, newest first */
    public function records(Request $request)
    {
        $this->need('read-simulation-provenance');
        $d = $request->validate(['module' => 'required|string|max:40', 'state' => 'nullable|in:imported,modified,synthetic,removed', 'page' => 'nullable|integer|min:1']);
        $m = SimulationCatalog::modules()[$d['module']] ?? abort(404);
        $t = $m['table'];
        $conn = DB::connection(SimulationImporter::isAccounts($t) ? 'accounts' : 'mysql');
        $state = $d['state'] ?? null;
        SimulationProvenance::refresh($t);
        $prov = DB::table('simulation_provenance')->where('table_name', $t)->get()->keyBy('sim_id');
        $q = $conn->table($t);
        if (\Illuminate\Support\Facades\Schema::connection(SimulationImporter::isAccounts($t) ? 'accounts' : 'mysql')->hasColumn($t, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if (in_array($state, ['imported', 'modified'], true)) {
            $q->whereIn('id', $prov->where('state', $state)->keys()->all() ?: [0]);
        } elseif ($state === 'synthetic') {
            $q->whereNotIn('id', $prov->whereIn('state', ['imported', 'modified'])->keys()->all() ?: [0]);
        } elseif ($state === 'removed') {
            return response()->json(['rows' => $prov->where('state', 'removed')->map(fn ($p) => ['id' => $p->sim_id, 'title' => '#' . $p->sim_id, 'state' => 'removed', 'source_id' => $p->source_id])->values()->all(), 'total' => $prov->where('state', 'removed')->count()]);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage((int) ($d['page'] ?? 1), 25)->get(['id', $m['title']]);

        return response()->json(['total' => $total, 'rows' => $rows->map(fn ($r) => ['id' => $r->id, 'title' => (string) ($r->{$m['title']} ?? '#' . $r->id), 'state' => ! isset($prov[$r->id]) || $prov[$r->id]->state === 'synthetic' ? 'synthetic' : $prov[$r->id]->state, 'source_id' => $prov[$r->id]->source_id ?? null])->all()]);
    }

    public function compare(Request $request)
    {
        $this->need('read-simulation-provenance');
        $d = $request->validate(['module' => 'required|string|max:40', 'id' => 'required|integer|min:1']);
        $m = SimulationCatalog::modules()[$d['module']] ?? abort(404);

        return response()->json(['state' => SimulationProvenance::stateOf($m['table'], (int) $d['id']), 'detail' => SimulationProvenance::compare($m['table'], (int) $d['id'])]);
    }

    public function restore(Request $request)
    {
        $this->need('manage-simulation-scenarios');
        $d = $request->validate(['module' => 'required|string|max:40', 'id' => 'required|integer|min:1']);
        $m = SimulationCatalog::modules()[$d['module']] ?? abort(404);
        $this->confirmed($request, 'RESTORE');
        $ok = SimulationProvenance::restore($m['table'], (int) $d['id']);
        log_activity('Simulation record restored to its imported state', ['table' => $m['table'], 'id' => $d['id']], null, 'Simulation');

        return response()->json(['ok' => $ok]);
    }
}
