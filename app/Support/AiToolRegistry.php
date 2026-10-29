<?php

namespace App\Support;

class AiToolRegistry
{
    /**
     * Canonical map of every safe, read-only query the AI Assistant may run.
     * The model never writes SQL — it only ever picks a tool name from this
     * list and supplies the parameters described by that tool's input_schema;
     * the PHP handler behind it does the actual (fully scoped) querying. This
     * is the single source of truth for: the Anthropic `tools` payload, the
     * frontend's suggested-question chips, and the handler dispatch table.
     */
    public static function tools(): array
    {
        return [
            'stock_lookup' => [
                'label' => __('Stock / Inventory Lookup'),
                'description' => 'Current on-hand quantity of an item, either company-wide or in one specific warehouse.',
                'examples' => [
                    'How much cement do I have in the entire company?',
                    'How many bags of cement are at GD1 warehouse?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'item_name' => ['type' => 'string', 'description' => 'Name (or partial name) of the item, e.g. "cement".'],
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name. Omit for the company-wide total across all warehouses.'],
                    ],
                    'required' => ['item_name'],
                ],
                'handler' => [\App\Services\AiTools\StockTool::class, 'lookup'],
            ],

            'low_stock_items' => [
                'label' => __('Low Stock Items'),
                'description' => 'List items that are at or below their configured low-stock alert quantity, either company-wide or in one warehouse.',
                'examples' => [
                    'What items are running low on stock?',
                    'What is running low at GD1 warehouse?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name. Omit to check across all warehouses.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\StockTool::class, 'lowStock'],
            ],

            'last_checkin' => [
                'label' => __('Last Checkin'),
                'description' => 'The most recent checkin (material received into a warehouse), optionally on a given date or at a given warehouse.',
                'examples' => [
                    'What was the last checkin done today?',
                    'What was the last checkin at GD1?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name.'],
                        'date' => ['type' => 'string', 'description' => 'Optional date in YYYY-MM-DD format. Omit to mean today.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\CheckinTool::class, 'last'],
            ],

            'user_checkin_activity' => [
                'label' => __('Did a User Check In?'),
                'description' => 'Whether a specific user performed any checkin on a given date (defaults to today), and how many.',
                'examples' => [
                    'Did John do any checkin today?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_name' => ['type' => 'string', 'description' => 'Name or username of the user to check.'],
                        'date' => ['type' => 'string', 'description' => 'Optional date in YYYY-MM-DD format. Omit to mean today.'],
                    ],
                    'required' => ['user_name'],
                ],
                'handler' => [\App\Services\AiTools\CheckinTool::class, 'didUserAct'],
            ],

            'last_checkout' => [
                'label' => __('Last Checkout'),
                'description' => 'The most recent checkout (material issued out of a warehouse), optionally on a given date or at a given warehouse.',
                'examples' => [
                    'What was the last checkout done today?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name.'],
                        'date' => ['type' => 'string', 'description' => 'Optional date in YYYY-MM-DD format. Omit to mean today.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\CheckoutTool::class, 'last'],
            ],

            'user_checkout_activity' => [
                'label' => __('Did a User Check Out?'),
                'description' => 'Whether a specific user performed any checkout on a given date (defaults to today), and how many.',
                'examples' => [
                    'Did Sarah check out any materials today?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_name' => ['type' => 'string', 'description' => 'Name or username of the user to check.'],
                        'date' => ['type' => 'string', 'description' => 'Optional date in YYYY-MM-DD format. Omit to mean today.'],
                    ],
                    'required' => ['user_name'],
                ],
                'handler' => [\App\Services\AiTools\CheckoutTool::class, 'didUserAct'],
            ],

            'last_transfer' => [
                'label' => __('Last Transfer'),
                'description' => 'The most recent stock transfer between warehouses, optionally on a given date or involving a given warehouse.',
                'examples' => [
                    'What was the last transfer done today?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name (either source or destination).'],
                        'date' => ['type' => 'string', 'description' => 'Optional date in YYYY-MM-DD format. Omit to mean today.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\TransferTool::class, 'last'],
            ],

            'last_adjustment' => [
                'label' => __('Last Adjustment'),
                'description' => 'The most recent stock adjustment, optionally on a given date or at a given warehouse.',
                'examples' => [
                    'What was the last stock adjustment made today?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name.'],
                        'date' => ['type' => 'string', 'description' => 'Optional date in YYYY-MM-DD format. Omit to mean today.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\AdjustmentTool::class, 'last'],
            ],

            'user_last_login' => [
                'label' => __('User Last Login'),
                'description' => 'When a specific user last successfully logged into the system.',
                'examples' => [
                    'When was the last time John logged into the system?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_name' => ['type' => 'string', 'description' => 'Name or username of the user to check.'],
                    ],
                    'required' => ['user_name'],
                ],
                'handler' => [\App\Services\AiTools\UserActivityTool::class, 'lastLogin'],
            ],


            'subcontract_summary' => [
                'label' => __('Subcontractor Contract and Payments'),
                'description' => 'Contract value, approved work, amounts actually paid (through paid Payment Vouchers plus historical payments), approved but unpaid and outstanding balance of one subcontractor, per currency.',
                'examples' => ['How much have we paid Eddy Aluminium?', 'What is the outstanding balance for Hitesh?'],
                'input_schema' => ['type' => 'object', 'properties' => ['subcontractor_name' => ['type' => 'string', 'description' => 'Name of the subcontractor (a Contact).']], 'required' => ['subcontractor_name']],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'summary'],
            ],
            'subcontract_pending' => [
                'label' => __('Subcontract Items Waiting'),
                'description' => 'How many subcontract agreements, variations and payment requests wait for a review or decision, and how many approved requests are not fully paid.',
                'examples' => ['Which subcontractor payment requests are awaiting approval?'],
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'pending'],
            ],
            'subcontract_claims_waiting' => [
                'label' => __('Contract Payment Requests Waiting'),
                'description' => 'Contract payment requests (claims for several completed subcontract agreements) with lines waiting for a decision, and the lines returned or deferred. Amounts only for people who may see subcontract financials.',
                'examples' => ['Which contract payment requests are waiting for approval?'],
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'claimsWaiting'],
            ],
            'subcontract_completed_unpaid' => [
                'label' => __('Completed Subcontract Agreements Not Yet Paid'),
                'description' => 'Completed subcontract agreements that still have an unpaid balance: how many can be claimed now and how many wait for verification or evidence. Needs the subcontract financial permission.',
                'examples' => ['Which completed contracts are not yet paid?'],
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'completedUnpaid'],
            ],
            'subcontract_unreconciled' => [
                'label' => __('Unreconciled Imported Subcontract Accounts'),
                'description' => 'Imported subcontract accounts whose workbook figures differ from WIMS and are not yet reconciled.',
                'examples' => ['Which imported accounts have unreconciled balances?'],
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                'handler' => [\App\Services\AiTools\SubcontractTool::class, 'unreconciled'],
            ],

            'material_request_status' => [
                'label' => __('Material Request Status'),
                'description' => 'Summary of material requests by status, optionally filtered to one warehouse.',
                'examples' => [
                    'How many material requests are pending approval?',
                    'What material requests are pending at GD1?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Optional status to filter by, e.g. "Pending Approval", "Approved", "Issued", "Closed".'],
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\MaterialRequestTool::class, 'statusSummary'],
            ],

            'transport_trip_count_for_vehicle' => [
                'label' => __('Transport Trip Count for Vehicle'),
                'description' => 'How many trips a specific vehicle has made, and their total cost, optionally within a date range or "this month".',
                'examples' => [
                    'How many trips did vehicle T123ABC make this month?',
                    'How much has the Hilux pickup cost in transport this year?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'vehicle_name' => ['type' => 'string', 'description' => 'Vehicle registration number or name, e.g. "T123ABC".'],
                        'date_from' => ['type' => 'string', 'description' => 'Optional start date (YYYY-MM-DD).'],
                        'date_to' => ['type' => 'string', 'description' => 'Optional end date (YYYY-MM-DD).'],
                        'this_month' => ['type' => 'boolean', 'description' => 'Set true to restrict to the current calendar month.'],
                    ],
                    'required' => ['vehicle_name'],
                ],
                'handler' => [\App\Services\AiTools\TransportTool::class, 'tripCountForVehicle'],
            ],

            'transport_most_active_vehicle' => [
                'label' => __('Most Active Transport Vehicle'),
                'description' => 'Which vehicle(s) made the most trips, optionally within a date range or "this month".',
                'examples' => [
                    'Which vehicle made the most trips this month?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'Optional start date (YYYY-MM-DD).'],
                        'date_to' => ['type' => 'string', 'description' => 'Optional end date (YYYY-MM-DD).'],
                        'this_month' => ['type' => 'boolean', 'description' => 'Set true to restrict to the current calendar month.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\TransportTool::class, 'mostActiveVehicle'],
            ],

            'transport_cost_by_project_or_warehouse' => [
                'label' => __('Transport Cost by Project or Warehouse'),
                'description' => 'Total, paid, and outstanding transport cost for one project/warehouse (Projects and Warehouses are the same underlying model in this app).',
                'examples' => [
                    'How much transport is outstanding for Project A?',
                    'How much was spent on transport to Warehouse B?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_or_warehouse_name' => ['type' => 'string', 'description' => 'Project or warehouse name.'],
                        'date_from' => ['type' => 'string', 'description' => 'Optional start date (YYYY-MM-DD).'],
                        'date_to' => ['type' => 'string', 'description' => 'Optional end date (YYYY-MM-DD).'],
                    ],
                    'required' => ['project_or_warehouse_name'],
                ],
                'handler' => [\App\Services\AiTools\TransportTool::class, 'transportCostByProjectOrWarehouse'],
            ],

            'wimschat_unread' => [
                'label' => __('WIMSChat, unread'),
                'description' => 'Lists the WIMSChat conversations where the user has unread messages.',
                'examples' => ['Do I have unread chat messages?'],
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass, 'required' => []],
                'handler' => [\App\Services\AiTools\WimsChatTool::class, 'unread'],
            ],
            'wimschat_search' => [
                'label' => __('WIMSChat, search messages'),
                'description' => 'Finds WIMSChat messages (only in conversations the user belongs to) containing a word or phrase.',
                'examples' => ['Find chat messages about cement delivery'],
                'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Word or phrase to look for.']], 'required' => ['query']],
                'handler' => [\App\Services\AiTools\WimsChatTool::class, 'search'],
            ],
            'wimschat_files' => [
                'label' => __('WIMSChat, files'),
                'description' => 'Finds files shared in the WIMSChat conversations the user belongs to, optionally by file name.',
                'examples' => ['Find files shared in chat named invoice'],
                'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Optional part of the file name.']], 'required' => []],
                'handler' => [\App\Services\AiTools\WimsChatTool::class, 'files'],
            ],
            'holiday_next' => [
                'label' => __('Holiday Greetings, next holiday'),
                'description' => 'The next holiday that counts, how many days away it is and when the Super Admins are reminded.',
                'examples' => ['When is the next holiday?'],
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                'handler' => [\App\Services\AiTools\HolidayGreetingsTool::class, 'next'],
            ],
            'holiday_year' => [
                'label' => __('Holiday Greetings, holidays of the year'),
                'description' => 'The holidays of a year that count (weekend holidays do not count).',
                'examples' => ['How many holidays are there this year?'],
                'input_schema' => ['type' => 'object', 'properties' => ['year' => ['type' => 'integer', 'description' => 'Optional year, default this year.']], 'required' => []],
                'handler' => [\App\Services\AiTools\HolidayGreetingsTool::class, 'year'],
            ],
            'holiday_status' => [
                'label' => __('Holiday Greetings, status'),
                'description' => 'Whether the greetings of a holiday were sent and how many emails and SMS went through.',
                'examples' => ['Were the Christmas greetings sent?'],
                'input_schema' => ['type' => 'object', 'properties' => ['holiday_name' => ['type' => 'string', 'description' => 'Name of the holiday, e.g. Christmas.'], 'year' => ['type' => 'integer', 'description' => 'Optional year.']], 'required' => ['holiday_name']],
                'handler' => [\App\Services\AiTools\HolidayGreetingsTool::class, 'status'],
            ],
            'site_diary_summary' => [
                'label' => __('Site Diary, counts'),
                'description' => 'Counts the site diaries the user may see, optionally for one project and for today / this month.',
                'examples' => ['How many site diaries were recorded for Project X this month?'],
                'input_schema' => ['type' => 'object', 'properties' => [
                    'project_or_warehouse_name' => ['type' => 'string', 'description' => 'Optional project name.'],
                    'period' => ['type' => 'string', 'enum' => ['today', 'month'], 'description' => 'Optional period.'],
                ], 'required' => []],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'count'],
            ],
            'site_diary_latest' => [
                'label' => __('Site Diary, latest'),
                'description' => 'Who recorded the latest site diary, optionally for one project.',
                'examples' => ['Who recorded the latest site diary for Project X?'],
                'input_schema' => ['type' => 'object', 'properties' => ['project_or_warehouse_name' => ['type' => 'string', 'description' => 'Optional project name.']], 'required' => []],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'latestRecorder'],
            ],
            'site_diary_remarks' => [
                'label' => __('Site Diary, latest remarks'),
                'description' => 'The latest site remarks, optionally for one project.',
                'examples' => ['Show the latest site remarks for Project X.'],
                'input_schema' => ['type' => 'object', 'properties' => ['project_or_warehouse_name' => ['type' => 'string', 'description' => 'Optional project name.']], 'required' => []],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'latestRemarks'],
            ],
            'site_diary_photos' => [
                'label' => __('Site Diary, photo count'),
                'description' => 'How many photos were uploaded to site diaries, optionally for one project.',
                'examples' => ['How many photos were uploaded for Project X?'],
                'input_schema' => ['type' => 'object', 'properties' => ['project_or_warehouse_name' => ['type' => 'string', 'description' => 'Optional project name.']], 'required' => []],
                'handler' => [\App\Services\AiTools\SiteDiaryTool::class, 'photoCount'],
            ],

            'transport_unpaid_records' => [
                'label' => __('Unpaid Transport Records'),
                'description' => 'Lists unpaid transport trips, optionally filtered to one project/warehouse.',
                'examples' => [
                    'Show unpaid transport records for Project X.',
                    'What transport trips are still unpaid?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_or_warehouse_name' => ['type' => 'string', 'description' => 'Optional project or warehouse name to filter by.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\TransportTool::class, 'unpaidTransportRecords'],
            ],

            'material_reallocation_status' => [
                'label' => __('Material Reallocation Status'),
                'description' => 'Summary of material reallocations by status.',
                'examples' => [
                    'How many material reallocations are awaiting warehouse allocation?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Optional status to filter by, e.g. "Submitted", "PM Clearance", "Completed".'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\MaterialReallocationTool::class, 'statusSummary'],
            ],

            'daily_site_report_latest' => [
                'label' => __('Latest Daily Site Report'),
                'description' => 'The most recent daily site report submitted for a given warehouse/project.',
                'examples' => [
                    'What was the latest daily site report for GD1?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Warehouse/project name.'],
                    ],
                    'required' => ['warehouse_name'],
                ],
                'handler' => [\App\Services\AiTools\DailySiteReportTool::class, 'latestForWarehouse'],
            ],

            'daily_site_report_missing_today' => [
                'label' => __('Sites Missing Today\'s Report'),
                'description' => 'Which warehouses/projects have not yet submitted a daily site report today.',
                'examples' => [
                    'Which sites have not submitted their daily report today?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\DailySiteReportTool::class, 'missingToday'],
            ],

            'work_package_status' => [
                'label' => __('Work Package Status'),
                'description' => 'Summary of project work packages by status, optionally filtered to one warehouse.',
                'examples' => [
                    'How many work packages are in progress at GD1?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\WorkPackageTool::class, 'statusSummary'],
            ],

            'issue_return_who_has' => [
                'label' => __('Who Has an Issued Item'),
                'description' => 'Who currently has an issued (not yet fully returned) tool/item outstanding, across all open issue transactions.',
                'examples' => [
                    'Who has the ladder?',
                    'Who is holding the concrete mixer?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'item_name' => ['type' => 'string', 'description' => 'Name (or partial name) of the issued item/tool.'],
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name.'],
                    ],
                    'required' => ['item_name'],
                ],
                'handler' => [\App\Services\AiTools\IssueReturnTool::class, 'whoHasItem'],
            ],

            'issue_return_overdue' => [
                'label' => __('Overdue Issued Items'),
                'description' => 'Issue transactions past their expected return date that still have an outstanding balance.',
                'examples' => [
                    'What is currently overdue?',
                    'What tools are overdue at GD1?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\IssueReturnTool::class, 'overdue'],
            ],

            'boq_item_status' => [
                'label' => __('BOQ Item Status'),
                'description' => 'Issued quantity vs. Bill of Quantities (BOQ) planned quantity for an item (or all items) on a project, status: Under Usage, On Track, Slight Overuse, Overused, or Not Set if no BOQ quantity was configured.',
                'examples' => [
                    'What is the BOQ status of cement on ISTIQAMA TUNGU?',
                    'BOQ status for GD1 project?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Project/warehouse name.'],
                        'item_name' => ['type' => 'string', 'description' => 'Optional item name. Omit for a status breakdown across all items on the project.'],
                    ],
                    'required' => ['warehouse_name'],
                ],
                'handler' => [\App\Services\AiTools\BoqTool::class, 'itemStatus'],
            ],

            'boq_over_budget' => [
                'label' => __('Items Over BOQ Budget'),
                'description' => 'Items on a project whose issued quantity is at or above 100% of their BOQ planned quantity (Slight Overuse or Overused).',
                'examples' => [
                    'What items are over BOQ on ISTIQAMA TUNGU?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Project/warehouse name.'],
                    ],
                    'required' => ['warehouse_name'],
                ],
                'handler' => [\App\Services\AiTools\BoqTool::class, 'overBudgetItems'],
            ],

            'active_projects_count' => [
                'label' => __('Active Projects Count'),
                'description' => 'How many active projects (warehouses with a project status, excluding Main/storage warehouses) the company currently has.',
                'examples' => [
                    'How many active projects do we have?',
                ],
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'handler' => [\App\Services\AiTools\DashboardStatsTool::class, 'activeProjectsCount'],
            ],

            'total_stock_value' => [
                'label' => __('Total Stock Value'),
                'description' => 'Total monetary value of on-hand stock (quantity x item price), company-wide or for one warehouse.',
                'examples' => [
                    'What is our total inventory value?',
                    'How much is stock worth at GD1?',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name. Omit for the company-wide total.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\DashboardStatsTool::class, 'totalStockValue'],
            ],

            'top_consuming_projects' => [
                'label' => __('Top Consuming Projects'),
                'description' => 'Ranking of the projects with the highest material checkout (issue) value over the last 7 days.',
                'examples' => [
                    'Which projects used the most materials this week?',
                ],
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'handler' => [\App\Services\AiTools\DashboardStatsTool::class, 'topConsumingProjects'],
            ],

            'top_used_materials' => [
                'label' => __('Top Used Materials'),
                'description' => 'Ranking of the materials/items with the highest checked-out quantity over the last 7 days.',
                'examples' => [
                    'What materials were used the most this week?',
                ],
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'handler' => [\App\Services\AiTools\DashboardStatsTool::class, 'topUsedMaterials'],
            ],

            'warehouse_info' => [
                'label' => __('Warehouse / Project Info'),
                'description' => 'Basic info about a specific warehouse/project, or a list of all warehouses/projects the user can access.',
                'examples' => [
                    'List all our warehouses.',
                    'Tell me about GD1 warehouse.',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'warehouse_name' => ['type' => 'string', 'description' => 'Optional warehouse/project name. Omit to list all accessible warehouses.'],
                    ],
                    'required' => [],
                ],
                'handler' => [\App\Services\AiTools\WarehouseTool::class, 'info'],
            ],

            'my_attendance_status' => [
                'label' => __('My Attendance Status'),
                'description' => 'Whether the asking user is currently clocked in today, and their clock-in/out times and hours worked so far.',
                'examples' => [
                    'What time did I clock in today?',
                    'Am I currently clocked in?',
                    'How many hours have I worked today?',
                ],
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'myStatus'],
            ],

            'my_attendance_week_hours' => [
                'label' => __('My Attendance - Week Hours'),
                'description' => "The asking user's total hours worked and attendance days for the current week.",
                'examples' => [
                    'How many hours did I work this week?',
                ],
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'myWeekHours'],
            ],

            'who_is_clocked_in' => [
                'label' => __('Who Is Currently Clocked In'),
                'description' => 'List of staff currently clocked in via Digital Attendance right now, and how many people are working. Requires attendance admin permission.',
                'examples' => [
                    'Who is currently clocked in?',
                    'How many people are currently working?',
                ],
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'whoIsClockedIn'],
            ],

            'attendance_today' => [
                'label' => __("Today's Attendance"),
                'description' => "Full Digital Attendance list for today (who clocked in/out and when). Requires attendance admin permission.",
                'examples' => [
                    'Show attendance for today.',
                ],
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'todayAttendance'],
            ],

            'attendance_missing_clock_outs' => [
                'label' => __('Missing Clock-Outs'),
                'description' => 'Staff who forgot to clock out in the last 7 days. Requires attendance admin permission.',
                'examples' => [
                    'Who forgot to clock out?',
                ],
                'input_schema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'handler' => [\App\Services\AiTools\AttendanceTool::class, 'missingClockOuts'],
            ],
        ];
    }

    /**
     * Flat list of every tool key.
     */
    public static function allToolKeys(): array
    {
        return array_keys(self::tools());
    }

    /**
     * The `tools` array shape the Anthropic Messages API expects.
     */
    public static function forClaude(): array
    {
        return collect(self::tools())
            ->map(fn ($tool, $key) => [
                'name' => $key,
                'description' => $tool['description'],
                'input_schema' => $tool['input_schema'],
            ])
            ->values()
            ->all();
    }

    /**
     * Resolve a tool key to its PHP [class, method] handler.
     */
    public static function handlerFor(string $toolKey): ?array
    {
        return self::tools()[$toolKey]['handler'] ?? null;
    }

}
