<?php

use App\Support\Workflow\WorkflowModules as W;

return [
    'key' => 'stock_alerts', 'path' => 'stock-alerts/workflow', 'page_route' => 'stock-alerts.workflow', 'update_route' => 'stock-alerts.workflow.update',
    'title' => 'Stock Alerts Alerts', 'subtitle' => 'Decide who is told when items run low, on which channels and with which words. The daily Low Stock Alert digest (items, schedule) is set on the Low Stock Alert page.',
    'record' => 'warehouse', 'owner_label' => 'nobody in particular',
    'events' => [
        'critical_low_stock' => W::ev('Critical / Out-of-Stock', 'A warehouse has items at or below their alert threshold (checked once a day, one notice per affected warehouse).', ['roles' => ['Super Admin', 'Admin', 'Procurement Manager', 'Procurement Manger'], 'bell' => false, 'sms_text' => "WIMS - Critical / Low Stock\nWarehouse: #warehouse\n#alerts item(s) at or below their alert threshold.", 'icon' => 'exclamation', 'aliases' => ['wc:critical-low-stock']]),
        'low_stock_digest' => W::ev('Low Stock Admin Digest', 'A daily summary that tells people how many warehouses have items at or below their threshold today.', ['bell' => false, 'icon' => 'chart-bar', 'aliases' => ['em:low-stock-alert-admin-digest']]),
        'summary_sent' => W::ev('Low Stock Alert Sent', 'The Low Stock Alert page sends its summary (on its schedule or with "Send now") to the people chosen there. This tells others that it went out.', ['roles' => ['Super Admin'], 'enabled' => false, 'bell' => true, 'icon' => 'chart-bar', 'subject' => 'Low Stock Alert sent', 'body' => '#message', 'sms_text' => "WIMS - Low Stock Alert Sent\n#message"]),
        'settings_changed' => W::ev('Low Stock Alert Settings Changed', 'Somebody changes the Low Stock Alert settings (who gets it, when, which materials, which projects).', ['roles' => ['Super Admin'], 'enabled' => false, 'bell' => true, 'icon' => 'cog', 'subject' => 'Low Stock Alert settings changed', 'body' => '#message', 'sms_text' => "WIMS - Low Stock Alert Settings Changed\nBy: #actor"]),
    ],
    'tags' => [
        W::tag('warehouse', 'Stock', 'Project / Warehouse', 'The warehouse with low stock.', 'MOMBASA MARKET'),
        W::tag('alerts', 'Stock', 'Number of items', 'How many items are at or below their threshold.', '4'),
    ],
];
