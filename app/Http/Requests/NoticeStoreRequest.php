<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NoticeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->can('create-notices');
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'send_to_all' => 'nullable|boolean',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'integer|exists:roles,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sendToAll = (bool) $this->boolean('send_to_all');
            $hasRoles = count((array) $this->input('role_ids', [])) > 0;
            $hasUsers = count((array) $this->input('user_ids', [])) > 0;

            if (! $sendToAll && ! $hasRoles && ! $hasUsers) {
                $validator->errors()->add('role_ids', __('Please choose at least one recipient role or user.'));
            }
        });
    }
}
