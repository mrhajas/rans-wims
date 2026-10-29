<?php

namespace App\Console\Commands;

use App\Mail\HolidayGreetingMail;
use App\Models\HolidayDay;
use App\Services\HolidayGreetings\GreetingSender;
use App\Services\HolidayGreetings\GreetingTemplates;
use App\Services\HolidayGreetings\HolidayCalendar;
use App\Services\HolidayGreetings\HolidayNotifier;
use App\Services\WimsConnect\GatewayManager;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends sample greetings to ONE address and ONE mobile number given on the command line (never to anybody in the greeting list), so the owner can see the emails and SMS.
 * The subject says SAMPLE. It sends through the real mailer and SMS gateway, ignoring the WIMS Connect switches of a local copy for this run only.
 */
class HolidayGreetingsSamples extends Command
{
    protected $signature = 'holiday-greetings:samples {--email= : the one address to send the sample emails to} {--sms= : the one mobile number to send the sample SMS to} {--mailer=smtp : mailer to use}
        {--themes=christmas,eid_fitr,new_year,independence_day,workers_day : holidays to send an email for} {--sms-themes=christmas,eid_fitr,independence_day : holidays to send an SMS for} {--no-reminder : skip the reminder sample} {--title=Dr. : title} {--name=Hajas : name}';

    protected $description = 'Send sample holiday emails and SMS to one address and one mobile number';

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));
        $sms = trim((string) $this->option('sms'));
        if ($email === '' && $sms === '') {
            $this->error('Give --email and/or --sms.');

            return self::FAILURE;
        }
        GreetingSender::$ignoreSwitches = true;
        GreetingSender::$mailer = $this->option('mailer') ?: null;
        $accountId = (int) \App\Models\Account::query()->value('id');
        $year = (int) now()->year;
        HolidayCalendar::ensureYear($accountId, $year);
        // "custom" is a made-up company holiday (not saved) so the RANS greeting for a day of the company's own can be seen
        $pick = fn (string $k) => $k === 'custom'
            ? new HolidayDay(['account_id' => $accountId, 'year' => $year, 'key' => 'custom', 'name' => 'Staff Appreciation Day', 'theme' => 'generic', 'kind' => 'custom', 'date' => now()->addDays(3)->format('Y-m-d')])
            : HolidayDay::withoutGlobalScope('account')->where('account_id', $accountId)->where('year', $year)->where('key', $k)->first();
        $title = (string) $this->option('title');
        $name = (string) $this->option('name');

        if ($email !== '') {
            foreach (array_filter(array_map('trim', explode(',', (string) $this->option('themes')))) as $key) {
                if (! ($h = $pick($key))) {
                    $this->warn("No holiday $key");
                    continue;
                }
                $tpl = GreetingTemplates::effective($h);
                $tpl['subject'] = '[SAMPLE] ' . $tpl['subject'];
                try {
                    Mail::mailer(GreetingSender::$mailer)->to($email)->send(new HolidayGreetingMail($h, $title, $name, $tpl));
                    $this->info("Email sent: {$h->name}");
                } catch (\Throwable $e) {
                    $this->error("Email failed ({$h->name}): " . $e->getMessage());
                }
            }
        }
        if ($sms !== '') {
            $gw = app(GatewayManager::class);
            foreach (array_filter(array_map('trim', explode(',', (string) $this->option('sms-themes')))) as $key) {
                if (! ($h = $pick($key))) {
                    continue;
                }
                $text = GreetingTemplates::sms($h, $title, $name);
                $r = $gw->send('sms', \App\Support\TzPhone::normalize($sms) ?: $sms, new OutboundMessage($text));
                $this->{$r->success ? 'info' : 'error'}("SMS {$h->name}: " . ($r->success ? 'sent' : $r->error) . ' (' . mb_strlen($text) . ' characters)');
            }
        }
        if (! $this->option('no-reminder') && ($h = $pick('christmas'))) {
            $res = HolidayNotifier::remind($h, ['email' => $email !== '' ? [$email] : [], 'sms' => $sms !== '' ? [\App\Support\TzPhone::normalize($sms) ?: $sms] : []]);
            foreach ($res as $r) {
                $this->{$r['ok'] ? 'info' : 'error'}('Reminder ' . $r['channel'] . ' to ' . $r['who'] . ': ' . ($r['ok'] ? 'sent' : $r['error']));
            }
        }

        return self::SUCCESS;
    }
}
