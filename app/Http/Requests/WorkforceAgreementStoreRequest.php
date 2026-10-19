<?php

namespace App\Http\Requests;

use App\Models\WorkforceAgreement;
use App\Support\GuardsUniqueOpeningBalance;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class WorkforceAgreementStoreRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check() && auth()->user()->can('create', WorkforceAgreement::class);
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            GuardsUniqueOpeningBalance::check($this->all(), $validator);
        });
    }

    public function rules()
    {
        return [
            'warehouse_id' => 'required|exists:warehouses,id',
            'payee_type' => 'required|in:' . WorkforceAgreement::PAYEE_TYPE_STAFF . ',' . WorkforceAgreement::PAYEE_TYPE_EXTERNAL,
            'staff_id' => 'required_if:payee_type,' . WorkforceAgreement::PAYEE_TYPE_STAFF . '|nullable|exists:staffs,id',
            'contact_id' => 'required_if:payee_type,' . WorkforceAgreement::PAYEE_TYPE_EXTERNAL . '|nullable|exists:contacts,id',
            'type_of_work' => 'nullable|string|max:255',
            'scope_of_work' => 'nullable|string',
            // A worker can be recorded with just a prior Opening Balance and
            // no new Agreed Amount yet — only required when there is no
            // opening balance to fall back on, so at least one of the two
            // is always present.
            'agreed_amount' => 'nullable|numeric|min:0|required_without:opening_balance',
            'opening_balance' => 'nullable|numeric|min:0.01|required_without:agreed_amount',
            'agreement_reference' => 'nullable|string|max:255',
            'agreement_date' => 'required|date',
            'notes' => 'nullable|string',
            'attachments' => 'nullable',
            'attachments.*' => 'mimes:pdf,doc,docx,jpg,jpeg,png',
        ];
    }
}
