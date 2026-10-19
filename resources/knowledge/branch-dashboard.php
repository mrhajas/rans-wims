<?php

return [
    'group' => 'reports',
    'title' => 'Branch Dashboard',
    'icon' => 'store',
    'order' => 60,
    'summary' => 'A one-page overview of the project or store you are working in: what is in stock right now, the latest checkins, checkouts and transfers, and who manages the place.',
    'where' => 'Sidebar > **Branch Dashboard** (it sits just under **Dashboard**). Use the **project picker** in the top bar to choose which project or store it shows.',
    'path' => '/branch',
    'permissions' => [
        'all' => 'There is no separate permission. Every signed-in person can open the Branch Dashboard, but it only shows the projects and stores that are assigned to them (see "Who sees which warehouses"). A Super Admin can switch the whole page off with **Global Feature Control** (feature **Branch Dashboard**).',
    ],
    'overview' => [
        'The **Branch Dashboard** answers a simple question: "what is going on in my store right now?". It is built for store keepers, project managers and office staff who look after one project or store at a time. It lists the items that are physically in stock, then the most recent checkins, checkouts and transfers for the same place, so you can spot a delivery that has not been booked, or stock that moved this morning.',
        'In WIMS a **project** and its **warehouse** are the same thing, so "branch", "project", "site" and "store" all mean one entry in **Warehouses**. The dashboard has no filters of its own. The **project picker** in the top bar decides what you see: pick one project to see only that project, or pick **All Project(s) / Warehouse(s)** to see every project assigned to you combined into one page.',
        'The numbers on the page are **snapshots, not totals**. The four cards at the top count the rows that are listed below them (at most 100 items and the 15 latest of each transaction type). They do not tell you how many checkins the project has ever had. For full totals and for money values use the [[reports]], [[material-value]] and [[project-material-ledger]].',
    ],
    'concepts' => [
        ['Managed warehouses', 'The projects and stores assigned to you on your user record. Only these appear in the project picker and on the dashboard. A Super Admin or Admin with nothing assigned manages every active project.'],
        ['All Project(s) / Warehouse(s)', 'The picker choice that combines every warehouse you manage. The dashboard then shows an extra **Project(s) / Warehouse(s)** column and lists everybody who manages any of them.'],
        ['Draft', 'A transaction saved but not finished. Drafts never change stock, so the dashboard leaves them out of the latest-transactions lists.'],
        ['Main stock row', 'Stock is kept per item per warehouse (and per variation for items that have variations). The item list shows the main row of each item, which is the quantity you would count on the shelf.'],
    ],
    'functions' => [
        [
            'id' => 'open-branch-dashboard',
            'title' => 'Open the dashboard for one project or for all your projects',
            'icon' => 'store',
            'summary' => 'Choose which warehouse the dashboard describes, using the project picker that is on every page.',
            'steps' => [
                'Click **Branch Dashboard** in the left menu.',
                'Click the **project picker** at the top of the page (it shows the name of the project you are working in).',
                'Choose **All Project(s) / Warehouse(s)** to see everything assigned to you together, or click one project name. The picker has a search box when you manage many projects.',
                'The page refreshes in place. The big card at the top shows the project name and its code (for example `DEMO-A`), or **All Project(s) / Warehouse(s)** with the line "Showing all your project(s) / warehouse(s)".',
            ],
            'shots' => [
                ['branch-overview', 'All Project(s) / Warehouse(s) selected: the header card, the four summary cards, the item list (with a Warehouse column) and the three latest-transactions cards.', 'Branch dashboard showing all warehouses'],
                ['branch-single-warehouse', 'One project selected in the project picker. The Project(s) / Warehouse(s) column disappears because every row belongs to the same project.', 'Branch dashboard for a single project'],
            ],
            'tips' => [
                'The project you pick stays selected while you move to other pages (checkins, reports and so on), so you can come back to the same view.',
                'If the page says **No project(s) / warehouse(s) selected. Choose one from the top bar to view its overview.** you are not assigned to any project. Ask an administrator to assign one to your user.',
            ],
            'warnings' => [
                'A project that has been marked **Completed** disappears for everyone except Super Admins. It is no longer offered in the picker and its stock and transactions are not shown here.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                '**Who sees which warehouses.** WIMS first takes the active, not-completed warehouses assigned to your user. If you have none assigned and you are a Super Admin, Admin, Procurement Manager or Store Manager, it uses every active warehouse instead. Anyone else with nothing assigned sees the "No warehouse selected" card.',
                'If you picked a project in the project picker and you manage it, only that one is used. Otherwise (picker on **All Project(s) / Warehouse(s)**, or a project you do not manage) all your managed warehouses are combined.',
                'The picker choice is remembered in your session, which is why it follows you around WIMS.',
                'Switching project is checked on the server: you cannot pick a project you are not assigned to, and only a Super Admin can pick a completed project.',
            ]],
        ],
        [
            'id' => 'read-summary-cards',
            'title' => 'Understand the header card and the four summary cards',
            'icon' => 'info',
            'summary' => 'What the managers line and the Items, Checkins, Checkouts and Transfers cards actually count.',
            'steps' => [
                'Look at the top card. It shows the project **name**, its **code** and a **Managed by** list with a round avatar for each person assigned to the project. If nobody is assigned it shows an **Unassigned** chip.',
                'Look at the four cards under it: **Items**, **Checkins**, **Checkouts** and **Transfers**.',
                'To see the full lists behind a card, scroll down. Each card counts the rows of the list that follows it.',
            ],
            'fields' => [
                ['Managed by', 'Every user who has this project in their assigned projects. In **All Project(s) / Warehouse(s)** mode it is everybody assigned to any of your projects, each name once.'],
                ['Items card', 'The number of rows in the **Items** list. The list holds at most **100** rows, so the card never shows more than 100.'],
                ['Checkins card', 'The number of rows in the **Checkins** list, at most **15** (the 15 newest).'],
                ['Checkouts card', 'The number of rows in the **Checkouts** list, at most **15**.'],
                ['Transfers card', 'The number of rows in the **Transfers** list, at most **15**. A transfer counts for a project if the project is either the sending or the receiving side.'],
            ],
            'warnings' => [
                'A card that shows **15** means "15 or more". It is not the real total number of transactions. Open the Checkins, Checkouts or Transfers pages (or the [[reports]]) for real totals.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The cards are calculated in the page from the lists that the server sent, not from separate counts, which is why they stop at 100 and 15.',
                'Latest transactions are taken from **all** users, not only your own records. The "own records only" rule used on the Checkins, Checkouts and Transfers lists does not apply here.',
            ]],
        ],
        [
            'id' => 'browse-items-in-stock',
            'title' => 'Browse, search and sort the items in stock',
            'icon' => 'package',
            'summary' => 'See what is on the shelf, newest movement first, and jump to any item.',
            'steps' => [
                'Scroll to the **Items** card (subtitle **recent first**).',
                'Type in **Search by code, name, project(s) / warehouse(s) or unit...** to narrow the list. It filters as you type and checks all four columns.',
                'Click a column heading (**Code**, **Name**, **Project(s) / Warehouse(s)**, **Quantity**, **Unit**) to sort. Click again to reverse the order. A small arrow shows the direction.',
                'Click a row to open that item\'s page. Click **View all** (top right of the card) to open the full **Items** list.',
            ],
            'fields' => [
                ['Code', 'The item code (for example `RSI-0001`).'],
                ['Name', 'The item name.'],
                ['Project(s) / Warehouse(s)', 'Only shown in **All Project(s) / Warehouse(s)** mode: which project holds this stock.'],
                ['Quantity', 'Quantity on hand in that warehouse, in a green chip.'],
                ['Unit', 'The unit code, for example `bags`, `pcs` or `tons`.'],
            ],
            'tips' => [
                'The list starts with the stock whose quantity changed most recently, so a delivery or an issue you made a minute ago is near the top.',
                'The same item can appear more than once in **All Project(s) / Warehouse(s)** mode, once per project that holds it.',
            ],
            'warnings' => [
                'Only the 100 most recently changed stock lines are sent to the page, and **Search** only looks inside those 100. To find an older item use **Items** or the [[reports]] stock reports.',
                'Items with zero (or negative) stock are not listed. If an item is missing, it has none on hand.',
                'Store Managers and Procurement Managers do not see this card at all. Their version of the page shows only the three latest-transactions cards (see below).',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The list reads the stock table for your selected warehouses, keeps rows with quantity above zero, ignores the extra per-variation rows, sorts by the last update time and keeps the first 100.',
                'Search and sorting happen inside your browser on those rows, which is why they are instant.',
                'The role check that hides the card is made in the page by role name (Store Manager and Procurement Manager). It is a display rule, not a security rule: the items are still sent to the page.',
            ]],
        ],
        [
            'id' => 'latest-transactions',
            'title' => 'Follow the latest checkins, checkouts and transfers',
            'icon' => 'swap',
            'summary' => 'Three cards list the 15 newest finished transactions for your project, each one a click away from its full record.',
            'steps' => [
                'Scroll to the three cards **Checkins**, **Checkouts** and **Transfers**.',
                'Read each row: the **reference** in bold (for example `RSC-0007`), the **date** in a grey chip, then **Contact** (or **Route** for a transfer, shown as sending project to receiving project) and **By**, the person who recorded it.',
                'Click a row to open the full record.',
            ],
            'fields' => [
                ['Reference', 'The number WIMS gave the record when it was saved: `RSC-` for checkins, `RSO-` style references for checkouts and `RST-` for transfers.'],
                ['Contact', 'The supplier who delivered (checkin) or the person or company the material was issued to (checkout).'],
                ['Route', 'For a transfer: the sending project, an arrow, then the receiving project.'],
                ['By', 'The user who created the record.'],
            ],
            'shots' => [
                ['branch-store-manager', 'What a Store Manager sees: the page shows only the three latest-transactions cards, without the items list.', 'Branch dashboard as a store manager'],
            ],
            'tips' => [
                'Use this to check that something you expect has been booked. If a delivery arrived this morning but no checkin is listed here, it has not been recorded (or it is still a draft).',
                'A transfer that is still waiting for approval is saved as a draft and is therefore not listed until it is approved. See [[transfers]].',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Each list takes records of your selected warehouses, leaves out drafts, sorts by newest record first and keeps 15.',
                'Checkins and checkouts are matched on the warehouse of the record. Transfers are matched when your warehouse is the **from** or the **to** side.',
                'Deleted records never appear because they are removed from the normal tables (they can be found in [[deleted-records]]).',
            ]],
        ],
        [
            'id' => 'print-branch-report',
            'title' => 'Print the dashboard as a report',
            'icon' => 'printer',
            'summary' => 'Print or save a tidy one-page copy of what you see, with a company header and the name of who printed it.',
            'steps' => [
                'Open the dashboard for the project you want (see the first function).',
                'Click **Print Report** at the top right of the page.',
                'In your browser print window choose your printer, or **Save as PDF**, then print.',
            ],
            'tips' => [
                'The printout hides the search box and the **View all** button and adds a header with the logo, the system version, **Generated By** and **Generated On**, plus a title **Branch Dashboard Report** with the project name and code.',
            ],
            'warnings' => [
                'The printout shows exactly what is on screen, including your search text and sort order. Clear the search box first if you want the complete list.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Printing here uses your browser\'s own print function, so it is not written to the **Download / Print Logs** the way PDF and print buttons on the other reports are. See [[download-print-logs]].',
            ]],
        ],
    ],
    'behind' => [
        ['title' => 'Scoped to your projects, not to your records', 'text' => 'The dashboard is limited by the projects assigned to you. Within those projects it shows records created by anybody, which is different from the Checkins, Checkouts and Transfers lists where people without the "view all" right see only their own records.', 'icon' => 'shield-check'],
        ['title' => 'Always live', 'text' => 'Nothing on this page is stored or cached. Every time you open it or change the project picker, WIMS reads the current stock and the newest transactions again.', 'icon' => 'refresh'],
        ['title' => 'Completed projects are hidden', 'text' => 'When a project is marked complete it is locked and removed from the project picker for everyone except Super Admins, so it also disappears from this dashboard.', 'icon' => 'lock'],
    ],
    'faq' => [
        ['q' => 'The Checkins card says 15 but I know we made hundreds of checkins.', 'a' => 'The cards count the rows of the lists below them, and the lists stop at the 15 newest records (100 for items). Use the Checkins list or the checkin report for real totals.'],
        ['q' => 'I cannot see the Items list.', 'a' => 'If your role is Store Manager or Procurement Manager the page deliberately shows only the latest transactions. Open **Items** or the stock reports in [[reports]] to see stock.'],
        ['q' => 'An item I just received is not in the list.', 'a' => 'The list only shows the 100 stock lines changed most recently, and only items with quantity above zero. Also check that the checkin was saved as finished, not as a draft.'],
        ['q' => 'It says "Unassigned" next to Managed by.', 'a' => 'Nobody has this project in their assigned projects. An administrator can add the project to a user in [[users]].'],
        ['q' => 'Why is my project missing from the picker?', 'a' => 'It is either not assigned to you or it has been marked Completed. Only Super Admins still see completed projects.'],
    ],
    'related' => ['reports', 'material-value', 'project-material-ledger', 'checkins', 'checkouts', 'transfers', 'warehouses-projects'],
];
