<?php

return [
    'group' => 'comms',
    'title' => 'WIMS Notification (Email Notifications Matrix)',
    'icon' => 'mail',
    'order' => 60,
    'summary' => 'One grid that decides who receives each system notification, as a bell alert and as an email. Tick a box and that person is told every time the event happens.',
    'where' => 'Sidebar > **WIMS Notification** (the page title is "WIMS Notification"; this guide calls it the Email Notifications page).',
    'path' => '/email-notifications',
    'permissions' => [
        'manage-email-notifications' => 'Open the page and save the grid. (Super Admins always can.)',
    ],
    'overview' => [
        'WIMS does many things worth telling people about: a main warehouse checkin was finished, a transfer was completed, the weekly executive report went out, a transport trip was verified. The **WIMS Notification** page is the single place where an administrator chooses **who** is told about each of these.',
        'It is a grid. Each **row is a person** (active users who have an email address) and each **column is a notification function** (grouped by module). A tick means "send this person this kind of notification". Nothing is sent to anyone unless a box is ticked here.',
        'A ticked person receives **two things** at once: a **bell notification** inside WIMS and a **real email** to their address. (To control SMS or WhatsApp see [[wims-connect]]. For the older list of outside email addresses that get stock emails see the Email Report event on the module Workflow page.)',
    ],
    'concepts' => [
        ['Function', 'One kind of notification, for example **Checkin Completed**. The grid column header shows a short name; hover it for a full description.'],
        ['Subscription', 'A tick in the grid: this person is subscribed to this function.'],
        ['Eligible users', 'Active users with an email address. Disabled users or users without an email are not listed.'],
    ],
    'functions' => [
        [
            'id' => 'read-grid',
            'title' => 'Read the grid and filter users',
            'icon' => 'list',
            'summary' => 'See at a glance who is subscribed to what.',
            'steps' => [
                'Open **WIMS Notification**. A green **LIVE** chip shows the page is active.',
                'Read the tiles: **Email Functions** (columns), **Eligible Users**, **Active Subscriptions** and **Users Receiving Nothing**.',
                'Type in **Search users by name or email...** to narrow the rows. The counter shows "n of N".',
                'Hover a column header for the description of that function. Columns of the same module share a coloured band (for example five **Transport Routes** columns).',
                'Click a person\'s name to open their user record.',
            ],
            'shots' => [
                ['email-notifications-grid', 'The matrix: users down the side, notification functions across the top, grouped by module.', 'Email notifications matrix'],
            ],
        ],
        [
            'id' => 'subscribe-users',
            'title' => 'Subscribe or unsubscribe people',
            'icon' => 'toggle',
            'summary' => 'Tick the boxes, then save.',
            'steps' => [
                'Tick a box to subscribe a person to a function, untick to remove them.',
                'The action bar shows **Unsaved changes** while you have edits that are not saved yet.',
                'Click **Save**. A "Saved." note appears and the message "Email notification preferences have been saved." is shown.',
            ],
            'shots' => [
                ['email-notifications-edit', 'Ticking boxes. The bar at the bottom reminds you that changes are not saved yet.', 'Matrix with unsaved changes'],
            ],
            'warnings' => [
                'Nothing happens until you click **Save**. Leaving the page loses your ticks.',
                'Saving replaces the whole grid for the company with what you see. Two administrators saving at the same time: the last one wins.',
                'Needs `manage-email-notifications`. Without it the page shows "403 forbidden".',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Only users of your company and only known notification functions are accepted; anything else in the request is ignored.',
                'Each time the event happens, WIMS looks up the subscribers of that function, skips anyone who is disabled or has no email, and sends each a bell notification flagged "also send email". A mail failure for one person is logged and never stops the original action (the checkin, the transfer...).',
            ]],
        ],
        [
            'id' => 'what-each-function-does',
            'title' => 'What each notification function sends',
            'icon' => 'bell',
            'summary' => 'The list of functions and when each one fires.',
            'steps' => ['Use this list to decide which columns to tick for whom.'],
            'fields' => [
                ['Checkin Completed `checkin-completed`', 'A checkin to a **Main Warehouse** project is finalised (draft to final, or created final).'],
                ['Checkout Completed `checkout-completed`', 'A checkout from a Main Warehouse project is finalised.'],
                ['Transfer Completed `transfer-completed`', 'A transfer to or from a Main Warehouse project is finalised.'],
                ['Adjustment Activity `adjustment-activity`', 'Any adjustment activity: created, updated, deleted, restored, draft or approved.'],
                ['Material Reallocation Stage Change `material-reallocation-stage-change`', 'A reallocation is cleared or finally approved.'],
                ['Material Request Lifecycle Event `material-request-lifecycle`', 'Material request stages: created, updated, status changes and cleared. Matrix subscribers also get the request emails once; nobody gets two copies.'],
                ['Site Report Submitted `site-report-submitted`', 'A daily site report is submitted, on top of the Admins and Project Managers who always hear about it.'],
                ['Site Report Submitted - Admin Copy `site-report-submitted-admin-copy`', 'A direct copy of the submission, separate from the broadcast.'],
                ['Low Stock Alert - Admin Digest `low-stock-alert-admin-digest`', 'Daily summary of how many projects have items at or below their alert level.'],
                ['Executive Intelligence Report - Weekly `executive-intelligence-report-weekly`', 'A notice with a link when the weekly executive briefing has gone out (the report itself goes to its own subscribers, see [[executive-report]]).'],
                ['Transport Routes: Trip Logged / Trip Verified / Payment Recorded / Payment Voucher Created / Trip Cancelled', 'Five functions for the trip lifecycle in [[transport-management]].'],
            ],
            'tips' => ['Start small. Give each manager only the columns that need their decision, otherwise people stop reading the emails.'],
        ],
    ],
    'behind' => [
        ['title' => 'One source of truth', 'text' => 'Every module that sends a system email asks this grid who to send to. It replaces the older habit of hard-coding roles. Some modules still keep their own extra audience (for example Admins and Project Managers for site reports); the grid only adds to it.', 'icon' => 'database'],
        ['title' => 'Bell and email together', 'text' => 'A subscription always creates the bell notification and the email. There is no "bell only" or "email only" choice here.', 'icon' => 'bell'],
        ['title' => 'Email must be configured', 'text' => 'WIMS needs working outgoing email settings to deliver. On demo and test copies mail is only written to a log.', 'icon' => 'server'],
    ],
    'faq' => [
        ['q' => 'A person is missing from the grid.', 'a' => 'They are disabled or have no email address. Fix it on their user record, see [[users]].'],
        ['q' => 'Can I send to someone who is not a WIMS user?', 'a' => 'Not in this grid. Outside addresses for stock emails are on the the Email Report event on the module Workflow page page; the weekly executive report has its own subscriber list.'],
        ['q' => 'I ticked a box but no email arrived.', 'a' => 'Check that you clicked **Save**, that the event really happened (for example the checkin was to a **Main Warehouse** project), and that the mail server is working.'],
    ],
    'related' => ['wims-connect', 'notifications', 'users', 'executive-report'],
];
