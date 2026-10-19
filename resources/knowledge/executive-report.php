<?php

return [
    'group' => 'reports',
    'title' => 'Executive Intelligence Report',
    'icon' => 'sparkles',
    'order' => 50,
    'summary' => 'A weekly briefing for management, written from the real numbers in WIMS and emailed automatically as a PDF. This guide covers what is inside it, who gets it, when it is sent and how to check that it went out.',
    'where' => 'Sidebar > **Executive Intelligence Report** (Admins and Super Admins).',
    'path' => '/executive-report',
    'permissions' => [
        'manage-executive-reports' => 'The one permission for this module. It allows opening the page, saving the schedule, adding, editing and deleting subscribers, reading the send logs and pressing **Send Test Now**. By default Super Admin and Admin have it. The menu entry is also limited to the Admin and Super Admin roles, so ticking the permission on another role is not enough to show the menu.',
    ],
    'overview' => [
        'Managers rarely have time to open ten reports. The **Executive Intelligence Report** gathers the numbers that matter once a week and sends them by email. Each week WIMS looks at the last seven days, compares them with the seven days before, looks for things that are stuck or going wrong, writes a short summary in plain English and emails a PDF to everyone on the subscriber list. A second PDF, the **Total Inventory Report** (all stock in all warehouses), is attached to the same email.',
        'The page you use to run this is small on purpose. There are three tabs. **Settings** decides if the report is on and when it goes. **Subscribers** is the list of people who get it. **Send Logs** is the history of what went out, with the failure reason if something went wrong.',
        'People on the subscriber list do **not** need a WIMS login. A director who never signs in can still receive the report: you only enter a name and an email address.',
    ],
    'concepts' => [
        ['Reporting week', 'Always the seven days that **end yesterday**. If the report is sent on Monday 12 October, the week is Monday 5 October to Sunday 11 October. Today is never included because it is not finished.'],
        ['Prior week', 'The seven days immediately before the reporting week. Every "change %" in the report compares the two.'],
        ['Subscriber', 'A name and an email address on the **Subscribers** tab. Only **active** subscribers receive the report.'],
        ['Send Test Now', 'Sends the real report right now to all active subscribers, ignoring the on/off switch. It is the only way to see the report.'],
        ['Send log', 'One row per send: the week covered, how many people it went to, **Sent** or **Failed**, when, and the error text for a failure.'],
        ['Notification subscription', 'A different list: WIMS users who ticked **Executive Intelligence Report - Weekly** in the Email Notifications screen. They get a short "the report has been sent" notice, not the PDF.'],
    ],
    'functions' => [
        [
            'id' => 'schedule-the-report',
            'title' => 'Switch the weekly report on and choose the day and time',
            'icon' => 'clock',
            'summary' => 'Set when the briefing goes out each week.',
            'steps' => [
                'Open **Executive Intelligence Report** and stay on the **Settings** tab.',
                'Use the **Enabled** switch. **Yes** (green) means WIMS sends the report automatically. **No** means nothing is sent on schedule.',
                'Choose the **Day of Week** (Sunday to Saturday) and the **Time** (24-hour clock, for example `07:00`).',
                'Click **Save Settings**. The message "Executive Report settings updated." appears.',
                'Check the card **Next Scheduled Send** at the top. It shows the day and time (for example **Monday 07:00**), or **Paused** when the switch is off.',
            ],
            'fields' => [
                ['Enabled', 'Required. Yes or No. If you never saved settings, the report starts **off**.'],
                ['Day of Week', 'Required. Sunday, Monday, Tuesday, Wednesday, Thursday, Friday or Saturday. Default when nothing is saved: Monday.'],
                ['Time', 'Required. Hours and minutes, `HH:MM`. Default when nothing is saved: `07:00`. The time is the server time of your WIMS system.'],
            ],
            'shots' => [
                ['executive-report-settings', 'The Settings tab: the four cards at the top and the Delivery Settings box with the Enabled switch, day, time and the two buttons.', 'Executive report settings tab'],
            ],
            'tips' => [
                'A good time is early on the first working day of the week, so managers read it before their Monday meeting.',
                'You can change the day or time at any time. The new schedule is used from the next minute.',
            ],
            'warnings' => [
                'The automatic send needs the WIMS **scheduler** to be running on the server (the standard cron job that runs every minute). If it is not running, nothing is sent even when the page says **Enabled**. Use the **Send Logs** tab to confirm a report really went out.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The three settings are stored as `executive_report_day`, `executive_report_time` and `executive_report_enabled` in the system settings.',
                'The server\'s scheduler reads those settings every minute and runs the send job on the chosen day at the chosen minute. The job never runs twice at the same time.',
                'At that moment the job checks **Enabled** again. If it is **No**, the job stops and writes nothing in the logs.',
                'The job also refuses to send a second time for the same reporting week when a **Sent** log already exists, which protects the subscribers from duplicates if the scheduler fires twice.',
            ]],
        ],
        [
            'id' => 'manage-subscribers',
            'title' => 'Add, edit, switch off and remove subscribers',
            'icon' => 'users',
            'summary' => 'Control exactly who receives the report.',
            'steps' => [
                'Open the **Subscribers** tab (it shows how many people are on the list).',
                'To add someone, type the **Name** and **Email** in the **Add Subscriber** box and click **Add**. "Subscriber added." appears and the new person is in the table, active.',
                'To change someone, click the pencil icon on their row. The name and email become boxes and **Active** becomes a tick box. Click the green tick to save or the cross to cancel.',
                'To stop sending to someone without deleting them, edit the row and untick **Active** (their chip turns grey **No**).',
                'To remove someone, click the red bin icon and confirm **Remove this subscriber?**.',
            ],
            'fields' => [
                ['Name', 'Required, up to 100 characters. It is used in the greeting, for example "Good morning, Mr./Ms. Amina".'],
                ['Email', 'Required, a valid email address, up to 150 characters.'],
                ['Active', 'Yes or No. Only **Yes** receives the report. New subscribers start as **Yes**.'],
                ['Added', 'The date the subscriber was created.'],
            ],
            'shots' => [
                ['executive-report-subscribers', 'The Subscribers tab: the Add Subscriber box and the list with an Active chip, the date added and edit and delete icons. One person has been switched off.', 'Executive report subscribers tab'],
                ['executive-report-subscriber-edit', 'Editing a subscriber in place: name, email and the Active tick box with save and cancel icons.', 'Editing an executive report subscriber'],
            ],
            'tips' => [
                'Use a shared mailbox, for example a management group address, if people change often.',
                'The list is for **external or management** readers. Staff who just want to know that the report went out can use the Email Notifications screen instead (see "Who receives what" below).',
            ],
            'warnings' => [
                'WIMS does not stop you adding the same email twice. A duplicate person simply receives two copies. Edit or delete the extra row.',
                'Deleting a subscriber is immediate. Their name is kept in old send logs, but they will not get the next report.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Every send reads the list again, so an add, a switch-off or a deletion affects the very next send.',
                'If a subscriber is tied to a WIMS user, the name in the table is a link that opens that user.',
            ]],
        ],
        [
            'id' => 'send-test-now',
            'title' => 'Send the report now (Send Test Now)',
            'icon' => 'mail',
            'summary' => 'Send the real report immediately, for example to check a new subscriber or to see what the report looks like.',
            'steps' => [
                'Make sure the people on the **Subscribers** tab who should **not** get a test are switched to **No** (see the warning below).',
                'Click **Send Test Now** (it is at the top right and also on the **Settings** tab).',
                'Wait while the button shows **Sending...**. Building the PDFs can take a minute on a large system.',
                'When "Test Executive Intelligence Report sent to all active subscribers." appears, open the **Send Logs** tab and check the newest row says **Sent**.',
            ],
            'shots' => [
                ['executive-report-logs', 'The Send Logs tab after a send: week covered, recipients, a green sent chip, the time and (for a failure) the reason.', 'Executive report send logs tab'],
            ],
            'warnings' => [
                'There is **no preview screen**. Send Test Now emails the real report to **every active subscriber**, not only to you. To see the report privately, switch everyone else to inactive first, add your own address as the only active subscriber, click **Send Test Now**, then restore the list.',
                'The message "sent to all active subscribers" appears even when the send failed. The **Send Logs** tab is the truth: a failed send is logged as **Failed** with the reason.',
                'A test counts as a real send: it is written to the send log exactly like a scheduled one (there is no "test" label) and it covers the same last-seven-days week.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The button runs the same send job as the scheduler, but "forced": it ignores the **Enabled** switch and it ignores the "already sent this week" protection, so you can press it more than once.',
                'It only runs for your own company account and only reads active subscribers.',
                'When it finishes it also sends the short notification described under "Who receives what".',
            ]],
        ],
        [
            'id' => 'read-send-logs',
            'title' => 'Read the send logs',
            'icon' => 'list',
            'summary' => 'Check what went out, to how many people and why a send failed.',
            'steps' => [
                'Open the **Send Logs** tab.',
                'Read **Week** (for example `2026-10-03 → 2026-10-09`), **Recipients** (number of people it was sent to), **Status** (**Sent** green, **Failed** red), **Sent At** and **Notes**.',
                'For a failed row, read **Notes**. It holds the error text, for example "Connection could not be established with host…". Hover it to read the whole text.',
                'Use the top cards: **Last Report** shows the status of the newest row, **Reports Sent** counts the rows listed.',
            ],
            'fields' => [
                ['Week', 'The first and last day of the seven days the report describes.'],
                ['Recipients', 'How many subscribers the email was sent to. A failed send shows `0`.'],
                ['Status', '**Sent** or **Failed**.'],
                ['Sent At', 'The date and time the send finished.'],
                ['Notes', 'Empty for a good send. The technical error for a failed one.'],
            ],
            'tips' => [
                'A failure is usually an email setup problem (server, password) or a missing PDF tool. Fix it, then click **Send Test Now** to send the missed week.',
            ],
            'warnings' => [
                'Only the latest 100 rows are shown and logs cannot be edited or deleted from the page.',
                '**Sent** means WIMS handed the emails to the mail system. If the mail server then rejects an address, that is not visible here. Ask the recipient, or check the mail server logs.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'After a good send WIMS writes one **Sent** row that lists the name and email of every recipient.',
                'If anything throws an error while building or mailing the report, WIMS writes a **Failed** row with the error text and nothing more is sent for that account. If the failure happens in the middle of the subscriber list, the earlier people may already have received the email.',
                'The error is also written to the system log.',
            ]],
        ],
        [
            'id' => 'what-is-in-the-report',
            'title' => 'What is inside the report',
            'icon' => 'file-text',
            'summary' => 'Every section of the email and the PDF, and exactly how its numbers are worked out.',
            'steps' => [
                'The **email** is short: a greeting ("Good morning, Mr./Ms. Name"), the written summary, three headline numbers (**Total Inventory Value**, **Consumed This Week**, **Active Projects**), the notable events and the recommended actions. It tells the reader the full report is attached.',
                'The **PDF** "Executive Intelligence Report" (file `executive-intelligence-report-<start>-<end>.pdf`) holds every section below.',
                'The second PDF "Total Inventory Report" (file `total-inventory-report-<start>-<end>.pdf`) lists all stock above zero in every warehouse with quantity, price and totals, like the **General Stock Report** in [[reports]].',
            ],
            'shots' => [
                ['executive-report-email', 'The email as a manager receives it: summary, headline numbers, notable events and recommended actions.', 'Executive intelligence report email'],
                ['executive-report-pdf-top', 'The top of the PDF: summary, notable events, recommended actions and the key metrics with week-on-week change.', 'Executive intelligence report PDF, first page'],
                ['executive-report-pdf-insights', 'The middle of the PDF: workflow pipelines, daily site report compliance, the BOQ variance flag (135.7% of baseline) and the damage adjustment watch.', 'Executive intelligence report PDF, insight sections'],
            ],
            'fields' => [
                ['Executive Summary, Notable Events, Recommended Actions', 'The written part. See "How the summary is written" below.'],
                ['Total Inventory Value', '`Σ (stock quantity × item price)` over all stock records of all warehouses.'],
                ['Consumed This Week', '`Σ (checked-out quantity × line price)` for finished checkouts dated in the week. The line price is the price on the checkout line, or the item price if the line has none. The brackets show the change against the prior week.'],
                ['Active Project(s) / Warehouse(s)', 'Number of active warehouses (not completed) whose type is **Private**, **Government**, **Directors** or **General**. Main Warehouses, Rans Warehouses and any other type are not counted.'],
                ['Month to Date', 'Consumption from the 1st of the month up to the last day of the reporting week, compared with the same number of days of the previous month.'],
                ['Transaction table', 'For Checkins, Checkouts, Transfers and Adjustments: the number of finished (non-draft, not deleted) records dated in the week, in the prior week and the change %.'],
                ['Top Consuming Projects This Week', 'The three warehouses with the highest checkout value in the week.'],
                ['Project Portfolio Overview', 'Every active project (types as above) with its stock value and its completion %, richest first.'],
                ['Top Used Materials This Week', 'The five items with the largest quantity checked out in the week.'],
                ['Material Requests Pipeline', 'Open material requests (not closed or rejected) counted by status, and a list of those **not updated for more than 5 days**, with the days stuck.'],
                ['Material Reallocations Pipeline', 'Open reallocations (not completed, rejected or cancelled) by status, and those stuck more than 5 days.'],
                ['Daily Site Report Compliance', 'How many of the expected site reports (one per assigned site engineer and project) were submitted in the week, as `submitted / expected (%)`, plus the engineers who submitted none.'],
                ['BOQ Budget Variance Flags', 'Projects that have a saved BOQ baseline, issued some of those materials this week, and whose total issued so far is **above 100%** of the BOQ total. Shows issued to date, baseline and the percentage. Top five, highest first.'],
                ['Stock & Discrepancy Watch', 'Warehouses with an unusual number of finished **Damage** adjustments this week: more than 2 **and** more than twice their own average of the previous four weeks.'],
                ['Stock Health', 'Items that were checked out at a warehouse in the last 30 days and are now at zero or below there. Shows the count and the eight lowest.'],
                ['Dispatch & Logistics', 'Material reallocations currently in **Dispatch Preparation** or **Warehouse Receiving**, with vehicle, driver and dispatch time.'],
            ],
            'tips' => [
                'A change % is `(this week − prior week) ÷ prior week × 100`, rounded to one decimal. When the prior week was zero the report shows **+100%** if this week has anything, otherwise a dash.',
                'Sections with nothing to say are left out of the PDF (for example the BOQ flags), or show a line such as "No open material requests."',
            ],
            'warnings' => [
                'The week always ends yesterday. Entries dated today are not in this week\'s report, whatever time it is sent.',
                'Drafts and deleted records are never counted anywhere in the report.',
            ],
            'inner' => ['title' => 'How the summary is written', 'items' => [
                'All numbers are calculated by WIMS first. If the administrator has connected an AI service, it is asked to **phrase** those numbers as a short summary, a few notable events and a few recommended actions. It is told never to invent a figure.',
                'If no AI service is connected, or it does not answer, WIMS uses a plain built-in text instead, for example "This week recorded 6 checkins and 11 checkouts across 3 active project(s), with an estimated 10,904,700.00 consumed in materials." with bullets for stuck material requests, missed site reports, BOQ overruns, out-of-stock items and damage spikes. The report is therefore always sent.',
            ]],
        ],
        [
            'id' => 'who-receives-what',
            'title' => 'Who receives what',
            'icon' => 'handshake',
            'summary' => 'Two different groups of people are told about the report. Know which is which.',
            'steps' => [
                'The **Subscribers** tab controls who gets the **email with both PDFs**. Each active subscriber receives a personal copy.',
                'WIMS **users** can also ask to be told when the report has gone out: in the Email Notifications screen they tick **Executive Intelligence Report - Weekly** (see [[email-notifications]]).',
                'Those users get a notification in their bell and a short email "This week\'s Executive Intelligence Report (03 Oct to 09 Oct 2026) was sent to 3 subscriber(s)" with a link that opens this page. No PDF is attached to that one.',
            ],
            'tips' => [
                'To give someone the full report, add them as a subscriber here. To simply keep an administrator informed, tick the notification instead.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The notification step runs only after a successful send. Users who are switched off or have no email address are skipped.',
                'A problem sending one of these short notices never cancels the main report.',
            ]],
        ],
    ],
    'behind' => [
        ['title' => 'Weekly job on the server clock', 'text' => 'Every minute the server checks whether it is the chosen day and minute. If it is, and the switch is on, it builds the report for the seven days ending yesterday, makes two PDFs, emails each active subscriber, writes the send log and sends the short notification to opted-in users.', 'icon' => 'clock'],
        ['title' => 'Same numbers as the rest of WIMS', 'text' => 'The report re-uses the same rules as the other reports: stock value is quantity multiplied by the item price, consumption is finished checkouts valued at their line price, BOQ flags use the BOQ Material Compliance baseline.', 'icon' => 'calculator'],
        ['title' => 'Always sent, even if the AI is down', 'text' => 'If the AI service is not connected or fails, a plain written summary is used. A missing AI never stops the report.', 'icon' => 'sparkles'],
        ['title' => 'Protection against duplicates', 'text' => 'A scheduled run is skipped when a sent log already exists for the same week. Send Test Now skips this check on purpose.', 'icon' => 'shield-check'],
        ['title' => 'Feature switch', 'text' => 'A Super Admin can turn the page off in [[global-feature-control]] (feature **Executive Intelligence Report**). That blocks the page for everyone except Super Admins; the weekly job itself is controlled by the **Enabled** switch.', 'icon' => 'toggle'],
    ],
    'faq' => [
        ['q' => 'The report did not arrive on Monday morning.', 'a' => 'Check in this order: is **Enabled** on, are there active subscribers, does the **Send Logs** tab show a row for that week (and is it **Failed**), and is the server\'s scheduler running. Then check the spam folder.'],
        ['q' => 'Can I see the report without emailing everybody?', 'a' => 'Not directly. Switch other subscribers to inactive, keep your own address as the only active subscriber and use **Send Test Now**.'],
        ['q' => 'Why does it say 0 active projects?', 'a' => 'Only warehouses of type Private, Government, Directors or General count. If your projects use another type they are left out.'],
        ['q' => 'Why is Consumed This Week lower than I expected?', 'a' => 'It counts finished checkouts dated in the seven days that ended yesterday. Drafts, today\'s entries and deleted checkouts are not counted.'],
        ['q' => 'A subscriber says they received it twice.', 'a' => 'Either the address is on the list twice, or **Send Test Now** was pressed as well as the scheduled send.'],
        ['q' => 'Does the report change the time zone?', 'a' => 'The schedule uses the server time of your WIMS system. If sends arrive at an unexpected hour, tell your administrator to check the server time zone.'],
    ],
    'related' => ['reports', 'email-notifications', 'material-value', 'project-material-ledger', 'global-feature-control', 'system-settings'],
];
