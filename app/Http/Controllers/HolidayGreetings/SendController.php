<?php

namespace App\Http\Controllers\HolidayGreetings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HolidayGreetings\Concerns\Guards;
use App\Models\HolidayBundle;
use App\Models\HolidayDelivery;
use App\Services\HolidayGreetings\HolidaySettings;
use App\Services\HolidayGreetings\GreetingSender;
use App\Services\HolidayGreetings\GreetingTemplates;
use Illuminate\Http\Request;

/** The working part of a holiday: the wording, who is left out, sending, and retrying what failed. */
class SendController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** The email and the SMS exactly as one person would get them, for the wording on the screen (not saved). */
    public function preview(Request $request, int $holiday)
    {
        $this->need('read-holiday-greetings');
        $h = $this->holiday($holiday);
        $d = $request->validate(['subject' => 'nullable|string|max:190', 'headline' => 'nullable|string|max:160', 'body' => 'nullable|string|max:6000', 'sms' => 'nullable|string|max:600', 'title' => 'nullable|string|max:40', 'name' => 'nullable|string|max:190']);
        $tpl = array_merge(GreetingTemplates::effective($h), array_filter(['subject' => $d['subject'] ?? null, 'headline' => $d['headline'] ?? null, 'body' => $d['body'] ?? null, 'sms' => $d['sms'] ?? null], fn ($v) => filled($v)));
        $sms = GreetingTemplates::sms($h, $d['title'] ?? null, $d['name'] ?? null, $tpl);

        return response()->json([
            'html' => GreetingTemplates::emailHtml($h, $d['title'] ?? null, $d['name'] ?? null, $tpl),
            'subject' => GreetingTemplates::emailData($h, $d['title'] ?? null, $d['name'] ?? null, $tpl)['subject'],
            'sms' => $sms, 'smsLength' => mb_strlen($sms), 'smsParts' => GreetingTemplates::smsParts($sms),
        ]);
    }

    public function template(Request $request, int $holiday)
    {
        $this->need('prepare-holiday-greetings');
        $h = $this->holiday($holiday);
        if ($h->locked()) {
            return back()->withErrors(['template' => __('The greetings of this holiday have been sent, so the message is locked and shows what was sent.')]);
        }
        $d = $request->validate(['subject' => 'required|string|max:190', 'headline' => 'required|string|max:160', 'body' => 'required|string|max:6000', 'sms' => 'required|string|max:600'], [
            'subject.required' => __('Enter the subject of the email.'), 'headline.required' => __('Enter the headline of the email.'), 'body.required' => __('Write the message of the email.'), 'sms.required' => __('Write the SMS.')]);
        if (preg_match('/https?:\/\/|www\./i', $d['sms'])) {
            return back()->withErrors(['sms' => __('The SMS gateway refuses links. Take the web address out of the SMS.')]);
        }
        $defaults = GreetingTemplates::defaults($h);
        $same = fn ($k, $v) => trim(str_replace("\r\n", "\n", $v)) === trim(str_replace("\r\n", "\n", $defaults[$k])) ? null : trim(str_replace("\r\n", "\n", $v));
        $before = GreetingTemplates::effective($h);
        $h->forceFill(['email_subject' => $same('subject', $d['subject']), 'email_headline' => $same('headline', $d['headline']), 'email_body' => $same('body', $d['body']), 'sms_body' => $same('sms', $d['sms']),
            'edited_by_user_id' => $request->user()->id, 'edited_at' => now()])->save();
        log_activity('Holiday greeting message changed', ['holiday' => $h->name, 'before' => $before, 'after' => GreetingTemplates::effective($h)], $h, 'Holiday Greetings');

        return back()->with('message', __('The message was saved.'));
    }

    public function reset(Request $request, int $holiday)
    {
        $this->need('prepare-holiday-greetings');
        $h = $this->holiday($holiday);
        if ($h->locked()) {
            return back()->withErrors(['template' => __('The greetings of this holiday have been sent, so the message is locked and shows what was sent.')]);
        }
        $h->forceFill(['email_subject' => null, 'email_headline' => null, 'email_body' => null, 'sms_body' => null, 'edited_by_user_id' => $request->user()->id, 'edited_at' => now()])->save();
        log_activity('Holiday greeting message reset to the WIMS template', ['holiday' => $h->name], $h, 'Holiday Greetings');

        return back()->with('message', __('The WIMS message was put back.'));
    }

    /** Leave people out of this holiday (or put them back): `ids` of the greeting list, on one channel. */
    public function exclude(Request $request, int $holiday)
    {
        $this->need('prepare-holiday-greetings');
        $h = $this->holiday($holiday);
        $d = $request->validate(['channel' => 'required|in:email,sms', 'ids' => 'required|array|min:1|max:5000', 'ids.*' => 'integer', 'excluded' => 'required|boolean']);
        $x = $h->excluded ?? ['email' => [], 'sms' => []];
        $cur = array_map('intval', (array) ($x[$d['channel']] ?? []));
        $ids = array_map('intval', $d['ids']);
        $x[$d['channel']] = array_values($d['excluded'] ? array_unique(array_merge($cur, $ids)) : array_diff($cur, $ids));
        $h->forceFill(['excluded' => $x])->save();
        log_activity('Holiday greeting list changed', ['holiday' => $h->name, 'channel' => $d['channel'], 'people' => count($ids), 'left_out' => (bool) $d['excluded']], $h, 'Holiday Greetings');

        return back();
    }

    public function start(Request $request, int $holiday)
    {
        $this->need('send-holiday-greetings');
        $h = $this->holiday($holiday);
        if ($h->isPast()) {
            return back()->withErrors(['send' => __('This holiday has passed. Its greetings can be read, but no greeting can be sent for it any more.')]);
        }
        if (! $h->counts()) {
            return back()->withErrors(['send' => __('This holiday does not count: it is switched off, or it falls on a Saturday or Sunday. Include it on the holiday page first.')]);
        }
        $bundleId = (int) $request->input('bundle_id', 0) ?: null;
        $bundle = $bundleId ? HolidayBundle::query()->find($bundleId) : null;
        if ($bundleId && ! $bundle) {
            return back()->withErrors(['send' => __('That greeting list bundle no longer exists. Choose another.')]);
        }
        $lists = GreetingSender::lists($h, $bundle?->id);
        $c = $lists['counts'];
        $fresh = collect($lists['email'])->where('excluded', false)->whereNull('delivery')->count() + collect($lists['sms'])->where('excluded', false)->whereNull('delivery')->count();
        if (! $c['email'] && ! $c['sms']) {
            return back()->withErrors(['send' => $bundle ? __('Nobody in :b has an email or a mobile number, or all of them are left out of this holiday.', ['b' => $bundle->name]) : __('Nobody is on the lists. Add people to the greeting list first.')]);
        }
        if (! $fresh) {
            return back()->withErrors(['send' => $bundle ? __('Everybody in :b has already been sent this greeting.', ['b' => $bundle->name]) : __('Everybody on the lists has already been sent this greeting.')]);
        }
        $q = GreetingSender::start($h, $request->user(), null, $bundle?->id);
        log_activity('Holiday greetings sent', ['holiday' => $h->name, 'date' => $h->date->format('Y-m-d'), 'emails' => $q['email'], 'sms' => $q['sms'], 'duplicates_removed' => $c['duplicates'], 'greeting_list' => $bundle?->name ?? 'The whole greeting list'], $h, 'Holiday Greetings');

        return back()->with('message', __('Sending: :e emails and :s SMS.', ['e' => $q['email'], 's' => $q['sms']]));
    }

    /** Automatic sending of one holiday: on or off, how long before the holiday begins, and which bundle. `follow` puts it back on the Settings default. */
    public function auto(Request $request, int $holiday)
    {
        $this->need('send-holiday-greetings');
        $h = $this->holiday($holiday);
        if ($h->isPast()) {
            return back()->withErrors(['auto' => __('This holiday has passed. Greetings can no longer be scheduled for it.')]);
        }
        if ($h->locked()) {
            return back()->withErrors(['auto' => __('The greetings of this holiday have already been sent.')]);
        }
        if ($request->boolean('follow')) {
            $h->forceFill(['auto_plan' => null, 'auto_attempted_at' => null])->save();
            log_activity('Holiday greetings schedule put back to the Settings', ['holiday' => $h->name], $h, 'Holiday Greetings');

            return back()->with('message', __('This holiday now follows the schedule of the Settings.'));
        }
        $d = $request->validate(['enabled' => 'required|boolean', 'minutes' => 'required|integer|min:0|max:' . HolidaySettings::AUTO_MAX_MINUTES, 'bundle_id' => 'nullable|integer|min:0'],
            ['minutes.required' => __('Enter how long before the holiday the greetings are sent.'), 'minutes.max' => __('The greetings can be sent at most 7 days before the holiday.')]);
        $bundleId = (int) ($d['bundle_id'] ?? 0) ?: null;
        if ($bundleId && ! HolidayBundle::query()->whereKey($bundleId)->exists()) {
            return back()->withErrors(['bundle_id' => __('Choose a greeting list bundle from the list.')]);
        }
        $before = $h->autoPlan();
        $h->forceFill(['auto_plan' => ['enabled' => (bool) $d['enabled'], 'minutes' => (int) $d['minutes'], 'bundle_id' => $bundleId], 'auto_attempted_at' => null])->save();
        log_activity('Holiday greetings schedule changed', ['holiday' => $h->name, 'before' => $before, 'after' => $h->autoPlan(), 'sends_at' => $h->autoSendAt()->toDateTimeString()], $h, 'Holiday Greetings');

        return back()->with('message', $d['enabled'] ? __('The greetings are scheduled to be sent on :t.', ['t' => $h->autoSendAt()->locale('en')->translatedFormat('l j F Y, H:i')]) : __('Scheduling is off for this holiday.'));
    }

    public function retryFailed(Request $request, int $holiday)
    {
        $this->need('send-holiday-greetings');
        $h = $this->holiday($holiday);
        $n = GreetingSender::retryFailed($h);
        log_activity('Holiday greetings retried', ['holiday' => $h->name, 'messages' => $n], $h, 'Holiday Greetings');

        return back()->with('message', $n ? __('Trying again: :n messages.', ['n' => $n]) : __('There is nothing to retry.'));
    }

    public function retryOne(Request $request, int $holiday, int $delivery)
    {
        $this->need('send-holiday-greetings');
        $h = $this->holiday($holiday);
        $d = HolidayDelivery::query()->where('holiday_id', $h->id)->findOrFail($delivery);
        $ok = GreetingSender::retryOne($d);
        if ($ok) {
            log_activity('Holiday greeting retried', ['holiday' => $h->name, 'channel' => $d->channel, 'address' => $d->address], $h, 'Holiday Greetings');
        }

        return back()->with('message', $ok ? __('Trying again: :a', ['a' => $d->address]) : __('Only a message that failed can be retried.'));
    }
}
