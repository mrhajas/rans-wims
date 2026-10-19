<?php

return [
    'group' => 'start',
    'title' => 'Welcome to WIMS',
    'icon' => 'rocket',
    'order' => 1,
    'summary' => 'What WIMS is, what it keeps track of for you, and how this guide is organised.',
    'where' => 'You are here. Open it any time from your **profile menu** (top right) > **Knowledge Base**.',
    'overview' => [
        '**WIMS** (Workflow Integrated Management System) is one place to run construction projects: the materials in each store, the people on site, the paperwork, the money and the reports that management needs. Everything is connected, so an entry made on site shows up for the office straight away.',
        'This knowledge base explains every part of WIMS the way a colleague would: what it is for, the exact clicks to do each job, what each field means, and the things WIMS does quietly in the background so you know what to expect.',
    ],
    'concepts' => [
        ['Project / Warehouse', 'In WIMS a project site and its store are the same thing. Almost every record belongs to one, and what you can see depends on the projects assigned to you.'],
        ['Role', 'A named set of permissions such as Store Manager or Project Manager. A person gets one or more roles.'],
        ['Permission', 'A single allowed action, for example `create-checkins`. If a button is missing, you probably do not have the permission.'],
        ['Record', 'Anything saved in WIMS: a checkin, a voucher, a task. Records keep a history of who changed what.'],
    ],
    'functions' => [
        [
            'id' => 'tour-of-the-screen',
            'title' => 'A quick tour of the screen',
            'icon' => 'compass',
            'summary' => 'Every page has the same layout, so once you know it you can find your way anywhere.',
            'steps' => [
                'The **left menu** lists the modules you may use. Groups with an arrow open to show their pages.',
                'The **top bar** holds the project picker, the dark-mode button, the global launcher, notifications and your profile menu.',
                'The **middle** of the page is the module itself: cards at the top, then lists, forms and reports.',
                'Use the **project picker** (top left) to switch between all projects or one project.',
            ],
            'shots' => [['welcome-dashboard', 'The dashboard: menu on the left, shortcuts and totals in the middle, tools along the top.', 'WIMS dashboard']],
            'tips' => ['Press **/** in this knowledge base at any time to search for a topic.'],
            'inner' => ['title' => 'What happens behind the scenes', 'items' => ['The menu is built from your permissions every time you sign in, which is why two people can see different menus.', 'Pages remember your last project and filters so you can pick up where you stopped.']],
        ],
        [
            'id' => 'using-this-guide',
            'title' => 'Using this guide',
            'icon' => 'book-open',
            'summary' => 'Search, print and share exactly what you need.',
            'steps' => [
                'Choose a **group** and a **guide** from the left menu, or press **/** to search.',
                'Read the **Overview** first, then jump to a function using **On this page** on the right.',
                'Click any screenshot to **enlarge** it. Use the arrow keys to move between screenshots.',
                'Use **Print guide** for the whole page, or the printer icon beside a function to print only that function.',
                'Use the **moon/sun** button to switch between light and dark mode. Your choice is remembered on this device.',
            ],
            'warnings' => ['Screenshots use fictional demo data, so names and amounts you see here are not real.'],
        ],
    ],
    'behind' => [
        ['title' => 'Everything is logged', 'text' => 'WIMS records who created, changed or deleted important records and when. Administrators can review this under the activity logs.', 'icon' => 'activity'],
        ['title' => 'Permissions are checked twice', 'text' => 'A button you cannot use is hidden, and the server also refuses the request if someone tries to reach it another way.', 'icon' => 'shield-check'],
    ],
    'faq' => [
        ['q' => 'Do I need to sign in to read this guide?', 'a' => 'No. Anyone with the link can read it, which makes it easy to share with new staff.'],
        ['q' => 'I cannot see a menu item the guide mentions.', 'a' => 'Your role does not include that permission, or the module is switched off. Ask a Super Admin, see [[roles-and-permissions-explained|Roles and permissions]].'],
    ],
    'related' => [],
];
