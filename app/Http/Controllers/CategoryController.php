<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CategoryCollection;

class CategoryController extends Controller
{
    /** Categorys workflow page (Who Gets Notified / Messages): which event happened; the page decides who is told. */
    private function alertCategory(string $event, string $title, string $message, $category): void
    {
        \App\Services\Workflow\WorkflowMessenger::dispatch('categories', $event, null, auth()->user(), [
            'account_id' => auth()->user()?->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('categories.index'),
            'dedupe' => 'categories|' . $event . '|' . ($category->id ?? 0) . '|' . intdiv(time(), 20),
            'values' => ['name' => (string) $category->name, 'code' => (string) ($category->code ?? ''), 'parent' => (string) optional($category->parent)->name],
        ]);
    }

    public function create()
    {
        return Inertia::render('Category/Form', ['parents' => Category::onlyParents()->get()]);
    }

    public function destroy(Category $category)
    {
        if ($category->del()) {
            $this->alertCategory('deleted', __('Category Deleted'), $category->name . ' was deleted.', $category);

            return redirect()->route('categories.index')->with('message', __choice('action_text', ['record' => 'Category', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Category $category)
    {
        if ($category->delP()) {
            $this->alertCategory('deleted', __('Category Permanently Deleted'), $category->name . ' was permanently deleted.', $category);

            return redirect()->route('categories.index')->with('message', __choice('action_text', ['record' => 'Category', 'action' => 'permanently deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function edit(Category $category)
    {
        return Inertia::render('Category/Form', [
            'parents' => Category::onlyParents()->get(),
            'edit'    => new CategoryResource($category),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('search', 'parent', 'trashed');

        return Inertia::render('Category/Index', [
            'filters'    => $filters,
            'parents'    => Category::onlyParents()->get(),
            'stats'      => [
                'total'  => Category::count(),
                'parent' => Category::whereNull('parent_id')->count(),
                'child'  => Category::whereNotNull('parent_id')->count(),
                'in_use' => Category::whereHas('items')->count(),
            ],
            'categories' => new CategoryCollection(
                Category::with('parent:id,name,code')->filter($filters)->orderByDesc('id')->paginate()->withQueryString()
            ),
        ]);
    }

    public function restore(Category $category)
    {
        abort_unless(auth()->user()->can('delete-categories'), 403);

        $category->restore();
        $this->alertCategory('restored', __('Category Restored'), $category->name . ' was restored.', $category);

        return back()->with('message', __choice('action_text', ['record' => 'Category', 'action' => 'restored']));
    }

    public function store(CategoryRequest $request)
    {
        $category = Category::create($request->validated());
        $this->alertCategory('created', __('Category Created'), $category->name . ' was created.', $category);

        return redirect()->route('categories.index')->with('message', __choice('action_text', ['record' => 'Category', 'action' => 'created']));
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $category->update($request->validated());
        $this->alertCategory('updated', __('Category Edited'), $category->name . ' was edited.', $category);

        return back()->with('message', __choice('action_text', ['record' => 'Category', 'action' => 'updated']));
    }
}
