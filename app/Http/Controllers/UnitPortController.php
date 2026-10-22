<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Support\Facades\Storage;

class UnitPortController extends Controller
{
    public function __construct()
    {
        $this->requirePermissions(['export' => 'read-units', 'import' => 'create-units', 'save' => 'create-units']);
    }

    public function export()
    {
        return (new FastExcel($this->unitGenerator()))->download('units.xlsx');
    }

    public function import()
    {
        return Inertia::render('Unit/Import');
    }

    public function save(Request $request)
    {
        $request->validate(['excel' => 'required|file|mimes:xls,xlsx']);

        $path = $request->file('excel')->store('imports');
        try {
            $units = (new FastExcel())->import(Storage::path($path), function ($line) {
                if (! $line['name'] || ! $line['code']) {
                    throw new \Exception(__('name & code are required.'));
                }

                return Unit::updateOrCreate(['code' => $line['code']], [
                    'name'            => $line['name'],
                    'operator'        => $line['operator'] ?? null,
                    'operation_value' => $line['operation_value'] ? (float) $line['operation_value'] : null,
                    'base_unit_id'    => $line['base_unit'] ? Unit::where('code', $line['base_unit'])->sole()->id : null,
                ]);
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        \App\Services\Workflow\WorkflowMessenger::dispatch('units', 'imported', null, $request->user(), [
            'account_id' => $request->user()->account_id ?? 1, 'title' => __('Units Imported'),
            'message' => $request->user()->name . ' imported ' . $units->count() . ' units from a spreadsheet.', 'url' => route('units.index'),
            'dedupe' => 'units|imported|' . $request->user()->id . '|' . intdiv(time(), 20), 'values' => ['count' => (string) $units->count()],
        ]);

        return redirect()->route('units.index')->with('message', __choice('imported_text', ['records' => 'Unit', 'count' => $units->count()]));
    }

    private function unitGenerator()
    {
        foreach (Unit::cursor() as $unit) {
            yield [
                'name'            => $unit->name,
                'code'            => $unit->code,
                'base_unit'       => $unit->base_unit_id ? $unit->baseUnit->code : '',
                'operator'        => $unit->operator,
                'operation_value' => $unit->operation_value,
            ];
        }
    }
}
