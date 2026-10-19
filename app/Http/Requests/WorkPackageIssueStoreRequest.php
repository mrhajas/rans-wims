<?php

namespace App\Http\Requests;

use App\Models\WorkPackage;
use App\Models\WorkPackageIssue;
use Illuminate\Foundation\Http\FormRequest;

class WorkPackageIssueStoreRequest extends FormRequest
{
    public function authorize()
    {
        $workPackage = $this->route('work_package');

        return $workPackage instanceof WorkPackage && $this->user()?->can('create', [WorkPackageIssue::class, $workPackage]);
    }

    public function rules()
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'required|in:' . implode(',', WorkPackageIssue::priorities()),
            'assigned_user_id' => 'nullable|exists:users,id',
            'deadline' => 'nullable|date',
            'photos' => 'nullable|array',
            'photos.*' => 'image|max:10240',
            'attachments' => 'nullable|array',
            'attachments.*' => 'mimes:pdf,doc,docx,xlsx,png,jpg,jpeg|max:20480',
        ];
    }
}
