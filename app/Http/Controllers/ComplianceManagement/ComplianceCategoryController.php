<?php

namespace App\Http\Controllers\ComplianceManagement;

use App\Http\Controllers\Controller;
use App\Models\ComplianceCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComplianceCategoryController extends Controller
{
    public const GROUPS = [
        'Company/Business', 'Government/Statutory', 'Construction', 'Financial', 'Insurance',
        'Vehicles', 'Assets/Equipment', 'People', 'Contracts', 'Project', 'Other',
    ];

    // See the enforce_manage_compliance_categories_permission migration — these actions
    // used to ride on the shared compliance-records read/create/update/delete abilities.
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('manage-compliance-categories'), 403);
    }

    public function index()
    {
        $this->authorizeManage();

        return Inertia::render('Compliance/Categories', [
            'categories' => ComplianceCategory::active()->orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'groups' => self::GROUPS,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'group' => 'required|in:' . implode(',', self::GROUPS),
            'is_active' => 'nullable|boolean',
        ]);

        $category = ComplianceCategory::create($data);
        \App\Services\ComplianceNotifier::fireCategory('category_created', $category, __('Compliance Category Created'), 'The compliance category ' . $category->name . ' was created by ' . auth()->user()->name . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Compliance Category', 'action' => 'created']));
    }

    public function update(Request $request, ComplianceCategory $compliance_category)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'group' => 'required|in:' . implode(',', self::GROUPS),
            'is_active' => 'nullable|boolean',
        ]);

        $compliance_category->update($data);
        \App\Services\ComplianceNotifier::fireCategory('category_updated', $compliance_category, __('Compliance Category Edited'), 'The compliance category ' . $compliance_category->name . ' was edited by ' . auth()->user()->name . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Compliance Category', 'action' => 'updated']));
    }

    public function destroy(ComplianceCategory $compliance_category)
    {
        $this->authorizeManage();

        if ($compliance_category->records()->exists()) {
            return back()->with('error', __('The record can not be deleted.'));
        }

        $compliance_category->delete();
        \App\Services\ComplianceNotifier::fireCategory('category_deleted', $compliance_category, __('Compliance Category Deleted'), 'The compliance category ' . $compliance_category->name . ' was deleted by ' . auth()->user()->name . '.');

        return back()->with('message', __choice('action_text', ['record' => 'Compliance Category', 'action' => 'deleted']));
    }
}
