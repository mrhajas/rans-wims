<?php

namespace App\Http\Controllers;

use App\Support\SystemHealth\Collector;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * System Health: one page that shows, as pictures, how the system and the connection of the person looking are doing right now (signal, server and database
 * speed, space, SMS credit, cloud drive, backups, jobs, people, the person's own place in the system). Read only. Super Admin by default; any role can be given
 * the permission read-system-health. The figures are read by App\Support\SystemHealth\Collector (real measurements, nothing invented).
 */
class SystemHealthController extends Controller
{
    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('read-system-health'), 403);
    }

    public function index(Request $request)
    {
        $this->allow($request);

        return Inertia::render('SystemHealth/Index', ['core' => Collector::core($request->user()), 'reportTo' => self::defaultReportEmail()]);
    }

    /** The fast part again (the page asks every 30 seconds while it is on screen). */
    public function data(Request $request)
    {
        $this->allow($request);

        return response()->json(Collector::core($request->user()));
    }

    /** The slow part: the SMS balance and the cloud drive (asked from the page after it has opened). */
    public function cloud(Request $request)
    {
        $this->allow($request);

        return response()->json(Collector::cloud());
    }

    /** The address the report always goes to (a soft-coded setting; Extrion by default). */
    public static function defaultReportEmail(): string
    {
        $v = trim((string) get_settings('system_health_report_email'));

        return filter_var($v, FILTER_VALIDATE_EMAIL) ? strtolower($v) : 'extriontech@gmail.com';
    }

    /**
     * Sends the System Health report by e-mail: always to the default address, and to up to three more that the person types. Each address gets its own message,
     * so nobody sees the others. The report is built here from real measurements (the person's own device measurement of the connection may come with it).
     */
    public function sendReport(Request $request)
    {
        $this->allow($request);
        $data = $request->validate([
            'extra' => 'nullable|string|max:300',
            'connection' => 'nullable|array',
            'connection.percent' => 'required_with:connection|integer|between:0,100',
            'connection.level' => 'nullable|string|in:excellent,good,fair,weak,poor,offline,unknown',
            'connection.rtt' => 'nullable|numeric|between:0,120000', 'connection.jitter' => 'nullable|numeric|between:0,120000', 'connection.loss' => 'nullable|numeric|between:0,100',
            'connection.type' => 'nullable|string|in:wifi,ethernet,cellular,other,unknown',
        ]);

        $recipients = [self::defaultReportEmail()];
        $extras = array_values(array_filter(preg_split('/[\s,;]+/', (string) ($data['extra'] ?? '')) ?: []));
        if (count($extras) > 3) {
            return response()->json(['message' => __('Add at most three more addresses.')], 422);
        }
        foreach ($extras as $e) {
            if (! filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 120) {
                return response()->json(['message' => __(':e is not a valid e-mail address.', ['e' => mb_substr($e, 0, 60)])], 422);
            }
            $recipients[] = strtolower($e);
        }
        $recipients = array_values(array_unique($recipients));

        $report = \App\Support\SystemHealth\ReportBuilder::build($request->user(), $data['connection'] ?? null);
        $company = optional($request->user()->account)->name;
        $sent = [];
        $failed = [];
        foreach ($recipients as $to) {
            try {
                \Illuminate\Support\Facades\Mail::to($to)->send(new \App\Mail\SystemHealthReportMail($report, $to, $company));
                $sent[] = $to;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('System Health report e-mail failed', ['to' => $to, 'error' => $e->getMessage()]);
                $failed[] = $to;
            }
        }
        log_activity('System Health report sent', ['sent' => $sent, 'failed' => $failed, 'score' => $report['score']], null, 'System Health');

        if (! $sent) {
            // the reason is in the log; the person is told only that it did not go
            return response()->json(['message' => __('The report could not be sent. Check the e-mail settings, then try again.'), 'sent' => [], 'failed' => $failed], 502);
        }

        return response()->json(['message' => __('The report was sent.'), 'sent' => $sent, 'failed' => $failed]);
    }

    /** A fresh little speed test of the database, the cache and the disk. */
    public function speedTest(Request $request)
    {
        $this->allow($request);
        log_activity('System Health speed test run', null, null, 'System Health');

        return response()->json(Collector::speedTest());
    }
}
