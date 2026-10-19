<?php

namespace App\Console\Commands;

use App\Models\SiteMeeting;
use App\Models\SiteMeetingAttendee;
use App\Models\SiteMeetingRow;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SiteMeetings\SiteMeetingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sample records for Site Meetings: the RANS "Management Meeting 07" form (Balozi residential building, Chukwani,
 * 13.09.2025) typed in as a finished set of minutes, plus a few meetings on this and next week's schedule. Written
 * straight to the tables (no events), so nobody is notified. Everything is marked "SAMPLE" in its remarks.
 *
 *   php artisan wims:seed-site-meeting-samples           add the samples (nothing happens if they exist)
 *   php artisan wims:seed-site-meeting-samples --remove  delete them again
 */
class SeedSiteMeetingSamples extends Command
{
    protected $signature = 'wims:seed-site-meeting-samples {--remove : Delete the sample meetings instead of creating them}';

    protected $description = 'Create (or remove) sample Site Meetings, including the Management Meeting 07 minutes';

    private const MARK = 'SAMPLE - ';

    public function handle(): int
    {
        $admin = User::withoutGlobalScopes()->role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        if (! $admin) {
            $this->error('No Super Admin user found.');

            return self::FAILURE;
        }
        Auth::setUser($admin);

        return $this->option('remove') ? $this->remove() : $this->create($admin);
    }

    private function remove(): int
    {
        $ids = SiteMeeting::withTrashed()->withoutGlobalScope('account')->where('remarks', 'like', self::MARK.'%')->pluck('id');
        DB::transaction(function () use ($ids) {
            SiteMeetingRow::whereIn('site_meeting_id', $ids)->delete();
            SiteMeetingAttendee::whereIn('site_meeting_id', $ids)->delete();
            DB::table('activity_log')->where('subject_type', SiteMeeting::class)->whereIn('subject_id', $ids)->delete();
            SiteMeeting::withTrashed()->withoutGlobalScope('account')->whereIn('id', $ids)->forceDelete();
        });
        $this->info($ids->count().' sample meeting(s) removed.');

        return self::SUCCESS;
    }

    private function create(User $admin): int
    {
        if (SiteMeeting::withTrashed()->where('remarks', 'like', self::MARK.'%')->exists()) {
            $this->warn('Sample meetings already exist. Run with --remove first to recreate them.');

            return self::SUCCESS;
        }

        $sites = Warehouse::query()->ofAccount($admin->account_id)->active()->whereNotIn(DB::raw('LOWER(TRIM(status))'), ['main warehouse', 'main warehouses'])->orderBy('name')->get();
        if ($sites->isEmpty()) {
            $this->error('There is no project / site to attach the samples to.');

            return self::FAILURE;
        }
        $balozi = $sites->first(fn ($w) => stripos($w->name, 'BALOZI ABDULSWAMAD') !== false) ?: $sites->first(fn ($w) => stripos($w->name, 'BALOZI') !== false) ?: $sites->first();
        $person = fn (string $like) => User::query()->where('account_id', $admin->account_id)->where('is_active', true)->where('username', 'not like', 'tempdebug%')->where('name', 'like', $like)->first();
        $settings = app(SiteMeetingSettings::class)->forAccount($admin->account_id);
        $template = array_values(array_filter($settings->template(), fn ($s) => $s['enabled']));

        DB::transaction(function () use ($admin, $sites, $balozi, $person, $template) {
            // ---- the RANS "Management Meeting 07" form, as minutes --------------------------------------------------
            $m7 = $this->meeting($admin, $balozi, $template, [
                'meeting_no' => 7, 'meeting_date' => '2025-09-13', 'start_time' => '09:00', 'end_time' => '10:30', 'venue' => 'Site office',
                'chairperson_id' => optional($person('Abdulhakeem%'))->id, 'status' => SiteMeeting::HELD, 'report_status' => SiteMeeting::REPORT_FINAL,
                'agenda' => "Site organisation and administration\nAuthority and compliance, health and safety\nVisitors and testing / samples\nWeekly work schedule and progress",
                'remarks' => self::MARK.'typed in from the manual "Management Meeting 07" form (Balozi residential building, Chukwani, 13.09.2025).',
            ], true);
            $this->attendees($m7, [
                ['Abdulhakeem Nasir', 'Project Manager'], ['Mahmoud Ali', 'Site Manager / Site Engineer'], ['Yahya', 'Site Foreman'], ['Juweiriya', 'Quantity Surveyor'],
                ['Suleiman', 'Quantity Surveyor'], ['Suleiman Jabir', 'Storekeeper'], ['Nassir', 'Safety Officer'],
            ], 'Present');
            $this->fillMinutes($m7);

            // ---- a few meetings on the weekly board ---------------------------------------------------------------------
            $monday = now()->startOfWeek();
            $engineers = User::query()->where('account_id', $admin->account_id)->where('is_active', true)->where('username', 'not like', 'tempdebug%')->role('Engineers')->orderBy('id')->limit(3)->get();
            $plan = [
                [0, '09:00', 'Site office', 'Scheduled'], [1, '10:30', 'Site office', 'Scheduled'], [2, '14:00', 'Client office', 'Scheduled'],
                [3, '09:30', 'Site office', 'Scheduled'], [5, '08:30', 'Site office', 'Postponed'], [7, '09:00', 'Site office', 'Scheduled'], [9, '11:00', 'Site office', 'Scheduled'],
            ];
            foreach ($plan as $i => [$offset, $time, $venue, $status]) {
                $site = $sites[($i + 1) % $sites->count()];
                $date = $monday->copy()->addDays($offset);
                $past = $date->lt(now()->startOfDay());
                $meeting = $this->meeting($admin, $site, $template, [
                    'meeting_date' => $date->toDateString(), 'start_time' => $time, 'end_time' => date('H:i', strtotime($time.' +90 minutes')), 'venue' => $venue,
                    'status' => $past && $status === 'Scheduled' ? SiteMeeting::HELD : $status, 'report_status' => SiteMeeting::REPORT_NONE,
                    'chairperson_id' => optional($person('%Hakeem%') ?: $person('%Majid%'))->id,
                    'status_reason' => $status === 'Postponed' ? 'Heavy rain expected; the site engineer asked to move it.' : null,
                    'postponed_to' => $status === 'Postponed' ? $date->copy()->addDays(3)->toDateString() : null,
                    'agenda' => 'Progress of works, materials and labour, safety, visitors.',
                    'remarks' => self::MARK.'weekly schedule example.',
                ], false);
                foreach ($engineers as $k => $u) {
                    SiteMeetingAttendee::create(['site_meeting_id' => $meeting->id, 'user_id' => $u->id, 'name' => $u->name, 'designation' => 'Site Engineer', 'attendance' => 'Invited', 'sort' => $k]);
                }
            }
        });

        $this->info('Sample meetings created: Management Meeting 07 (with full minutes) and the weekly schedule examples.');

        return self::SUCCESS;
    }

    private function meeting(User $admin, Warehouse $site, array $template, array $attrs, bool $withReport): SiteMeeting
    {
        $meeting = SiteMeeting::create(array_merge([
            'account_id' => $admin->account_id,
            'reference' => SiteMeeting::nextReference(),
            'meeting_no' => SiteMeeting::nextMeetingNo($site->id),
            'warehouse_id' => $site->id,
            'template' => $template,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ], $attrs));
        if ($withReport) {
            $day = $meeting->meeting_date->copy()->setTime(15, 0);
            $meeting->forceFill([
                'report_started_at' => $day, 'report_started_by' => $admin->id,
                'report_submitted_at' => $day->copy()->addHours(2), 'report_submitted_by' => $admin->id,
                'report_finalised_at' => $day->copy()->addDay()->setTime(9, 30), 'report_finalised_by' => $admin->id,
            ])->saveQuietly();
        }

        return $meeting;
    }

    private function attendees(SiteMeeting $meeting, array $people, string $attendance): void
    {
        foreach ($people as $i => $p) {
            SiteMeetingAttendee::create([
                'site_meeting_id' => $meeting->id, 'name' => $p[0], 'designation' => $p[1] ?? null,
                'attendance' => $attendance, 'sort' => $i,
            ]);
        }
    }

    private function put(SiteMeeting $m, string $key, array $rows): void
    {
        foreach (array_values($rows) as $i => $data) {
            SiteMeetingRow::create(['site_meeting_id' => $m->id, 'section_key' => $key, 'sort' => $i, 'data' => $data]);
        }
    }

    /** The content of the manual form "MANAGEMENT MEETING 07", 13.09.2025. */
    private function fillMinutes(SiteMeeting $m): void
    {
        $this->put($m, 'site_organisation', [
            ['role' => 'Project Manager', 'name' => 'Abdulhakeem Nasir', 'challenges' => '', 'remarks' => ''],
            ['role' => 'Site Manager / Site Engineer', 'name' => 'Mahmoud Ali', 'challenges' => 'to prepare daily/ weekly reports; work schedule; revise work program; testing of materials', 'remarks' => ''],
            ['role' => 'Site Foreman', 'name' => 'Yahya', 'challenges' => '', 'remarks' => ''],
            ['role' => 'Technical Manager', 'name' => '-', 'challenges' => 'to deal with all technical issues and quality', 'remarks' => ''],
            ['role' => 'Quantity Surveyors', 'name' => 'Juweiriya / Suleiman', 'challenges' => 'to perform takeoff of all works performed on site', 'remarks' => ''],
            ['role' => 'Sectional Foreman', 'name' => "Formwork - Matola, Steelwork - Majaaliwa, Excavation works - Sillas, Land surveyor - Njoroge, Concrete works - Abdallah, Electrical works - Masoud, Plumbing works - Hafidh", 'challenges' => '', 'remarks' => ''],
            ['role' => 'Storekeeper', 'name' => 'Suleiman Jabir', 'challenges' => 'keeping all store records', 'remarks' => ''],
            ['role' => 'Safety Officer', 'name' => 'Nassir', 'challenges' => '', 'remarks' => ''],
        ]);

        $admin = fn ($item, $resp, $status, $chal = '') => ['item' => $item, 'responsible' => $resp, 'status' => $status, 'challenges' => $chal, 'remarks' => ''];
        $se = 'Site Manager / Site Engineer';
        $this->put($m, 'site_administration', [
            $admin('Store Record', 'Proc Dept / Store keeper', 'Material Delivered / issuing - on going', 'Store ledger book usage to be monitored'),
            $admin('Work Schedule', $se, 'on going', 'Book available'),
            $admin('Material request', $se, 'on going', 'Book available'),
            $admin('Material Issuing', 'Site Manager / Store keeper', 'on going', 'Book available'),
            $admin('Labour request', $se, 'on going', 'Book available'),
            $admin('Site Attendance Book', 'Site Manager / Site Security', 'on going', "Labour's to mention their trades"),
            $admin('Site Data', "Eng's", 'on going', 'Files available'),
            $admin('Safety Gears', 'Site manager', 'Gears available.', "SG to be provided to all labour's, recorded and monitored while on site;"),
            $admin('Delivery logistics', 'Proc Dept / Store keeper', 'on going', 'Book available'),
        ]);
        $this->put($m, 'authority_compliance', [
            $admin('BUILDING PERMIT', $se, 'Provided'), $admin('ZCRB', $se, 'Provided'), $admin('ZECO', $se, 'Provided'),
            $admin('ZAWA', $se, 'Borehole provided'), $admin('OSHA', $se, 'In Process'), $admin('INSURANCE', $se, 'In Process'),
        ]);
        $this->put($m, 'health_safety', [$admin('Accidents / incidents', 'Site manager', 'No accident occured')]);

        // Visitors — the form shows the years 2026-2030 for rows 3, 5-9, which are typing slips of 2025; entered as 2025.
        $v = fn ($name, $date, $org, $purpose) => ['name' => $name, 'date' => $date, 'organisation' => $org, 'purpose' => $purpose, 'comments' => ''];
        $this->put($m, 'visitors', [
            $v('Hidaya A. Abbas', '2025-08-08', 'KIST', 'Students assessment'), $v('Eng. Mussa Kimaka', '2025-09-06', 'KIMPHIL', 'Management meeting'),
            $v('Arch. Said Mwanga', '2025-09-06', 'qD Consult (T) Limited', 'Management meeting'), $v('Abdulhamid Mhoma', '2025-09-10', 'AL HATMY', 'Site meeting'),
            $v('Abdulsamad Abdulrahim', '2025-09-10', 'Client', 'Site Meeting'), $v('Latifa Kimaka', '2025-09-10', 'KIMPHIL', 'Site Meeting'),
            $v('Abdiel Mathias', '2025-09-10', 'KIMPHIL', 'Site Meeting'), $v('Ally Kimaka', '2025-09-10', 'KIMPHIL', 'Site Meeting'), $v('Timoth Joseph', '2025-09-10', 'KIMPHIL', 'Site Meeting'),
        ]);

        $this->put($m, 'testing_samples', [
            ['test' => 'Concrete cube test', 'description' => 'Pad footing base concrete (C25)', 'cast_date' => '2025-09-06', 'test_date' => '2025-09-14', 'strength_7' => '', 'strength_28' => '', 'remark' => '7 days: Waiting for testing date'],
            ['test' => 'Concrete cube test', 'description' => 'Pad footing base concrete (C25)', 'cast_date' => '2025-09-06', 'test_date' => '2025-10-04', 'strength_7' => '', 'strength_28' => '', 'remark' => '28 days: Waiting for testing date'],
        ]);

        $this->put($m, 'details_approval', []);
        $this->put($m, 'program_of_works', [['text' => 'Responsible: Project manager']]);

        $w = fn ($desc, $resp, $prev, $cur, $ant) => ['description' => $desc, 'responsible' => $resp, 'previous' => $prev, 'current' => $cur, 'anticipation' => $ant];
        $this->put($m, 'weekly_schedule', [
            ['_group' => 'MOBILIZATION (43 days)'],
            $w('Mobilization', '', '100%', '100%', 'COMPLETED'),
            ['_group' => 'SUBSTRUCTURE'],
            $w('Excavation of the Pad footing pits (57/47 pits)', 'SILLAS', '68%', '82% (13/09/2025)', '100% (14/09/2025)'),
            $w('Concrete blinding to Pad footing pit (Vo=4.4m³, 57/33 pits).', 'ABDALLAH ZEGE', '58%', '58% (15/09/2025)', '100% (15/09/2025)'),
            $w('Formwork to Pad footing bases (Ao=87m², 57/33 bases)', 'MATOLA', '58%', '58% (15/09/2025)', '100% (16/09/2025)'),
            $w('Steelwork to Pad footing bases (57/33 bases)', 'MAJAALIWA', '58%', '58% (16/09/2025)', '100% (17/09/2025)'),
            $w('Steelwork to Starter columns (60/35 columns)', 'MAJAALIWA', '58%', '58% (17/09/2025)', '100% (18/09/2025)'),
            $w('Concrete to Pad footing bases (Vo=37m³, 57/33 bases)', 'ABDALLAH ZEGE', '58%', '58% (19/09/2025)', '100% (19/09/2025)'),
            $w('Formwork to Starter columns (60/35 columns)', 'MATOLA', '58%', '58% (19/09/2025)', '100% (20/09/2025)'),
            $w('Concrete to Starter columns (60/35 columns)', 'ABDALLAH ZEGE', '58%', '58% (21/09/2025)', '100% (21/09/2025)'),
            $w('Excavation of the Ground beams', 'SILLAS', '0%', '0% (13/09/2025)', '50% (20/09/2025)'),
        ]);
    }
}
