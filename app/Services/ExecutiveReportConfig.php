<?php

namespace App\Services;

use App\Models\Setting;

/**
 * What goes into the Executive Intelligence Report. Stored as JSON in the settings table (per account) and edited at
 * Executive Report > Report Content: which sections appear, which transaction types are counted, which projects and items
 * the figures cover, and how many rows each list shows. Nothing about the report's content is hard-wired any more.
 */
class ExecutiveReportConfig
{
    public const KEY = 'executive_report_config';

    /** Sections the person can switch on or off, grouped for the settings screen: key => [label, hint] */
    public const GROUPS = [
        'Briefing' => [
            'summary' => ['Executive summary', 'The written summary paragraph at the top (AI-written when AI is set up).'],
            'notable_events' => ['Notable events', 'The short list of things that most need attention.'],
            'actions' => ['Recommended actions', 'The decision-ready follow-ups.'],
        ],
        'Key numbers' => [
            'kpi_inventory' => ['Total inventory value', 'Value of all stock in the chosen projects.'],
            'kpi_consumption' => ['Consumption this week', 'Value of materials checked out this week against last week.'],
            'kpi_projects' => ['Active projects', 'How many projects or warehouses are active.'],
            'kpi_month' => ['Month to date', 'Consumption so far this month against the same point last month.'],
        ],
        'Stock movements' => [
            'tx_table' => ['Transactions table', 'This week against last week for each type below.'],
        ],
        'Projects and materials' => [
            'top_projects' => ['Top consuming projects', 'The projects that used the most material this week.'],
            'portfolio' => ['Project portfolio overview', 'Every active project with its stock value and completion.'],
            'top_materials' => ['Top used materials', 'The materials checked out the most this week.'],
        ],
        'Workflow' => [
            'material_requests' => ['Material requests pipeline', 'Open requests by status and those stuck.'],
            'reallocations' => ['Material reallocations pipeline', 'Open reallocations by status and those stuck.'],
            'dispatch' => ['Dispatch and logistics', 'Reallocations being prepared or on the road.'],
        ],
        'Site and compliance' => [
            'site_reports' => ['Daily site report compliance', 'Who submitted and which sites missed their report.'],
            'boq' => ['BOQ budget variance', 'Projects that used more than their bill of quantities.'],
        ],
        'Stock health' => [
            'adjustments' => ['Stock and discrepancy watch', 'Unusual damage adjustments.'],
            'stock_health' => ['Out-of-stock items', 'Items in active use that have run out.'],
        ],
        'Attachments' => [
            'inventory_pdf' => ['Attach the Total Inventory Report (PDF)', 'The full stock listing sent with the email.'],
        ],
    ];

    public const TRANSACTIONS = ['checkins' => 'Check-ins', 'checkouts' => 'Check-outs', 'transfers' => 'Transfers', 'adjustments' => 'Adjustments'];

    public const DEFAULTS = [
        'transactions' => ['checkins', 'checkouts', 'transfers', 'adjustments'],
        'projects' => ['mode' => 'all', 'ids' => []],
        'items' => ['mode' => 'all', 'ids' => []],
        'limits' => ['top_projects' => 3, 'top_materials' => 5, 'stale_days' => 5, 'critical_items' => 8, 'boq_projects' => 5],
    ];

    private array $c;

    private function __construct(array $c)
    {
        $this->c = $c;
    }

    public static function forAccount(int $accountId): self
    {
        $saved = [];
        $raw = Setting::query()->where('tec_key', self::KEY)->where('account_id', $accountId)->value('tec_value');
        if (is_string($raw)) {
            $saved = json_decode($raw, true) ?: [];
        }

        return self::make($saved);
    }

    public static function make(array $saved): self
    {
        $sections = [];
        foreach (self::GROUPS as $items) {
            foreach (array_keys($items) as $k) {
                $sections[$k] = array_key_exists($k, $saved['sections'] ?? []) ? (bool) $saved['sections'][$k] : true;
            }
        }
        $c = [
            'sections' => $sections,
            'transactions' => array_values(array_intersect(array_keys(self::TRANSACTIONS), (array) ($saved['transactions'] ?? self::DEFAULTS['transactions']))),
            'projects' => ['mode' => ($saved['projects']['mode'] ?? 'all') === 'selected' ? 'selected' : 'all', 'ids' => array_values(array_unique(array_map('intval', (array) ($saved['projects']['ids'] ?? []))))],
            'items' => ['mode' => ($saved['items']['mode'] ?? 'all') === 'selected' ? 'selected' : 'all', 'ids' => array_values(array_unique(array_map('intval', (array) ($saved['items']['ids'] ?? []))))],
            'limits' => [],
        ];
        foreach (self::DEFAULTS['limits'] as $k => $d) {
            $c['limits'][$k] = max(1, min(50, (int) ($saved['limits'][$k] ?? $d)));
        }

        return new self($c);
    }

    public static function save(array $input, int $accountId): self
    {
        $cfg = self::make($input);
        Setting::updateOrCreate(['tec_key' => self::KEY, 'account_id' => $accountId], ['tec_value' => json_encode($cfg->toArray())]);

        return $cfg;
    }

    public function toArray(): array
    {
        return $this->c;
    }

    public function section(string $key): bool
    {
        return $this->c['sections'][$key] ?? true;
    }

    /** @return list<int>|null null = every project */
    public function projectIds(): ?array
    {
        return $this->c['projects']['mode'] === 'selected' ? ($this->c['projects']['ids'] ?: [0]) : null;
    }

    /** @return list<int>|null null = every item */
    public function itemIds(): ?array
    {
        return $this->c['items']['mode'] === 'selected' ? ($this->c['items']['ids'] ?: [0]) : null;
    }

    /** @return list<string> */
    public function transactions(): array
    {
        return $this->c['transactions'];
    }

    public function limit(string $key): int
    {
        return $this->c['limits'][$key] ?? self::DEFAULTS['limits'][$key];
    }

    /** One line per switched-off section, for the AI so it does not talk about what the report leaves out. */
    public function excludedLabels(): array
    {
        $out = [];
        foreach (self::GROUPS as $items) {
            foreach ($items as $k => [$label]) {
                if (! $this->section($k)) {
                    $out[] = $label;
                }
            }
        }

        return $out;
    }

    /** Removes the switched-off sections from the computed data so nothing downstream (summary, templates) can show them. */
    public function scrub(array $metrics, array $insights): array
    {
        $drop = fn (array &$a, array $keys) => array_walk($keys, function ($k) use (&$a) { unset($a[$k]); });
        $m = $metrics;
        $i = $insights;
        if (! $this->section('kpi_inventory')) { $drop($m, ['total_inventory_value']); }
        if (! $this->section('kpi_consumption')) { $drop($m, ['consumption_value']); }
        if (! $this->section('kpi_projects')) { $drop($m, ['active_projects']); }
        if (! $this->section('kpi_month')) { $drop($m, ['month_to_date']); }
        if (! $this->section('tx_table')) { $drop($m, ['transaction_counts']); }
        if (! $this->section('top_projects')) { $drop($m, ['top_consuming_projects']); }
        if (! $this->section('portfolio')) { $drop($m, ['portfolio_overview']); }
        if (! $this->section('top_materials')) { $drop($m, ['top_used_materials']); }
        foreach (['material_requests' => 'material_requests_pipeline', 'reallocations' => 'material_reallocations_pipeline', 'dispatch' => 'dispatch_in_transit', 'site_reports' => 'site_report_compliance', 'boq' => 'boq_variance_flags', 'adjustments' => 'adjustment_watch', 'stock_health' => 'stock_health'] as $section => $key) {
            if (! $this->section($section)) {
                $drop($i, [$key]);
            }
        }

        return [$m, $i];
    }
}
