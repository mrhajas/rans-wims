<?php

namespace App\Http\Requests;

use App\Models\DailySiteReport;
use Illuminate\Foundation\Http\FormRequest;

class DailySiteReportUpdateRequest extends FormRequest
{
    public function authorize()
    {
        if (! auth()->check()) {
            return false;
        }

        $dailySiteReport = $this->route('daily_site_report');
        if (! $dailySiteReport) {
            return false;
        }

        return auth()->user()->can('update', $dailySiteReport);
    }

    public function rules()
    {
        $reportDateRules = ['required', 'date', 'before_or_equal:' . now()->format('Y-m-d')];
        if ($this->input('report_type') === DailySiteReport::TYPE_DAILY_SITE_REPORT) {
            $reportDateRules[] = 'after_or_equal:' . now()->subDays(3)->format('Y-m-d');
        }

        return [
            'warehouse_id' => 'required|exists:warehouses,id',
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
            if (! $user || $user->hasRole(['Super Admin', 'Admin'])) {
                return;
            }

            $warehouseId = (int) $this->input('warehouse_id');
            if (! $user->manages($warehouseId)) {
                $validator->errors()->add('warehouse_id', __('You are not assigned to this project/warehouse.'));
            }
        });
    }
}
