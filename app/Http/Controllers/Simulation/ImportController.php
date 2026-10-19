<?php

namespace App\Http\Controllers\Simulation;

use App\Http\Controllers\Controller;
use App\Services\Simulation\SimulationCatalog;
use App\Services\Simulation\SimulationFileImport;
use App\Services\Simulation\SimulationImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** The Import Centre: look at production records (read only), see what an import would bring in, and bring it in as independent simulation records. */
class ImportController extends Controller
{
    use GuardsSimulation;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index()
    {
        $this->need('read-simulation-import', 'run-simulation-import');
        $batches = DB::table('simulation_import_batches')->orderByDesc('id')->limit(30)->get()->map(fn ($b) => ['id' => $b->id, 'reference' => $b->reference, 'module' => $b->module, 'status' => $b->status, 'summary' => json_decode((string) $b->summary, true), 'problems' => json_decode((string) $b->problems, true),
            'started_at' => (string) $b->started_at, 'finished_at' => (string) $b->finished_at]);

        return Inertia::render('Simulation/Import', ['modules' => SimulationCatalog::forPage(), 'batches' => $batches, 'kinds' => collect(SimulationFileImport::specs())->map(fn ($s, $k) => ['key' => $k, 'label' => __($s['label']), 'columns' => SimulationFileImport::template($k)])->values(),
            'can' => ['run' => auth()->user()->can('run-simulation-import')], 'limit' => (int) config('simulation.import_batch_limit')]);
    }

    public function search(Request $request)
    {
        $this->need('read-simulation-import', 'run-simulation-import');
        $d = $request->validate(['module' => 'required|string|max:40', 'q' => 'nullable|string|max:100', 'filter' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:10000']);
        abort_unless(isset(SimulationCatalog::modules()[$d['module']]), 404);

        return response()->json((new SimulationImporter(auth()->id()))->search($d['module'], (string) ($d['q'] ?? ''), $d['filter'] ?? null, (int) ($d['page'] ?? 1)));
    }

    public function preview(Request $request)
    {
        $this->need('read-simulation-import', 'run-simulation-import');
        $d = $request->validate(['module' => 'required|string|max:40', 'id' => 'required|integer|min:1']);
        abort_unless(isset(SimulationCatalog::modules()[$d['module']]), 404);

        return response()->json((new SimulationImporter(auth()->id()))->preview($d['module'], (int) $d['id']));
    }

    private function selection(Request $request): array
    {
        $d = $request->validate(['module' => 'required|string|max:40', 'ids' => 'required|array|min:1|max:' . (int) config('simulation.import_batch_limit'), 'ids.*' => 'integer|min:1', 'optional' => 'nullable|array', 'optional.*' => 'boolean', 'children' => 'nullable|array', 'children.*' => 'boolean',
            'files' => 'nullable|boolean', 'mask' => 'nullable|boolean']);
        abort_unless(isset(SimulationCatalog::modules()[$d['module']]), 404);

        return [$d['module'], $d['ids'], ['optional' => (array) ($d['optional'] ?? []), 'children' => (array) ($d['children'] ?? []), 'files' => (bool) ($d['files'] ?? false), 'mask' => (bool) ($d['mask'] ?? true)]];
    }

    /** what an import would bring in: nothing is written */
    public function plan(Request $request)
    {
        $this->need('read-simulation-import', 'run-simulation-import');
        [$module, $ids, $opt] = $this->selection($request);
        try {
            $plan = (new SimulationImporter(auth()->id()))->plan($module, $ids, $opt);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['ids' => $e->getMessage()]);
        }
        unset($plan['_role'], $plan['_dups']);

        return response()->json($plan);
    }

    public function run(Request $request)
    {
        $this->need('run-simulation-import');
        [$module, $ids, $opt] = $this->selection($request);
        try {
            $r = (new SimulationImporter(auth()->id()))->run($module, $ids, $opt);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['ids' => $e->getMessage()]);
        }
        log_activity('Simulation import', ['batch' => $r['reference'], 'module' => $module, 'rows' => $r['summary']['total'] ?? 0], null, 'Simulation');

        return response()->json($r);
    }

    /** run an earlier batch again (records that are already in are skipped, so nothing is copied twice) */
    public function retry(Request $request, int $id)
    {
        $this->need('run-simulation-import');
        $b = DB::table('simulation_import_batches')->where('id', $id)->first() ?? abort(404);
        $sel = json_decode((string) $b->selection, true);
        abort_unless($sel && isset(SimulationCatalog::modules()[$b->module]), 422);
        try {
            $r = (new SimulationImporter(auth()->id()))->run($b->module, $sel['ids'], $sel['options'] ?? []);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['ids' => $e->getMessage()]);
        }
        log_activity('Simulation import retried', ['batch' => $r['reference'], 'from' => $b->reference], null, 'Simulation');

        return response()->json($r);
    }

    public function template(string $kind)
    {
        $this->need('run-simulation-import');
        abort_unless(isset(SimulationFileImport::specs()[$kind]), 404);
        $cols = SimulationFileImport::template($kind);

        return response(implode(',', $cols) . "\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="simulation-' . $kind . '-template.csv"']);
    }

    public function fileCheck(Request $request)
    {
        $this->need('run-simulation-import');
        $d = $request->validate(['kind' => 'required|string|max:40', 'file' => 'required|file|max:8192|mimes:csv,txt,xlsx']);
        abort_unless(isset(SimulationFileImport::specs()[$d['kind']]), 404);
        $r = SimulationFileImport::check($d['kind'], $request->file('file')->getRealPath());

        return response()->json(['rows' => count($r['rows']), 'errors' => $r['errors'], 'error_count' => $r['error_count'], 'sample' => array_slice($r['rows'], 0, 5)]);
    }

    public function fileRun(Request $request)
    {
        $this->need('run-simulation-import');
        $d = $request->validate(['kind' => 'required|string|max:40', 'file' => 'required|file|max:8192|mimes:csv,txt,xlsx']);
        abort_unless(isset(SimulationFileImport::specs()[$d['kind']]), 404);
        $r = SimulationFileImport::run($d['kind'], $request->file('file')->getRealPath(), auth()->id());
        log_activity('Simulation file import', ['kind' => $d['kind'], 'rows' => $r['count']], null, 'Simulation');

        return response()->json($r);
    }
}
