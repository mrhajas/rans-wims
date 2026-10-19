<?php

return [
    'group' => 'comms',
    'title' => 'Notice Board',
    'icon' => 'megaphone',
    'order' => 30,
    'summary' => 'Send an official announcement to everyone, to chosen roles or to chosen people, and see who has read it.',
    'where' => 'Sidebar > **Notice Board**.',
    'path' => '/notices',
    'permissions' => [
        'read-notices' => 'See the Notice Board: notices sent to you (or to your role, or to everyone), and the notices you sent.',
        'create-notices' => 'Show **Send Notice** and send notices.',
    ],
    'overview' => [
        'The **Notice Board** is for messages that everyone concerned must see: a change of working hours, a safety rule, a site closure, a new procedure. A person with the right permission writes a title and a message and chooses the audience. Each recipient gets a bell notification "New Notice Board Message" that opens the notice.',
        'Unlike chat, a notice is an official one-way announcement that stays on the board. The person who sent it (and Super Admins) can open a **Sent History** showing every recipient and whether they have read it, so you can chase the ones who have not.',
        'For two-way conversation use [[wimschat]]. For a reminder that only you see use [[task-reminders]].',
    ],
    'concepts' => [
        ['Recipient rules', 'Who a notice is for: **Everyone** (every user of the company account), or **Selected recipients** (one or more roles and/or individual users).'],
        ['Sent History', 'The list of everybody the notice was delivered to, with the time and whether each person has **Read** or left it **Unread**.'],
        ['Unread', 'A notice stays unread for you until you open its bell notification.'],
    ],
    'functions' => [
        [
            'id' => 'browse-notices',
            'title' => 'Read the Notice Board',
            'icon' => 'list',
            'summary' => 'See the notices you may read, newest first, and which are still unread for you.',
            'steps' => [
                'Open **Notice Board** in the sidebar.',
                'Read the cards: **Notices** (all you can see), **Unread by me**, **Sent by me**, **Sent to everyone** and **Last 7 days**.',
                'Use the **Search** box to look in titles and messages. **Reset** clears it.',
                'Each row shows the title, the sender, whether it went to **Everyone** or **Selected recipients**, and an **Unread** or **Read** badge. Click **Open Notice** to read it.',
            ],
            'shots' => [
                ['notice-board-list', 'The Notice Board with summary cards and one row per notice.', 'Notice Board list'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Super Admins and Admins can see every notice. Everyone else sees only notices that were sent to everyone, to them personally, to a role they hold, or that they sent themselves.',
                'Only a Super Admin or the notice\'s sender sees the recipient names in the list.',
            ]],
        ],
        [
            'id' => 'send-notice',
            'title' => 'Send a notice',
            'icon' => 'plus',
            'summary' => 'Write the announcement, choose the audience and send it.',
            'steps' => [
                'Click **Send Notice** (needs `create-notices`).',
                'Type the **Title** (short and clear) and the **Message**.',
                'Under **Recipient Rules** choose **Everyone** to send to all users, or **Selected recipients**.',
                'For selected recipients pick one or more **Target Roles** and/or **Selected Users**. A person in several chosen groups gets it once.',
                'Click **Send Notice**. You land back on the board with "Notice has been sent successfully."',
            ],
            'fields' => [
                ['Title', 'Required, up to 255 characters.'],
                ['Message', 'Required. Plain text; line breaks are kept.'],
                ['Everyone', 'Sends to every user of your company account (including disabled ones; they simply cannot sign in to read it).'],
                ['Target Roles', 'Everyone holding any of the chosen roles.'],
                ['Selected Users', 'Individual people.'],
            ],
            'shots' => [
                ['notice-board-create', 'Compose a notice: title, message and recipient rules.', 'Send notice form'],
            ],
            'warnings' => [
                'You must choose at least one recipient: everyone, a role or a user. Otherwise WIMS asks "Please choose at least one recipient role or user."',
                'A notice **cannot be edited or deleted** after it is sent. Check the wording and the audience first. To correct a mistake, send a new notice.',
                'There is no expiry date: a notice stays on the board.',
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'WIMS works out the final list of people from "everyone", roles and users, and writes one **sent history** line per person with the time and the sender.',
                'Each recipient gets a bell notification titled **New Notice Board Message** with the notice title, linking to the notice.',
                'Sending is done as one unit: either everyone is recorded or nothing is.',
                'Notices only use the bell. They are not sent by email or SMS. (For SMS to phones use [[wims-connect]].)',
            ]],
        ],
        [
            'id' => 'open-notice',
            'title' => 'Open a notice and check who has read it',
            'icon' => 'eye',
            'summary' => 'Read the full text and, if you sent it, follow up with people who have not.',
            'steps' => [
                'Click **Open Notice** on the board, or the bell notification.',
                'Read the message. The panel shows **Sent By**, the audience (**Everyone** or **Selected recipients**), and the **Roles** and **Selected Users** chosen.',
                'If you sent the notice (or you are a Super Admin) the **Sent History** table lists each **Recipient**, **Sent At** and **Read Status** (**Read** or **Unread**), with a summary like "5 of 12 recipients have read this notice".',
            ],
            'shots' => [
                ['notice-board-show', 'An opened notice with its Sent History and read status per person.', 'Notice details with sent history'],
            ],
            'warnings' => ['You can only open a notice you are allowed to see. Anyone else gets a "not allowed" error.'],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                '"Read" means the person\'s bell notification for this notice was marked read, which happens when they click it in the bell or mark all as read.',
                'People who are not the sender and not Super Admin never see who else received the notice.',
            ]],
        ],
    ],
    'behind' => [
        ['title' => 'One notification per person', 'text' => 'Each recipient receives one bell notification. Opening it marks the notice read; the Sent History uses this to show **Read** or **Unread**.', 'icon' => 'bell'],
        ['title' => 'Permanent record', 'text' => 'Notices and their sent history are never removed from the screens, so you can prove who was told what and when.', 'icon' => 'archive'],
    ],
    'faq' => [
        ['q' => 'I sent a notice with a mistake. Can I fix it?', 'a' => 'No. Send a corrected notice. Both stay on the board.'],
        ['q' => 'Why can I not see the Send Notice button?', 'a' => 'You need the `create-notices` permission. Ask a Super Admin to tick it on your role, see [[users]].'],
        ['q' => 'Does a notice expire?', 'a' => 'No. There is no expiry date.'],
        ['q' => 'Do people get an email or SMS?', 'a' => 'No, only the bell inside WIMS.'],
    ],
    'related' => ['notifications', 'wimschat', 'wims-connect', 'task-reminders'],
];
