<?php

namespace App\Http\Controllers\SiteMeetings;

use App\Models\SiteMeeting;
use App\Models\SiteMeetingRow;
use App\Models\User;
use App\Services\SiteMeetings\SiteMeetingLogger;
use App\Services\SiteMeetings\SiteMeetingNotifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** The minutes document of a meeting ("Management Meeting 07"): the sections the site engineers fill in. */
class SiteMeetingReportController extends SiteMeetingBaseController
{
    private function sectionsOf(SiteMeeting $meeting): array
    {
        return array_values(array_filter($meeting->template ?? [], fn ($s) => ! empty($s['key'])));
    }

    /** rows grouped by section: [key => [[id, data], …]] */
    private function rowsOf(SiteMeeting $meeting): array
    {
        $out = [];
        foreach ($meeting->rows()->get() as $row) {
            $out[$row->section_key][] = ['id' => $row->id, 'data' => $row->data ?: new \stdClass()];
        }

        return $out;
    }

    /** First time an editor opens the minutes: take the current layout and lay out its starting rows. */
    private function startReport(SiteMeeting $meeting, User $user): void
    {
        DB::transaction(function () use ($meeting, $user) {
            $template = array_values(array_filter($this->settings($user)->template(), fn ($s) => $s['enabled']));
            $meeting->template = $template;
            foreach ($template as $section) {
                foreach (($section['default_rows'] ?? []) as $i => $row) {
                    SiteMeetingRow::create(['site_meeting_id' => $meeting->id, 'section_key' => $section['key'], 'sort' => $i, 'data' => $row]);
                }
            }
            $meeting->forceFill([
                'report_status' => SiteMeeting::REPORT_DRAFT, 'report_started_at' => now(), 'report_started_by' => $user->id, 'updated_by' => $user->id,
            ])->save();
        });
        SiteMeetingLogger::log($user, $meeting, 'REPORT_START');
    }

    public function show(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user);
        abort_if($meeting->trashed(), 404);
        $can = $this->abilities($user);

        $editable = $can['report'] && $meeting->canWorkBy($user) && ! $meeting->reportLocked() && $meeting->status !== SiteMeeting::CANCELLED;
        if ($meeting->report_status === SiteMeeting::REPORT_NONE && $editable) {
            $this->startReport($meeting, $user);
            $meeting->refresh();
        } elseif ($meeting->report_status !== SiteMeeting::REPORT_NONE) {
            SiteMeetingLogger::logView($user, $meeting, 'REPORT_VIEW', (int) $this->settings($user)->get('history_view_minutes'));
        }

        $meeting->load(['warehouse:id,name,code', 'chairperson:id,name', 'recorder:id,name', 'attendees', 'attachments']);
        $styles = $this->settings($user)->styles();

        return Inertia::render('SiteMeetings/Report', [
            'meeting' => [
                'id' => $meeting->id, 'reference' => $meeting->reference, 'title' => $meeting->display_title, 'meeting_no' => $meeting->meeting_no,
                'site' => ['id' => $meeting->warehouse_id, 'name' => optional($meeting->warehouse)->name, 'code' => optional($meeting->warehouse)->code],
                'date' => $meeting->meeting_date->toDateString(), 'start' => SiteMeeting::hm($meeting->start_time), 'end' => SiteMeeting::hm($meeting->end_time),
                'venue' => $meeting->venue, 'status' => $meeting->status, 'status_label' => $styles[$meeting->status]['label'] ?? $meeting->status, 'status_color' => $styles[$meeting->status]['color'] ?? 'slate',
                'report_status' => $meeting->report_status, 'report_label' => $styles[$meeting->report_status]['label'] ?? $meeting->report_status, 'report_color' => $styles[$meeting->report_status]['color'] ?? 'slate',
                'chairperson' => optional($meeting->chairperson)->name, 'recorder' => optional($meeting->recorder)->name,
                'report_submitted_at' => optional($meeting->report_submitted_at)->toIso8601String(), 'report_finalised_at' => optional($meeting->report_finalised_at)->toIso8601String(),
                'locked' => $meeting->reportLocked(),
            ],
            'sections' => $this->sectionsOf($meeting),
            'lastPrinted' => $can['print'] ? SiteMeetingLogger::lastPrinted($meeting) : null,
            'rows' => (object) $this->rowsOf($meeting),
            'attendees' => $this->attendeePayload($meeting),
            'people' => $this->people($user),
            'can' => $can + ['edit_report' => $editable, 'work' => $meeting->canWorkBy($user), 'cancelled' => $meeting->status === SiteMeeting::CANCELLED],
        ]);
    }

    public function save(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meeting-reports');
        abort_if($meeting->trashed() || $meeting->reportLocked(), 422, __('The minutes are finalised. Ask someone allowed to reopen them.'));
        abort_if($meeting->status === SiteMeeting::CANCELLED, 422, __('This meeting was cancelled.'));

        $request->validate([
            'sections' => ['nullable', 'array'],
            'attendees' => ['nullable', 'array', 'max:80'],
            'attendees.*.name' => ['nullable', 'string', 'max:190'],
            'attendees.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'attendees.*.attendance' => ['nullable', 'string', 'max:20'],
            'attendees.*.designation' => ['nullable', 'string', 'max:190'],
            'attendees.*.organisation' => ['nullable', 'string', 'max:190'],
        ]);

        $template = collect($this->sectionsOf($meeting))->keyBy('key');
        $before = $this->fingerprints($meeting);
        $incoming = (array) $request->input('sections', []);

        DB::transaction(function () use ($meeting, $template, $incoming, $request, $user) {
            foreach ($template as $key => $section) {
                if (! array_key_exists($key, $incoming)) {
                    continue; // a section the page did not send is left as it was
                }
                $meeting->rows()->where('section_key', $key)->delete();
                $rows = array_slice(array_values((array) $incoming[$key]), 0, 300);
                $sort = 0;
                foreach ($rows as $row) {
                    $clean = $this->cleanRow((array) $row, $section);
                    if ($clean === null) {
                        continue;
                    }
                    SiteMeetingRow::create(['site_meeting_id' => $meeting->id, 'section_key' => $key, 'sort' => $sort++, 'data' => $clean]);
                }
            }
            if ($request->has('attendees')) {
                $this->syncAttendees($meeting, (array) $request->input('attendees'));
            }
            $meeting->forceFill(['updated_by' => $user->id])->save();
        });

        $after = $this->fingerprints($meeting);
        $changed = array_keys(array_filter($after, fn ($hash, $key) => ($before[$key] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));
        SiteMeetingLogger::log($user, $meeting, 'REPORT_UPDATE', null, ['changed_sections' => array_map(fn ($k) => $template[$k]['title'] ?? $k, $changed), 'changes' => array_map(fn ($k) => $template[$k]['title'] ?? $k, $changed)]);
        if ($changed) {
            SiteMeetingNotifier::minutesSaved($meeting, implode(', ', array_map(fn ($k) => $template[$k]['title'] ?? $k, $changed)), $user);
        }

        return back()->with('message', __('Minutes saved.'));
    }

    /** Keeps only the template's own columns and sensible values; a row left completely empty is dropped. */
    private function cleanRow(array $row, array $section): ?array
    {
        if ($section['type'] === 'text') {
            $text = mb_substr(trim((string) ($row['text'] ?? '')), 0, 20000);

            return $text === '' ? null : ['text' => $text];
        }
        if (! empty($row['_group'])) {
            return $section['grouped'] ? ['_group' => mb_substr(trim((string) $row['_group']), 0, 160)] : null;
        }
        $out = [];
        $any = false;
        foreach ($section['columns'] as $col) {
            $v = trim((string) ($row[$col['key']] ?? ''));
            if ($col['type'] === 'date' && $v !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $v = '';
            }
            $v = mb_substr($v, 0, $col['type'] === 'textarea' ? 4000 : 500);
            $out[$col['key']] = $v;
            $any = $any || $v !== '';
        }

        return $any ? $out : null;
    }

    private function fingerprints(SiteMeeting $meeting): array
    {
        return $meeting->rows()->get()->groupBy('section_key')->map(fn ($rows) => md5(json_encode($rows->pluck('data')->all())))->all();
    }

    /** Earlier meetings (any site the user can see, same site first) whose minutes can be copied into this one. */
    public function sources(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meeting-reports');

        // a Super Admin may reuse any meeting; everybody else only their own projects' (or ones they prepared / wrote)
        return SiteMeeting::query()->visibleTo($user)->workableBy($user)->where('site_meetings.id', '!=', $meeting->id)
            ->whereIn('site_meetings.id', SiteMeetingRow::query()->select('site_meeting_id'))
            ->with('warehouse:id,name')
            ->orderByRaw('site_meetings.warehouse_id = ? DESC', [$meeting->warehouse_id])->orderByDesc('meeting_date')->limit(40)->get()
            ->map(fn ($m) => [
                'value' => $m->id,
                'label' => $m->reference.' · '.$m->display_title.' · '.$m->meeting_date->format('d M Y').((int) $m->warehouse_id !== (int) $meeting->warehouse_id ? ' · '.optional($m->warehouse)->name : ''),
                'same_site' => (int) $m->warehouse_id === (int) $meeting->warehouse_id,
            ])->values();
    }

    /**
     * Fills this meeting's minutes from another meeting's. "replace" takes everything (its layout and every row); "fill" only
     * completes the sections that are still empty here and exist in the other meeting, keeping what has been written.
     */
    public function copyFrom(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meeting-reports');
        abort_if($meeting->trashed() || $meeting->reportLocked(), 422, __('The minutes are finalised. Ask someone allowed to reopen them.'));
        abort_if($meeting->status === SiteMeeting::CANCELLED, 422, __('This meeting was cancelled.'));
        $data = $request->validate(['source_id' => ['required', 'integer'], 'mode' => ['required', 'in:replace,fill']]);

        $source = SiteMeeting::query()->find($data['source_id']);
        abort_unless($source && $source->account_id == $user->account_id && $source->isVisibleTo($user) && $source->canWorkBy($user) && (int) $source->id !== (int) $meeting->id, 404);
        abort_unless($source->rows()->exists(), 422, __('That meeting has no minutes to copy.'));

        DB::transaction(function () use ($meeting, $source, $data, $user) {
            if ($data['mode'] === 'replace') {
                $meeting->rows()->delete();
                foreach ($source->rows()->get() as $row) {
                    SiteMeetingRow::create(['site_meeting_id' => $meeting->id, 'section_key' => $row->section_key, 'sort' => $row->sort, 'data' => $row->data]);
                }
                $meeting->template = $source->template;
            } else {
                $mine = $meeting->rows()->get()->groupBy('section_key');
                $has = fn ($rows) => collect($rows)->contains(fn ($r) => collect($r->data)->except('_group')->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty());
                foreach ($source->rows()->get()->groupBy('section_key') as $key => $rows) {
                    if (! collect($this->sectionsOf($meeting))->contains('key', $key) || $has($mine[$key] ?? [])) {
                        continue;
                    }
                    $meeting->rows()->where('section_key', $key)->delete();
                    foreach ($rows as $row) {
                        SiteMeetingRow::create(['site_meeting_id' => $meeting->id, 'section_key' => $key, 'sort' => $row->sort, 'data' => $row->data]);
                    }
                }
            }
            $meeting->forceFill([
                'report_status' => $meeting->report_status === SiteMeeting::REPORT_NONE ? SiteMeeting::REPORT_DRAFT : $meeting->report_status,
                'report_started_at' => $meeting->report_started_at ?: now(), 'report_started_by' => $meeting->report_started_by ?: $user->id, 'updated_by' => $user->id,
            ])->save();
        });

        SiteMeetingLogger::log($user, $meeting, 'REPORT_UPDATE', null, ['copied_from' => $source->reference, 'mode' => $data['mode'], 'changes' => ['minutes' => ['from' => 'own content', 'to' => 'copied from '.$source->reference.' ('.$data['mode'].')']]]);

        SiteMeetingNotifier::minutesSaved($meeting, 'copied from '.$source->reference, $user);

        return redirect()->route('site-meetings.report', $meeting->id)->with('message', __('Minutes copied from :ref.', ['ref' => $source->reference]));
    }

    public function submit(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'update-site-meeting-reports');
        abort_unless(in_array($meeting->report_status, [SiteMeeting::REPORT_DRAFT, SiteMeeting::REPORT_NONE], true), 422, __('The minutes are already submitted.'));

        $meeting->forceFill(['report_status' => SiteMeeting::REPORT_SUBMITTED, 'report_submitted_at' => now(), 'report_submitted_by' => $user->id, 'updated_by' => $user->id])->save();
        SiteMeetingLogger::log($user, $meeting, 'REPORT_SUBMIT');
        SiteMeetingNotifier::report($meeting, 'submitted', $user);

        return back()->with('message', __('Minutes submitted.'));
    }

    public function finalise(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'finalise-site-meeting-reports');
        abort_if($meeting->reportLocked(), 422, __('The minutes are already finalised.'));
        abort_if($meeting->report_status === SiteMeeting::REPORT_NONE, 422, __('Nothing has been recorded yet.'));
        // Signatories: refuse the final step when an administrator made a signature required and it is missing (inert until switched on).
        if ($refusal = \App\Services\Signatories\Documents\StockSigningGuard::refusal('site_meeting_minutes', $meeting, ['report_status' => SiteMeeting::REPORT_FINAL, 'report_finalised_at' => now(), 'report_finalised_by' => $user->id], __('finalised'))) {
            return back()->with('error', $refusal);
        }

        $meeting->forceFill([
            'report_status' => SiteMeeting::REPORT_FINAL, 'report_finalised_at' => now(), 'report_finalised_by' => $user->id,
            'status' => $meeting->status === SiteMeeting::SCHEDULED ? SiteMeeting::HELD : $meeting->status, 'updated_by' => $user->id,
        ])->save();
        SiteMeetingLogger::log($user, $meeting, 'REPORT_FINALISE');
        SiteMeetingNotifier::report($meeting, 'finalised', $user);

        return back()->with('message', __('Minutes finalised and locked.'));
    }

    public function reopen(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'finalise-site-meeting-reports');
        abort_unless($meeting->reportLocked(), 422, __('The minutes are not finalised.'));

        $meeting->forceFill(['report_status' => SiteMeeting::REPORT_DRAFT, 'report_finalised_at' => null, 'report_finalised_by' => null, 'updated_by' => $user->id])->save();
        \App\Services\Signatories\Documents\StockSigningGuard::forget('site_meeting_minutes', (int) $meeting->id);
        SiteMeetingLogger::log($user, $meeting, 'REPORT_REOPEN');
        SiteMeetingNotifier::report($meeting, 'reopened', $user);

        return back()->with('message', __('Minutes reopened for editing.'));
    }

    // ---- print / download ---------------------------------------------------------------------------------------------

    public function print(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'print-site-meetings');
        SiteMeetingLogger::log($user, $meeting, 'REPORT_PRINT');

        return view('site-meetings.report-print', $this->document($meeting, false));
    }

    public function pdf(Request $request, SiteMeeting $site_meeting)
    {
        $user = $request->user();
        $meeting = $this->meetingFor($site_meeting, $user, 'print-site-meetings');
        SiteMeetingLogger::log($user, $meeting, 'REPORT_DOWNLOAD');

        return Pdf::loadView('site-meetings.report-print', $this->document($meeting, true))->setPaper('a4', 'landscape')->download('site-meeting-minutes-'.$meeting->reference.'.pdf');
    }

    private function document(SiteMeeting $meeting, bool $isPdf): array
    {
        $user = auth()->user();
        $s = $this->settings($user);
        $meeting->load(['warehouse', 'chairperson:id,name', 'recorder:id,name', 'creator:id,name', 'attendees']);

        return [
            'meeting' => $meeting, 'isPdf' => $isPdf, 'companyName' => optional($user->account)->name, 'printedBy' => $user->name, 'printedAt' => now(), 'styles' => $s->styles(),
            'sections' => $this->sectionsOf($meeting), 'rows' => collect($this->rowsOf($meeting))->map(fn ($rs) => collect($rs)->map(fn ($r) => (array) $r['data'])->all())->all(),
            'sign' => ['left' => $s->get('sign_left'), 'right' => $s->get('sign_right')],
            'qr' => \App\Support\PrintQrCode::dataUri(route('site-meetings.show', $meeting->id)),
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('site_meeting_minutes', $meeting),
        ];
    }
}
