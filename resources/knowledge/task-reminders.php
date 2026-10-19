<?php

return [
    'group' => 'comms',
    'title' => 'Task Reminders (My Reminders)',
    'icon' => 'clock',
    'order' => 40,
    'summary' => 'Your own to-do reminders: write what you must remember, set a date and time, and WIMS alerts you (and can email you) when it is due.',
    'where' => 'Sidebar > **Task Reminders**. The email switch is in your profile menu > **Profile**.',
    'path' => '/task-reminders',
    'permissions' => [
        'read-task-reminders' => 'Open **Task Reminders** and see your own reminders.',
        'create-task-reminders' => 'Add a reminder.',
        'update-task-reminders' => 'Edit a reminder, including its attachment.',
        'delete-task-reminders' => 'Delete a reminder.',
    ],
    'overview' => [
        '**Task Reminders** are personal sticky notes with an alarm. You write a name and notes, choose a due date and time, a priority and a category, and optionally attach one file. When the time comes you get a bell notification, and an email too if you have switched that on in your profile.',
        'Reminders are **private**. You only see your own, whatever your role and whatever the "Can view all record" setting says. Only a Super Admin can see everyone\'s reminders (each card then shows the owner\'s name). A reminder cannot be assigned to another person: for work you give to others use the [[task-manager]].',
    ],
    'concepts' => [
        ['Upcoming / Overdue / Completed', 'Upcoming: not done and not yet due. Overdue: not done and the due time has passed. Completed: ticked off.'],
        ['Priority', '**Low**, **Normal**, **High** or **Urgent**. It colours the card and the email.'],
        ['Category', 'A free label to group reminders. Suggestions: General, Meeting, Site Visit, Procurement, Maintenance, Inspection, Documentation, Follow-up, Deadline, Personal. You can type your own.'],
        ['Email reminders', 'A personal switch in your profile. Off by default: you get the bell notification either way.'],
    ],
    'functions' => [
        [
            'id' => 'view-reminders',
            'title' => 'See and filter your reminders',
            'icon' => 'list',
            'summary' => 'Cards for every reminder with tabs, counts and search.',
            'steps' => [
                'Open **Task Reminders**. The page title is **My Reminders**.',
                'Read the tiles: **Total**, **Upcoming**, **Overdue**, **Completed**.',
                'Use the tabs **Upcoming**, **Overdue**, **Completed** and **All** (each shows its count) and the **Search reminders...** box (name, notes, category).',
                'Each card shows the priority icon, the name, the due date and time (red when overdue), the category, notes, attachments and **Edit** / **Delete** buttons.',
            ],
            'shots' => [
                ['task-reminders-list', 'My Reminders: counts at the top, filter tabs, and a card for each reminder.', 'Task reminders page'],
            ],
            'tips' => ['A blue note "Email reminders are off." with a link **Turn on in your profile** appears until you enable email.'],
        ],
        [
            'id' => 'create-reminder',
            'title' => 'Create a reminder',
            'icon' => 'plus',
            'summary' => 'Write it once, set the alarm and forget it.',
            'steps' => [
                'Click **New Reminder**.',
                'Type the **Name** ("What do you need to remember?").',
                'Under **Schedule** choose the **Due Date** and **Time**. A new reminder starts at today, 09:00.',
                'Choose the **Priority** and a **Category** (search or type your own).',
                'Write any **Notes** (up to 2000 characters).',
                'Optionally add an **Attachment**.',
                'Click **Create Reminder**.',
            ],
            'fields' => [
                ['Name', 'Required, up to 150 characters.'],
                ['Due Date and time', 'Required.'],
                ['Priority', 'Required: Low, Normal (default), High, Urgent.'],
                ['Category', 'Optional, up to 60 characters.'],
                ['Notes', 'Optional, up to 2000 characters.'],
                ['Attachment', 'One file up to 5 MB: PDF, Word, Excel, PowerPoint, images, ZIP, TXT or CSV.'],
            ],
            'shots' => [
                ['task-reminders-create', 'The New Reminder form.', 'New reminder dialog'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The reminder belongs to you and is private.',
                'The alarm is checked every minute. A reminder whose time is already past rings at the next check.',
            ]],
        ],
        [
            'id' => 'edit-reminder',
            'title' => 'Edit a reminder',
            'icon' => 'pen',
            'summary' => 'Change the time, priority, notes or attachment.',
            'steps' => [
                'Click **Edit** on the card. The **Edit Reminder** window opens.',
                'Change what you need and click **Save**.',
            ],
            'warnings' => ['Adding an attachment adds to the existing one. Removing an attachment is not possible from this screen.'],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'If you change the due date or time, WIMS forgets that it already alerted you, so you are alerted again at the new time.',
            ]],
        ],
        [
            'id' => 'complete-reminder',
            'title' => 'Mark done or not done',
            'icon' => 'check',
            'summary' => 'Tick the circle when the job is finished.',
            'steps' => [
                'Click the round tick on the card (tooltip **Mark as done**). The reminder moves to **Completed** and its name is struck through.',
                'Click it again (**Mark as not done**) to reopen it.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The time you completed it is saved. A completed reminder never sends an alert.',
            ]],
        ],
        [
            'id' => 'delete-restore-reminder',
            'title' => 'Delete a reminder',
            'icon' => 'trash',
            'summary' => 'Throw a reminder away; it is moved to trash, not erased.',
            'steps' => [
                'Click **Delete** on the card and confirm "Delete Reminder?" ("This reminder will be moved to trash.").',
            ],
            'warnings' => ['The page has no list of deleted reminders and no restore button. The record is kept (and remembers who deleted it), so an administrator with database access can bring it back, but you cannot do it yourself from this screen.'],
        ],
        [
            'id' => 'attachments-reminder',
            'title' => 'Download a reminder\'s attachment',
            'icon' => 'clip',
            'summary' => 'Open the file saved on a reminder.',
            'steps' => ['Click the paper-clip chip on the card. The file downloads.'],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => ['WIMS checks that the file really belongs to that reminder before serving it, and you can only reach reminders you own (Super Admin: all).']],
        ],
        [
            'id' => 'reminder-report',
            'title' => 'Print or download a reminders report',
            'icon' => 'printer',
            'summary' => 'A paper or PDF list of your reminders, optionally for a date range.',
            'steps' => [
                'Click **Report** at the top.',
                'Choose **From** and **To** (leave blank for all your reminders).',
                'Click **Print** or **Download** (a PDF named `task-reminders-date-time.pdf`).',
            ],
            'shots' => [
                ['task-reminders-report', 'Choose a date range, then print or download.', 'Reminders report dialog'],
            ],
            'tips' => ['The range is on the **due date**.'],
        ],
        [
            'id' => 'email-preference',
            'title' => 'Turn on email reminders',
            'icon' => 'mail',
            'summary' => 'Get the reminder in your inbox as well as in the bell.',
            'steps' => [
                'Click **Turn on in your profile** on the Task Reminders page, or open your profile menu > **Profile**.',
                'In the **Task Reminders** card switch on **Email me when a task reminder is due**.',
                'Click **Save**. A chip shows **On**.',
            ],
            'shots' => [
                ['task-reminders-profile', 'The profile card that controls reminder emails.', 'Task reminder email setting in the profile'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'The email goes to the address on your user account. The bell notification is always sent.',
                'The email shows the name, notes, due time and a coloured priority and links back to the reminder.',
            ]],
        ],
    ],
    'behind' => [
        ['title' => 'The alarm runs every minute', 'text' => 'Every minute the command `task-reminders:dispatch` finds reminders that are due, not completed, not snoozed and not yet alerted, sends the bell notification (and the email if the owner turned it on) and marks them as alerted so you are never alerted twice for the same time.', 'icon' => 'clock'],
        ['title' => 'Open from the bell', 'text' => 'The notification "Your reminder is due now" opens the Task Reminders page, switches to the right tab and highlights the card for a few seconds.', 'icon' => 'bell'],
        ['title' => 'Private by design', 'text' => 'Reminders ignore the "Can view all record" setting on purpose. Only Super Admin sees other people\'s reminders.', 'icon' => 'lock'],
    ],
    'faq' => [
        ['q' => 'Can I set a reminder for someone else?', 'a' => 'No. Reminders are personal. Use the [[task-manager]] to assign work to people, or the [[notice-board]] for announcements.'],
        ['q' => 'I did not get an email.', 'a' => 'Email reminders are off by default. Turn them on in your profile, and check that your user has the right email address.'],
        ['q' => 'Can I repeat a reminder every week?', 'a' => 'No. Each reminder rings once. Create another or change the date after it rings.'],
    ],
    'related' => ['task-manager', 'notice-board', 'notifications', 'signing-in-and-profile'],
];
