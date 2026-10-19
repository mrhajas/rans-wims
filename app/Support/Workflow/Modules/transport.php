<?php

use App\Support\Workflow\WorkflowModules as W;

return [
    'key' => 'transport', 'path' => 'transport/workflow', 'page_route' => 'transport.workflow', 'update_route' => 'transport.workflow.update',
    'title' => 'Transport Routes Alerts', 'subtitle' => 'Decide who is told about trips, payments, vehicles, locations and Loading & Unloading, on which channels and with which words.',
    'record' => 'trip', 'owner_label' => 'the person who logged the trip',
    'events' => [
    'trip_created' => W::ev('Trip logged', 'A new trip is logged in Transport Routes (a trip made from a Transfer has its own notification).', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => true, 'icon' => 'car', 'aliases' => ['nc:transport-trip-created', 'em:transport-trip-created']]),
    'trip_from_transfer' => W::ev('Route generated from a Transfer', 'Somebody generates the Transport Routes trip for a Transfer from its Gate Pass. The trip starts as waiting for verification.', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => true, 'icon' => 'truck', 'aliases' => ['nc:transport-trip-from-transfer']]),
    'trip_updated' => W::ev('Trip edited', 'A trip is edited and saved (including a change of price or number of trips).', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => true, 'icon' => 'edit', 'aliases' => ['nc:transport-trip-updated']]),
    'trip_deleted' => W::ev('Trip deleted', 'A trip is deleted.', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => true, 'icon' => 'trash', 'aliases' => ['nc:transport-trip-deleted']]),
    'trip_cancelled' => W::ev('Trip cancelled', 'A trip that was not paid yet is cancelled.', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => true, 'icon' => 'cross', 'aliases' => ['nc:transport-trip-cancelled', 'em:transport-trip-cancelled']]),
    'trip_verified' => W::ev('Trip verified', 'One trip, or several at once, are verified and can now be paid.', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => true, 'icon' => 'tick', 'aliases' => ['nc:transport-trip-verified', 'em:transport-trip-verified']]),
    'trip_marked_paid' => W::ev('Trip payment recorded', 'A payment is recorded directly against a trip.', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => true, 'icon' => 'cash', 'aliases' => ['nc:transport-trip-marked-paid', 'em:transport-trip-marked-paid']]),
    'payment_voucher_created' => W::ev('Payment Voucher created from trips', 'A Payment Voucher is created from one or more trips.', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => false, 'icon' => 'doc-text', 'aliases' => ['nc:transport-payment-voucher-created', 'em:transport-payment-voucher-created']]),
    'vehicle_changed' => W::ev('Vehicle added, edited or removed', 'A vehicle in the Transport Routes register is added, edited, deleted or has its opening balance changed.', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => false, 'icon' => 'car', 'aliases' => ['nc:transport-vehicle-changed']]),
    'location_changed' => W::ev('Location added, edited or removed', 'A Transport Routes location (office, site, workshop, port ...) is added, edited or deleted.', ['roles' => ['Super Admin'], 'bell' => false, 'owner' => false, 'icon' => 'map-pin', 'aliases' => ['nc:transport-location-changed']]),
    'loading_changed' => W::ev('Loading & Unloading activity', 'Attendance is taken or corrected, or a Loading & Unloading payment, voucher or opening balance is recorded or removed.', ['roles' => ['Super Admin'], 'bell' => false, 'owner' => false, 'icon' => 'users', 'aliases' => ['nc:transport-loading-changed']]),
    'settings_changed' => W::ev('Transport Routes settings changed', 'The default trip price or other Transport Routes settings are changed.', ['roles' => ['Super Admin'], 'bell' => true, 'owner' => false, 'icon' => 'cog', 'aliases' => ['nc:transport-settings-changed']]),
    ],
    'tags' => [],
];
