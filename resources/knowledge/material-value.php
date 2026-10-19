<?php

return [
    'group' => 'reports',
    'title' => 'Material Value',
    'icon' => 'calculator',
    'order' => 20,
    'summary' => 'Pick the materials (or whole categories) and the projects or stores you care about, and WIMS tells you exactly how much money that stock is worth right now.',
    'where' => 'Sidebar > **Material Value**.',
    'path' => '/material-value',
    'permissions' => [
        'read-material-value' => 'Open the **Material Value** page, calculate a value, open the item details window, and print or download the report. Super Admins always have it. By default only the Quantity Surveyor (QS) role is given it, so an administrator must tick it on other roles (see [[roles-and-permissions-explained]]).',
    ],
    'overview' => [
        '**Material Value** is a calculator for stock. You choose **what** to value (specific items, or every item in one or more categories) and **where** (one or more projects or stores). WIMS then adds up, for each project, the quantity on hand multiplied by the item\'s price, and shows a subtotal per project and a grand total. It is the quick way to answer questions such as "How much cement is sitting on all our private projects?" or "What are the steel bars in the main warehouses worth today?"',
        'It looks at **today\'s stock only**. There is no date filter. If you want to see how much material moved during a period, use the [[movement-value]] report or the [[project-material-ledger]]. If you want the value of **everything** a warehouse holds, use the **Warehouse Value** report in [[reports]].',
        'The value uses the **current price on the item record** (not the price paid on each delivery), so changing an item\'s price in [[items]] changes the value of all existing stock of that item immediately. The amounts are shown in Tanzanian shillings (`Tsh`).',
    ],
    'concepts' => [
        ['Stock value', 'Quantity on hand multiplied by the item\'s current price, added up for the selected items and warehouses. Items with no price count as zero.'],
        ['By Item / By Category', 'Two ways to choose materials. **By Item** lets you tick individual items. **By Category** values every item in the ticked categories. Both selections are remembered and added together.'],
        ['Warehouse type', 'The type chosen on a project or store (Main Warehouse, Private, Government, Directors, Rans Warehouse). The quick buttons under **Choose warehouses** select all projects of one type at once.'],
        ['Subtotal / Grand Total', 'The subtotal is the value for one project. The grand total is the sum of all subtotals shown.'],
    ],
    'functions' => [
        [
            'id' => 'open-material-value',
            'title' => 'Open the page and understand the layout',
            'icon' => 'calculator',
            'summary' => 'The page has two numbered steps (choose materials, choose warehouses) and a **Calculate Value** button. Results appear below it.',
            'steps' => [
                'Click **Material Value** in the left menu.',
                'Read **Report Filters**. Step **1 Choose materials** is on top and step **2 Choose warehouses** is under it.',
                'Choose what you want in both steps (see the next two functions), then click **Calculate Value**.',
                'Until you calculate, the page shows "Choose materials and warehouses above, then calculate."',
            ],
            'shots' => [
                ['material-value-filters', 'The two filter steps: materials on top, warehouses below, and the green Calculate Value button.', 'Material Value filter steps'],
            ],
            'tips' => [
                'After you calculate, the address of the page holds your choices. Bookmark it or send the link to a colleague who has the same permission and they will see the same result.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Only items that have stock above zero in at least one warehouse you may use appear in the picker, so you never choose an item that would return nothing.',
                'Only categories that contain such items appear in the category picker.',
                'The warehouse list is limited to your assigned, active projects. Completed projects are hidden from everyone except Super Admins (they show a red dot and the word **Completed**).',
            ]],
        ],
        [
            'id' => 'choose-materials',
            'title' => 'Choose materials: by item or by category',
            'icon' => 'tag',
            'summary' => 'Search for items or categories and tick as many as you need.',
            'steps' => [
                'In step **1 Choose materials** click **By Item** to pick single items, or **By Category** to pick whole categories.',
                'Type in the search box (**Search materials...** or **Search categories...**). You can search by name or by item code.',
                'Click a name in the list to tick it. It appears as a chip under the list. Click the small cross on a chip to remove it.',
                'Repeat as often as you like. The counter under the chips (for example **3 selected**) shows how many you ticked in the current tab.',
                'Click **Clear selection** to untick everything in the current tab, or **Reset** (top right of the step) to start the whole page again.',
            ],
            'fields' => [
                ['By Item', 'Tick individual items. The list shows the item name and its code.'],
                ['By Category', 'Tick categories. Every item that belongs to a ticked category is valued.'],
                ['Search box', 'Filters the list as you type. The list shows at most 50 rows at a time ("Showing 50 of 212, type to narrow down"), so type a few letters to find the rest.'],
            ],
            'tips' => [
                'You can mix both tabs. For example tick two single items under **By Item**, switch to **By Category**, tick **Steel**, and the report values those two items **and** every steel item.',
                'An item that belongs to a ticked category and is also ticked as a single item is counted once.',
            ],
            'warnings' => [
                'If you tick nothing in either tab, the **Calculate Value** button returns an empty result ("No stock found for that selection").',
            ],
        ],
        [
            'id' => 'choose-warehouses',
            'title' => 'Choose the warehouses (projects and stores)',
            'icon' => 'building',
            'summary' => 'Tick the projects or stores to include, one by one or by type.',
            'steps' => [
                'In step **2 Choose warehouses** click a project name to tick it. A tick appears in the box and it is added under the list as a green chip.',
                'To tick all projects of one type, click one of the quick buttons, for example **All Private** or **All Main Warehouse**. The number on the button is how many projects of that type you may use.',
                'Click **Select All** to tick every project in the list, or **Clear** to untick them.',
                'Check the counter (for example **4 warehouse(s) selected**) and click **Calculate Value**.',
            ],
            'fields' => [
                ['Project card', 'The project name, a coloured dot (green = running, red = completed) and its type (or the word **Completed**).'],
                ['Quick buttons', 'One per warehouse type: **Main Warehouse**, **Private**, **Government**, **Directors**, **Rans Warehouse**, each with a count. Clicking one adds all projects of that type to what is already ticked.'],
            ],
            'warnings' => [
                'You must tick at least one warehouse. Without one, WIMS returns an empty result even if you ticked materials.',
                'Projects whose type is something else (for example the demo projects whose type is **Active**) have no quick button. Tick them one by one or use **Select All**.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Any warehouse number that is not on your allowed list is thrown away on the server before the calculation, so editing the address cannot show another project\'s stock.',
                'The list is sorted by type and then name.',
            ]],
        ],
        [
            'id' => 'calculate-value',
            'title' => 'Calculate the value and read the result',
            'icon' => 'chart',
            'summary' => 'Click **Calculate Value** to see four summary cards and one card per project with every item line.',
            'steps' => [
                'Click **Calculate Value** (the button shows **Calculating...** while it works).',
                'Read the four cards at the top: **Grand Total Value**, **Materials**, **Project(s) / Warehouse(s)** and **Stock Lines**.',
                'Under them there is one card per project, the richest first. Each card shows the project name, its type, and a **Subtotal** on the right.',
                'Click a project card header to fold or open its table. After a calculation all cards start open.',
                'In each table read **Item** (with its code under the name), **Quantity**, **Unit**, **Unit Price** and **Value**.',
            ],
            'fields' => [
                ['Grand Total Value', 'The sum of all project subtotals.'],
                ['Materials', 'How many different items appear in the result.'],
                ['Warehouses', 'How many projects have at least one matching stock line. A project with none of your materials in stock is not shown and not counted.'],
                ['Stock Lines', 'The number of item rows in all tables together.'],
                ['Quantity', 'Quantity on hand in that project, in the item\'s unit.'],
                ['Unit Price', 'The item\'s current price from the item record.'],
                ['Value', '`Quantity × Unit Price` for that line.'],
                ['Subtotal', 'The sum of the **Value** column for that project.'],
            ],
            'shots' => [
                ['material-value-results', 'Result for five materials across seven projects: summary cards, one expanded project card and the item table with Quantity, Unit Price and Value.', 'Material Value result with project cards'],
                ['material-value-category-mode', 'The same page used in By Category mode: two categories ticked and every item inside them valued.', 'Material Value in By Category mode'],
            ],
            'tips' => [
                'To compare two situations (for example "before" and "after" a delivery), run the report twice and keep both printouts.',
            ],
            'warnings' => [
                'Only stock above zero is valued. Negative stock (possible when over-selling is switched on) is left out, so the total can be higher than the real figure if some items are negative.',
                '**No stock found for that selection** means none of the chosen items has stock in the chosen warehouses, not that the report is broken.',
            ],
            'inner' => ['title' => 'How the numbers are worked out', 'items' => [
                'For each stock record that belongs to a chosen warehouse and a chosen item, with quantity above zero: `Value = stock quantity × item price` (an empty price counts as 0).',
                '`Subtotal` = sum of the values of one warehouse. `Grand Total` = sum of all subtotals. `Materials` counts distinct items, `Warehouses` counts warehouses that have lines.',
                'Lines in a project are sorted by item name. Project cards are sorted by subtotal, highest first.',
                'The page asks the server for the numbers each time you click **Calculate Value**, so they are always live stock, never a saved copy.',
            ]],
        ],
        [
            'id' => 'item-details',
            'title' => 'Open the item details window',
            'icon' => 'eye',
            'summary' => 'Click any item row to see that item\'s total stock and value across all the warehouses you may use.',
            'steps' => [
                'In a project table, click the row of the item.',
                'Read the four boxes: **Unit**, **Unit Price**, **Total Quantity** and **Total Value**.',
                'Read the **Warehouse Stock Details** table to see the quantity in every warehouse.',
                'Click **Close** (or click outside the window) to go back.',
            ],
            'shots' => [
                ['material-value-item-modal', 'The item details window: unit, price, total quantity, total value and the stock held in each warehouse.', 'Material Value item details window'],
            ],
            'warnings' => [
                'The window shows stock in **all** warehouses you may use, not only the ones you ticked. Its **Total Value** can therefore be larger than the value on the row you clicked.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The window loads its data separately when you click, so it always reflects the latest stock. It checks the same `read-material-value` permission again on the server.',
            ]],
        ],
        [
            'id' => 'print-download-material-value',
            'title' => 'Print or download the report',
            'icon' => 'printer',
            'summary' => 'Make a paper or PDF copy of the result with a company header, the grand total and one table per project.',
            'steps' => [
                'Calculate a value first. The **Print** and **Download** buttons only appear at the top right after you have calculated.',
                'Click **Print** to open a print-ready page in a new tab (your browser\'s print window opens by itself), or **Download** to save a PDF named `material-value-report-<date>-<time>.pdf`.',
            ],
            'shots' => [
                ['material-value-print', 'The print layout: company name, who generated it and when, counts, the grand total band and one table per project.', 'Material Value print layout'],
            ],
            'tips' => [
                'The printout contains exactly the materials and warehouses you calculated. To print something different, change the filters and calculate again.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Every print and every PDF download is written to the **Download / Print Logs** automatically (who, when, from where). See [[download-print-logs]].',
                'The PDF is A4 portrait. It carries the generated date, the person who generated it, the number of materials and warehouses and the grand total band.',
            ]],
        ],
        [
            'id' => 'reset-material-value',
            'title' => 'Start over with Reset',
            'icon' => 'refresh',
            'summary' => 'Clear all choices and the result in one click.',
            'steps' => [
                'Click **Reset** in step **1 Choose materials**.',
                'The page reloads with no materials, no warehouses and no result.',
            ],
        ],
    ],
    'behind' => [
        ['title' => 'One formula for every value report', 'text' => 'Stock value is always quantity on hand multiplied by the item\'s current price. The same rule is used by the Reports hub, the Warehouse Value report, the executive summary and the dashboard stock value, so the numbers agree with each other.', 'icon' => 'calculator'],
        ['title' => 'Your project list is enforced on the server', 'text' => 'The page, the item details window and the printout only ever use warehouses assigned to you. Extra warehouse numbers added to the link are ignored.', 'icon' => 'shield-check'],
        ['title' => 'Prints are logged', 'text' => 'Every Print and Download writes a line to the Download / Print Logs, so management can see who printed stock values.', 'icon' => 'receipt'],
        ['title' => 'Feature switch', 'text' => 'A Super Admin can turn the whole **Material Value** page off in [[global-feature-control]] (feature **Material Value**). The menu entry disappears and everyone except Super Admins is sent to a page saying "This WIMS function is currently disabled by the system administrator."', 'icon' => 'toggle'],
    ],
    'faq' => [
        ['q' => 'Why does Material Value not match the Warehouse Value report?', 'a' => 'Warehouse Value adds up **every** item a warehouse holds. Material Value adds up only the items or categories you ticked and only the warehouses you ticked.'],
        ['q' => 'The price of an item changed. Will old reports change?', 'a' => 'Yes. The value always uses the price on the item today. WIMS does not keep the price that applied on a past date, so a printout is only correct on the day it was made.'],
        ['q' => 'I ticked a warehouse but it is not in the result.', 'a' => 'It has none of the ticked items in stock (above zero). Only warehouses with at least one matching line are listed.'],
        ['q' => 'I cannot find Material Value in my menu.', 'a' => 'Your role does not have `read-material-value`, or the feature is switched off. Ask a Super Admin. See [[roles-and-permissions-explained]].'],
        ['q' => 'Does it include items that are on order or not yet checked in?', 'a' => 'No. Only stock that has been checked in and not issued. Drafts and pending transfers do not change stock, so they are not counted.'],
    ],
    'related' => ['reports', 'movement-value', 'project-material-ledger', 'items', 'categories', 'warehouses-projects', 'download-print-logs'],
];
