<?php

namespace App\Services\HolidayGreetings;

use App\Mail\HolidayReminderMail;
use App\Models\HolidayDay;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\WimsConnect\GatewayManager;
use App\Services\WimsConnect\OutboundLog;
use App\Services\WimsConnect\RecipientDirectory;
use App\Support\WimsConnect\OutboundMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Tells the Super Admins (and anyone given the reminder permission) that a holiday is coming: an email, an SMS and a bell alert, as the settings allow. */
class HolidayNotifier
{
    /** @return \Illuminate\Support\Collection<int,User> */
    public static function recipients(int $accountId)
    {
        return User::withoutGlobalScope('account')->where('account_id', $accountId)->where('is_active', true)->whereNull('disabled_at')->with('staff')->get()
            ->filter(fn (User $u) => $u->hasRole('Super Admin') || $u->hasPermissionTo('receive-holiday-reminders', 'web'))->values();
    }

    public static function whenText(HolidayDay $h): string
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $d = Carbon::parse($h->date->format('Y-m-d'), config('app.timezone'))->startOfDay();
        $days = (int) $today->diffInDays($d, false);
        $label = $d->locale('en')->translatedFormat('l, j F Y');

        return match (true) {
            $days === 0 => __('Today, :d', ['d' => $label]),
            $days === 1 => __('Tomorrow, :d', ['d' => $label]),
            default => $label,
        };
    }

    /**
     * Sends the reminder. $only (a test) replaces the recipients with exactly these addresses, writes no bell alert and does not mark the holiday as reminded.
     *
     * @param  array{email?:array,sms?:array}|null  $only
     * @return array<int,array{who:string,channel:string,ok:bool,error:?string}>
     */
    public static function remind(HolidayDay $h, ?array $only = null): array
    {
        $planned = $h->autoPlan();
        $lists = GreetingSender::lists($h, $planned['enabled'] ? $planned['bundle_id'] : null);
        $counts = $lists['counts'];
        $when = self::whenText($h);
        $hours = max(1, (int) round(Carbon::now(config('app.timezone'))->diffInHours($h->startsAt(), false)));
        $hours = $hours > 0 && $hours <= 72 ? $hours : HolidaySettings::hoursBefore();
        $url = route('holiday-greetings.holidays.show', $h->id);
        $theme = GreetingThemes::get($h->theme);
        $results = [];

        $people = [];
        if ($only !== null) {
            foreach ((array) ($only['email'] ?? []) as $e) {
                $people[] = ['name' => 'Dr Hajas', 'email' => $e, 'mobile' => null, 'user' => null];
            }
            foreach ((array) ($only['sms'] ?? []) as $m) {
                $people[] = ['name' => 'Dr Hajas', 'email' => null, 'mobile' => $m, 'user' => null];
            }
        } else {
            foreach (self::recipients($h->account_id) as $u) {
                $c = RecipientDirectory::candidates($u);
                $people[] = ['name' => $u->name, 'email' => $c['email'] ?? null, 'mobile' => $c['sms'] ?? null, 'user' => $u];
            }
        }

        $plan = $h->autoPlan();
        $autoText = $plan['enabled'] && $h->autoSendAt()->gt(now(config('app.timezone'))) ? __('They will be sent automatically on :t.', ['t' => $h->autoSendAt()->locale('en')->translatedFormat('D j M, H:i')]) : null;
        $title = __(':holiday is coming', ['holiday' => $h->name]);
        $sms = GreetingTemplates::plain(__('WIMS: :holiday is :when. The greetings are ready to review: :e emails and :s SMS. Please open Holiday Greetings in WIMS, check the message and the lists, then send.', [
            'holiday' => $h->name, 'when' => self::whenShort($h), 'e' => $counts['email'], 's' => $counts['sms']]) . ($autoText ? ' ' . $autoText : ''));

        foreach ($people as $p) {
            if ($p['email'] && ($only !== null || HolidaySettings::remindEmail())) {
                try {
                    $data = ['title' => $title, 'whenText' => $when, 'recipient' => $p['name'], 'holiday' => $h->name, 'hours' => $hours, 'emoji' => $theme['emoji'], 'icon' => $theme['icon'] ?? 'sparkle', 'palette' => $theme['palette'], 'counts' => $counts, 'url' => $url,
                        'details' => [__('Holiday') => $h->name, __('Date') => $when, __('People who will receive the email') => $counts['email'], __('People who will receive the SMS') => $counts['sms'],
                            __('Repeated addresses removed') => $counts['duplicates']] + ($autoText ? [__('Automatic sending') => $h->autoSendAt()->locale('en')->translatedFormat('D j M Y, H:i')] : [])];
                    (GreetingSender::$mailer ? Mail::mailer(GreetingSender::$mailer) : Mail::mailer())->to($p['email'], $p['name'])->send(new HolidayReminderMail($data));
                    $results[] = ['who' => $p['email'], 'channel' => 'email', 'ok' => true, 'error' => null];
                } catch (\Throwable $e) {
                    $results[] = ['who' => $p['email'], 'channel' => 'email', 'ok' => false, 'error' => Str::limit(strip_tags($e->getMessage()), 200)];
                }
            }
            if ($p['mobile'] && ($only !== null || HolidaySettings::remindSms())) {
                if (! GreetingSender::smsReady()) {
                    $results[] = ['who' => $p['mobile'], 'channel' => 'sms', 'ok' => false, 'error' => 'SMS is switched off (WIMS Connect).'];
                } else {
                    $r = app(GatewayManager::class)->send('sms', $p['mobile'], new OutboundMessage($sms));
                    try {
                        OutboundLog::record(['event_key' => 'holiday-greetings-reminder', 'channel' => 'sms', 'address' => $p['mobile'], 'message' => $sms, 'source_type' => HolidayDay::class, 'source_id' => $h->id,
                            'recipient_user_id' => $p['user']?->id, 'status' => $r->success ? 'SENT' : 'FAILED', 'sent_at' => $r->success ? now() : null, 'error_message' => $r->success ? null : Str::limit(strip_tags((string) $r->error), 300)]);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                    $results[] = ['who' => $p['mobile'], 'channel' => 'sms', 'ok' => $r->success, 'error' => $r->success ? null : $r->error];
                }
            }
            if ($only === null && $p['user'] && HolidaySettings::remindApp()) {
                $p['user']->notify(new AppNotification(['module' => 'holiday_greetings', 'type' => 'holiday_reminder', 'title' => $title, 'message' => __(':holiday is :when. The greetings are ready for you to review and send.', ['holiday' => $h->name, 'when' => Str::lower($when)]),
                    'url' => $url, 'created_at' => now()->toDateTimeString()]));
            }
        }

        if ($only === null) {
            $h->forceFill(['reminder_sent_at' => now()])->save();
            log_activity('Holiday reminder sent', ['holiday' => $h->name, 'date' => $h->date->format('Y-m-d'), 'results' => collect($results)->map(fn ($r) => $r['channel'] . ' ' . ($r['ok'] ? 'ok' : 'failed'))->countBy()->all()], $h, 'Holiday Greetings');
        }

        return $results;
    }

    /** Tells the Super Admins (bell alert) that the greetings of a holiday went out by themselves. */
    public static function autoSent(HolidayDay $h, array $queued, ?string $bundleName): void
    {
        if (! HolidaySettings::remindApp()) {
            return;
        }
        $url = route('holiday-greetings.holidays.show', $h->id);
        foreach (self::recipients((int) $h->account_id) as $u) {
            $u->notify(new AppNotification(['module' => 'holiday_greetings', 'type' => 'holiday_auto_sent', 'title' => __(':holiday greetings were sent automatically', ['holiday' => $h->name]),
                'message' => __(':e emails and :s SMS went to :b.', ['e' => $queued['email'], 's' => $queued['sms'], 'b' => $bundleName ?: __('the whole greeting list')]), 'url' => $url, 'created_at' => now()->toDateTimeString()]));
        }
    }

    private static function whenShort(HolidayDay $h): string
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $d = Carbon::parse($h->date->format('Y-m-d'), config('app.timezone'))->startOfDay();
        $short = $d->locale('en')->translatedFormat('D j M');

        return match ((int) $today->diffInDays($d, false)) {
            0 => 'today (' . $short . ')',
            1 => 'tomorrow (' . $short . ')',
            default => 'on ' . $short,
        };
    }
}
