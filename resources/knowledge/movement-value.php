<?php

return [
    'group' => 'reports',
    'title' => 'Movement Value Report',
    'icon' => 'truck',
    'order' => 30,
    'summary' => 'Shows what moved INTO your projects, from a Main Warehouse or from a supplier, how much of it and what it was worth, line by line and project by project.',
    'where' => 'Sidebar > **Movement Value Report**.',
    'path' => '/movement-value',
    'permissions' => [
        'read-movement-value' => 'Open the report, calculate it, and print or download it. Super Admins always have it. No other role has it by default, so an administrator must tick it on the roles that need it (see [[roles-and-permissions-explained]]).',
    ],
    'overview' => [
        'The **Movement Value Report** answers "what did we send to (or buy for) this project, and what was it worth?". It has two views of the same idea. **Warehouse → Project** lists materials transferred from a Main Warehouse to site projects. **Supplier → Project** lists materials delivered by suppliers straight to site projects (checkins). In both views you choose the materials, the source, the destination projects and an optional date range.',
        'The result is grouped by destination project. Each project shows a subtotal, and each line is one real transaction (a transfer or a checkin) with its reference, date, quantity, unit price and value. Click a reference to open that transaction. A grand total sits at the top.',
        'This report is about **movement during a period**. For the value of stock that is on the shelf today use [[material-value]]. For every in and out of one project in a single list, including consumption, use the [[project-material-ledger]].',
    ],
    'concepts' => [
        ['Main Warehouse', 'A store whose type is set to **Main Warehouse**. In **Warehouse → Project** mode these are the only possible sources.'],
        ['Project / Site', 'Any active warehouse whose type is **not** Main Warehouse. These are the only possible destinations.'],
        ['Movement line', 'One item on one transaction. A transfer with five items gives five lines, one per item.'],
        ['Entire project (no date limit)', 'A tick box that ignores the From and To dates and reports everything since the project began.'],
        ['Unit price used', 'For transfers: the item\'s current price (transfers store no price). For supplier checkins: the price typed on the checkin line, or the item\'s current price if that line has no price.'],
    ],
    'functions' => [
        [
            'id' => 'choose-movement-type',
            'title' => 'Choose the movement type',
            'icon' => 'swap',
            'summary' => 'Decide whether you are following material from a Main Warehouse or from suppliers.',
            'steps' => [
                'Open **Movement Value Report**.',
                'In step **1 Movement type** click **Warehouse → Project** (transfers) or **Supplier → Project** (checkins).',
                'Changing the type clears the source you had picked, because a warehouse and a supplier are different lists. Your materials, destinations and dates stay.',
            ],
            'shots' => [
                ['movement-value-filters', 'The five filter steps: movement type, materials, source, destination projects and date range.', 'Movement Value filter steps'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                '**Warehouse → Project** reads finished transfers (not drafts, not deleted). A transfer that leaves a Main Warehouse is saved as a draft until an approver releases it, so it appears here only **after** it is approved. See [[transfers]].',
                '**Supplier → Project** reads finished checkins (not drafts, not deleted) whose contact is one of the chosen suppliers and whose warehouse is one of the chosen projects.',
            ]],
        ],
        [
            'id' => 'choose-materials-movement',
            'title' => 'Choose the materials',
            'icon' => 'tag',
            'summary' => 'Pick single items or whole categories, exactly like in Material Value.',
            'steps' => [
                'In step **2 Choose materials** click **By Item** or **By Category**.',
                'Type in the search box and click names to tick them. Ticked names appear as chips; click the cross on a chip to remove one.',
                'The counter shows how many you ticked, for example **3 selected**.',
            ],
            'tips' => [
                'Ticks from both tabs are added together, and an item is never counted twice.',
                'The pickers list **all** items and categories, even ones that never moved. A project simply shows nothing for an item that did not move.',
            ],
            'warnings' => [
                'You must tick at least one item or category, or the report stays empty.',
            ],
        ],
        [
            'id' => 'choose-source-movement',
            'title' => 'Choose the source: Main Warehouses or suppliers',
            'icon' => 'store',
            'summary' => 'Narrow down where the material came from, or leave it empty to include every source.',
            'steps' => [
                'In step **3 Source** you see **Source: Main Warehouse(s)** (Warehouse mode) or **Source: Supplier(s)** (Supplier mode).',
                'Warehouse mode: click the Main Warehouses you want, or click **All Main Warehouses**. Click **Clear** to untick.',
                'Supplier mode: type in **Search suppliers...** and click the suppliers you want. Ticked suppliers appear as chips.',
                'You may leave the source empty. The page then says "None selected. Defaults to all Main Warehouses" or "None selected. Defaults to all suppliers".',
            ],
            'fields' => [
                ['Main Warehouse list', 'Active Main Warehouses assigned to you. A red dot marks a completed one.'],
                ['Supplier list', 'Every contact that has at least one checkin on record, whatever its contact category.'],
            ],
            'warnings' => [
                'If you have no Main Warehouse assigned, the message **No Main Warehouses available to you** appears and Warehouse mode will find nothing.',
            ],
        ],
        [
            'id' => 'choose-destination-movement',
            'title' => 'Choose the destination projects',
            'icon' => 'building',
            'summary' => 'Tick the projects that received the material.',
            'steps' => [
                'In step **4 Destination: Project(s) / Site(s)** click project cards to tick them.',
                'Use the quick buttons to tick many at once: one button per project type (for example **All Private**), **Select All Projects** for everything, and **Clear** to untick.',
                'The counter shows **N project(s) selected**.',
            ],
            'warnings' => [
                'At least one destination project is required. Without it nothing is calculated.',
                'Main Warehouses are never offered as destinations, only projects and sites.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Destination and source numbers that are not on your allowed lists are discarded on the server.',
            ]],
        ],
        [
            'id' => 'set-period-movement',
            'title' => 'Set the date range or take the whole project',
            'icon' => 'calendar',
            'summary' => 'Limit the report to a period, or ignore dates altogether.',
            'steps' => [
                'In step **5 Date range** pick **From** and **To**, or leave one of them empty for an open end.',
                'Or tick **Entire project (no date limit)**. The two date boxes switch off.',
            ],
            'tips' => [
                'Dates are compared with the transaction date typed on the transfer or checkin, not with the day it was saved.',
            ],
        ],
        [
            'id' => 'calculate-movement',
            'title' => 'Calculate and read the result',
            'icon' => 'chart',
            'summary' => 'Click **Calculate Movement Value** to see summary cards and one card per destination project.',
            'steps' => [
                'Click **Calculate Movement Value** (it shows **Calculating...** while it works).',
                'Read the four cards: **Grand Total Value**, **Materials**, **Projects** and **Movement Lines**.',
                'Open or fold a project card by clicking its header. The right side shows the project\'s **Subtotal**.',
                'In the table read **Transaction**, **Item**, **Date**, **Quantity**, **Unit**, **Unit Price** and **Value**.',
                'Click a transaction reference to open the full record in a new tab (it opens the read-only detail page of the [[project-material-ledger]]).',
            ],
            'fields' => [
                ['Transaction', 'The reference of the transfer (`RST-…`) or checkin (`RSC-…`). Click to open.'],
                ['Item', 'Item name with its code under it.'],
                ['Date', 'The date of the transaction.'],
                ['Quantity / Unit', 'Quantity moved and its unit.'],
                ['Unit Price', 'See "Unit price used" above.'],
                ['Value', '`Quantity × Unit Price` for the line.'],
                ['Subtotal', 'The sum of all lines of that project.'],
                ['Materials', 'Number of different items in the result.'],
                ['Projects', 'Number of projects that have at least one line.'],
                ['Movement Lines', 'Total number of rows in all project tables.'],
            ],
            'shots' => [
                ['movement-value-warehouse-result', 'Warehouse → Project result: material sent from a Main Warehouse to site projects, one expanded project card with its transfer lines.', 'Movement value, warehouse to project'],
                ['movement-value-supplier-result', 'Supplier → Project result: deliveries from suppliers straight to site, valued at the price typed on the checkin.', 'Movement value, supplier to project'],
            ],
            'warnings' => [
                '**No movement found for that selection** means no finished transaction matches the materials, source, projects and dates you chose.',
                'Transfers valued with the item\'s **current** price will change if the item price changes later. Supplier checkins keep the price that was typed on them.',
            ],
            'inner' => ['title' => 'How the numbers are worked out', 'items' => [
                '**Warehouse → Project**: every line of a finished transfer where the sending warehouse is one of the chosen (or all your) Main Warehouses, the receiving warehouse is a chosen project and the item is a chosen one. `Value = quantity × item price` (empty price counts as 0).',
                '**Supplier → Project**: every line of a finished checkin where the contact is a chosen supplier (or any supplier if none chosen), the line\'s warehouse is a chosen project and the item is a chosen one. `Unit price = checkin line price`, or the item\'s price when the line price is empty or 0; `Value = quantity × unit price`.',
                'Lines are not added together across dates. Each line stays visible so the **Date** column means something. Lines inside a project are sorted by date, newest first; project cards are sorted by subtotal, highest first.',
                '`Subtotal` = sum of line values of a project. `Grand Total` = sum of subtotals.',
                'Drafts and deleted transactions are never counted.',
            ]],
        ],
        [
            'id' => 'print-download-movement',
            'title' => 'Print or download the report',
            'icon' => 'printer',
            'summary' => 'Make a paper or PDF copy of exactly what you calculated.',
            'steps' => [
                'Calculate the report first. **Print** and **Download** appear at the top right only after a calculation.',
                'Click **Print** to open a print-ready page in a new tab, or **Download** to save `movement-value-report-<date>-<time>.pdf`.',
            ],
            'shots' => [
                ['movement-value-print', 'The print layout shows the movement type (Warehouse to Project or Supplier to Project), the grand total band and one table per project.', 'Movement value print layout'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The title line of the printout names the type: **Warehouse to Project** or **Supplier to Project**.',
                'Print and download are logged in the **Download / Print Logs** automatically. See [[download-print-logs]].',
            ]],
        ],
        [
            'id' => 'reset-movement',
            'title' => 'Start over with Reset',
            'icon' => 'refresh',
            'summary' => 'Clear every choice and the result.',
            'steps' => [
                'Click **Reset** next to the movement type buttons.',
                'The page reloads in **Warehouse → Project** mode with nothing selected.',
            ],
        ],
    ],
    'behind' => [
        ['title' => 'Approval comes first for Main Warehouse transfers', 'text' => 'A transfer that leaves a Main Warehouse waits for an approver before stock moves. Until then it is a draft, and drafts are never included in this report.', 'icon' => 'clipboard-check'],
        ['title' => 'Only your projects', 'text' => 'Source and destination warehouses are limited to the projects assigned to you. Completed projects are hidden unless you are a Super Admin.', 'icon' => 'shield-check'],
        ['title' => 'Prints are logged', 'text' => 'Every Print and Download is written to the Download / Print Logs.', 'icon' => 'receipt'],
        ['title' => 'Feature switch', 'text' => 'A Super Admin can turn this report off in [[global-feature-control]] (feature **Movement Value Report**). Everyone else is then sent to a page saying the function is disabled.', 'icon' => 'toggle'],
    ],
    'faq' => [
        ['q' => 'I approved a transfer but it is not in the report.', 'a' => 'Check the dates. The report uses the date written on the transfer, which may be earlier than the day it was approved. Tick **Entire project (no date limit)** to confirm.'],
        ['q' => 'Why is a transfer valued differently from last month?', 'a' => 'Transfers are valued with the item\'s current price, because a transfer line does not store a price. If the item price changed, old transfers change too.'],
        ['q' => 'Why do I see no Main Warehouses?', 'a' => 'No active warehouse with the type **Main Warehouse** is assigned to you. Ask an administrator to assign one or change the project type in [[warehouses-projects]].'],
        ['q' => 'Can I see what the project used (checkouts)?', 'a' => 'Not here. This report is about material arriving. Use the [[project-material-ledger]] or the **Usage Material Per Site** report in [[reports]] for consumption.'],
    ],
    'related' => ['reports', 'material-value', 'project-material-ledger', 'transfers', 'checkins', 'warehouses-projects'],
];
