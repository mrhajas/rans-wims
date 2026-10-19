<?php

return [
    'group' => 'comms',
    'title' => 'Security Reminders',
    'icon' => 'shield-check',
    'order' => 50,
    'summary' => 'See which users have two-step sign-in switched on, and send a friendly on-screen nudge to some or all of them to set up a strong password and two-step sign-in.',
    'where' => 'Sidebar > **Security Reminders** (shown to Super Admin).',
    'path' => '/security-reminders',
    'permissions' => [
        'read-security-reminders' => 'Open the page and the **History** of reminders.',
        'send-security-reminders' => 'Send a reminder to all or selected users.',
        'print-security-reminders' => 'Print or download the history.',
    ],
    'overview' => [
        'Passwords alone are weak. **Two-step sign-in** (2FA) asks for a second code, from an authenticator app, an email or an SMS, so a stolen password is not enough. WIMS cannot force people to switch it on, but a Super Admin can **nudge** them.',
        'The **Security Reminders** page lists every active user with their 2FA status and the date they were last reminded. You tick the people who need a nudge (or choose everyone), add an optional message and send. Each person sees a pop-up the next time they use WIMS and a notification in the bell, with a button that takes them straight to their profile to set it up.',
        'A reminder is only a nudge. It never locks anyone out. To switch two-step sign-in **off** for someone who lost their phone see [[users#reset-2fa]].',
    ],
    'concepts' => [
        ['2FA status', '**Enabled** when any second step is on (authenticator app, email code or SMS code). **Not Enabled** otherwise.'],
        ['Campaign', 'One send action: a message sent to a set of people at one time. Each is saved in the History.'],
    ],
    'functions' => [
        [
            'id' => 'review-2fa-status',
            'title' => 'Review who has two-step sign-in',
            'icon' => 'users',
            'summary' => 'A list of all active users with their security status.',
            'steps' => [
                'Open **Security Reminders**.',
                'Read the tiles: **Total Users**, **2FA Enabled**, **2FA Not Enabled**. They always count all active users, whatever you type in the search.',
                'Use **Search name or email...** to find a person. Click **Select all without 2FA** to tick everybody who is not protected, or **Select all** (the box in the table header) to tick everyone shown.',
                'The table shows **Name**, **Email**, **Role**, **2FA Status** (**Enabled** / **Not Enabled**) and **Last Reminded** (a date or **Never**).',
            ],
            'shots' => [
                ['security-reminders-list', 'Every active user with 2FA status and the date they were last reminded.', 'Security Reminders list'],
            ],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'Only active users of your company are listed. Disabled accounts are left out.',
                'A user counts as protected when any one of the three second-step methods is on.',
            ]],
        ],
        [
            'id' => 'send-reminder',
            'title' => 'Send a security reminder',
            'icon' => 'bell',
            'summary' => 'Nudge selected people, or everyone, with a message of your own or the standard one.',
            'steps' => [
                'To remind some people, tick their rows and click **Send Reminder to Selected** (the bar shows how many are selected; **Clear Selection** unticks all).',
                'To remind everybody click **Send Reminder to All Users**.',
                'In the **Send Security Reminder** box write an optional **Message**. Leave it empty to use the standard text: "We recommend setting a strong password and enabling Two-Factor Authentication to keep your account secure. This only takes a minute."',
                'The box tells you "This will notify n user(s)." Click **Send Now**.',
            ],
            'fields' => [
                ['Message', 'Optional, up to 1000 characters.'],
                ['Who', '**All Users** (every active user) or **Selected**.'],
            ],
            'shots' => [
                ['security-reminders-send', 'Confirm the audience and write an optional message.', 'Send security reminder dialog'],
                ['security-reminders-popup', 'What the person sees: a pop-up with Set Up Now and Maybe Later.', 'Security reminder pop-up'],
            ],
            'warnings' => ['Needs `send-security-reminders`. At least one person must match, or WIMS answers "No users matched". Reminders are sent immediately; they cannot be recalled.'],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => [
                'WIMS saves one campaign (who sent it, the message, how many people, all or selected) and one line per recipient recording whether they already had 2FA at that moment.',
                'Each recipient gets a bell notification "Strengthen Your Account Security". The next page they open shows a pop-up titled "A note from your Super Admin" with **Set Up Now** (opens their profile) and **Maybe Later**. Either button marks it read, so it does not come back.',
                'If someone has several unread reminders only the newest pops up; the others stay in the bell.',
                'The send is written to the activity log.',
            ]],
        ],
        [
            'id' => 'reminder-history',
            'title' => 'View, print and download the history',
            'icon' => 'clock',
            'summary' => 'A record of every reminder campaign you sent.',
            'steps' => [
                'Click **History** on the Security Reminders page.',
                'Filter with **Search sender or message...** and the **From** / **To** dates.',
                'Read **Sent By**, **Mode** (**All Users** or **Selected**), **Recipients** (the first five names and "n more"), the **Message** ("(default message)" when you used the standard text) and **Sent At**.',
                'Click **Print** or **Download** (PDF, up to 1000 campaigns with full recipient names).',
            ],
            'shots' => [
                ['security-reminders-history', 'The history of campaigns.', 'Security reminders history'],
            ],
            'warnings' => ['Print and Download need `print-security-reminders` and are written to the activity log.'],
        ],
    ],
    'behind' => [
        ['title' => 'Never mandatory', 'text' => 'The reminder suggests a strong password and two-step sign-in; nothing is blocked if a person ignores it. Send another reminder whenever you like, each send creates a fresh notification.', 'icon' => 'shield'],
        ['title' => 'Menu visibility', 'text' => 'The menu item is shown to Super Admins only. The three permissions exist so the page and actions are protected on the server as well.', 'icon' => 'lock'],
    ],
    'faq' => [
        ['q' => 'How do people turn on two-step sign-in?', 'a' => 'From their profile (profile menu > **Profile**), where they can choose an authenticator app, email code or SMS code. See [[signing-in-and-profile]].'],
        ['q' => 'A person says they never saw the pop-up.', 'a' => 'It appears the next time they load a page after being sent. If they chose **Maybe Later** it is marked read; send another one, or point them to their profile.'],
    ],
    'related' => ['users', 'signing-in-and-profile', 'login-logs', 'notifications'],
];
