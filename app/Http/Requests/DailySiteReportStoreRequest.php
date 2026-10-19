<?php

namespace App\Http\Requests;

use App\Models\DailySiteReport;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DailySiteReportStoreRequest extends FormRequest
{
    public function authorize()
    {
        if (! auth()->check()) {
            return false;
        }

        return auth()->user()->can('create', DailySiteReport::class);
    }

    public function rules()
    {
        $user = auth()->user();
        $isSiteEngineer = $user->hasRole(User::SITE_ENGINEER_ROLES)
            && ! $user->hasRole(['Super Admin', 'Admin']);

        $reportDateRules = ['required', 'date', 'before_or_equal:' . now()->format('Y-m-d')];
        if ($this->input('report_type') === DailySiteReport::TYPE_DAILY_SITE_REPORT) {
            $reportDateRules[] = 'after_or_equal:' . now()->subDays(3)->format('Y-m-d');
        }

        return [
            'warehouse_id' => 'required|exists:warehouses,id',
            'submitted_by' => $isSiteEngineer ? 'nullable' : 'required|exists:users,id',
            'report_date' => $reportDateRules,
            'report_type' => 'required|in:' . implode(',', DailySiteReport::reportTypes()),
            'priority' => 'required|in:' . implode(',', DailySiteReport::priorities()),
            'remarks' => 'nullable|string',
            'attachments' => 'nullable|array|max:' . config('daily_site_reports.max_attachments'),
            'attachments.*' => 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp|max:' . config('daily_site_reports.max_attachment_size_kb'),
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $user = auth()->user();
            if (! $user) {
                return;
            }

            if ($user->hasRole(['Super Admin', 'Admin'])) {
                return;
            }

            $warehouseId = (int) $this->input('warehouse_id');
            if (! $user->manages($warehouseId)) {
                $validator->errors()->add('warehouse_id', __('You are not assigned to this project/warehouse.'));
            }
        });

        $validator->after(function ($validator) {
            $user = auth()->user();
            if (! $user || ! $user->hasRole(['Super Admin', 'Admin'])) {
                return;
            }

            $submittedBy = $this->input('submitted_by');
            if (! $submittedBy) {
                return;
            }

            $isSiteEngineer = User::whereKey($submittedBy)->whereHas('roles', function ($query) {
                $query->whereIn('name', User::SITE_ENGINEER_ROLES);
            })->exists();

            if (! $isSiteEngineer) {
                $validator->errors()->add('submitted_by', __('The selected user is not a Site Engineer.'));
            }
        });
    }
}
