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

        return Inertia::render('SystemHealth/Index', ['core' => Collector::core($request->user())]);
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

    /** A fresh little speed test of the database, the cache and the disk. */
    public function speedTest(Request $request)
    {
        $this->allow($request);
        log_activity('System Health speed test run', null, null, 'System Health');

        return response()->json(Collector::speedTest());
    }
}
