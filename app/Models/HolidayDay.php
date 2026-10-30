<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * One holiday of one year (Christmas Day 2026). The WIMS template is the default wording; the Super Admin's own wording is kept on the row until the greetings go
 * out, after which the wording is locked so the record shows what was really sent. A holiday that falls on a Saturday or Sunday does not count unless it is included.
 */
class HolidayDay extends Model
{
    public static $tracksDeletes = false;

    protected $table = 'holiday_days';

    protected $fillable = ['account_id', 'year', 'key', 'name', 'theme', 'kind', 'date', 'date_confirmed', 'is_enabled', 'force_include', 'email_subject', 'email_headline', 'email_body', 'sms_body',
        'excluded', 'reminder_sent_at', 'send_started_at', 'sent_by_user_id', 'edited_by_user_id', 'edited_at', 'note', 'created_by_user_id', 'auto_plan', 'auto_attempted_at'];

    public $casts = ['date' => 'date:Y-m-d', 'date_confirmed' => 'boolean', 'is_enabled' => 'boolean', 'force_include' => 'boolean', 'excluded' => 'array',
        'auto_plan' => 'array', 'auto_attempted_at' => 'datetime', 'reminder_sent_at' => 'datetime', 'send_started_at' => 'datetime', 'edited_at' => 'datetime', 'year' => 'integer'];

    public const KINDS = ['public' => 'Public holiday', 'religious' => 'Religious holiday', 'observance' => 'Observance', 'custom' => 'Company holiday or greeting'];

    public function deliveries()
    {
        return $this->hasMany(HolidayDelivery::class, 'holiday_id');
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function isWeekend(): bool
    {
        return Carbon::parse($this->date)->isWeekend();
    }

    /** Whether the module acts on this holiday: switched on, and not on a Saturday or Sunday (unless a Super Admin included it, or the setting lets weekends count). */
    public function counts(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        return ! $this->isWeekend() || $this->force_include || ! \App\Services\HolidayGreetings\HolidaySettings::skipWeekends();
    }

    /** The moment the reminder is due: the set number of hours before the holiday begins (00:00 in the WIMS timezone). */
    public function reminderDueAt(): Carbon
    {
        return Carbon::parse($this->date->format('Y-m-d'), config('app.timezone'))->startOfDay()->subHours(\App\Services\HolidayGreetings\HolidaySettings::hoursBefore());
    }

    public function startsAt(): Carbon
    {
        return Carbon::parse($this->date->format('Y-m-d'), config('app.timezone'))->startOfDay();
    }

    /**
     * What automatic sending does for this holiday: the holiday's own plan where the Super Admin set one, the Settings default otherwise.
     *
     * @return array{enabled:bool,minutes:int,bundle_id:?int,own:bool}
     */
    public function autoPlan(): array
    {
        $own = is_array($this->auto_plan) && array_key_exists('enabled', $this->auto_plan);
        $src = $own ? $this->auto_plan : \App\Services\HolidayGreetings\HolidaySettings::autoDefaults();

        return ['enabled' => (bool) $src['enabled'], 'minutes' => max(0, min(\App\Services\HolidayGreetings\HolidaySettings::AUTO_MAX_MINUTES, (int) $src['minutes'])), 'bundle_id' => ! empty($src['bundle_id']) ? (int) $src['bundle_id'] : null, 'own' => $own];
    }

    /** The moment the greetings send themselves: the plan's minutes before the holiday begins (00:00 in the WIMS timezone). */
    public function autoSendAt(): Carbon
    {
        return $this->startsAt()->copy()->subMinutes($this->autoPlan()['minutes']);
    }

    /** The holiday day is over: its greetings can still be read, but no new greeting can be sent or scheduled. */
    public function isPast(): bool
    {
        return $this->startsAt()->lt(now(config('app.timezone'))->startOfDay());
    }

    public function locked(): bool
    {
        return $this->send_started_at !== null;
    }

    public function excludedIds(string $channel): array
    {
        return array_map('intval', (array) (($this->excluded ?? [])[$channel] ?? []));
    }

    /** not_sent | sending | sent | partly (some failed) — worked out from the delivery rows. */
    public function sendState(): string
    {
        if (! $this->send_started_at) {
            return 'not_sent';
        }
        $c = $this->deliveries()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        if (($c['Queued'] ?? 0) + ($c['Sending'] ?? 0) > 0) {
            return 'sending';
        }
        if (($c['Failed'] ?? 0) > 0) {
            return 'partly';
        }

        return $c->sum() > 0 ? 'sent' : 'not_sent';
    }
}
