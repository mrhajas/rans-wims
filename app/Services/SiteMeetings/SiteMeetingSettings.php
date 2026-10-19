<?php

namespace App\Services\SiteMeetings;

use App\Models\Setting;

/**
 * Everything adjustable about Site Meetings is a setting, never a constant: the week layout, default times, reminder
 * behaviour, the wording / colours of the statuses and — most importantly — the layout of the minutes document
 * (sections, columns and starting rows). Stored in the normal `settings` table under a `site_meetings_` prefix.
 */
class SiteMeetingSettings
{
    public const PREFIX = 'site_meetings_';

    public const DEFAULTS = [
        'week_start' => 1,                  // 1 = Monday … 0 = Sunday
        'weekdays' => '1,2,3,4,5,6,0',      // days shown on the weekly board
        'default_start_time' => '09:00',
        'default_duration_min' => 90,
        'default_venue' => 'Site office',
        'title_prefix' => 'Management Meeting',
        'repeat_max_weeks' => 12,
        'reminders_enabled' => 1,
        'reminder_days_before' => 1,
        'notify_on_schedule' => 1,
        'notify_on_change' => 1,
        'notify_on_report' => 1,
        'send_email' => 0,
        'history_view_minutes' => 30,       // the same person opening a record again within this time is counted as one view
        'sign_left' => 'Prepared by (Site Engineer)',
        'sign_right' => 'Chaired by',
    ];

    /**
     * Every notification Site Meetings can send, with who gets it by default. Each one is switched per channel (bell, SMS, e-mail)
     * and per audience in Settings → Notifications. `who` groups: invited, chairperson, recorder, preparer, site_team.
     */
    public const EVENTS = [
        'scheduled' => ['label' => 'Meeting scheduled', 'hint' => 'A meeting is scheduled and people are invited.', 'who' => ['invited', 'chairperson', 'recorder']],
        'changed' => ['label' => 'Meeting changed or moved', 'hint' => 'The date, time, site or venue changes, also when it is dragged to another day.', 'who' => ['invited', 'chairperson', 'recorder']],
        'status' => ['label' => 'Held / postponed / cancelled', 'hint' => 'The status of a meeting changes.', 'who' => ['invited', 'chairperson', 'recorder']],
        'deleted' => ['label' => 'Meeting deleted', 'hint' => 'A meeting is deleted.', 'who' => ['invited', 'chairperson', 'recorder']],
        'minutes_submitted' => ['label' => 'Minutes submitted', 'hint' => 'The site engineer submits the minutes.', 'who' => ['chairperson', 'recorder', 'preparer']],
        'minutes_finalised' => ['label' => 'Minutes finalised', 'hint' => 'The minutes are finalised and locked.', 'who' => ['invited', 'chairperson', 'recorder', 'preparer']],
        'minutes_reopened' => ['label' => 'Minutes reopened', 'hint' => 'Finalised minutes are reopened for a correction.', 'who' => ['chairperson', 'recorder', 'preparer']],
        'restored' => ['label' => 'Meeting restored', 'hint' => 'A deleted meeting is brought back.', 'who' => ['invited', 'chairperson', 'recorder']],
        'minutes_saved' => ['label' => 'Minutes saved', 'hint' => 'The minutes are saved with changes (draft or copied from an earlier meeting).', 'who' => ['chairperson', 'recorder', 'preparer']],
        'attachment_added' => ['label' => 'Attachment added', 'hint' => 'A file is attached to an existing meeting.', 'who' => ['chairperson', 'recorder', 'preparer']],
        'attachment_removed' => ['label' => 'Attachment removed', 'hint' => 'A file is removed from a meeting.', 'who' => ['chairperson', 'recorder', 'preparer']],
        'reminder' => ['label' => 'Reminder', 'hint' => 'Sent on the day of the meeting and the chosen number of days before.', 'who' => ['invited', 'chairperson', 'recorder']],
    ];

    public const WHO = [
        'invited' => 'Invited people',
        'chairperson' => 'Chairperson',
        'recorder' => 'Minutes writer',
        'preparer' => 'Who prepared it',
        'site_team' => 'Everyone assigned to the site',
    ];

    /** Status keys are fixed (the workflow depends on them); the wording and colour are settings. */
    public const STATUS_KEYS = ['Scheduled', 'Held', 'Postponed', 'Cancelled'];

    public const REPORT_KEYS = ['Not started', 'Draft', 'Submitted', 'Finalised'];

    public const STATUS_STYLE = [
        'Scheduled' => ['label' => 'Scheduled', 'color' => 'sky'],
        'Held' => ['label' => 'Held', 'color' => 'green'],
        'Postponed' => ['label' => 'Postponed', 'color' => 'amber'],
        'Cancelled' => ['label' => 'Cancelled', 'color' => 'red'],
        'Not started' => ['label' => 'Report not started', 'color' => 'slate'],
        'Draft' => ['label' => 'Report draft', 'color' => 'amber'],
        'Submitted' => ['label' => 'Report submitted', 'color' => 'indigo'],
        'Finalised' => ['label' => 'Report finalised', 'color' => 'green'],
    ];

    public const COLORS = ['sky', 'green', 'amber', 'red', 'indigo', 'violet', 'slate', 'teal', 'rose'];

    public const COLUMN_TYPES = ['text', 'textarea', 'date', 'number', 'percent', 'select'];

    private ?array $cache = null;

    private ?int $accountId = null;

    public function forAccount(?int $accountId): self
    {
        $clone = clone $this;
        $clone->accountId = $accountId;
        $clone->cache = null;

        return $clone;
    }

    private function account(): int
    {
        return (int) ($this->accountId ?? optional(auth()->user())->account_id ?? getAccountId(1));
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = Setting::query()->where('account_id', $this->account())->where('tec_key', 'like', self::PREFIX.'%')->pluck('tec_value', 'tec_key');
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $raw = $rows[self::PREFIX.$key] ?? null;
            $out[$key] = $raw === null ? $default : (is_int($default) ? (int) $raw : (string) $raw);
        }

        return $this->cache = $out;
    }

    public function get(string $key)
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function flag(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function set(string $key, $value): void
    {
        Setting::updateOrCreate(['tec_key' => self::PREFIX.$key, 'account_id' => $this->account()], ['tec_value' => is_array($value) ? json_encode($value) : (string) $value]);
        $this->cache = null;
    }

    private function json(string $key, $fallback)
    {
        $raw = Setting::query()->where('account_id', $this->account())->where('tec_key', self::PREFIX.$key)->value('tec_value');
        $decoded = $raw ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : $fallback;
    }

    /** Days (0–6) shown on the board, in week order starting from `week_start`. */
    public function weekdays(): array
    {
        $shown = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->get('weekdays'))), fn ($d) => $d >= 0 && $d <= 6)));
        $shown = $shown ?: [1, 2, 3, 4, 5, 6, 0];
        $start = (int) $this->get('week_start') % 7;
        $order = [];
        for ($i = 0; $i < 7; $i++) {
            $order[] = ($start + $i) % 7;
        }

        return array_values(array_filter($order, fn ($d) => in_array($d, $shown, true)));
    }

    // ---- the people of the meeting each notice is for ----------------------------------------------------------------

    /**
     * For every notice, which people of the meeting it is for (invited, chairperson, minutes writer, who prepared it, the site team):
     * the saved choice, or the starting one. The channels, extra roles / people and wording of each notice are set on the Site
     * Meetings workflow page.
     */
    public function rules(): array
    {
        $saved = $this->json('notification_rules', []);
        $out = [];
        foreach (self::EVENTS as $key => $def) {
            $r = is_array($saved[$key] ?? null) ? $saved[$key] : null;
            $out[$key] = ['who' => $r ? array_values(array_intersect(array_keys(self::WHO), (array) ($r['who'] ?? []))) : $def['who']];
        }

        return $out;
    }

    /** Saves the "people of the meeting" choice per notice. The older channel / role / person keys of this setting were copied to the workflow page and are kept as they were. */
    public function saveRules(array $input): array
    {
        $saved = $this->json('notification_rules', []);
        $clean = [];
        foreach (self::EVENTS as $key => $def) {
            $r = (array) ($input[$key] ?? []);
            $clean[$key] = (is_array($saved[$key] ?? null) ? $saved[$key] : []) + [];
            $clean[$key]['who'] = array_values(array_intersect(array_keys(self::WHO), array_map('strval', (array) ($r['who'] ?? []))));
        }
        $this->set('notification_rules', $clean);

        return $this->rules();
    }

    // ---- status wording / colours ----------------------------------------------------------------------------------

    public function styles(): array
    {
        $custom = $this->json('status_styles', []);
        $out = [];
        foreach (self::STATUS_STYLE as $key => $def) {
            $c = $custom[$key] ?? [];
            $color = in_array($c['color'] ?? null, self::COLORS, true) ? $c['color'] : $def['color'];
            $label = trim((string) ($c['label'] ?? '')) ?: $def['label'];
            $out[$key] = ['label' => mb_substr($label, 0, 40), 'color' => $color];
        }

        return $out;
    }

    public function saveStyles(array $input): void
    {
        $clean = [];
        foreach (self::STATUS_STYLE as $key => $def) {
            $c = $input[$key] ?? [];
            $clean[$key] = [
                'label' => mb_substr(trim((string) ($c['label'] ?? '')) ?: $def['label'], 0, 40),
                'color' => in_array($c['color'] ?? null, self::COLORS, true) ? $c['color'] : $def['color'],
            ];
        }
        $this->set('status_styles', $clean);
    }

    // ---- the minutes document layout ---------------------------------------------------------------------------------

    /** The layout in use: the saved one, or the WIMS version of the RANS "Management Meeting" form. */
    public function template(): array
    {
        return $this->normalize($this->json('template', null) ?: self::defaultTemplate());
    }

    public function saveTemplate(array $sections): array
    {
        $clean = $this->normalize($sections);
        $this->set('template', $clean);

        return $clean;
    }

    public function resetTemplate(): array
    {
        Setting::query()->where('account_id', $this->account())->where('tec_key', self::PREFIX.'template')->delete();
        $this->cache = null;

        return $this->template();
    }

    /** Makes any submitted / stored layout safe: known column types, unique keys, bounded sizes. */
    public function normalize(array $sections): array
    {
        $out = [];
        $usedKeys = [];
        foreach (array_slice(array_values($sections), 0, 30) as $i => $s) {
            if (! is_array($s)) {
                continue;
            }
            $title = mb_substr(trim((string) ($s['title'] ?? '')), 0, 120);
            if ($title === '') {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($s['key'] ?? ''))) ?: (substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($title)), '_'), 0, 40) ?: 'section_'.($i + 1));
            while (in_array($key, $usedKeys, true)) {
                $key .= '_'.($i + 1);
            }
            $usedKeys[] = $key;
            $type = ($s['type'] ?? 'table') === 'text' ? 'text' : 'table';

            $columns = [];
            $colKeys = [];
            if ($type === 'table') {
                foreach (array_slice(array_values($s['columns'] ?? []), 0, 12) as $j => $c) {
                    $label = mb_substr(trim((string) ($c['label'] ?? '')), 0, 80);
                    if ($label === '') {
                        continue;
                    }
                    $ck = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($c['key'] ?? ''))) ?: (substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_'), 0, 40) ?: 'col_'.($j + 1));
                    while (in_array($ck, $colKeys, true)) {
                        $ck .= '_'.($j + 1);
                    }
                    $colKeys[] = $ck;
                    $ctype = in_array($c['type'] ?? 'text', self::COLUMN_TYPES, true) ? $c['type'] : 'text';
                    $col = ['key' => $ck, 'label' => $label, 'type' => $ctype, 'width' => max(0, min(60, (int) ($c['width'] ?? 0)))];
                    if ($ctype === 'select') {
                        $opts = is_array($c['options'] ?? null) ? $c['options'] : preg_split('/\R|,/', (string) ($c['options'] ?? ''));
                        $col['options'] = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 60), $opts)));
                    }
                    $columns[] = $col;
                }
                if (! $columns) {
                    $columns = [['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => 0]];
                    $colKeys = ['description'];
                }
            }

            $rows = [];
            if ($type === 'table') {
                foreach (array_slice(array_values($s['default_rows'] ?? []), 0, 80) as $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    if (! empty($r['_group'])) {
                        $rows[] = ['_group' => mb_substr(trim((string) $r['_group']), 0, 160)];
                        continue;
                    }
                    $row = [];
                    foreach ($colKeys as $ck) {
                        $row[$ck] = mb_substr(trim((string) ($r[$ck] ?? '')), 0, 500);
                    }
                    $rows[] = $row;
                }
            }

            $out[] = [
                'key' => $key,
                'title' => $title,
                'type' => $type,
                'icon' => preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($s['icon'] ?? 'doc-text'))) ?: 'doc-text',
                'color' => in_array($s['color'] ?? null, self::COLORS, true) ? $s['color'] : self::COLORS[$i % count(self::COLORS)],
                'enabled' => ! array_key_exists('enabled', $s) || (bool) $s['enabled'],
                'grouped' => $type === 'table' && ! empty($s['grouped']),
                'hint' => mb_substr(trim((string) ($s['hint'] ?? '')), 0, 200),
                'columns' => $columns,
                'default_rows' => $rows,
            ];
        }

        return $out;
    }

    /** The RANS "Management Meeting NN" form, section by section, as a WIMS layout. */
    public static function defaultTemplate(): array
    {
        $std = fn (array $items) => array_map(fn ($i) => ['item' => $i[0], 'responsible' => $i[1] ?? '', 'status' => $i[2] ?? ''], $items);
        $five = [
            ['key' => 'item', 'label' => 'Work description', 'type' => 'text', 'width' => 26],
            ['key' => 'responsible', 'label' => 'Responsible', 'type' => 'text', 'width' => 18],
            ['key' => 'status', 'label' => 'Current status', 'type' => 'text', 'width' => 20],
            ['key' => 'challenges', 'label' => 'Challenges / Proposal', 'type' => 'textarea', 'width' => 22],
            ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'width' => 14],
        ];

        return [
            [
                'key' => 'site_organisation', 'title' => 'Site organisation', 'type' => 'table', 'icon' => 'users', 'color' => 'sky', 'enabled' => true, 'grouped' => false,
                'hint' => 'Who holds each site role right now, what they are working on and the challenges they raise.',
                'columns' => [
                    ['key' => 'role', 'label' => 'Role', 'type' => 'text', 'width' => 22],
                    ['key' => 'name', 'label' => 'Name (current status)', 'type' => 'text', 'width' => 26],
                    ['key' => 'challenges', 'label' => 'Challenges / Proposal', 'type' => 'textarea', 'width' => 32],
                    ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'width' => 20],
                ],
                'default_rows' => array_map(fn ($r) => ['role' => $r, 'name' => '', 'challenges' => '', 'remarks' => ''], ['Project Manager', 'Site Manager / Site Engineer', 'Site Foreman', 'Technical Manager', 'Quantity Surveyors', 'Sectional Foreman', 'Storekeeper', 'Safety Officer']),
            ],
            [
                'key' => 'site_administration', 'title' => 'Site administration', 'type' => 'table', 'icon' => 'collection', 'color' => 'violet', 'enabled' => true, 'grouped' => false,
                'hint' => 'Books, records and routines kept on site.',
                'columns' => $five,
                'default_rows' => $std([['Store record', 'Proc Dept / Store keeper'], ['Work schedule', 'Site Manager / Site Engineer'], ['Material request', 'Site Manager / Site Engineer'], ['Material issuing', 'Site Manager / Store keeper'], ['Labour request', 'Site Manager / Site Engineer'], ['Site attendance book', 'Site Manager / Site Security'], ['Site data', "Eng's"], ['Safety gears', 'Site manager'], ['Delivery logistics', 'Proc Dept / Store keeper']]),
            ],
            [
                'key' => 'authority_compliance', 'title' => 'Authority & compliance', 'type' => 'table', 'icon' => 'key', 'color' => 'teal', 'enabled' => true, 'grouped' => false,
                'hint' => 'Permits, registrations and approvals needed on this site.',
                'columns' => $five,
                'default_rows' => $std([['Building permit', 'Site Manager / Site Engineer'], ['ZCRB', 'Site Manager / Site Engineer'], ['ZECO', 'Site Manager / Site Engineer'], ['ZAWA', 'Site Manager / Site Engineer'], ['OSHA', 'Site Manager / Site Engineer'], ['Insurance', 'Site Manager / Site Engineer']]),
            ],
            [
                'key' => 'health_safety', 'title' => 'Health & safety compliance', 'type' => 'table', 'icon' => 'exclamation', 'color' => 'rose', 'enabled' => true, 'grouped' => false,
                'hint' => 'Accidents, incidents, toolbox talks and safety observations.',
                'columns' => $five,
                'default_rows' => $std([['Accidents / incidents', 'Site manager']]),
            ],
            [
                'key' => 'visitors', 'title' => 'Visitors', 'type' => 'table', 'icon' => 'user', 'color' => 'amber', 'enabled' => true, 'grouped' => false,
                'hint' => 'Everyone who visited the site since the last meeting.',
                'columns' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'width' => 24],
                    ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'width' => 14],
                    ['key' => 'organisation', 'label' => 'Organisation name & address', 'type' => 'text', 'width' => 26],
                    ['key' => 'purpose', 'label' => 'Purpose', 'type' => 'text', 'width' => 20],
                    ['key' => 'comments', 'label' => 'Comments', 'type' => 'textarea', 'width' => 16],
                ],
                'default_rows' => [],
            ],
            [
                'key' => 'testing_samples', 'title' => 'Testing / samples', 'type' => 'table', 'icon' => 'chart-pie', 'color' => 'indigo', 'enabled' => true, 'grouped' => false,
                'hint' => 'Concrete cubes and other samples: when cast, when crushed or tested and the result.',
                'columns' => [
                    ['key' => 'test', 'label' => 'Test / sample', 'type' => 'text', 'width' => 14],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => 20],
                    ['key' => 'cast_date', 'label' => 'Casting date', 'type' => 'date', 'width' => 11],
                    ['key' => 'test_date', 'label' => 'Crushing / testing date', 'type' => 'date', 'width' => 11],
                    ['key' => 'strength_7', 'label' => 'Average strength - 7 days', 'type' => 'text', 'width' => 11],
                    ['key' => 'strength_28', 'label' => 'Average strength - 28 days', 'type' => 'text', 'width' => 11],
                    ['key' => 'remark', 'label' => 'Remark', 'type' => 'textarea', 'width' => 22],
                ],
                'default_rows' => [],
            ],
            [
                'key' => 'details_approval', 'title' => 'Details / approval', 'type' => 'text', 'icon' => 'tick', 'color' => 'green', 'enabled' => true, 'grouped' => false,
                'hint' => 'Details discussed and approvals given in the meeting.', 'columns' => [], 'default_rows' => [],
            ],
            [
                'key' => 'program_of_works', 'title' => 'Program of works', 'type' => 'text', 'icon' => 'calendar', 'color' => 'slate', 'enabled' => true, 'grouped' => false,
                'hint' => 'Status of the program of works (Project Manager).', 'columns' => [], 'default_rows' => [],
            ],
            [
                'key' => 'weekly_schedule', 'title' => 'Weekly work schedule', 'type' => 'table', 'icon' => 'chart-bar', 'color' => 'sky', 'enabled' => true, 'grouped' => true,
                'hint' => 'Progress of each activity: where it was, where it is now and when it will be done. Add headings (Mobilisation, Substructure…) to group the activities.',
                'columns' => [
                    ['key' => 'description', 'label' => 'Work description', 'type' => 'text', 'width' => 34],
                    ['key' => 'responsible', 'label' => 'Responsible', 'type' => 'text', 'width' => 16],
                    ['key' => 'previous', 'label' => 'Previous progress (%)', 'type' => 'percent', 'width' => 14],
                    ['key' => 'current', 'label' => 'Current progress (% & date)', 'type' => 'text', 'width' => 18],
                    ['key' => 'anticipation', 'label' => 'Anticipation (% & date)', 'type' => 'text', 'width' => 18],
                ],
                'default_rows' => [['_group' => 'Mobilisation'], ['_group' => 'Substructure']],
            ],
        ];
    }
}
