<?php

use App\Support\Workflow\WorkflowModules as W;

$staff = ['Super Admin', 'Admin'];

return [
    'key' => 'items', 'path' => 'items/workflow', 'page_route' => 'items.workflow', 'update_route' => 'items.workflow.update',
    'title' => 'Items Alerts', 'subtitle' => 'Decide who is told when an item is added, changed, deleted, restored, imported or its price changes, on which channels and with which words.',
    'record' => 'item', 'owner_label' => 'nobody in particular',
    'events' => [
        'deleted' => W::ev('Item Deleted', 'An item is deleted (or removed for good).', ['roles' => ['Super Admin', 'Admin'], 'icon' => 'trash']),
        'created' => W::ev('Item Added', 'A new item is added to the catalogue.', ['roles' => $staff, 'enabled' => false, 'icon' => 'plus', 'subject' => 'Item added: #item', 'body' => '#item (#code) was added by #actor.', 'sms_text' => "WIMS - Item Added\n#item (#code)\nBy: #actor"]),
        'updated' => W::ev('Item Edited', 'An item is edited and saved.', ['roles' => $staff, 'enabled' => false, 'icon' => 'edit', 'subject' => 'Item edited: #item', 'body' => '#item (#code) was edited by #actor.', 'sms_text' => "WIMS - Item Edited\n#item (#code)\nBy: #actor"]),
        'restored' => W::ev('Item Restored', 'A deleted item is brought back from the deleted records.', ['roles' => $staff, 'enabled' => false, 'icon' => 'refresh', 'subject' => 'Item restored: #item', 'body' => '#item (#code) was restored by #actor.', 'sms_text' => "WIMS - Item Restored\n#item (#code)\nBy: #actor"]),
        'permanently_deleted' => W::ev('Item Deleted for Good', 'An item is removed from the deleted records for good. When this is switched on it is used instead of "Item Deleted" for this step.', ['roles' => $staff, 'enabled' => false, 'icon' => 'trash', 'subject' => 'Item deleted for good: #item', 'body' => '#item (#code) was deleted for good by #actor.', 'sms_text' => "WIMS - Item Deleted For Good\n#item (#code)\nBy: #actor"]),
        'imported' => W::ev('Items Imported', 'Items are imported from an Excel file (new items are added, existing codes are updated).', ['roles' => $staff, 'enabled' => false, 'icon' => 'in', 'subject' => 'Items imported: #count', 'body' => '#actor imported #count item(s) from a file.', 'sms_text' => "WIMS - Items Imported\n#count item(s)\nBy: #actor"]),
        'used_copy_created' => W::ev('Used Copy Created', 'A "USED" copy of an item is created for second-hand stock.', ['roles' => $staff, 'enabled' => false, 'icon' => 'plus', 'subject' => 'Used copy created: #item', 'body' => '#item (#code) was created as the used copy of another item by #actor.', 'sms_text' => "WIMS - Used Item Created\n#item (#code)\nBy: #actor"]),
        'price_history_changed' => W::ev('Price History Changed', 'A price is added to an item\'s price history, or an entry is edited or removed.', ['roles' => $staff, 'enabled' => false, 'icon' => 'cash', 'subject' => 'Price history changed: #item', 'body' => '#message', 'sms_text' => "WIMS - Price History Changed\n#item (#code)\n#message"]),
        'price_applied' => W::ev('Item Price Applied', 'A price from the price history is applied, so the item\'s current price changes.', ['roles' => $staff, 'enabled' => false, 'icon' => 'cash', 'subject' => 'Item price changed: #item', 'body' => '#message', 'sms_text' => "WIMS - Item Price Changed\n#item (#code)\n#message"]),
    ],
    'tags' => [
        W::tag('item', 'Item', 'Item name', 'The name of the item.', 'CEMENT 50KG'),
        W::tag('code', 'Item', 'Item code', 'The item code.', 'RSI-0042'),
        W::tag('price', 'Item', 'Price', 'The current price of the item.', '15,500.00'),
        W::tag('count', 'Import', 'Number of items', 'How many items an import handled.', '25'),
    ],
];
