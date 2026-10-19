<?php

namespace App\Services\SmartBrief;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting period in the WIMS timezone (APP_TIMEZONE, Africa/Dar_es_Salaam). WIMS stores its timestamps in that same local time, so the
 * boundaries are used as they are in the queries (no conversion). `to` is always the end of its last day.
 */
class Period
{
    public const KEYS = ['today', 'yesterday', 'last_7_days', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'];

    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to, public string $key, public string $label)
    {
    }

    public static function resolve(?string $key = 'today', ?string $from = null, ?string $to = null, int $maxDays = 92): self
    {
        $tz = config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $key = in_array($key, self::KEYS, true) ? $key : 'today';

        switch ($key) {
            case 'yesterday':
                $f = $now->subDay()->startOfDay(); $t = $now->subDay()->endOfDay(); $label = __('Yesterday');
                break;
            case 'last_7_days':
                $f = $now->subDays(6)->startOfDay(); $t = $now->endOfDay(); $label = __('Last 7 days');
                break;
            case 'this_week':
                $f = $now->startOfWeek()->startOfDay(); $t = $now->endOfDay(); $label = __('This week');
                break;
            case 'last_week':
                $f = $now->subWeek()->startOfWeek(); $t = $now->subWeek()->endOfWeek(); $label = __('Last week');
                break;
            case 'this_month':
                $f = $now->startOfMonth(); $t = $now->endOfDay(); $label = __('This month');
                break;
            case 'last_month':
                $f = $now->subMonthNoOverflow()->startOfMonth(); $t = $now->subMonthNoOverflow()->endOfMonth(); $label = __('Last month');
                break;
            case 'custom':
                try {
                    $f = CarbonImmutable::parse((string) $from, $tz)->startOfDay();
                    $t = CarbonImmutable::parse((string) ($to ?: $from), $tz)->endOfDay();
                } catch (\Throwable) {
                    throw new InvalidArgumentException(__('Enter a valid date range.'));
                }
                if ($t->lt($f)) {
                    [$f, $t] = [$t->startOfDay(), $f->endOfDay()];
                }
                if ($f->diffInDays($t) + 1 > $maxDays) {
                    throw new InvalidArgumentException(__('Choose a period of at most :n days.', ['n' => $maxDays]));
                }
                $label = $f->isSameDay($t) ? $f->format('d M Y') : $f->format('d M Y') . ' - ' . $t->format('d M Y');
                break;
            default:
                $f = $now->startOfDay(); $t = $now->endOfDay(); $label = __('Today');
        }

        return new self($f, $t, $key, $label);
    }

    public function isSingleDay(): bool
    {
        return $this->from->isSameDay($this->to);
    }

    public function includesNow(): bool
    {
        return CarbonImmutable::now(config('app.timezone'))->between($this->from, $this->to);
    }

    public function sql(): array
    {
        return [$this->from->format('Y-m-d H:i:s'), $this->to->format('Y-m-d H:i:s')];
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d'),
            'from_at' => $this->from->toIso8601String(), 'to_at' => $this->to->toIso8601String(), 'single_day' => $this->isSingleDay()];
    }
}
