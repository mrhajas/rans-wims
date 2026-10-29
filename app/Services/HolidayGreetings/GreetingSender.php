<?php

namespace App\Services\HolidayGreetings;

use App\Jobs\HolidayGreetings\SendHolidayGreetingJob;
use App\Mail\HolidayGreetingMail;
use App\Models\HolidayBundle;
use App\Models\HolidayContact;
use App\Models\HolidayDay;
use App\Models\HolidayDelivery;
use App\Models\User;
use App\Models\WimsConnectGateway;
use App\Services\FeatureManager;
use App\Services\WimsConnect\GatewayManager;
use App\Services\WimsConnect\OutboundLog;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Who receives a holiday's greetings and how they are sent. The lists are built from the greeting list every time they are asked for, so an email address or a mobile number can
 * never appear twice; sending writes one delivery row per person and channel, and each row is sent by its own job so one failure never stops the rest.
 */
class GreetingSender
{
    /** Sample runs on a copy where WIMS Connect is switched off: lets the SMS go out anyway. Never set by the web pages. */
    public static bool $ignoreSwitches = false;

    /** Sample runs: the mailer to use instead of the default one (the local copy keeps mail on the log). */
    public static ?string $mailer = null;

    public static function smsReady(): bool
    {
        if (! self::$ignoreSwitches && (! FeatureManager::isEnabled('wims_connect') || ! FeatureManager::isEnabled('wims_connect_sms'))) {
            return false;
        }

        return WimsConnectGateway::where('driver', 'sms')->exists();
    }

    /**
     * The two lists of a holiday. Each person appears once per channel; an address that two rows of the list share is kept for the first and reported under `duplicates`.
     *
     * @return array{email:array,sms:array,duplicates:array,counts:array}
     */
    public static function lists(HolidayDay $h, ?int $bundleId = null): array
    {
        $contacts = HolidayContact::withoutGlobalScope('account')->where('account_id', $h->account_id)->active()->orderBy('name')->orderBy('id')->get();
        $memberOf = DB::table('holiday_bundle_contacts')->whereIn('contact_id', $contacts->pluck('id'))->get()->groupBy('contact_id')->map(fn ($g) => $g->pluck('bundle_id')->map(fn ($b) => (int) $b)->all());
        if ($bundleId) {
            $contacts = $contacts->filter(fn ($c) => in_array($bundleId, $memberOf[$c->id] ?? [], true))->values();
        }
        $deliveries = $h->deliveries()->get()->groupBy('channel')->map(fn ($g) => $g->keyBy('address'));
        $out = ['email' => [], 'sms' => [], 'duplicates' => []];
        $seen = ['email' => [], 'sms' => []];
        foreach (['email' => 'email', 'sms' => 'mobile'] as $channel => $field) {
            $left = $h->excludedIds($channel);
            foreach ($contacts as $c) {
                $addr = $c->{$field};
                if (! $addr) {
                    continue;
                }
                $key = $channel === 'email' ? mb_strtolower($addr) : $addr;
                if (isset($seen[$channel][$key])) {
                    $out['duplicates'][] = ['channel' => $channel, 'address' => $addr, 'name' => $c->fullName(), 'kept' => $seen[$channel][$key]];
                    continue;
                }
                $seen[$channel][$key] = $c->fullName();
                $d = ($deliveries[$channel] ?? collect())->get($addr);
                $out[$channel][] = ['id' => $c->id, 'title' => $c->title, 'name' => $c->name, 'full' => $c->fullName(), 'address' => $addr, 'group' => $c->group_label, 'bundles' => $memberOf[$c->id] ?? [], 'excluded' => in_array($c->id, $left, true),
                    'delivery' => $d ? ['id' => $d->id, 'status' => $d->status, 'error' => $d->error, 'attempts' => $d->attempts, 'sent_at' => optional($d->sent_at)->toIso8601String()] : null];
            }
        }
        $out['counts'] = ['email' => count(array_filter($out['email'], fn ($r) => ! $r['excluded'])), 'sms' => count(array_filter($out['sms'], fn ($r) => ! $r['excluded'])), 'duplicates' => count($out['duplicates']),
            'email_left_out' => count(array_filter($out['email'], fn ($r) => $r['excluded'])), 'sms_left_out' => count(array_filter($out['sms'], fn ($r) => $r['excluded']))];

        return $out;
    }

    /**
     * Sends the greetings of a holiday: one delivery row per person and channel that has none yet, each queued as its own job. Pressing Send again only sends to people who were added
     * since; a person already sent to is never sent to twice. Returns how many were queued.
     */
    public static function start(HolidayDay $h, ?User $by, ?array $only = null, ?int $bundleId = null): array
    {
        $lists = self::lists($h, $bundleId);
        $bundleName = $bundleId ? HolidayBundle::withoutGlobalScope('account')->whereKey($bundleId)->value('name') : null;
        $queued = ['email' => 0, 'sms' => 0];
        $ids = [];
        foreach (['email', 'sms'] as $channel) {
            foreach ($lists[$channel] as $r) {
                if ($r['excluded'] || ($only !== null && ! in_array($r['address'], $only[$channel] ?? [], true))) {
                    continue;
                }
                $d = HolidayDelivery::withoutGlobalScope('account')->firstOrCreate(['holiday_id' => $h->id, 'channel' => $channel, 'address' => $r['address']], [
                    'contact_id' => $r['id'], 'title' => $r['title'], 'name' => $r['name'], 'status' => 'Queued', 'sent_by_user_id' => $by?->id, 'bundle_name' => $bundleName, 'queued_at' => now()]);
                if ($d->wasRecentlyCreated) {
                    $queued[$channel]++;
                    $ids[] = $d->id;
                }
            }
        }
        if (! $h->send_started_at) {
            $h->forceFill(['send_started_at' => now(), 'sent_by_user_id' => $by?->id])->save();
        }
        self::dispatchAll($ids);

        return $queued;
    }

    /** The bundles of the account with what a send to each would reach right now: people, emails, SMS, and how many of them have not been sent this holiday's greeting yet. */
    public static function bundleCounts(HolidayDay $h): array
    {
        $out = [];
        $whole = self::lists($h);
        $fresh = fn (array $l) => collect($l['email'])->where('excluded', false)->whereNull('delivery')->count() + collect($l['sms'])->where('excluded', false)->whereNull('delivery')->count();
        $people = fn (array $l) => collect([...$l['email'], ...$l['sms']])->where('excluded', false)->pluck('id')->unique()->count();
        $out[] = ['id' => 0, 'name' => __('The whole greeting list'), 'people' => $people($whole), 'email' => $whole['counts']['email'], 'sms' => $whole['counts']['sms'], 'fresh' => $fresh($whole)];
        foreach (HolidayBundle::withoutGlobalScope('account')->where('account_id', $h->account_id)->orderBy('name')->get() as $b) {
            $l = self::lists($h, $b->id);
            $out[] = ['id' => $b->id, 'name' => $b->name, 'people' => $people($l), 'email' => $l['counts']['email'], 'sms' => $l['counts']['sms'], 'fresh' => $fresh($l)];
        }

        return $out;
    }

    /** Sends the ones that failed (or were left "sending" by a crash) once more. */
    public static function retryFailed(HolidayDay $h): int
    {
        $rows = $h->deliveries()->where(fn ($q) => $q->where('status', 'Failed')->orWhere(fn ($x) => $x->where('status', 'Sending')->where('last_attempt_at', '<', now()->subMinutes(10))))->get();
        $ids = [];
        foreach ($rows as $d) {
            $d->update(['status' => 'Queued', 'error' => null, 'queued_at' => now()]);
            $ids[] = $d->id;
        }
        self::dispatchAll($ids);

        return count($ids);
    }

    public static function retryOne(HolidayDelivery $d): bool
    {
        if (! in_array($d->status, ['Failed'], true)) {
            return false;
        }
        $d->update(['status' => 'Queued', 'error' => null, 'queued_at' => now()]);
        self::dispatchAll([$d->id]);

        return true;
    }

    private static function dispatchAll(array $ids): void
    {
        $inline = config('queue.default') === 'sync';
        foreach ($ids as $id) {
            $inline ? SendHolidayGreetingJob::dispatchAfterResponse($id) : SendHolidayGreetingJob::dispatch($id);
        }
    }

    /** Sends one delivery now and writes how it went on its row. Safe to call twice: only a row that is still Queued is taken. */
    public static function deliver(HolidayDelivery $d): void
    {
        $taken = HolidayDelivery::withoutGlobalScope('account')->whereKey($d->id)->where('status', 'Queued')->update(['status' => 'Sending', 'last_attempt_at' => now(), 'attempts' => \DB::raw('attempts + 1')]);
        if (! $taken) {
            return;
        }
        $d->refresh();
        $h = HolidayDay::withoutGlobalScope('account')->withTrashed()->find($d->holiday_id);
        try {
            $result = $d->channel === 'email' ? self::sendEmail($h, $d) : self::sendSms($h, $d);
            $d->update(['status' => 'Sent', 'sent_at' => now(), 'error' => null, 'provider_message_id' => $result]);
        } catch (\Throwable $e) {
            $d->update(['status' => 'Failed', 'error' => Str::limit(strip_tags($e->getMessage()), 300) ?: __('The message could not be sent.')]);
        }
    }

    private static function sendEmail(HolidayDay $h, HolidayDelivery $d): ?string
    {
        $mail = new HolidayGreetingMail($h, $d->title, $d->name);
        (self::$mailer ? Mail::mailer(self::$mailer) : Mail::mailer())->to($d->address, $d->fullName())->send($mail);

        return null;
    }

    private static function sendSms(HolidayDay $h, HolidayDelivery $d): ?string
    {
        if (! self::smsReady()) {
            throw new \RuntimeException(__('SMS is switched off (WIMS Connect or its SMS channel). Switch it on, then retry.'));
        }
        $text = GreetingTemplates::sms($h, $d->title, $d->name);
        $result = app(GatewayManager::class)->send('sms', $d->address, new OutboundMessage($text));
        try {
            OutboundLog::record(['event_key' => 'holiday-greetings', 'channel' => 'sms', 'address' => $d->address, 'message' => $text, 'source_type' => HolidayDay::class, 'source_id' => $h->id,
                'status' => $result->success ? 'SENT' : 'FAILED', 'sent_at' => $result->success ? now() : null, 'error_message' => $result->success ? null : Str::limit(strip_tags((string) $result->error), 300)]);
        } catch (\Throwable $e) {
            report($e);
        }
        if (! $result->success) {
            throw new \RuntimeException($result->error ?: __('The SMS gateway refused the message.'));
        }

        return $result->providerMessageId;
    }

    /** Figures for a holiday's record: per channel and status. */
    public static function summary(HolidayDay $h): array
    {
        $rows = $h->deliveries()->selectRaw('channel, status, COUNT(*) n')->groupBy('channel', 'status')->get();
        $o = [];
        foreach (['email', 'sms'] as $ch) {
            $by = $rows->where('channel', $ch)->pluck('n', 'status');
            $total = (int) $by->sum();
            $o[$ch] = ['total' => $total, 'sent' => (int) ($by['Sent'] ?? 0), 'failed' => (int) ($by['Failed'] ?? 0), 'pending' => (int) (($by['Queued'] ?? 0) + ($by['Sending'] ?? 0))];
        }
        $o['total'] = $o['email']['total'] + $o['sms']['total'];
        $o['sent'] = $o['email']['sent'] + $o['sms']['sent'];
        $o['failed'] = $o['email']['failed'] + $o['sms']['failed'];
        $o['pending'] = $o['email']['pending'] + $o['sms']['pending'];
        $o['state'] = $h->sendState();

        return $o;
    }
}
