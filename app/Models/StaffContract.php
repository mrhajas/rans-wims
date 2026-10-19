<?php

namespace App\Models;

use App\Traits\HasAttachments;
use Carbon\CarbonImmutable;

/** A staff member's employment contract (attached documents, start and end date, renewals). */
class StaffContract extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public const TYPES = ['Permanent', 'Fixed term', 'Probation', 'Casual', 'Internship', 'Consultancy'];

    public const STATUS_ACTIVE = 'Active';

    public const STATUS_RENEWED = 'Renewed';

    public const STATUS_ENDED = 'Ended';

    /** Days before the end date from which a contract counts as "expiring soon", and as urgent. */
    public const SOON_DAYS = 60;

    public const URGENT_DAYS = 14;

    protected $fillable = [
        'contract_number', 'staff_id', 'type', 'position', 'start_date', 'end_date', 'signed_date', 'basic_salary',
        'probation_months', 'notice_period_days', 'status', 'renewed_from_id', 'notes', 'last_reminder_days', 'created_by_user_id',
        'stopped_reason_type', 'stopped_reason', 'stopped_on', 'stopped_by_user_id',
    ];

    public $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'signed_date' => 'date:Y-m-d',
        'stopped_on' => 'date:Y-m-d',
        'basic_salary' => 'decimal:2',
        'extra_attributes' => 'array',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function renewedFrom()
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    public function scopeCurrent($q)
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    /** Days from today to the end date (negative once it has passed), or null for an open-ended contract. */
    public function daysLeft(?CarbonImmutable $today = null): ?int
    {
        if (! $this->end_date) {
            return null;
        }
        $today ??= CarbonImmutable::today();

        return (int) $today->startOfDay()->diffInDays(CarbonImmutable::parse($this->end_date)->startOfDay(), false);
    }

    /** open | ok | soon | urgent | expired | ended | renewed: what the staff list and the calendar colour by. */
    public function state(?CarbonImmutable $today = null): string
    {
        if ($this->status === self::STATUS_RENEWED) {
            return 'renewed';
        }
        if ($this->status === self::STATUS_ENDED) {
            return 'ended';
        }
        $left = $this->daysLeft($today);
        if ($left === null) {
            return 'open';
        }

        return $left < 0 ? 'expired' : ($left <= self::URGENT_DAYS ? 'urgent' : ($left <= self::SOON_DAYS ? 'soon' : 'ok'));
    }

    public static function nextNumber(): string
    {
        $prefixLength = strlen('CT-') + 1;
        $max = (int) \Illuminate\Support\Facades\DB::table('staff_contracts')
            ->selectRaw("MAX(CAST(SUBSTRING(contract_number, {$prefixLength}) AS UNSIGNED)) as m")
            ->where('contract_number', 'like', 'CT-%')->value('m');

        return 'CT-'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /** The shape the pages, the print sheet and the calendar all use. */
    public function present(bool $withFiles = false): array
    {
        $state = $this->state();
        $row = [
            'id' => $this->id, 'staff_id' => $this->staff_id, 'contract_number' => $this->contract_number, 'type' => $this->type, 'position' => $this->position,
            'start_date' => optional($this->start_date)->format('Y-m-d'), 'end_date' => optional($this->end_date)->format('Y-m-d'), 'signed_date' => optional($this->signed_date)->format('Y-m-d'),
            'basic_salary' => $this->basic_salary === null ? null : (float) $this->basic_salary, 'probation_months' => $this->probation_months, 'notice_period_days' => $this->notice_period_days,
            'status' => $this->status, 'state' => $state, 'days_left' => $this->status === self::STATUS_ACTIVE ? $this->daysLeft() : null, 'notes' => $this->notes,
            'stopped_reason_type' => $this->stopped_reason_type, 'stopped_reason' => $this->stopped_reason, 'stopped_on' => optional($this->stopped_on)->format('Y-m-d'),
            'renewed_from_id' => $this->renewed_from_id, 'renewed_from_number' => $this->renewed_from_id ? optional($this->renewedFrom)->contract_number : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
        if ($withFiles) {
            $this->loadMissing('attachments');
            $row['attachments'] = $this->attachments->map(fn ($a) => [
                'id' => $a->id, 'group' => $a->group, 'name' => $a->title ?: $a->filename, 'is_image' => str_starts_with((string) $a->filetype, 'image/'), 'is_pdf' => str_contains((string) $a->filetype, 'pdf'),
                'preview_url' => route('staffs.contracts.attachments.download', [$this->staff_id, $this->id, $a->id]).'?disposition=inline',
                'download_url' => route('staffs.contracts.attachments.download', [$this->staff_id, $this->id, $a->id]),
                'delete_url' => route('staffs.contracts.attachments.destroy', [$this->staff_id, $this->id, $a->id]),
            ])->values()->all();
        }

        return $row;
    }
}
