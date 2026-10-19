<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\Subcontracts\SubcontractProjectMapping as Pm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;
use Rap2hpoutre\FastExcel\SheetCollection;

/**
 * Project Name Mapping: the project / location names typed in the Excel (work group names) matched to Project(s) / Warehouse(s), saved as drafts, then CONFIRMED with the person's password.
 * Every action needs map-projects-subcontracts (a Super Admin has it); money figures only for who may see financials.
 */
class ProjectMappingController extends Controller
{
    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]); // every action is checked by name below
    }

    /** The Project(s) / Warehouse(s) a name can be matched to: the account's active warehouses. */
    private function projectOptions()
    {
        return Warehouse::ofAccount()->active()->orderBy('name')->get(['id', 'name'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name])->values();
    }

    /** The ids of the account's warehouses among these (the ones a person may choose). */
    private function knownProjects(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids ? Warehouse::ofAccount()->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
    }

    public function index(Request $request)
    {
        $this->need('map-projects-subcontracts');
        $money = $this->money();
        $rows = collect(Pm::names())->map(function ($r) use ($money) {
            $r['totals'] = $money ? (object) $r['totals'] : (object) [];

            return $r;
        })->values();
        $by = $rows->countBy('state');
        $counts = ['names' => $rows->count(), 'matched' => ($by['confirmed'] ?? 0) + ($by['pending'] ?? 0), 'pending' => (int) ($by['pending'] ?? 0), 'none' => (int) ($by['none'] ?? 0), 'confirmed' => (int) ($by['confirmed'] ?? 0)];

        return Inertia::render('Subcontracts/ProjectMapping', ['rows' => $rows, 'projects' => $this->projectOptions(), 'counts' => $counts, 'money' => $money, 'can' => ['map' => true, 'create_project' => (bool) $request->user()->can('create-warehouses')],
            'categories' => ['Main Warehouse', 'Rans Warehouse', 'Directors', 'Private', 'Government', 'General']]);
    }

    /**
     * Creates a Project(s) / Warehouse(s) from the Excel name, from this page, so a name that has no project yet can be matched straight away.
     * Needs the mapping right AND the right to create warehouses; the new record is an ordinary active Project(s) / Warehouse(s) like one made on the Warehouses page.
     */
    public function createProject(Request $request)
    {
        $this->need('map-projects-subcontracts');
        abort_unless($request->user()->can('create-warehouses'), 403, __('You do not have permission to create a Project(s) / Warehouse(s).'));
        $d = $request->validate([
            'name' => 'required|string|max:50', 'code' => 'required|string|max:20|unique:warehouses,code', 'status' => 'required|string|in:Main Warehouse,Directors,Private,Government,General,Rans Warehouse',
            'for_who' => 'nullable|string|max:255', 'address' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:40', 'project_manager' => 'nullable|string|max:190', 'stage' => 'nullable|string|max:255',
            'start_date' => 'nullable|date', 'expected_end_date' => 'nullable|date|after_or_equal:start_date', 'site_budget' => 'nullable|numeric|min:0|max:99999999999999',
        ]);
        $w = Warehouse::create($d + ['active' => 1]);
        log_activity('Project(s) / Warehouse(s) ' . $w->name . ' created from the Subcontract Project Name Mapping', ['code' => $w->code], $w, 'Subcontracts');
        \App\Services\ProjectNotifier::fire('created', $w, __('Project created'), $w->name . ' ' . __('was created by :n.', ['n' => $request->user()->name]));

        return response()->json(['message' => __('Project(s) / Warehouse(s) :n was created.', ['n' => $w->name]), 'project' => ['value' => $w->id, 'label' => $w->name]]);
    }

    /** Saves the chosen projects as DRAFTS: no work group changes until the mapping is confirmed. */
    public function save(Request $request)
    {
        $this->need('map-projects-subcontracts');
        $d = $request->validate(['mappings' => 'required|array|min:1|max:500', 'mappings.*.key' => 'required|string|max:190', 'mappings.*.warehouse_id' => 'nullable|integer']);
        $n = $this->saveDrafts($d['mappings'], $request);

        return $this->done($request, __(':n choice(s) saved as a draft. Nothing changes in the accounts until you confirm.', ['n' => $n]), ['saved' => $n]);
    }

    /** Reads a matched workbook and says what each row would become. Nothing is saved. */
    public function upload(Request $request)
    {
        $this->need('map-projects-subcontracts');
        $request->validate(['file' => 'required|file|max:4096']);
        $f = $request->file('file');
        try {
            $rows = Pm::importWorkbook($f->getRealPath(), $f->getClientOriginalName());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $by = collect($rows)->countBy('status');
        log_activity('Subcontract project mapping workbook read', ['file' => mb_substr($f->getClientOriginalName(), 0, 120), 'rows' => count($rows), 'matched' => (int) ($by['matched'] ?? 0)], null, 'Subcontracts');

        return response()->json(['rows' => $rows, 'counts' => ['rows' => count($rows), 'matched' => (int) ($by['matched'] ?? 0), 'unmatched' => (int) ($by['unmatched'] ?? 0), 'blank' => (int) ($by['blank'] ?? 0), 'unknown_name' => (int) ($by['unknown_name'] ?? 0)]]);
    }

    /** Saves the matched rows of a preview as drafts. */
    public function import(Request $request)
    {
        $this->need('map-projects-subcontracts');
        $d = $request->validate(['rows' => 'required|array|min:1|max:1000', 'rows.*.key' => 'required|string|max:190', 'rows.*.warehouse_id' => 'nullable|integer']);
        $n = $this->saveDrafts(array_values(array_filter($d['rows'], fn ($r) => ! empty($r['warehouse_id']))), $request, 'rows');

        return $this->done($request, __(':n match(es) from the workbook saved as a draft. Nothing changes in the accounts until you confirm.', ['n' => $n]), ['saved' => $n]);
    }

    /** Applies every waiting mapping; needs the person's own password. */
    public function confirm(Request $request)
    {
        $this->need('map-projects-subcontracts');
        $d = $request->validate(['password' => 'required|string|max:200']);
        $u = $request->user();
        if (! Hash::check($d['password'], (string) $u->password)) {
            log_activity('Subcontract project mapping confirmation refused: wrong password', ['user' => $u->id], null, 'Subcontracts');
            throw ValidationException::withMessages(['password' => __('That password is incorrect.')]);
        }
        $res = Pm::confirm((int) $u->id);

        return $this->done($request, __('The mapping is confirmed: :g work group(s) now show their Project(s) / Warehouse(s).', ['g' => $res['groups']]), ['result' => $res]);
    }

    /** An Excel workbook to fill in: every Excel name with where it is used, a column for the Project(s) / Warehouse(s), and the list of Project(s) / Warehouse(s). */
    public function template(Request $request)
    {
        $this->need('map-projects-subcontracts');
        $names = Pm::names();
        $head = ['No.', 'Excel project name', 'Used in', 'Sheets', 'Items', 'Project(s) / Warehouse(s)', 'Note'];
        $a = [['Project Name Mapping'], ['Type the exact name (or the code) of a Project(s) / Warehouse(s) in the Project(s) / Warehouse(s) column, as listed on the sheet "Projects and Warehouses". Leave a row blank when the name has no Project(s) / Warehouse(s). Do not change the other columns, then upload this file again on the Project Name Mapping page.'], array_map(fn ($h) => __($h), $head)];
        foreach ($names as $i => $n) {
            $a[] = [$i + 1, $n['excel_name'], $n['agreements'], implode('; ', array_map(fn ($x) => $x['title'], $n['agreement_list'])), $n['items'], '', ''];
        }
        $b = [[__('Project(s) / Warehouse(s)'), __('Code')]];
        foreach (Warehouse::ofAccount()->active()->orderBy('name')->get(['name', 'code']) as $w) {
            $b[] = [$w->name, (string) $w->code];
        }
        // names come from the database and from the workbook: a text that starts with = + - or @ gets a leading quote so Excel never runs it as a formula (the upload takes the quote off again)
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && ! is_numeric($v) ? "'" . $v : $v;
        $a = array_map(fn ($row) => array_map($safe, $row), $a);
        $b = array_map(fn ($row) => array_map($safe, $row), $b);
        $file = tempnam(sys_get_temp_dir(), 'pm') . '.xlsx';
        (new FastExcel(new SheetCollection([Pm::SHEET => $a, 'Projects and Warehouses' => $b])))->withoutHeaders()->export($file);
        log_activity('Subcontract project mapping template downloaded', ['names' => count($names), 'projects' => count($b) - 1], null, 'Subcontracts');

        return response()->download($file, 'project-name-mapping-' . now()->format('Ymd-His') . '.xlsx', ['X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
    }

    // -----------------------------------------------------------------------------------------------------------------

    private function saveDrafts(array $items, Request $request, string $field = 'mappings'): int
    {
        $known = array_flip($this->knownProjects(array_column($items, 'warehouse_id')));
        $clean = [];
        foreach ($items as $i) {
            $w = isset($i['warehouse_id']) && $i['warehouse_id'] !== '' && $i['warehouse_id'] !== null ? (int) $i['warehouse_id'] : null;
            if ($w !== null && ! isset($known[$w])) {
                throw ValidationException::withMessages([$field => __('One of the chosen projects does not exist.')]);
            }
            $clean[] = ['key' => (string) $i['key'], 'warehouse_id' => $w];
        }
        try {
            return Pm::saveMany($clean, (int) $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    private function done(Request $request, string $message, array $json)
    {
        if ($request->header('X-Inertia') || ! $request->expectsJson()) {
            return back()->with('message', $message);
        }

        return response()->json($json + ['message' => $message]);
    }
}
