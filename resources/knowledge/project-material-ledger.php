<?php

return [
    'group' => 'reports',
    'title' => 'Project Material Ledger',
    'icon' => 'book-open',
    'order' => 40,
    'summary' => 'One list of every Checked In, Checked Out, Transfer In and Transfer Out line for a single project, with quantities, values, who did it, plus summaries, a stock reconciliation and a BOQ comparison.',
    'where' => 'Sidebar > **Project Material Ledger**.',
    'path' => '/project-material-ledger',
    'permissions' => [
        'Role based (no permission to tick)' => 'There is no permission to tick for this report. It is limited by **role**: Super Admin, Admin, Procurement Manager and Project Manager can open it. Everybody else gets "You are not allowed to view this report". The menu entry is hidden for other roles.',
        'Which projects' => 'Super Admins and Admins can pick any active project. Procurement Managers and Project Managers can pick only the projects assigned to them.',
    ],
    'overview' => [
        'The **Project Material Ledger** is the full story of the material of **one project** (one site). Every time material is checked into the project, checked out (used), or moved in or out by transfer, it becomes a line in the ledger. You can read it as a list, regroup it by item, by source and destination or by supplier, compare it with the stock that is really on the shelf, and compare usage with the project\'s BOQ.',
        'Each line has a **reference** you can click to open the original checkin, checkout or transfer with its audit history, so the ledger is a good place to start when someone asks "where did this material go?".',
        'The ledger reads **transactions**. It does not include stock **adjustments** (damage, additions, subtractions) or issue and return records. That matters for the Stock Reconciliation tab, which compares the ledger with live stock: differences usually point at adjustments or at stock that was loaded without a checkin.',
    ],
    'concepts' => [
        ['Checked In', 'A checkin into the project warehouse (a delivery from a supplier). Adds stock. From = the supplier, To = the project.'],
        ['Checked Out', 'A checkout from the project warehouse (material used or issued). Removes stock. From = the project, To = the person or company it was issued to, or **Consumed on site** when no contact was entered.'],
        ['Transfer In', 'A transfer whose receiving warehouse is the project. Adds stock.'],
        ['Transfer Out', 'A transfer whose sending warehouse is the project. Removes stock.'],
        ['Status', 'For checkins and checkouts: **Completed** or **Draft**. For transfers: **Draft**, **Approved** (approved but not yet marked received) or **Received**.'],
        ['Entire project (no date limit)', 'A tick box that ignores the dates and shows everything since the project began.'],
        ['Reconciliation', 'A check that "opening stock + movements = stock on the shelf now". A zero variance means the ledger fully explains the stock.'],
    ],
    'functions' => [
        [
            'id' => 'pick-project-ledger',
            'title' => 'Pick the project',
            'icon' => 'building',
            'summary' => 'The ledger always describes one project. Choose it first.',
            'steps' => [
                'Open **Project Material Ledger**. The top card **Project / Site** lists the projects you may use.',
                'Type in **Search project / site…** to narrow a long list.',
                'Click a project card. The filters, summary cards and tabs appear under it.',
                'To change project later, click another project card. Your filters reset to page 1 but stay in place.',
            ],
            'shots' => [
                ['project-material-ledger-picker', 'The project picker before a project is chosen. Only active, non-main warehouses are offered.', 'Project Material Ledger project picker'],
            ],
            'warnings' => [
                'Main Warehouses are not offered, only projects and sites. To see a Main Warehouse\'s movement use the **Item Transactional Report** in [[reports]].',
                'If you have only one project the page opens straight to it.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The list contains active warehouses whose type is not Main Warehouse. Completed projects are hidden except for Super Admins.',
                'The chosen project is checked on the server against your allowed projects; anything else returns "You are not assigned to the selected project".',
            ]],
        ],
        [
            'id' => 'read-ledger',
            'title' => 'Read the ledger and the summary cards',
            'icon' => 'list',
            'summary' => 'The **Ledger** tab lists every movement line, newest first, with five value cards above it.',
            'steps' => [
                'After choosing a project, look at the cards: **Checked In**, **Checked Out**, **Transfer In**, **Transfer Out** and **Total Material Value**.',
                'Open the **Ledger** tab. Read each row: **Date**, **Type** (coloured chip), **Reference**, **Item** (with code), **Quantity**, **Unit**, **Unit Value**, **Total Value**, **From**, **To**, **Responsible** and **Status**.',
                'Click a column heading that has an arrow (**Date**, **Type**, **Item**, **Quantity**, **Total Value**) to sort. Click again to reverse.',
                'Use **Previous** and **Next** under the table to move through pages of 25 lines. The line **Page 1 of 4 (96 total)** tells you where you are.',
                'Click a row to open the original transaction in a new tab (see "Open a transaction").',
            ],
            'fields' => [
                ['Checked In / Checked Out / Transfer In / Transfer Out cards', 'The value (`Tsh`) of all lines of that type that match your filters.'],
                ['Total Material Value', '`Checked In + Checked Out + Transfer In`. Transfer Out is shown separately but **not** added, because that material already left for another project.'],
                ['Type chip', 'Green = Checked In, red = Checked Out, blue = Transfer In, amber = Transfer Out.'],
                ['Unit Value', 'The price used for one unit (see the formulas below).'],
                ['Total Value', '`Quantity × Unit Value`.'],
                ['From / To', 'Where the material came from and where it went (names of supplier, project or person).'],
                ['Responsible', 'The user who recorded the transaction.'],
                ['Status', '**Completed**, **Draft** (checkins and checkouts) or **Draft**, **Approved**, **Received** (transfers).'],
            ],
            'shots' => [
                ['project-material-ledger-ledger', 'The Ledger tab for one project: the five value cards, the filters and the first lines.', 'Project Material Ledger, Ledger tab'],
            ],
            'warnings' => [
                'With **Status** left on **All Statuses**, **drafts are included** in every card and table. A draft does not move stock, but it is counted here. Choose **Completed** in the **Status** filter for figures that match real stock.',
                'A transfer that is still waiting for approval is a draft, so it also appears with status **Draft** until it is approved.',
            ],
            'inner' => ['title' => 'How the numbers are worked out', 'items' => [
                'The ledger is built from four lists put together: checkins into the project, checkouts from the project, transfers into the project and transfers out of it. Deleted records are never included.',
                '**Checked In** and **Checked Out** unit value: the price typed on the line; if that line has no price (or 0), the item\'s current price. **Transfer In / Out** unit value: the item\'s current price (a transfer stores no price).',
                '`Total Value = Quantity × Unit Value`.',
                'Every table and card is calculated by the database from the same combined list, so the cards, the item summary and the ledger always agree with each other.',
            ]],
        ],
        [
            'id' => 'filter-ledger',
            'title' => 'Filter the ledger',
            'icon' => 'filter',
            'summary' => 'Narrow the list by item, category, type, status, person, supplier, dates or free text.',
            'steps' => [
                'In the **Filters** card choose any of: **Item**, **Category**, **Transaction Type**, **Status**, **Responsible Person**, **Supplier / Contact**.',
                'Set **From** and **To** dates, or tick **Entire project (no date limit)** to switch the dates off.',
                'Optionally type in **Search** (reference, item, from, to or responsible person) and press Enter.',
                'Click **Apply Filters**. Click **Reset** to clear everything except the project.',
            ],
            'fields' => [
                ['Item', 'One item. If you also pick a **Category**, the **Item** wins.'],
                ['Category', 'Every item in the category.'],
                ['Transaction Type', '**All Transactions**, **Checked In**, **Checked Out**, **Transfer In** or **Transfer Out**.'],
                ['Status', '**All Statuses**, **Completed** (not a draft) or **Draft**.'],
                ['Responsible Person', 'Only the people who actually have transactions in this project are listed.'],
                ['Supplier / Contact', 'Only contacts that appear on checkins or checkouts of this project are listed.'],
                ['From / To', 'Dates compared with the transaction date.'],
                ['Search', 'Matches reference, item name, **From**, **To** and responsible person.'],
            ],
            'tips' => [
                'Use **Quick filter this table…** (above the table) to narrow what you already see without asking the server again.',
            ],
            'warnings' => [
                'The **Transaction Type** and **Search** filters change the **Ledger** tab only. The five cards and the other tabs keep showing all types. Use **Item**, **Category**, **Status**, person, supplier and dates to change the cards.',
                'The **Supplier / Contact** filter only applies to checkins and checkouts. Transfers have no contact, so they stay in the list.',
            ],
        ],
        [
            'id' => 'item-summary-tab',
            'title' => 'Item Summary tab',
            'icon' => 'boxes',
            'summary' => 'One row per item: how much came in, went out and what is the net.',
            'steps' => [
                'Click the **Item Summary** tab.',
                'Read **Checked In**, **Checked Out**, **Transfer In**, **Transfer Out**, **Net Movement** and **Total Value** for each item.',
                'Use **Quick filter this table…** to find an item.',
            ],
            'fields' => [
                ['Net Movement', '`Checked In + Transfer In − Checked Out − Transfer Out`. A **+** sign is shown for positive values; negative numbers are red.'],
                ['Total Value', 'The sum of the values of **all four** movement types for that item. It is the total value that moved, not a net figure.'],
            ],
            'shots' => [
                ['project-material-ledger-item-summary', 'Item Summary: the four movement columns, the net movement and the total value per item.', 'Project Material Ledger, Item Summary tab'],
            ],
            'tips' => [
                'The same item can appear twice if it was recorded in two different units.',
            ],
        ],
        [
            'id' => 'source-destination-tab',
            'title' => 'Source / Destination tab',
            'icon' => 'swap',
            'summary' => 'Every movement line again, but sorted by value so the biggest movements are on top.',
            'steps' => [
                'Click the **Source / Destination** tab.',
                'Read **Reference**, **Item**, **From**, **To**, **Type**, **Quantity**, **Unit** and **Value**.',
                'Click a reference to open the transaction in a new tab.',
            ],
            'shots' => [
                ['project-material-ledger-source-destination', 'Source / Destination: biggest movements first, with a clickable reference.', 'Project Material Ledger, Source and Destination tab'],
            ],
            'tips' => [
                'This tab ignores paging, so all lines are on one scrolling list.',
            ],
        ],
        [
            'id' => 'supplier-summary-tab',
            'title' => 'Supplier Summary tab',
            'icon' => 'handshake',
            'summary' => 'How much each supplier delivered to the project, with the items inside each total.',
            'steps' => [
                'Click the **Supplier Summary** tab.',
                'Each supplier has a bold row (name, number of items, quantity, unit, value) followed by one lighter row per item.',
                'Use **Quick filter this table…** to find a supplier or an item.',
            ],
            'shots' => [
                ['project-material-ledger-supplier-summary', 'Supplier Summary: a bold row per supplier and unit, with the items that make up the total underneath.', 'Project Material Ledger, Supplier Summary tab'],
            ],
            'warnings' => [
                'Only **Checked In** lines that have a supplier (contact) are included. Transfers and checkouts are not part of this tab.',
                'Supplier quantities are shown per unit (bags, tons, pieces) and never added together across different units.',
            ],
        ],
        [
            'id' => 'reconciliation-tab',
            'title' => 'Stock Reconciliation tab',
            'icon' => 'scale',
            'summary' => 'Check that the ledger explains the stock that is on the shelf today.',
            'steps' => [
                'Click the **Stock Reconciliation** tab.',
                'Optionally set **From** and **To** (or the **Entire project** box) in the filters and click **Apply Filters**.',
                'Read **Opening Stock**, **Period Movement**, **Expected Closing**, **Actual Current Stock** and **Variance** for each item.',
                'A variance of `0` (green) means the ledger and the shelf agree. A red variance needs a look.',
            ],
            'fields' => [
                ['Opening Stock', '`Current stock − net movement since the From date`. This is the stock the project had at the start of the period.'],
                ['Period Movement', 'Net movement inside the period (`in + transfer in − out − transfer out`), with a **+** or minus sign.'],
                ['Expected Closing', '`Opening Stock + Period Movement`.'],
                ['Actual Current Stock', 'The quantity in the project store right now. Shown only when the period reaches today (the **To** date is today or later, or empty). Otherwise it shows a dash.'],
                ['Variance', '`Actual Current Stock − Expected Closing`. **N/A** when the period ends before today.'],
            ],
            'shots' => [
                ['project-material-ledger-reconciliation', 'Stock Reconciliation: opening stock, period movement, expected closing, actual stock and variance.', 'Project Material Ledger, Stock Reconciliation tab'],
            ],
            'tips' => [
                'Read a non-zero variance as "something changed the stock that is not a checkin, checkout or transfer". Typical causes: a stock **adjustment** (damage, addition, subtraction), a return, a material reallocation, or stock that was loaded when the system started without a checkin.',
                'Without a **From** date the opening stock is calculated from the very first movement, so it equals what the project held before its first recorded transaction.',
            ],
            'warnings' => [
                'Only items that have a stock record in this project (even a zero one) are checked. Items with nothing to show (no opening stock, no movement) are hidden.',
                'The tab ignores the **Status**, **Responsible Person** and **Supplier / Contact** filters. Drafts are counted in the movement, but a draft does not change stock, so drafts can create a variance. Finish or delete old drafts first.',
            ],
            'inner' => ['title' => 'How the numbers are worked out', 'items' => [
                'WIMS does not keep a day-by-day stock history for live transactions, so it works **backwards** from today\'s stock: `Opening = current stock − movement from the From date onwards`.',
                '`Expected Closing = Opening + movement between From and To`. `Variance = Current − Expected Closing`, rounded to 4 decimals.',
                'Variance only makes sense when the report ends today, because today\'s stock cannot be compared with a past closing date. That is why it shows **N/A** for past periods.',
            ]],
        ],
        [
            'id' => 'boq-tab',
            'title' => 'BOQ tab (planned versus used)',
            'icon' => 'ruler',
            'summary' => 'When the project has a saved BOQ baseline, compare each planned quantity with what has been checked out.',
            'steps' => [
                'Click the **BOQ** tab. It only exists when the project has a saved baseline in **BOQ Material Compliance** (see [[reports#boq-compliance|BOQ Material Compliance]]).',
                'Read **BOQ Item**, **Unit**, **BOQ Quantity**, **Used Quantity**, **Remaining** and **Material Value**.',
            ],
            'fields' => [
                ['BOQ Quantity', 'The planned quantity saved in the project\'s BOQ baseline.'],
                ['Used Quantity', 'All finished checkouts of that item from the project, since the project began (the date filter does not apply).'],
                ['Remaining', '`BOQ Quantity − Used Quantity`. Red when used more than planned.'],
                ['Material Value', 'Quantity checked out multiplied by the checkout line price (or the item price when the line has none).'],
            ],
            'shots' => [
                ['project-material-ledger-boq', 'BOQ tab: planned quantity, used quantity, remaining and value per item.', 'Project Material Ledger, BOQ tab'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The numbers use exactly the same rule as the BOQ Material Compliance report (finished checkouts only), so the two reports never disagree.',
            ]],
        ],
        [
            'id' => 'open-ledger-transaction',
            'title' => 'Open a transaction and read its history',
            'icon' => 'eye',
            'summary' => 'Click a reference to see the whole checkin, checkout or transfer plus who changed it and when.',
            'steps' => [
                'In the **Ledger** or **Source / Destination** tab click a row (or a reference). A new tab opens.',
                'Read the record exactly as it looks in **Checkins**, **Checkouts** or **Transfers**.',
                'Scroll to **Audit History** to see each action (for example **Checkin Created**), the date and **By** whom.',
                'Click **Print** to print the page, or **Project Material Ledger** (top left) to go back.',
            ],
            'shots' => [
                ['project-material-ledger-transaction', 'The transaction page: the full record on top and the Audit History underneath.', 'Project Material Ledger transaction detail'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The history shows up to the last 50 entries from the activity log for that record. See [[activity-logs]].',
                'Admins and Super Admins can open any transaction. Procurement and Project Managers can open only transactions of their own projects (and transfers involving a Main Warehouse).',
            ]],
        ],
        [
            'id' => 'print-download-ledger',
            'title' => 'Print or download the ledger',
            'icon' => 'printer',
            'summary' => 'Print what you see: the printout follows the tab that is open.',
            'steps' => [
                'Choose the project, apply your filters and open the tab you want to print (for example **Supplier Summary**).',
                'Click **Print** (top right) to open a print-ready page, or **Download** for a PDF named `project-material-ledger-<tab>-<date>-<time>.pdf` (A4 landscape).',
            ],
            'shots' => [
                ['project-material-ledger-print', 'The printout: project, period, who generated it, the five value cards and the table of the open tab.', 'Project Material Ledger print layout'],
            ],
            'tips' => [
                'If you typed something in **Quick filter this table…**, it is sent along as the search text, so the Ledger printout contains only the matching lines.',
            ],
            'warnings' => [
                'The Ledger printout holds at most 5,000 lines. Narrow the filters (dates or item) for very busy projects.',
                'The printed Ledger is sorted by your chosen sort column but is not split into pages of 25.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Each print or download writes an entry such as **Project Material Ledger Printed (Ledger)** in the **Activity Logs** (project, tab and number of records), and also a line in the **Download / Print Logs**. See [[activity-logs]] and [[download-print-logs]].',
            ]],
        ],
    ],
    'behind' => [
        ['title' => 'Transfers use today\'s price', 'text' => 'A transfer line stores no price, so transfer values use the item\'s current price. They can change when the item price changes. Checkin and checkout values keep the price typed on the line.', 'icon' => 'calculator'],
        ['title' => 'Drafts are counted unless you filter them out', 'text' => 'The ledger shows drafts too (marked Draft). They add to the cards and tables but never change stock. Set Status to Completed for real figures.', 'icon' => 'alert'],
        ['title' => 'Adjustments are not in the ledger', 'text' => 'Only checkins, checkouts and transfers are listed. Stock adjustments and returns change stock without appearing here, which is why the Reconciliation tab can show a variance.', 'icon' => 'scale'],
        ['title' => 'Role based, not permission based', 'text' => 'Access is decided by role (Super Admin, Admin, Procurement Manager, Project Manager), so there is no permission to tick. This keeps the page working without extra setup for those roles.', 'icon' => 'shield-check'],
        ['title' => 'Feature switch', 'text' => 'A Super Admin can turn this report off in [[global-feature-control]] (feature **Project Material Ledger**). Everyone else is then sent to a page saying the function is disabled.', 'icon' => 'toggle'],
    ],
    'faq' => [
        ['q' => 'Why is the Total Material Value not equal to the sum of the four cards?', 'a' => 'Transfer Out is not added. It is material that left the project, so it is shown on its own card only.'],
        ['q' => 'The Reconciliation shows a variance for cement. What now?', 'a' => 'Check the project\'s adjustments (damage or corrections), returns and any draft transactions. If none explain it, open the item history and compare the checkins and checkouts with the physical count.'],
        ['q' => 'Why does Supplier Summary not show a supplier I know delivered?', 'a' => 'Either the delivery is outside your date range, it was recorded without a supplier, or it was a transfer from another project (transfers are not supplier deliveries).'],
        ['q' => 'I cannot see the BOQ tab.', 'a' => 'The project has no saved BOQ baseline for the selected items. Create one in **BOQ Material Compliance** (see [[reports#boq-compliance|BOQ Material Compliance]]).'],
        ['q' => 'Can I see another project\'s ledger?', 'a' => 'Only Super Admins and Admins can pick any project. Other allowed roles see the projects assigned to them.'],
    ],
    'related' => ['reports', 'material-value', 'movement-value', 'checkins', 'checkouts', 'transfers', 'adjustments', 'download-print-logs'],
];
