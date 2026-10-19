<?php

namespace App\Http\Requests;

use App\Models\WorkPackageIssue;
use Illuminate\Foundation\Http\FormRequest;

class WorkPackageIssueUpdateRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()?->can('update', WorkPackageIssue::class);
    }

    public function rules()
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'sometimes|required|in:' . implode(',', WorkPackageIssue::priorities()),
            'status' => 'sometimes|required|in:' . implode(',', WorkPackageIssue::statuses()),
            'assigned_user_id' => 'nullable|exists:users,id',
            'deadline' => 'nullable|date',
            'resolution' => 'nullable|string',
        ];
    }
}
