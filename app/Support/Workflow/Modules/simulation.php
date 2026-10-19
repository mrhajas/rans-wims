<?php

use App\Support\Workflow\WorkflowModules as W;

// Simulation WIMS: who is told when somebody signs in to the simulation (Super Admins, by bell, email and SMS). The alert is raised on the PRODUCTION side.
return [
    'key' => 'simulation', 'path' => 'simulation/workflow', 'page_route' => 'simulation.workflow', 'update_route' => 'simulation.workflow.update',
    'title' => 'Simulation Alerts', 'subtitle' => 'Decide who is told when somebody signs in to Simulation WIMS (or keeps failing to), on which channels and with which words.',
    'record' => 'simulation sign-in', 'owner_label' => 'nobody in particular',
    'events' => [
        'simulation_login' => W::ev('Simulation Sign-in', 'Somebody signed in to Simulation WIMS.', [
            'roles' => ['Super Admin'], 'bell' => true, 'sms' => true, 'email' => true, 'icon' => 'lock-closed',
            'sms_text' => "WIMS - Simulation sign-in\n#simuser (#simname) at #simtime\nFrom #simip, #simdevice",
            'subject' => 'Simulation sign-in: #simuser', 'body' => '#message',
        ]),
        'simulation_login_failed' => W::ev('Simulation Sign-in Refused (repeated)', 'Several sign-in attempts to Simulation WIMS were refused from one address in a short time.', [
            'roles' => ['Super Admin'], 'bell' => true, 'sms' => false, 'email' => true, 'icon' => 'exclamation',
            'sms_text' => "WIMS - Simulation sign-in refused\n#simattempts attempts for #simuser from #simip", 'subject' => 'Simulation sign-in refused: #simuser', 'body' => '#message',
        ]),
    ],
    'tags' => [
        W::tag('simuser', 'Simulation', 'Username', 'The simulation username used to sign in.', 'sim.admin'),
        W::tag('simname', 'Simulation', 'Name', 'The name of that simulation user.', 'Simulation Administrator'),
        W::tag('simtime', 'Simulation', 'Time', 'When it happened.', '18 Oct 2026, 23:41'),
        W::tag('simip', 'Simulation', 'Address', 'The network address the sign-in came from.', '196.41.0.12'),
        W::tag('simdevice', 'Simulation', 'Device', 'Browser and system used.', 'Chrome on Windows'),
        W::tag('simattempts', 'Simulation', 'Attempts', 'How many refused attempts were counted.', '3'),
    ],
];
