<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkTransportRecordPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization is handled explicitly in TransportRecordController::markPaid()
    }

    public function rules(): array
    {
        /** @var \App\Models\TransportRecord $record */
        $record = $this->route('transport_record');
        $alreadyPaid = $record ? (float) $record->paid_amount : 0;
        $amount = $record ? (float) $record->amount : 0;
        $remaining = max(0, $amount - $alreadyPaid);

        return [
            'paid_amount' => ['required', 'numeric', 'min:0.01', 'max:' . $remaining],
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:190',
            'payment_reference' => 'nullable|string|max:190',
            'payment_notes' => 'nullable|string',
        ];
    }
}
