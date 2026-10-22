<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Category;
use Illuminate\Http\Request;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Support\Facades\Storage;

class CategoryPortController extends Controller
{
    public function __construct()
    {
        $this->requirePermissions(['export' => 'read-categories', 'import' => 'create-categories', 'save' => 'create-categories']);
    }

    public function export()
    {
        return (new FastExcel($this->categoryGenerator()))->download('categories.xlsx');
    }

    public function import()
    {
        return Inertia::render('Category/Import');
    }

    public function save(Request $request)
    {
        $request->validate(['excel' => 'required|file|mimes:xls,xlsx']);

        $path = $request->file('excel')->store('imports');
        try {
            $categories = (new FastExcel())->import(Storage::path($path), function ($line) {
                if (! $line['name'] || ! $line['code']) {
                    throw new \Exception(__('name & code are required.'));
                }

                return Category::updateOrCreate(['code' => $line['code']], [
                    'name'      => $line['name'],
                    'parent_id' => $line['parent'] ? Category::where('code', $line['parent'])->sole()->id : null,
                ]);
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        \App\Services\Workflow\WorkflowMessenger::dispatch('categories', 'imported', null, $request->user(), [
            'account_id' => $request->user()->account_id ?? 1, 'title' => __('Categories Imported'),
            'message' => $request->user()->name . ' imported ' . $categories->count() . ' categories from a spreadsheet.', 'url' => route('categories.index'),
            'dedupe' => 'categories|imported|' . $request->user()->id . '|' . intdiv(time(), 20), 'values' => ['count' => (string) $categories->count()],
        ]);

        return redirect()->route('categories.index')->with('message', __choice('imported_text', ['records' => 'Category', 'count' => $categories->count()]));
    }

    private function categoryGenerator()
    {
        foreach (Category::cursor() as $category) {
            yield [
                'name'   => $category->name,
                'code'   => $category->code,
                'parent' => $category->parent_id ? $category->parent->code : '',
            ];
        }
    }
}
