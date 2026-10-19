<?php

namespace App\Http\Requests;

use App\Models\WorkPackage;
use Illuminate\Foundation\Http\FormRequest;

class WorkPackageDailyUpdateStoreRequest extends FormRequest
{
    public function authorize()
    {
        $workPackage = $this->route('work_package');

        return $workPackage instanceof WorkPackage && $this->user()?->can('submitUpdate', $workPackage);
    }

    public function rules()
    {
        return [
            'activities_completed' => 'nullable|string',
            'progress_percent' => 'required|numeric|min:0|max:100',
            'remarks' => 'nullable|string',
            'weather' => 'nullable|string|max:100',
            'manpower' => 'nullable|array',
            'safety_observations' => 'nullable|string',
            'inspection_results' => 'nullable|string',
            'photos' => 'nullable|array',
            'photos.*' => 'image|max:10240',
            'videos' => 'nullable|array',
            'videos.*' => 'mimes:mp4,mov,avi|max:51200',
            'documents' => 'nullable|array',
            'documents.*' => 'mimes:pdf,doc,docx,xlsx,dwg|max:20480',
        ];
    }
}
