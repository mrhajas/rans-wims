<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\StaffContract;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Staff alerts. Who is told, on which channels and with which words is set on the Staff workflow page (staffs/workflow); this class says
 * which event happened. The staff member's own login (when they have one) is counted as "the owner" of the notice.
 */
class StaffNotifier
{
    public static function fire(string $event, Staff $staff, string $title, string $message, ?User $actor = null, ?StaffContract $contract = null, array $values = [], array $details = []): void
    {
        $actor ??= auth()->user();
        try {
            $url = route('staffs.show', $staff->id) . ($contract ? '?tab=contracts' : '');
        } catch (\Throwable) {
            $url = null;
        }
        $linked = $staff->user_id ? User::find($staff->user_id) : null;
        $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '';

        WorkflowMessenger::dispatch('staff', $event, $contract ?? $staff, $actor, [
            'account_id' => (int) ($actor?->account_id ?? $linked?->account_id ?? 1),
            'title' => $title, 'message' => $message, 'url' => $url,
            'owner_user_id' => (int) ($staff->user_id ?? 0),
            'dedupe' => ($contract?->id ?? $staff->id) . '|' . $event . '|' . intdiv(time(), 20),
            'details' => array_filter([__('Staff') => $staff->name, __('Staff number') => $staff->staff_number, __('Position') => $staff->position] + $details, fn ($v) => filled($v)),
            'values' => $values + [
                'staff' => (string) $staff->name, 'staffnumber' => (string) $staff->staff_number, 'position' => (string) $staff->position, 'status' => (string) $staff->status,
                'contract' => (string) ($contract?->contract_number ?? ''), 'contracttype' => (string) ($contract?->type ?? ''),
                'starts' => $fmt($contract?->start_date), 'ends' => $fmt($contract?->end_date),
                'user' => (string) ($linked?->name ?? ''),
            ],
        ]);
    }
}
