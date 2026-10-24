<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'return_date' => 'required|date',
            'returned_by' => 'required|string|max:190',
            'received_by' => 'required|string|max:190',
            'contact_number' => 'nullable|string|max:60',
            'remarks' => 'nullable|string',

            'attachments' => 'nullable|array',
            'attachments.*' => 'mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),

            'items' => 'required|array|min:1',
            'items.*.issue_transaction_item_id' => 'required|exists:issue_transaction_items,id',
            'items.*.selected' => 'nullable|boolean',
            'items.*.returning_qty' => 'nullable|numeric|gt:0',
            'items.*.condition' => 'nullable|in:good,damaged,lost',
            'items.*.remarks' => 'nullable|string',
        ];
    }
}
