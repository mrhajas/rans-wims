<?php

namespace App\Http\Controllers\Asset;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AssetCategoryController extends Controller
{
    // See the manage-asset-categories permission migration — this used to
    // silently ride on the shared 'read-assets'/'create-assets' abilities
    // (Authorizable::callAction() derives its check from the route name's
    // 'assets' prefix, shared with the whole Assets module), so anyone who
    // could just browse the asset register could also edit its category
    // tree. Clearing the auto-derived map and gating every action on the
    // real, standalone permission below closes that.
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index()
    {
        abort_unless(auth()->user()->can('manage-asset-categories'), 403);

        return Inertia::render('Asset/Categories', [
            'categories' => AssetCategory::with('children')->topLevel()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('manage-asset-categories'), 403);

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'parent_id' => 'nullable|exists:asset_categories,id',
            'is_active' => 'nullable|boolean',
        ]);

        $category = AssetCategory::create($data);
        \App\Services\AssetNotifier::fireCategory('category_created', $category, __('Asset Category Created'), 'The asset category ' . $category->name . ' was created by ' . auth()->user()->name . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Asset Category', 'action' => 'created']));
    }

    public function update(Request $request, AssetCategory $asset_category)
    {
        abort_unless(auth()->user()->can('manage-asset-categories'), 403);

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'parent_id' => 'nullable|exists:asset_categories,id|not_in:' . $asset_category->id,
            'is_active' => 'nullable|boolean',
        ]);

        $asset_category->update($data);
        \App\Services\AssetNotifier::fireCategory('category_updated', $asset_category, __('Asset Category Edited'), 'The asset category ' . $asset_category->name . ' was edited by ' . auth()->user()->name . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Asset Category', 'action' => 'updated']));
    }

    public function destroy(AssetCategory $asset_category)
    {
        abort_unless(auth()->user()->can('manage-asset-categories'), 403);

        if ($asset_category->children()->exists() || $asset_category->assets()->exists()) {
            return back()->with('error', __('The record can not be deleted.'));
        }

        $asset_category->delete();
        \App\Services\AssetNotifier::fireCategory('category_deleted', $asset_category, __('Asset Category Deleted'), 'The asset category ' . $asset_category->name . ' was deleted by ' . auth()->user()->name . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Asset Category', 'action' => 'deleted']));
    }
}
