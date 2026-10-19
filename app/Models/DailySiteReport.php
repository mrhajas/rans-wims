<?php

namespace App\Models;

use App\Traits\HasAttachments;

class DailySiteReport extends Model
{
    use HasAttachments;

    public static $hasReference = false;

    public static $hasUser = false;

    public static $hasAccount = false;

    public static $tracksDeletes = true;

    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_VIEWED = 'Viewed';
    public const STATUS_DOWNLOADED = 'Downloaded';
    public const STATUS_ARCHIVED = 'Archived';

    public const PRIORITY_LOW = 'Low';
    public const PRIORITY_NORMAL = 'Normal';
    public const PRIORITY_HIGH = 'High';
    public const PRIORITY_URGENT = 'Urgent';

    public const TYPE_DAILY_SITE_REPORT = 'Daily Site Report';
    public const TYPE_PROGRESS_REPORT = 'Progress Report';
    public const TYPE_STOCK_REPORT = 'Stock Report';
    public const TYPE_MATERIAL_REQUEST = 'Material Request';
    public const TYPE_MATERIAL_RETURN_REPORT = 'Material Return Report';
    public const TYPE_EQUIPMENT_REPORT = 'Equipment Report';
    public const TYPE_PLANT_MACHINERY_REPORT = 'Plant & Machinery Report';
    public const TYPE_WORKFORCE_REPORT = 'Workforce Report';
    public const TYPE_QUALITY_INSPECTION_REPORT = 'Quality Inspection Report';
    public const TYPE_SAFETY_REPORT = 'Safety Report';
    public const TYPE_INCIDENT_REPORT = 'Incident Report';
    public const TYPE_DELAY_REPORT = 'Delay Report';
    public const TYPE_CONCRETE_POUR_REPORT = 'Concrete Pour Report';
    public const TYPE_SURVEY_REPORT = 'Survey Report';
    public const TYPE_SITE_VISIT_REPORT = 'Site Visit Report';
    public const TYPE_GENERAL_REPORT = 'General Report';

    public const SCHEDULE_ON_TIME = 'OnTime';
    public const SCHEDULE_OVERDUE = 'Overdue';

    protected $appends = ['schedule_status', 'submitted_at'];

    protected $fillable = [
        'report_number',
        'warehouse_id',
        'report_type',
        'priority',
        'report_date',
        'remarks',
        'submitted_by',
        'status',
        'viewed_by',
        'viewed_at',
        'downloaded_by',
        'downloaded_at',
        'archived_by',
        'archived_at',
        'auto_archived',
        'extra_attributes',
    ];

    public $casts = [
        'report_date' => 'date:Y-m-d',
        'viewed_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'archived_at' => 'datetime',
        'auto_archived' => 'boolean',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_SUBMITTED,
            self::STATUS_VIEWED,
            self::STATUS_DOWNLOADED,
            self::STATUS_ARCHIVED,
        ];
    }

    /**
     * The true local submission time.
     */
    public function submittedAtLocal(): ?\Illuminate\Support\Carbon
    {
        return $this->created_at;
    }

    public function getSubmittedAtAttribute(): ?string
    {
        return $this->submittedAtLocal()?->format('Y-m-d H:i:s');
    }

    /**
     * Whether the report was submitted the same calendar day it's for.
     */
    public function getScheduleStatusAttribute(): string
    {
        $submittedLocal = $this->submittedAtLocal();

        if (! $this->report_date || ! $submittedLocal) {
            return self::SCHEDULE_ON_TIME;
        }

        return $submittedLocal->toDateString() > $this->report_date->toDateString()
            ? self::SCHEDULE_OVERDUE
            : self::SCHEDULE_ON_TIME;
    }

    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_NORMAL,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }

    public static function reportTypes(): array
    {
        return [
            self::TYPE_DAILY_SITE_REPORT,
            self::TYPE_PROGRESS_REPORT,
            self::TYPE_STOCK_REPORT,
            self::TYPE_MATERIAL_REQUEST,
            self::TYPE_MATERIAL_RETURN_REPORT,
            self::TYPE_EQUIPMENT_REPORT,
            self::TYPE_PLANT_MACHINERY_REPORT,
            self::TYPE_WORKFORCE_REPORT,
            self::TYPE_QUALITY_INSPECTION_REPORT,
            self::TYPE_SAFETY_REPORT,
            self::TYPE_INCIDENT_REPORT,
            self::TYPE_DELAY_REPORT,
            self::TYPE_CONCRETE_POUR_REPORT,
            self::TYPE_SURVEY_REPORT,
            self::TYPE_SITE_VISIT_REPORT,
            self::TYPE_GENERAL_REPORT,
        ];
    }

    public function isLockedForSiteEngineer(): bool
    {
        return $this->status !== self::STATUS_SUBMITTED
            || ! empty($this->viewed_by)
            || ! empty($this->downloaded_by);
    }

    public function logs()
    {
        return $this->hasMany(DailySiteReportLog::class)->orderBy('id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function viewedBy()
    {
        return $this->belongsTo(User::class, 'viewed_by');
    }

    public function downloadedBy()
    {
        return $this->belongsTo(User::class, 'downloaded_by');
    }

    public function archivedBy()
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function scopeVisibleTo($query, User $user)
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return $query;
        }

        if ($user->hasRole(['Project Manager', 'Project Managers'])) {
            return $query->whereIn('warehouse_id', $user->warehouses()->pluck('warehouses.id'));
        }

        return $query->where('submitted_by', $user->id);
    }

    public function scopeFilter($query, array $filters)
    {
        $query = $query
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->search($s))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['submitted_by'] ?? null, fn ($q, $id) => $q->where('submitted_by', $id))
            ->when($filters['report_type'] ?? null, fn ($q, $t) => $q->where('report_type', $t))
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->whereDate('report_date', '>=', $d))
            ->when($filters['end_date'] ?? null, fn ($q, $d) => $q->whereDate('report_date', '<=', $d));

        return $this->applySort($query, $filters);
    }

    protected function applySort($query, array $filters)
    {
        $sort = $filters['sort'] ?? null;
        $direction = strtolower($filters['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $directColumns = ['report_number', 'report_type', 'report_date', 'status', 'created_at'];
        if (in_array($sort, $directColumns, true)) {
            return $query->orderBy('daily_site_reports.' . $sort, $direction);
        }

        if ($sort === 'warehouse_name') {
            return $query->select('daily_site_reports.*')
                ->leftJoin('warehouses', 'warehouses.id', '=', 'daily_site_reports.warehouse_id')
                ->orderBy('warehouses.name', $direction);
        }

        if ($sort === 'submitted_by_name') {
            return $query->select('daily_site_reports.*')
                ->leftJoin('users as sr_submitters', 'sr_submitters.id', '=', 'daily_site_reports.submitted_by')
                ->orderBy('sr_submitters.name', $direction);
        }

        if ($sort === 'viewed_by_name') {
            return $query->select('daily_site_reports.*')
                ->leftJoin('users as sr_viewers', 'sr_viewers.id', '=', 'daily_site_reports.viewed_by')
                ->orderBy('sr_viewers.name', $direction);
        }

        return $query;
    }

    public function scopeSearch($query, $search)
    {
        $localCreatedAt = 'daily_site_reports.created_at';

        return $query->where(function ($q) use ($search, $localCreatedAt) {
            $q->where('report_number', 'like', "%{$search}%")
                ->orWhere('report_type', 'like', "%{$search}%")
                ->orWhere('status', 'like', "%{$search}%")
                ->orWhere('remarks', 'like', "%{$search}%")
                ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$search}%"))
                ->orWhereHas('submittedBy', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                ->orWhereHas('viewedBy', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                ->orWhereRaw("DATE_FORMAT(report_date, '%Y-%m-%d') like ?", ["%{$search}%"])
                ->orWhereRaw("DATE_FORMAT(report_date, '%d/%m/%Y') like ?", ["%{$search}%"])
                ->orWhereRaw("DATE_FORMAT(report_date, '%b %e, %Y') like ?", ["%{$search}%"])
                ->orWhereRaw("DATE_FORMAT(report_date, '%M %e, %Y') like ?", ["%{$search}%"])
                ->orWhereRaw("DATE_FORMAT({$localCreatedAt}, '%Y-%m-%d') like ?", ["%{$search}%"])
                ->orWhereRaw("DATE_FORMAT({$localCreatedAt}, '%d/%m/%Y') like ?", ["%{$search}%"])
                ->orWhereRaw("DATE_FORMAT({$localCreatedAt}, '%b %e, %Y') like ?", ["%{$search}%"])
                ->orWhereRaw("DATE_FORMAT({$localCreatedAt}, '%M %e, %Y') like ?", ["%{$search}%"]);
        });
    }
}
