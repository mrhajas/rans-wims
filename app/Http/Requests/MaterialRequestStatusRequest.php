<?php

namespace App\Http\Requests;

use App\Models\MaterialRequest;
use Illuminate\Foundation\Http\FormRequest;

class MaterialRequestStatusRequest extends FormRequest
{
    /**
     * The real, role-and-target-status-aware check lives in
     * MaterialRequestPolicy::changeStatus() (called explicitly by
     * MaterialRequestController::updateStatus()) — this layer just confirms
     * the request is authenticated, so Project Manager/Store Manager users
     * aren't rejected here before the controller can apply that finer-grained
     * check.
     */
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'status' => 'required|in:' . implode(',', MaterialRequest::statuses()),
            'remarks' => 'nullable|string',
            'confirm_undelivered' => 'nullable|boolean',
        ];
    }
}
