<?php

namespace App\Http\Controllers;

use App\Models\CaseCategory;
use App\Models\CasePriority;
use App\Models\CaseTicket;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Covers three of the module's spec permissions at once — "Manage Case
 * Categories", "Manage Case Priorities", "Manage Case Settings" — as one
 * settings hub rather than three separate concepts, since there's nothing
 * else in the spec meant by "Case Settings" beyond these two lookup lists.
 * Who gets notified is no longer set here: it lives on the Case Tracking
 * workflow page (route case-tracking.workflow, Super Admin only).
 */
class CaseSettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize('manageSettings', CaseTicket::class);

        return Inertia::render('CaseTracking/Settings', [
            'categories' => CaseCategory::ordered()->get(),
            'priorities' => CasePriority::ordered()->get(),
            'canOpenWorkflow' => $request->user()->hasRole('Super Admin'),
        ]);
    }

    public function storeCategory(Request $request)
    {
        $this->authorize('manageSettings', CaseTicket::class);
        $data = $request->validate(['name' => 'required|string|max:100|unique:case_categories,name']);

        $category = CaseCategory::create($data + ['sort_order' => (CaseCategory::max('sort_order') ?? 0) + 1]);
        log_activity(__('Case category ":name" created.', ['name' => $category->name]), [], $category, 'Case Tracking');

        \App\Services\CaseWorkflowNotifier::settings('Case category "' . $category->name . '" was added by ' . $request->user()->name . '.', $request->user());

        return back()->with('message', __('Category added.'));
    }

    public function updateCategory(Request $request, CaseCategory $case_category)
    {
        $this->authorize('manageSettings', CaseTicket::class);
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:case_categories,name,' . $case_category->id,
            'is_active' => 'required|boolean',
        ]);

        $case_category->update($data);
        log_activity(__('Case category ":name" updated.', ['name' => $case_category->name]), [], $case_category, 'Case Tracking');

        \App\Services\CaseWorkflowNotifier::settings('Case category "' . $case_category->name . '" was updated by ' . $request->user()->name . '.', $request->user());

        return back()->with('message', __('Category updated.'));
    }

    public function destroyCategory(Request $request, CaseCategory $case_category)
    {
        $this->authorize('manageSettings', CaseTicket::class);
        abort_if($case_category->cases()->exists(), 422, __('This category is used by existing cases and cannot be deleted, deactivate it instead.'));

        $case_category->delete();
        log_activity(__('Case category ":name" deleted.', ['name' => $case_category->name]), [], $case_category, 'Case Tracking');

        \App\Services\CaseWorkflowNotifier::settings('Case category "' . $case_category->name . '" was deleted by ' . $request->user()->name . '.', $request->user());

        return back()->with('message', __('Category deleted.'));
    }

    public function storePriority(Request $request)
    {
        $this->authorize('manageSettings', CaseTicket::class);
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:case_priorities,name',
            'level' => 'required|integer|min:1|max:10',
            'color' => 'required|string|max:20',
        ]);

        $priority = CasePriority::create($data);
        log_activity(__('Case priority ":name" created.', ['name' => $priority->name]), [], $priority, 'Case Tracking');

        \App\Services\CaseWorkflowNotifier::settings('Case priority "' . $priority->name . '" was added by ' . $request->user()->name . '.', $request->user());

        return back()->with('message', __('Priority added.'));
    }

    public function updatePriority(Request $request, CasePriority $case_priority)
    {
        $this->authorize('manageSettings', CaseTicket::class);
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:case_priorities,name,' . $case_priority->id,
            'level' => 'required|integer|min:1|max:10',
            'color' => 'required|string|max:20',
            'is_active' => 'required|boolean',
        ]);

        $case_priority->update($data);
        log_activity(__('Case priority ":name" updated.', ['name' => $case_priority->name]), [], $case_priority, 'Case Tracking');

        \App\Services\CaseWorkflowNotifier::settings('Case priority "' . $case_priority->name . '" was updated by ' . $request->user()->name . '.', $request->user());

        return back()->with('message', __('Priority updated.'));
    }

    public function destroyPriority(Request $request, CasePriority $case_priority)
    {
        $this->authorize('manageSettings', CaseTicket::class);
        abort_if($case_priority->cases()->exists(), 422, __('This priority is used by existing cases and cannot be deleted, deactivate it instead.'));

        $case_priority->delete();
        log_activity(__('Case priority ":name" deleted.', ['name' => $case_priority->name]), [], $case_priority, 'Case Tracking');

        \App\Services\CaseWorkflowNotifier::settings('Case priority "' . $case_priority->name . '" was deleted by ' . $request->user()->name . '.', $request->user());

        return back()->with('message', __('Priority deleted.'));
    }
}
