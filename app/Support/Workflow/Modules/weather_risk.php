<?php

use App\Support\Workflow\WorkflowModules as W;

// Weather & Site Risk tells people when the FORECAST may be unsuitable for weather-sensitive work. It never says work is allowed or forbidden:
// every notice is advice to review. Starting audience: the people who can acknowledge warnings (Project Manager role), kept inside the project.
// Bell only. Provider-problem, acknowledgement and settings events start off.
$sms = "WIMS - #title (#project)\n#message";

return [
    'key' => 'weather_risk', 'path' => 'weather/workflow', 'page_route' => 'weather.workflow', 'update_route' => 'weather.workflow.update',
    'title' => 'Weather & Site Risk Alerts', 'subtitle' => 'Decide who is told when the forecast may be unsuitable for weather-sensitive work, when the weather provider reports a severe alert, and when weather data is unavailable. These notices are advice to review, never an instruction to start or stop work.',
    'record' => 'weather warning', 'owner_label' => 'nobody in particular (weather warnings belong to a project, so the roles and people chosen here are told, kept inside the project)',
    'events' => [
        'warning_raised' => W::ev('Forecast may be unsuitable for an activity', 'A refreshed forecast goes past the threshold of a weather-sensitive activity (or becomes clearly worse after it was acknowledged). One notice per warning, never repeated by a refresh.', ['roles' => ['Project Manager'], 'permission' => 'acknowledge-weather-warnings', 'project' => true, 'sms_text' => $sms, 'icon' => 'exclamation', 'subject' => '#title (#project)', 'body' => '#message']),
        'severe_alert' => W::ev('Severe-weather alert from the provider', 'The weather provider reports an official severe-weather alert covering a project (only providers that supply alerts).', ['roles' => ['Project Manager'], 'permission' => 'acknowledge-weather-warnings', 'project' => true, 'sms_text' => $sms, 'icon' => 'exclamation', 'subject' => '#title (#project)', 'body' => '#message']),
        'warning_acknowledged' => W::ev('Warning acknowledged', 'Somebody records that they have seen a weather warning and what they decided.', ['roles' => ['Super Admin'], 'project' => true, 'enabled' => false, 'sms_text' => $sms, 'icon' => 'tick', 'subject' => 'Weather warning acknowledged (#project)', 'body' => '#actor acknowledged "#title" for #project. Decision: #decision. #remarks']),
        'data_unavailable' => W::ev('Weather data unavailable', 'The weather provider keeps failing, or the data for a project is out of date, so warnings cannot be relied on. One notice per project every six hours.', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'exclamation', 'subject' => 'Weather data unavailable (#project)', 'body' => '#message']),
        'settings_changed' => W::ev('Weather settings changed', 'The provider, its address, its key or the refresh times are changed. The key itself is never shown.', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'cog', 'subject' => 'Weather settings changed', 'body' => '#message']),
        'thresholds_changed' => W::ev('Weather thresholds changed', 'A threshold of a weather-sensitive activity is changed, marked as reviewed or put back to its starting values.', ['roles' => ['Super Admin'], 'enabled' => false, 'sms_text' => $sms, 'icon' => 'adjustments', 'subject' => 'Weather thresholds changed', 'body' => '#message']),
    ],
    'tags' => [
        W::tag('project', 'Weather', 'Project', 'The project the warning is about.', 'Demo Hospital Block A'),
        W::tag('activity', 'Weather', 'Activity', 'The weather-sensitive activity.', 'Roofing'),
        W::tag('window', 'Weather', 'Time window', 'When the forecast goes past the threshold.', '14:00 to 17:00'),
        W::tag('severity', 'Weather', 'Level', 'Caution or Warning.', 'Warning'),
        W::tag('provider', 'Weather', 'Source', 'The weather provider the forecast came from.', 'OpenWeatherMap'),
        W::tag('workpackage', 'Weather', 'Scheduled work', 'The scheduled work package that could be affected (empty if none).', 'Roof sheeting, Block A'),
        W::tag('decision', 'Weather', 'Decision recorded', 'What the person who acknowledged the warning decided.', 'Precautions taken'),
    ],
];
