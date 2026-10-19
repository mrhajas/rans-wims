<?php

namespace App\Support;

/**
 * Deterministic, non-AI front door for ARIS. Each intent reuses the exact
 * same tool handler AiToolRegistry hands to Claude — the only difference is
 * *how* we decide which one to call: plain regex/keyword matching here,
 * versus an LLM's judgement over there. A question that matches one of
 * these patterns never touches the Anthropic API at all.
 *
 * Patterns use named capture groups (?<item>...), (?<warehouse>...),
 * (?<user>...), (?<status>...) which `map()` turns into the same input
 * shape the tool handler already expects. `format()` turns the handler's
 * structured result into the same short, factual sentence style Claude is
 * instructed to use — see AiAssistantService::buildSystemPrompt().
 *
 * A question that matches no pattern here simply falls through to Claude
 * (see AiNativeQueryEngine::answer() returning null) — nothing is lost,
 * this is a pure fast-path in front of the existing AI path.
 */
class AiNativeIntentCatalog
{
    public static function intents(): array
    {
        return [

            // These four are matched before stock_lookup deliberately —
            // stock_lookup's "how (?:much|many) (?<item>.+?) do we have"
            // pattern is greedy enough to otherwise swallow "how many
            // active projects do we have" as a (bogus) item lookup.
            // Site Diary — answered only from what the asking user may open (see SiteDiaryTool).
            // WIMSChat — only the asking user's own conversations (see WimsChatTool).
            'wimschat_unread' => [
                'patterns' => [
                    '/(?:do i have|have i got|any|show|list)\s+(?:any\s+)?(?:unread\s+)?(?:wimschat|chat)\s+(?:messages?|chats?)(?:\s+unread)?\s*\??$/i',
                    '/(?:unread|new)\s+(?:wimschat|chat)\s+messages?\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\WimsChatTool::class, 'unread'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? (($r['total'] ?? 0) === 0 ? 'You have no unread WIMSChat messages.' : "You have {$r['total']} unread WIMSChat message(s): ".implode(', ', $r['conversations']).'.')
                    : ($r['message'] ?? null),
            ],
            'wimschat_search' => [
                'patterns' => [
                    '/(?:find|search|look for)\s+(?:wimschat|chat)\s+messages?\s+(?:about|for|with|containing)\s+(?<q>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => ['query' => trim($m['q'], " \"'")],
                'handler' => [\App\Services\AiTools\WimsChatTool::class, 'search'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? "Found {$r['count']} chat message(s) about \"{$r['query']}\": ".implode(' | ', $r['items'])
                    : ($r['message'] ?? 'No chat messages matched.'),
            ],
            'site_diary_today' => [
                'patterns' => [
                    '/(?:show|list|what are)\s+(?:me\s+)?(?:today\'?s|the\s+today\'?s)\s+site\s+diar(?:y|ies)(?:\s+(?:for|at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                    '/site\s+diar(?:y|ies)\s+(?:recorded\s+)?today(?:\s+(?:for|at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['project_or_warehouse_name' => isset($m['warehouse']) ? self::stripTrailingLabel($m['warehouse']) : null],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'todayList'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? ($r['count'] === 0 ? 'No site diaries have been recorded today.' : "{$r['count']} site diar".($r['count'] === 1 ? 'y' : 'ies').' today: '.implode('; ', $r['items']).'.')
                    : ($r['message'] ?? null),
            ],
            'site_diary_count' => [
                'patterns' => [
                    '/how many\s+site\s+diar(?:y|ies)\s+(?:were\s+|have been\s+|are\s+)?(?:recorded|submitted|logged|there)?\s*(?:for|in|at)\s+(?<warehouse>.+?)(?:\s+(?<period>this month|today))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['project_or_warehouse_name' => self::stripTrailingLabel($m['warehouse']), 'period' => isset($m['period']) ? (str_contains(strtolower($m['period']), 'month') ? 'month' : 'today') : null],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'count'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? "{$r['count']} site diar".($r['count'] === 1 ? 'y' : 'ies').' for '.($r['project'] ?? 'all projects').(($r['period'] ?? 'all') === 'month' ? ' this month' : (($r['period'] ?? '') === 'today' ? ' today' : '')).'.'
                    : ($r['message'] ?? null),
            ],
            'site_diary_latest_recorder' => [
                'patterns' => [
                    '/who\s+(?:recorded|submitted|made|wrote)\s+the\s+(?:latest|last|most recent)\s+(?:site\s+)?diary(?:\s+(?:for|at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['project_or_warehouse_name' => isset($m['warehouse']) ? self::stripTrailingLabel($m['warehouse']) : null],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'latestRecorder'],
                'format' => fn (array $r) => ($r['found'] ?? false) ? "{$r['by']} recorded the latest site diary ({$r['reference']}) for {$r['project']} on {$r['date']}." : ($r['message'] ?? null),
            ],
            'site_diary_photo_count' => [
                'patterns' => [
                    '/how many\s+photos?\s+(?:were\s+|have been\s+)?(?:uploaded|added|recorded|taken)?\s*(?:for|in|at|to)\s+(?<warehouse>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => ['project_or_warehouse_name' => self::stripTrailingLabel($m['warehouse'])],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'photoCount'],
                'format' => fn (array $r) => ($r['found'] ?? false) ? "{$r['photos']} photo(s) in the site diaries of ".($r['project'] ?? 'all projects').'.' : ($r['message'] ?? null),
            ],
            'site_diary_latest_remarks' => [
                'patterns' => [
                    '/(?:show|list|what are)\s+(?:me\s+)?the\s+(?:latest|last|recent)\s+site\s+(?:remarks?|diary remarks?)(?:\s+(?:for|at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['project_or_warehouse_name' => isset($m['warehouse']) ? self::stripTrailingLabel($m['warehouse']) : null],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'latestRemarks'],
                'format' => fn (array $r) => ($r['found'] ?? false) ? 'Latest site remarks: '.implode(' | ', $r['items']) : ($r['message'] ?? null),
            ],

            'active_projects_count' => [
                'patterns' => [
                    '/how many\s+(?:active\s+)?projects?\s+(?:do we have|are (?:there|active|running))\s*\??$/i',
                    '/(?:number of|count of)\s+active\s+projects?\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\DashboardStatsTool::class, 'activeProjectsCount'],
                'format' => fn (array $r) => "There are currently {$r['count']} active project(s).",
            ],

            'total_stock_value' => [
                'patterns' => [
                    '/(?:what(?:\'s| is))?\s*(?:the\s+)?total\s+(?:stock|inventory)\s+value(?:\s*(?:at|in|for)\s+(?<warehouse>.+?))?\s*\??$/i',
                    '/how much\s+is\s+(?:our\s+|the\s+)?(?:stock|inventory)\s+worth(?:\s*(?:at|in|for)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['warehouse_name' => isset($m['warehouse']) ? self::stripTrailingLabel($m['warehouse']) : null],
                'handler' => [\App\Services\AiTools\DashboardStatsTool::class, 'totalStockValue'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    return "{$r['scope']}: total stock value is " . self::formatQuantity($r['total_value']) . '.';
                },
            ],

            'top_consuming_projects' => [
                'patterns' => [
                    '/(?:which|what)\s+projects?\s+(?:used|consumed|is using|are using)\s+(?:the\s+)?most(?:.*)?$/i',
                    '/top\s+consuming\s+projects?(?:.*)?$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\DashboardStatsTool::class, 'topConsumingProjects'],
                'format' => function (array $r) {
                    if (($r['count'] ?? 0) === 0) {
                        return "No checkout activity in the last 7 days ({$r['period']}).";
                    }
                    $lines = collect($r['projects'])->map(fn ($p, $i) => ($i + 1) . ". {$p['warehouse']} - " . self::formatQuantity($p['value']));
                    return "Top consuming projects ({$r['period']}):\n" . $lines->implode("\n");
                },
            ],

            'top_used_materials' => [
                'patterns' => [
                    '/(?:which|what)\s+(?:material|item)s?\s+(?:(?:were|are)\s+)?(?:used|issued|consumed)\s+(?:the\s+)?most(?:.*)?$/i',
                    '/top\s+used\s+materials?(?:.*)?$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\DashboardStatsTool::class, 'topUsedMaterials'],
                'format' => function (array $r) {
                    if (($r['count'] ?? 0) === 0) {
                        return "No checkout activity in the last 7 days ({$r['period']}).";
                    }
                    $lines = collect($r['materials'])->map(fn ($m, $i) => ($i + 1) . ". {$m['item']} - " . self::formatQuantity($m['quantity']));
                    return "Top used materials ({$r['period']}):\n" . $lines->implode("\n");
                },
            ],

            'stock_lookup' => [
                'patterns' => [
                    '/how (?:much|many)\s+(?<item>.+?)\s+(?:do (?:we|i) have|(?:is|are) (?:there|left|available|remaining))(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                    '/(?<item>.+?)\s+(?:stock|balance|availability)(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                    '/stock\s+of\s+(?<item>.+?)(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['item_name' => $m['item'] ?? null, 'warehouse_name' => $m['warehouse'] ?? null],
                'handler' => [\App\Services\AiTools\StockTool::class, 'lookup'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    $unit = $r['unit'] ? ' ' . $r['unit'] : '';
                    return "{$r['warehouse']}: {$r['item']} - " . self::formatQuantity($r['quantity']) . "{$unit}.";
                },
            ],

            'low_stock_items' => [
                'patterns' => [
                    '/what(?:\'s| is)?\s+running low(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                    '/(?:low stock|low[- ]on[- ]stock)(?:\s+items?)?(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['warehouse_name' => $m['warehouse'] ?? null],
                'handler' => [\App\Services\AiTools\StockTool::class, 'lowStock'],
                'format' => function (array $r) {
                    if (($r['count'] ?? 0) === 0) {
                        return 'Nothing is currently below its alert quantity.';
                    }
                    $lines = collect($r['items'])->map(fn ($i) => "{$i['item']} ({$i['warehouse']}): " . self::formatQuantity($i['quantity']));
                    return "{$r['count']} item(s) running low:\n" . $lines->implode("\n");
                },
            ],

            'last_checkin' => [
                'patterns' => [
                    '/last checkin(?:.*?(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m, string $q) => [
                    'warehouse_name' => $m['warehouse'] ?? null,
                    'date' => self::hasToday($q) ? 'today' : null,
                ],
                'handler' => [\App\Services\AiTools\CheckinTool::class, 'last'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? "Last checkin ({$r['reference']}): {$r['item_count']} item(s) at {$r['warehouse']}, by {$r['performed_by']} on " . self::formatDate($r['date']) . '.'
                    : ($r['message'] ?? null),
            ],

            'last_checkout' => [
                'patterns' => [
                    '/last checkout(?:.*?(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m, string $q) => [
                    'warehouse_name' => $m['warehouse'] ?? null,
                    'date' => self::hasToday($q) ? 'today' : null,
                ],
                'handler' => [\App\Services\AiTools\CheckoutTool::class, 'last'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? "Last checkout ({$r['reference']}): {$r['item_count']} item(s) at {$r['warehouse']}, by {$r['performed_by']} on " . self::formatDate($r['date']) . '.'
                    : ($r['message'] ?? null),
            ],

            'last_transfer' => [
                'patterns' => [
                    '/last transfer(?:.*?(?:at|in|to|from)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m, string $q) => [
                    'warehouse_name' => $m['warehouse'] ?? null,
                    'date' => self::hasToday($q) ? 'today' : null,
                ],
                'handler' => [\App\Services\AiTools\TransferTool::class, 'last'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? "Last transfer ({$r['reference']}): {$r['from_warehouse']} → {$r['to_warehouse']}, {$r['item_count']} item(s), by {$r['performed_by']}."
                    : ($r['message'] ?? null),
            ],

            'last_adjustment' => [
                'patterns' => [
                    '/last (?:stock )?adjustment(?:.*?(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m, string $q) => [
                    'warehouse_name' => $m['warehouse'] ?? null,
                    'date' => self::hasToday($q) ? 'today' : null,
                ],
                'handler' => [\App\Services\AiTools\AdjustmentTool::class, 'last'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? "Last adjustment ({$r['reference']}, {$r['type']}): {$r['item_count']} item(s) at {$r['warehouse']}, by {$r['performed_by']}."
                    : ($r['message'] ?? null),
            ],

            'warehouse_info' => [
                'patterns' => [
                    '/(?:list|show)\s+(?:all\s+)?(?:our\s+)?(?:warehouses|projects)\s*\??$/i',
                    '/tell me about\s+(?<warehouse>.+?)\s*\??$/i',
                    '/(?:info|information|details)\s+(?:on|about|for)\s+(?<warehouse>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => ['warehouse_name' => isset($m['warehouse']) ? self::stripTrailingLabel($m['warehouse']) : null],
                'handler' => [\App\Services\AiTools\WarehouseTool::class, 'info'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    if (isset($r['warehouses'])) {
                        return "{$r['count']} project(s)/warehouse(s):\n" . collect($r['warehouses'])->pluck('name')->implode(', ');
                    }
                    return "{$r['name']} ({$r['code']}), status: {$r['status']}, " . ($r['active'] ? 'active' : 'inactive') . '.';
                },
            ],


            // Subcontractors: figures come from SubcontractLedger (see SubcontractTool); a payment counts only when its Payment Voucher is paid.
            'subcontract_summary' => [
                'patterns' => [
                    '/(?:how much|what)\s+(?:have we|did we|has been|is)\s+(?:paid|owed|outstanding|the contract(?: value)?)\s+(?:(?:to|for|of)\s+)?(?:subcontractor\s+)?(?<name>.+?)\s*\??$/i',
                    '/subcontract(?:or)?\s+(?:summary|balance|status|account)\s+(?:for|of)\s+(?<name>.+?)\s*\??$/i',
                    '/(?:contract value|outstanding balance|approved unpaid)\s+(?:for|of)\s+(?:subcontractor\s+)?(?<name>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => ['subcontractor_name' => trim($m['name'], " \"'")],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'summary'],
                'format' => fn (array $r) => ! ($r['found'] ?? false) ? ($r['message'] ?? null) : ($r['agreements'] === 0 ? "{$r['name']} has no agreements you may see." : "{$r['name']}: " . collect($r['totals'])->map(fn ($t, $c) => "{$c}: revised contract " . number_format((float) $t['revised'], 2) . ", approved work " . number_format((float) $t['approved_work'], 2) . ", actually paid " . number_format((float) $t['paid_total'], 2) . " (vouchers " . number_format((float) $t['paid_live'], 2) . " + historical " . number_format((float) $t['paid_legacy'], 2) . "), approved but unpaid " . number_format((float) $t['approved_unpaid'], 2) . ", outstanding contract commitment " . number_format((float) $t['commitment_left'], 2))->implode('; ') . '.'),
            ],
            'subcontract_pending' => [
                'patterns' => [
                    '/(?:what|which|how many)\s+(?:subcontract(?:or)?\s+)?(?:payment\s+)?requests?\s+(?:are\s+)?(?:waiting|pending|awaiting)(?:\s+(?:for\s+)?approval)?\s*\??$/i',
                    '/subcontract(?:or)?s?\s+(?:items\s+)?(?:waiting|pending|awaiting)\s+(?:for\s+)?approval\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'pending'],
                'format' => fn (array $r) => ($r['found'] ?? false) ? "Waiting for a decision: {$r['agreements']} agreement(s), {$r['variations']} variation(s), {$r['requests']} payment request(s); {$r['approved_unpaid_requests']} approved request(s) not fully paid yet." : ($r['message'] ?? null),
            ],
            'subcontract_claims_waiting' => [
                'patterns' => [
                    '/(?:which|what|how many|show|list)\s+(?:subcontract(?:or)?\s+)?(?:contract\s+)?(?:payment\s+)?(?:claims?|contract payment requests?)\s+(?:are\s+|is\s+)?(?:waiting|pending|awaiting)(?:\s+(?:for\s+)?(?:approval|a decision|decision))?\s*\??$/i',
                    '/(?:claims?|contract payment requests?)\s+(?:waiting|pending|awaiting)\s+(?:for\s+)?(?:approval|a decision|decision)\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'claimsWaiting'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    $t = $r['totals'] ? ' Requested: ' . collect($r['totals'])->map(fn ($v, $c) => $c . ' ' . number_format((float) $v, 2))->implode(', ') . '.' : '';

                    return $r['claims'] === 0 ? 'No contract payment request is waiting for a decision.' . ($r['returned_lines'] || $r['deferred_lines'] ? " ({$r['returned_lines']} line(s) returned, {$r['deferred_lines']} deferred.)" : '')
                        : "{$r['claims']} contract payment request(s) ({$r['lines']} line(s)) wait for a decision: " . implode('; ', $r['items']) . ".{$t}" . ($r['returned_lines'] || $r['deferred_lines'] ? " {$r['returned_lines']} line(s) returned, {$r['deferred_lines']} deferred." : '');
                },
            ],
            'subcontract_completed_unpaid' => [
                'patterns' => [
                    '/(?:which|what|how many|show|list)\s+(?:subcontract(?:or)?\s+)?(?:completed\s+)?(?:contracts?|agreements?)\s+(?:that\s+)?(?:are\s+)?(?:completed\s+)?(?:but\s+)?(?:not\s+(?:yet\s+)?(?:fully\s+)?paid|unpaid)\s*\??$/i',
                    '/completed\s+(?:subcontract(?:or)?\s+)?(?:contracts?|agreements?)\s+(?:that are\s+)?(?:not\s+(?:yet\s+)?(?:fully\s+)?paid|unpaid)\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'completedUnpaid'],
                'format' => fn (array $r) => ($r['found'] ?? false) ? ($r['eligible'] + $r['waiting_verification'] + $r['missing_evidence'] + $r['no_balance_open'] === 0 ? 'No completed subcontract agreement has an unpaid balance.'
                    : "Completed agreements not fully paid: {$r['eligible']} can be put on a contract payment request now, {$r['waiting_verification']} wait for the completion to be verified, {$r['missing_evidence']} need evidence documents, {$r['no_balance_open']} have everything already requested. Outstanding: " . collect($r['outstanding'])->map(fn ($v, $c) => $c . ' ' . number_format((float) $v, 2))->implode(', ') . '.') : ($r['message'] ?? null),
            ],
            'subcontract_unreconciled' => [
                'patterns' => [
                    '/(?:which|what|show|list)\s+(?:imported\s+)?(?:subcontract(?:or)?\s+)?accounts?\s+(?:are\s+|have\s+)?(?:un)?reconcil(?:ed|e)?(?:\s+balances?)?\s*\??$/i',
                    '/unreconciled\s+subcontract(?:or)?\s+(?:accounts?|balances?)\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'unreconciled'],
                'format' => fn (array $r) => ($r['found'] ?? false) ? (($r['count'] ?? 0) === 0 ? 'No imported subcontract account is waiting for reconciliation.' : "{$r['count']} imported account(s) wait for reconciliation: " . implode('; ', $r['items']) . '.') : ($r['message'] ?? null),
            ],

            'material_request_status' => [
                'patterns' => [
                    '/how many\s+(?:material )?requests?\s+(?:are\s+)?(?<status>pending(?:\s+approval)?|approved|rejected|issued|closed)(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                    '/material requests?\s+(?:pending|status)(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => [
                    'status' => isset($m['status']) ? self::normalizeRequestStatus($m['status']) : null,
                    'warehouse_name' => $m['warehouse'] ?? null,
                ],
                'handler' => [\App\Services\AiTools\MaterialRequestTool::class, 'statusSummary'],
                'format' => function (array $r) {
                    // statusSummary() returns two different shapes depending
                    // on whether a status filter was resolved: a single
                    // filtered list ('requests'/'count'), or an unfiltered
                    // breakdown ('counts_by_status').
                    if (array_key_exists('requests', $r)) {
                        if (($r['count'] ?? 0) === 0) {
                            return "No material requests are currently \"{$r['status_filter']}\".";
                        }
                        $refs = collect($r['requests'])->pluck('reference')->implode(', ');
                        return "{$r['count']} request(s) \"{$r['status_filter']}\": {$refs}.";
                    }

                    $counts = collect($r['counts_by_status'] ?? []);
                    if ($counts->isEmpty()) {
                        return 'No material requests found.';
                    }
                    return collect($counts)->map(fn ($c, $status) => "{$c} {$status}")->implode(', ') . '.';
                },
            ],

            'user_checkin_activity' => [
                'patterns' => [
                    '/did\s+(?<user>.+?)\s+(?:do\s+(?:any\s+)?)?check\s*ins?(?:\s+.*)?$/i',
                ],
                'map' => fn (array $m, string $q) => [
                    'user_name' => $m['user'] ?? null,
                    'date' => self::hasToday($q) ? 'today' : null,
                ],
                'handler' => [\App\Services\AiTools\CheckinTool::class, 'didUserAct'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    return $r['did_checkin']
                        ? "Yes - {$r['user']} did {$r['count']} checkin(s) on {$r['date']}."
                        : "No, {$r['user']} did not do any checkin on {$r['date']}.";
                },
            ],

            'user_checkout_activity' => [
                'patterns' => [
                    '/did\s+(?<user>.+?)\s+(?:do\s+(?:any\s+)?)?check\s*outs?(?:\s+.*)?$/i',
                ],
                'map' => fn (array $m, string $q) => [
                    'user_name' => $m['user'] ?? null,
                    'date' => self::hasToday($q) ? 'today' : null,
                ],
                'handler' => [\App\Services\AiTools\CheckoutTool::class, 'didUserAct'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    return $r['did_checkout']
                        ? "Yes - {$r['user']} did {$r['count']} checkout(s) on {$r['date']}."
                        : "No, {$r['user']} did not do any checkout on {$r['date']}.";
                },
            ],

            'user_last_login' => [
                'patterns' => [
                    '/when\s+(?:was|did)\s+(?<user>.+?)\s+(?:last\s+)?log(?:ged)?\s*in(?:.*)?$/i',
                ],
                'map' => fn (array $m) => ['user_name' => $m['user'] ?? null],
                'handler' => [\App\Services\AiTools\UserActivityTool::class, 'lastLogin'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? "{$r['user']} last logged in on {$r['last_login_at']}."
                    : ($r['message'] ?? null),
            ],

            'material_reallocation_status' => [
                'patterns' => [
                    '/material reallocations?\s+(?:status|summary)\s*\??$/i',
                    '/how many material reallocations?\s+are\s+(?<status>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => ['status' => $m['status'] ?? null],
                'handler' => [\App\Services\AiTools\MaterialReallocationTool::class, 'statusSummary'],
                'format' => function (array $r) {
                    if (array_key_exists('reallocations', $r)) {
                        if (($r['count'] ?? 0) === 0) {
                            return "No material reallocations are currently \"{$r['status_filter']}\".";
                        }
                        $refs = collect($r['reallocations'])->pluck('reference')->implode(', ');
                        return "{$r['count']} reallocation(s) \"{$r['status_filter']}\": {$refs}.";
                    }

                    $counts = collect($r['counts_by_status'] ?? []);
                    if ($counts->isEmpty()) {
                        return 'No material reallocations found.';
                    }
                    return collect($counts)->map(fn ($c, $status) => "{$c} {$status}")->implode(', ') . '.';
                },
            ],

            'daily_site_report_latest' => [
                'patterns' => [
                    '/latest daily site report\s+(?:for|at|in)\s+(?<warehouse>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => ['warehouse_name' => isset($m['warehouse']) ? self::stripTrailingLabel($m['warehouse']) : null],
                'handler' => [\App\Services\AiTools\DailySiteReportTool::class, 'latestForWarehouse'],
                'format' => fn (array $r) => ($r['found'] ?? false)
                    ? "Latest report for {$r['warehouse']}: {$r['report_date']}, status \"{$r['status']}\", submitted by {$r['submitted_by']}."
                    : ($r['message'] ?? null),
            ],

            'daily_site_report_missing_today' => [
                'patterns' => [
                    '/(?:which\s+)?sites?\s+(?:have\s+)?not\s+submitted.*$/i',
                    '/sites?\s+missing.*report.*$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\DailySiteReportTool::class, 'missingToday'],
                'format' => function (array $r) {
                    if (($r['count'] ?? 0) === 0) {
                        return "All sites have submitted today's daily report.";
                    }
                    return "{$r['count']} site(s) missing today's report: " . collect($r['warehouses'])->implode(', ') . '.';
                },
            ],

            'work_package_status' => [
                'patterns' => [
                    '/work packages?\s+status(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                    '/how many work packages?\s+are\s+.+?(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['warehouse_name' => $m['warehouse'] ?? null],
                'handler' => [\App\Services\AiTools\WorkPackageTool::class, 'statusSummary'],
                'format' => function (array $r) {
                    $counts = collect($r['counts_by_status'] ?? []);
                    if ($counts->isEmpty()) {
                        return "No work packages found for {$r['warehouse']}.";
                    }
                    return "{$r['warehouse']} - " . collect($counts)->map(fn ($c, $status) => "{$c} {$status}")->implode(', ') . '.';
                },
            ],

            'boq_item_status' => [
                'patterns' => [
                    '/(?:boq|budget)\s+status\s+(?:of|for)\s+(?<item>.+?)\s+(?:on|at|in|for)\s+(?<warehouse>.+?)\s*\??$/i',
                    '/(?:how much|how many)\s+(?<item>.+?)\s+(?:has|have)\s+(?:been\s+)?(?:issued|used)\s+(?:against|vs\.?|versus)\s+(?:the\s+)?boq\s+(?:on|at|in|for)\s+(?<warehouse>.+?)\s*\??$/i',
                    '/(?:boq|budget)\s+status\s+(?:on|at|in|for)\s+(?<warehouse>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => [
                    'warehouse_name' => isset($m['warehouse']) ? self::stripTrailingLabel($m['warehouse']) : null,
                    'item_name' => $m['item'] ?? null,
                ],
                'handler' => [\App\Services\AiTools\BoqTool::class, 'itemStatus'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    if (isset($r['item'])) {
                        $i = $r['item'];
                        return "{$r['project']} - {$i['item_name']}: issued " . self::formatQuantity($i['issued_quantity'])
                            . ' vs. BOQ ' . self::formatQuantity($i['boq_quantity']) . " ({$i['status']}).";
                    }
                    $counts = collect($r['status_summary'] ?? []);
                    if ($counts->isEmpty()) {
                        return "No BOQ data found for {$r['project']}.";
                    }
                    return "{$r['project']} BOQ status ({$r['count']} item(s)): " . $counts->map(fn ($c, $status) => "{$c} {$status}")->implode(', ') . '.';
                },
            ],

            'boq_over_budget' => [
                'patterns' => [
                    '/(?:what|which)\s+items?\s+(?:are\s+|is\s+)?(?:over|exceed(?:ing|ed)?|above)\s+(?:the\s+)?boq\s+(?:on|at|in|for)\s+(?<warehouse>.+?)\s*\??$/i',
                    '/(?:boq|budget)\s+overuse(?:s)?\s+(?:on|at|in|for)\s+(?<warehouse>.+?)\s*\??$/i',
                    '/items?\s+over\s+budget\s+(?:on|at|in|for)\s+(?<warehouse>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => ['warehouse_name' => isset($m['warehouse']) ? self::stripTrailingLabel($m['warehouse']) : null],
                'handler' => [\App\Services\AiTools\BoqTool::class, 'overBudgetItems'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    if (($r['count'] ?? 0) === 0) {
                        return "No items are over BOQ on {$r['project']}.";
                    }
                    $lines = collect($r['items'])->map(fn ($i) => "{$i['item_name']}: issued " . self::formatQuantity($i['issued_quantity'])
                        . ' vs. BOQ ' . self::formatQuantity($i['boq_quantity']) . " ({$i['status']})");
                    return "{$r['count']} item(s) over BOQ on {$r['project']}:\n" . $lines->implode("\n");
                },
            ],

            'issue_return_overdue' => [
                'patterns' => [
                    '/what(?:\'s| is)?\s+(?:currently\s+)?overdue(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                    '/(?:issue|tool)s?\s+overdue(?:\s*(?:at|in)\s+(?<warehouse>.+?))?\s*\??$/i',
                ],
                'map' => fn (array $m) => ['warehouse_name' => $m['warehouse'] ?? null],
                'handler' => [\App\Services\AiTools\IssueReturnTool::class, 'overdue'],
                'format' => function (array $r) {
                    if (($r['count'] ?? 0) === 0) {
                        return 'Nothing is currently overdue.';
                    }
                    $lines = collect($r['overdue'])->map(fn ($t) => "{$t['issue_number']} - {$t['issued_to']} ({$t['warehouse']}), due {$t['expected_return_date']}");
                    return "{$r['count']} overdue issue(s):\n" . $lines->implode("\n");
                },
            ],

            'my_attendance_status' => [
                'patterns' => [
                    '/am i\s+(?:currently\s+)?clocked\s+in\s*\??$/i',
                    '/what time did i clock in(?:\s+today)?\s*\??$/i',
                    '/how many hours (?:have i worked|did i work)\s+today\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'myStatus'],
                'format' => function (array $r) {
                    if (! ($r['clocked_in'] ?? false)) {
                        return $r['message'] ?? "You haven't clocked in today.";
                    }
                    return "You clocked in at {$r['clock_in_at']} and have worked {$r['worked_label']} so far today.";
                },
            ],

            'my_attendance_week_hours' => [
                'patterns' => [
                    '/how many hours did i work this week\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'myWeekHours'],
                'format' => fn (array $r) => "You've worked {$r['total_label']} across {$r['attendance_days']} day(s) this week.",
            ],

            'who_is_clocked_in' => [
                'patterns' => [
                    '/who\s+is\s+currently\s+clocked\s+in\s*\??$/i',
                    '/how many people are currently working\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'whoIsClockedIn'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    if ($r['count'] === 0) {
                        return 'No one is currently clocked in.';
                    }
                    $lines = collect($r['people'])->map(fn ($p) => "{$p['name']} (since {$p['clock_in_at']}, {$p['worked_label']})");
                    return "{$r['count']} people currently clocked in:\n" . $lines->implode("\n");
                },
            ],

            'attendance_missing_clock_outs' => [
                'patterns' => [
                    '/who\s+forgot\s+to\s+clock\s+out\s*\??$/i',
                ],
                'map' => fn (array $m) => [],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'missingClockOuts'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    if ($r['count'] === 0) {
                        return 'Nobody has a missing clock-out in the last 7 days.';
                    }
                    $lines = collect($r['people'])->map(fn ($p) => "{$p['name']} - {$p['date']} (clocked in {$p['clock_in_at']})");
                    return "Missing clock-outs:\n" . $lines->implode("\n");
                },
            ],

            'issue_return_who_has' => [
                'patterns' => [
                    '/who\s+has\s+(?:the\s+|a\s+|an\s+)?(?<item>.+?)\s*\??$/i',
                    '/who(?:\'s| is)?\s+(?:currently\s+)?holding\s+(?:the\s+)?(?<item>.+?)\s*\??$/i',
                ],
                'map' => fn (array $m) => ['item_name' => $m['item'] ?? null],
                'handler' => [\App\Services\AiTools\IssueReturnTool::class, 'whoHasItem'],
                'format' => function (array $r) {
                    if (! ($r['found'] ?? false)) {
                        return $r['message'] ?? null;
                    }
                    if (($r['count'] ?? 0) === 0) {
                        return "Nobody currently has {$r['item']} outstanding, it's all been returned or none is issued.";
                    }
                    $lines = collect($r['holders'])->map(fn ($h) => "{$h['issued_to']} ({$h['warehouse']}): " . self::formatQuantity($h['outstanding_quantity']));
                    return "{$r['item']} is currently with:\n" . $lines->implode("\n");
                },
            ],

        ];
    }

    protected static function stripTrailingLabel(string $name): string
    {
        return trim(preg_replace('/\s+(warehouse|project)s?$/i', '', trim($name)));
    }

    protected static function hasToday(string $question): bool
    {
        return (bool) preg_match('/\btoday\b/i', $question);
    }

    protected static function normalizeRequestStatus(string $raw): string
    {
        $raw = trim($raw);

        return str_contains(strtolower($raw), 'pending') ? 'Pending Approval' : ucfirst(strtolower($raw));
    }

    protected static function formatQuantity($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    }

    protected static function formatDate($value): string
    {
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }
}
