<?php

namespace App\Services\AiTools;

use App\Models\HolidayDay;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\HolidayGreetings\GreetingSender;
use Carbon\Carbon;

/**
 * ARIS answers about Holiday Greetings: which holiday is next and when the reminder is due, the holidays of the year, and whether the greetings of a holiday were sent and how many
 * went through. Every handler checks the Holiday Greetings permission and the Global Feature switch; no email address, mobile number or message text is ever returned.
 */
class HolidayGreetingsTool
{
    private function denied(User $user): ?array
    {
        if (! FeatureManager::canAccess('holiday_greetings', $user) || ! $user->can('read-holiday-greetings')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to Holiday Greetings.'];
        }

        return null;
    }

    private function today(): Carbon
    {
        return now(config('app.timezone'))->startOfDay();
    }

    public function next(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $h = HolidayDay::query()->where('is_enabled', true)->where('date', '>=', $this->today()->toDateString())->orderBy('date')->limit(20)->get()->first(fn ($x) => $x->counts());
        if (! $h) {
            return ['found' => false, 'message' => 'There is no coming holiday on the list.'];
        }
        $days = (int) $this->today()->diffInDays($h->startsAt(), false);

        return ['found' => true, 'name' => $h->name, 'date' => $h->date->locale('en')->translatedFormat('l, j F Y'), 'days' => $days, 'reminder' => $h->reminderDueAt()->locale('en')->translatedFormat('D j M, H:i'), 'reminded' => (bool) $h->reminder_sent_at, 'confirmed' => (bool) $h->date_confirmed];
    }

    public function year(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $year = (int) ($input['year'] ?? $this->today()->year);
        $rows = HolidayDay::query()->where('year', $year)->where('is_enabled', true)->orderBy('date')->get()->filter(fn ($h) => $h->counts());
        if ($rows->isEmpty()) {
            return ['found' => false, 'message' => "No holidays are on the list for {$year}."];
        }

        return ['found' => true, 'year' => $year, 'count' => $rows->count(), 'items' => $rows->map(fn ($h) => $h->name . ' (' . $h->date->locale('en')->translatedFormat('D j M') . ')')->values()->all()];
    }

    public function status(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $name = trim((string) ($input['holiday_name'] ?? ''));
        if ($name === '') {
            return ['found' => false, 'message' => 'Tell me which holiday.'];
        }
        $year = (int) ($input['year'] ?? $this->today()->year);
        $h = HolidayDay::query()->where('year', $year)->where('name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $name) . '%')->orderBy('date')->first();
        if (! $h) {
            return ['found' => false, 'message' => "I could not find a holiday matching \"{$name}\" in {$year}."];
        }
        $s = GreetingSender::summary($h);

        return ['found' => true, 'name' => $h->name, 'date' => $h->date->locale('en')->translatedFormat('D j M Y'), 'state' => $s['state'], 'emailSent' => $s['email']['sent'], 'emailTotal' => $s['email']['total'], 'smsSent' => $s['sms']['sent'], 'smsTotal' => $s['sms']['total'], 'failed' => $s['failed'], 'pending' => $s['pending']];
    }
}
