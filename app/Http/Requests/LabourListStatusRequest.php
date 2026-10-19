<?php

namespace App\Http\Requests;

use App\Models\LabourList;
use Illuminate\Foundation\Http\FormRequest;

class LabourListStatusRequest extends FormRequest
{
    /**
     * The real, role-and-target-status-aware check lives in
     * LabourPolicy::changeStatus() (called explicitly by
     * LabourController::updateStatus()) — this layer just confirms the
     * request is authenticated.
     */
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'status' => 'required|in:' . implode(',', LabourList::statuses()),
            'remarks' => 'nullable|string',
            // Only meaningful on the Cleared → Approved transition — Super
            // Admin optionally revising a Site Engineer's requested amount.
            // approved_amount left null/absent means "unchanged, no revision".
            'items' => 'nullable|array',
            'items.*.id' => 'required_with:items|integer|exists:labour_list_items,id',
            'items.*.approved_amount' => 'nullable|numeric|min:0.01',
            'items.*.revision_reason' => 'nullable|string|required_with:items.*.approved_amount',
        ];
    }
}
