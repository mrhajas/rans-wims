<?php

namespace App\Http\Requests;

use App\Models\CaseTicket;
use Illuminate\Foundation\Http\FormRequest;

class CaseTicketStoreRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check() && auth()->user()->can('create', CaseTicket::class);
    }

    public function rules()
    {
        return [
            'subject' => 'required|string|max:190',
            'description' => 'required|string|max:5000',
            'case_category_id' => 'nullable|exists:case_categories,id',
            'case_priority_id' => 'nullable|exists:case_priorities,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'module_reference' => 'required|string|in:' . implode(',', CaseTicket::wimsModules()),
            'external_url' => 'nullable|url|max:2048',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240|mimes:' . env('ATTACHMENT_EXTS', 'jpg,jpeg,png,pdf,doc,docx,xlsx'),
            // true = also submit immediately after creating (Draft -> Submitted in one step)
            'should_submit' => 'nullable|boolean',
        ];
    }
}
