<?php

namespace App\Console\Commands;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestReminder;
use App\Models\User;
use App\Services\HolidayGreetings\GreetingSender;
use App\Services\MrReminders\ReminderSender;
use App\Services\MrReminders\ReminderSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Sample reminders for review: a few approved requests that are not cleared get a short story of reminders (a first nudge, a second with a file, a last one), each sent through the
 * real pipeline to ONE test address and ONE test mobile, then spread over the last days. Nobody else is told. Run with MAIL_MAILER=smtp to really send the email on a copy that keeps mail on the log.
 */
class MrRemindersSamples extends Command
{
    protected $signature = 'mr-reminders:samples {--email=extriontech@gmail.com} {--sms=+255674020223} {--requests=3} {--clear : Remove every sample reminder first} {--no-sms : Do not send the SMS (email and app alert only)}';

    protected $description = 'Add sample Material Request reminders and send them to one test email and one test mobile';

    private const STORY = [
        [0, 'First reminder. This request was approved and has not moved. Please confirm when the items will leave the store.', false],
        [1, 'Second reminder. The site is waiting for these materials and work is slowing down. Please see the attached follow-up note and update the request today.', true],
        [2, 'Last reminder before escalation to the Project Manager. Please issue the remaining items or tell us the reason for the delay.', true],
    ];

    public function handle(): int
    {
        if ($this->option('clear')) {
            $this->clearAll();
        }
        $requests = MaterialRequest::withoutGlobalScopes()->whereIn('status', ReminderSender::eligibleStatuses())->with('warehouse:id,name')->orderByDesc('id')->get()
            ->groupBy('status')->flatMap(fn ($g) => $g->take(2))->take((int) $this->option('requests'))->values();
        if ($requests->isEmpty()) {
            $this->error('There is no approved request that is not yet cleared.');

            return self::FAILURE;
        }
        $by = User::withoutGlobalScope('account')->get()->first(fn ($u) => $u->hasRole('Super Admin'));
        $tester = User::withoutGlobalScope('account')->create(['name' => 'Extrion Tech (sample)', 'email' => 'mrr-sample-' . substr(md5(microtime()), 0, 8) . '@example.test', 'username' => 'mrr-sample-' . substr(md5(microtime()), 0, 6), 'password' => bcrypt(bin2hex(random_bytes(12))),
            'is_active' => 1, 'account_id' => $by->account_id]);
        ReminderSettings::$onlyUserIds = [$tester->id];
        ReminderSettings::$noSms = (bool) $this->option('no-sms');
        ReminderSettings::$addresses = ['email' => $this->option('email'), 'sms' => $this->option('sms')];
        GreetingSender::$ignoreSwitches = true;
        $this->info('Test recipient: ' . $this->option('email') . ' / ' . $this->option('sms'));
        $dir = storage_path('app/mrr-sample');
        @mkdir($dir, 0775, true);
        try {
            foreach ($requests as $i => $mr) {
                $this->line("Request {$mr->reference} ({$mr->status}) at " . optional($mr->warehouse)->name);
                foreach (self::STORY as $n => [$daysAgo, $note, $file]) {
                    if ($n >= 1 + ($i % 3)) {
                        break; // the first request gets one reminder, the second two, the third all three
                    }
                    $files = [];
                    if ($file) {
                        $path = $dir . '/Follow-up-' . $mr->reference . '-' . ($n + 1) . '.pdf';
                        Pdf::loadHTML('<h2 style="font-family:Helvetica">Follow-up note</h2><p style="font-family:Helvetica">Request ' . e($mr->reference) . ' (' . e((string) optional($mr->warehouse)->name) . ') is waiting for ' . e(MaterialRequest::statusLabel($mr->status)) . ' action.</p><p style="font-family:Helvetica">Sample file added to a reminder.</p>')->save($path);
                        $files[] = new UploadedFile($path, 'Follow-up-' . $mr->reference . '.pdf', 'application/pdf', null, true);
                    }
                    $r = ReminderSender::send($mr, $by, $note, $files)['reminder'];
                    $at = now()->subDays(3 - $n + $i)->subHours(2 + $n);
                    $r->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
                    DB::table('mr_reminder_deliveries')->where('reminder_id', $r->id)->update(['sent_at' => DB::raw('IF(status = "Sent", ' . DB::getPdo()->quote($at->toDateTimeString()) . ', NULL)'), 'created_at' => $at, 'updated_at' => $at]);
                    $c = ReminderSender::counts($r);
                    $this->line("  reminder #" . ($n + 1) . ": app {$c['app']['sent']}, email {$c['email']['sent']}, sms {$c['sms']['sent']} sent");
                }
            }
        } finally {
            // the test person is only a recipient while sending: the history keeps the name and address, not a login
            DB::table('mr_reminder_deliveries')->where('user_id', $tester->id)->update(['user_id' => null]);
            DB::table('notifications')->where('notifiable_id', $tester->id)->where('notifiable_type', User::class)->delete();
            $tester->forceDelete();
            ReminderSettings::$onlyUserIds = null;
            ReminderSettings::$addresses = null;
            ReminderSettings::$noSms = false;
            GreetingSender::$ignoreSwitches = false;
        }

        return self::SUCCESS;
    }

    private function clearAll(): void
    {
        foreach (DB::table('attachments')->where('model_type', MaterialRequestReminder::class)->get() as $a) {
            \Illuminate\Support\Facades\Storage::disk($a->disk ?: 'local')->delete($a->filepath);
        }
        DB::table('attachments')->where('model_type', MaterialRequestReminder::class)->delete();
        DB::table('mr_reminder_deliveries')->delete();
        DB::table('mr_reminders')->delete();
        $this->warn('Earlier reminders removed.');
    }
}
