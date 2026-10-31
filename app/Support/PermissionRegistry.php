<?php

namespace App\Support;

class PermissionRegistry
{
    /**
     * Canonical map of every permission the Roles UI can grant, grouped by module.
     * This is the single source of truth: it drives the `permissions:sync` command
     * (which creates any missing Permission rows) and the Role/Form.vue matrix.
     */
    public static function modules(): array
    {
        return [
            'checkins' => [
                'label' => __('Checkins'),
                'abilities' => [
                    'read-checkins' => __('view_x', ['x' => __('Checkins')]),
                    'create-checkins' => __('create_x', ['x' => __('Checkins')]),
                    'update-checkins' => __('update_x', ['x' => __('Checkins')]),
                    'delete-checkins' => __('delete_x', ['x' => __('Checkins')]),
                    'print-checkins' => __('Print / Download Checkins'),
                ],
            ],

            'checkouts' => [
                'label' => __('Checkouts'),
                'abilities' => [
                    'read-checkouts' => __('view_x', ['x' => __('Checkouts')]),
                    'create-checkouts' => __('create_x', ['x' => __('Checkouts')]),
                    'update-checkouts' => __('update_x', ['x' => __('Checkouts')]),
                    'delete-checkouts' => __('delete_x', ['x' => __('Checkouts')]),
                    'print-checkouts' => __('Print / Download Checkouts'),
                ],
            ],

            'adjustments' => [
                'label' => __('Adjustments'),
                'abilities' => [
                    'read-adjustments' => __('view_x', ['x' => __('Adjustments')]),
                    'create-adjustments' => __('create_x', ['x' => __('Adjustments')]),
                    'update-adjustments' => __('update_x', ['x' => __('Adjustments')]),
                    'delete-adjustments' => __('delete_x', ['x' => __('Adjustments')]),
                    'approve-adjustments' => __('Approve / Reject Adjustments'),
                    'print-adjustments' => __('Print / Download Adjustments'),
                ],
            ],

            // Everything starts as Draft. "Approve / Reject" is a grantable ability (Super Admin always has it); only a
            // Super Admin's own submission is approved on the spot. Editing an Approved / Rejected record and changing a
            // record's status stay Super Admin only (hardcoded, like the Adjustments "Change status").
            'super_adjustments' => [
                'label' => __('Super Adjustments'),
                'abilities' => [
                    'read-super-adjustments' => __('view_x', ['x' => __('Super Adjustments')]),
                    'create-super-adjustments' => __('create_x', ['x' => __('Super Adjustments')]),
                    'update-super-adjustments' => __('update_x', ['x' => __('Super Adjustments')]),
                    'delete-super-adjustments' => __('delete_x', ['x' => __('Super Adjustments')]),
                    'approve-super-adjustments' => __('Approve / Reject Super Adjustments'),
                    'print-super-adjustments' => __('Print / Download Super Adjustments'),
                ],
            ],

            // Adjustment Session: a multi-day count of one Project / Warehouse made of several Super Adjustments. Super Admin has these by default.
            'adjustment_sessions' => [
                'label' => __('Adjustment Sessions'),
                'abilities' => [
                    'read-adjustment-sessions' => __('View Adjustment Sessions'),
                    'start-adjustment-sessions' => __('Start Adjustment Sessions'),
                    'end-adjustment-sessions' => __('End Adjustment Sessions (and clear the items not counted)'),
                    'print-adjustment-sessions' => __('Print / Download Adjustment Sessions'),
                    'stop-stock-movement' => __('Stop Stock Movement of a Project / Warehouse during an Adjustment Session (needs the password)'),
                ],
            ],

            // Vehicles reuse the existing Asset model (scoped to the
            // 'Vehicles' asset-category tree) and Drivers reuse Contacts
            // (category = 'Drivers') — see TransportVehicleController /
            // TransportRecordController. These abilities gate the
            // Transport-scoped views/actions over that shared data, not a
            // separate driver/vehicle permission system.
            'transport_management' => [
                'label' => __('Transport Routes'),
                'abilities' => [
                    'read-transport-dashboard' => __('View Transport Dashboard'),

                    'read-transport-vehicles' => __('view_x', ['x' => __('Transport Vehicles')]),
                    'create-transport-vehicles' => __('create_x', ['x' => __('Transport Vehicles')]),
                    'update-transport-vehicles' => __('update_x', ['x' => __('Transport Vehicles')]),
                    'delete-transport-vehicles' => __('delete_x', ['x' => __('Transport Vehicles')]),

                    'read-transport-records' => __('view_x', ['x' => __('Transport Records')]),
                    'create-transport-records' => __('create_x', ['x' => __('Transport Records')]),
                    'update-transport-records' => __('update_x', ['x' => __('Transport Records')]),
                    'delete-transport-records' => __('delete_x', ['x' => __('Transport Records')]),
                    'override-transport-amount' => __('Change Transport Record Amount From Default'),
                    'verify-transport-records' => __('Verify Transport Records'),
                    'mark-paid-transport-records' => __('Mark Transport Records as Paid'),
                    'create-payment-voucher-from-transport' => __('Create Payment Voucher from Transport Records'),

                    'read-transport-locations' => __('view_x', ['x' => __('Transport Locations')]),
                    'create-transport-locations' => __('create_x', ['x' => __('Transport Locations')]),
                    'update-transport-locations' => __('update_x', ['x' => __('Transport Locations')]),
                    'delete-transport-locations' => __('delete_x', ['x' => __('Transport Locations')]),

                    'print-transport-records' => __('Print / Download Transport Records & Reports'),
                    'export-transport-records' => __('Export Transport Data'),
                    'read-transport-timely-reports' => __('view_x', ['x' => __('Transport Timely Reports')]),

                    'read-transport-loading' => __('view_x', ['x' => __('Loading & Unloading')]),
                    'create-transport-loading' => __('Take Loading & Unloading Attendance'),
                    'update-transport-loading' => __('Correct Loading & Unloading Attendance'),
                    'delete-transport-loading' => __('Delete Loading & Unloading Attendance / Payments'),
                    'pay-transport-loading' => __('Record Loading & Unloading Payments'),
                    'read-transport-loading-reports' => __('view_x', ['x' => __('Loading & Unloading Report')]),
                    'print-transport-loading-reports' => __('Print / Download Loading & Unloading Report and Statements'),
                    'export-transport-loading-reports' => __('Export Loading & Unloading Report to Excel'),

                    'manage-transport-settings' => __('Manage Transport Settings'),
                    'manage-transport-vehicle-opening-balance' => __('Manage Vehicle Opening Balance'),
                ],
            ],

            'transfers' => [
                'label' => __('Transfers'),
                'abilities' => [
                    'read-transfers' => __('view_x', ['x' => __('Transfers')]),
                    'create-transfers' => __('create_x', ['x' => __('Transfers')]),
                    'update-transfers' => __('update_x', ['x' => __('Transfers')]),
                    'delete-transfers' => __('delete_x', ['x' => __('Transfers')]),
                    'warehouse-cleanup' => __('Warehouse Cleanup'),
                    'print-transfers' => __('Print / Download Transfers'),
                    // Deliberately separate from update-transfers — marking a
                    // transfer received is a one-way confirmation, not an
                    // edit (see TransferController::receive()'s own
                    // docblock). Granted by default to Super Admin/Admin
                    // (unrestricted) and Engineers (their own transfers
                    // only) — see the seed migration and
                    // TransferController::userMayDeliver().
                    'deliver-transfers' => __('Mark Transfers as Received'),
                    // Transfers leaving a MAIN warehouse wait for approval before stock moves.
                    'approve-transfers' => __('Approve Transfers from Main Warehouses'),
                    'cancel-transfers' => __('Cancel Transfers Awaiting Approval'),
                    // From a Transfer that has a Gate Pass (driver + vehicle), log the Transport Routes trip for it.
                    'generate-transport-route-transfers' => __('Generate Transport Route from a Transfer'),
                ],
            ],

            'accounts_tracking' => [
                'label' => __('Accounts Tracking'),
                'abilities' => [
                    'view-accounts-tracking' => __('Open Accounts Tracking and its Financial Dashboard'),
                    'read-acct-accounts' => __('View the accounts you are responsible for (bank, cash and other)'),
                    'view-all-acct-accounts' => __('View every tracked account, not only your own'),
                    'create-acct-accounts' => __('Register new accounts'),
                    'update-acct-accounts' => __('Edit account details and control settings'),
                    'manage-acct-cash' => __('Manage cash-in-hand accounts'),
                    'manage-acct-banks' => __('Manage bank account details'),
                    'deactivate-acct-accounts' => __('Activate, deactivate and suspend accounts'),
                    'close-acct-accounts' => __('Close accounts'),
                    'view-acct-sensitive' => __('See full bank account numbers and other sensitive banking details'),
                    'read-acct-transactions' => __('View the transaction ledger'),
                    'read-acct-transfers' => __('View the transfers between accounts'),
                    'create-acct-inflows' => __('Money In: create receipts (needs approval before it counts)'),
                    'approve-acct-inflows' => __('Money In: approve or reject receipts'),
                    'post-acct-inflows' => __('Money In: post approved receipts (this is what changes balances)'),
                    'create-acct-outflows' => __('Money Out: create payments (needs approval before it counts)'),
                    'approve-acct-outflows' => __('Money Out: approve or reject payments'),
                    'post-acct-outflows' => __('Money Out: post approved payments (this is what changes balances)'),
                    'create-acct-transfers' => __('Transfer: create transfers between accounts (needs approval before it counts)'),
                    'approve-acct-transfers' => __('Transfer: approve or reject transfers'),
                    'post-acct-transfers' => __('Transfer: post approved transfers (this is what changes balances)'),
                    'create-acct-adjustments' => __('Adjustment: create balance adjustments (needs approval before it counts)'),
                    'approve-acct-adjustments' => __('Adjustment: approve or reject adjustments'),
                    'post-acct-adjustments' => __('Adjustment: post approved adjustments'),
                    'submit-acct-transactions' => __('Submit other people\'s draft transactions for approval'),
                    'reverse-acct-transactions' => __('Reverse posted transactions and transfers'),
                    'view-acct-attachments' => __('See attachments (receipts, vouchers, confirmations)'),
                    'export-acct-transactions' => __('Export transactions and reports'),
                    'view-all-acct-departments' => __('See transactions of every department and project'),
                    'manage-acct-currencies' => __('Manage currencies'),
                    'create-acct-rates' => __('Enter exchange rates'),
                    'approve-acct-rates' => __('Approve exchange rates'),
                    'manage-acct-approval-rules' => __('Set approval thresholds and levels'),
                    'perform-acct-reconciliations' => __('Prepare bank and cash reconciliations'),
                    'approve-acct-reconciliations' => __('Review and approve reconciliations'),
                    'read-acct-alerts' => __('See Accounts Tracking alerts'),
                    'resolve-acct-alerts' => __('Acknowledge, assign and resolve alerts'),
                    'read-acct-reports' => __('Open the financial reports'),
                    'view-acct-audit' => __('See the financial audit trail'),
                    'manage-acct-settings' => __('Manage Accounts Tracking settings'),
                ],
            ],

            'qr_codes' => [
                'label' => __('QR Codes'),
                'abilities' => [
                    'scan-qr-codes' => __('Use the QR Scanner and see the QR code button on records you can open'),
                    'print-qr-codes' => __('Print / Download the QR code of a record'),
                    'read-qr-scans' => __('Open QR Scans: the history of your own scans'),
                    'view-all-qr-scans' => __('See everybody\'s scans in QR Scans, not only your own'),
                    'print-qr-scans' => __('Print / Download the QR scan history'),
                    'activate-qr-codes' => __('QR Activation: switch QR codes on or off for each module'),
                    'manage-qr-codes' => __('Manage QR codes: settings (size, print layout, colours) and replace a record\'s code'),
                ],
            ],

            'multi_transfers' => [
                'label' => __('Multi Transfer'),
                'abilities' => [
                    'read-multi-transfers' => __('Open Multi Transfer: the list and each request with its transfers'),
                    'create-multi-transfers' => __('Create a Multi Transfer: items from several warehouses to one destination (one ordinary transfer per source, each held for approval)'),
                    'approve-multi-transfers' => __('Approve all the transfers of a Multi Transfer at once (stock moves for each source that has enough)'),
                    'cancel-multi-transfers' => __('Cancel the transfers of a Multi Transfer that are still waiting for approval'),
                    'print-multi-transfers' => __('Print / Download a Multi Transfer'),
                ],
            ],

            'external_stock' => [
                'label' => __('External Stock'),
                'abilities' => [
                    'read-external-stock' => __('Open External Stock: movements, balances and reports'),
                    'create-external-stock' => __('Record Stock In, Stock Out and opening stock of External Personnel (each is held for approval)'),
                    'update-external-stock' => __('Edit a movement that is still waiting for approval'),
                    'approve-external-stock' => __('Approve or reject External Stock movements (this is what changes the balances)'),
                    'cancel-external-stock' => __('Cancel a movement, even one that is already approved'),
                    'print-external-stock' => __('Print and download External Stock documents and reports'),
                    'generate-transport-route-external-stock' => __('Generate a Transport Route from an approved External Stock movement'),
                ],
            ],

            'travels' => [
                'label' => __('Travels'),
                'abilities' => [
                    'read-travels' => __('Open Travels: the travel records, ticket agent accounts and reports'),
                    'create-travels' => __('Record a travel (each is held for approval)'),
                    'update-travels' => __('Edit a travel that is still waiting for approval, and the flight details of an approved one'),
                    'approve-travels' => __('Approve or reject travels (an approved travel is what the ticket agent is owed for)'),
                    'cancel-travels' => __('Cancel a travel, even one that is already approved'),
                    'print-travels' => __('Print and download travel records, statements and reports'),
                    'read-travel-agents' => __('See ticket agent accounts: balances, statements and payments'),
                    'create-payment-voucher-from-travels' => __('Make a Payment Voucher for a ticket agent from the agent balance (the voucher then waits for approval in the Payment Vouchers module)'),
                    'manage-travel-agents' => __('Set or change the opening balance of a ticket agent (what they were owed before Travels started)'),
                    'manage-travel-settings' => __('Change the Travels settings: currencies, travel types and reminder days'),
                    'read-travel-packages' => __('See the Travel Packages (the ready-made travels and their amounts)'),
                    'create-travel-packages' => __('Create a Travel Package'),
                    'update-travel-packages' => __('Change a Travel Package: its route and amounts, and switch it on or off (every change is kept in its history)'),
                    'delete-travel-packages' => __('Delete a Travel Package'),
                    'use-travel-packages' => __('Start a Travel Request from a Travel Package (choose the people and the dates)'),
                    'audit-travel-packages' => __('See the history of a Travel Package: every change of its amounts and rates, and every request made from it'),
                ],
            ],

            // Holiday Greetings: standalone. It only reads users, staff and contacts when somebody presses Import.
            'holiday_greetings' => [
                'label' => __('Holiday Greetings'),
                'abilities' => [
                    'read-holiday-greetings' => __('Open Holiday Greetings: the dashboard with this year\'s holidays, the coming ones and the record of every holiday'),
                    'manage-holidays' => __('Add, change, switch off or remove a holiday, confirm a date that follows the moon, and include a holiday that falls on a weekend'),
                    'read-holiday-contacts' => __('See the list of people who receive the greetings'),
                    'manage-holiday-contacts' => __('Import users, staff and contacts into the list, add people by hand, change or remove them'),
                    'prepare-holiday-greetings' => __('Change the greeting message (email and SMS) and leave people out of one holiday'),
                    'send-holiday-greetings' => __('Send the greetings of a holiday and retry the ones that failed'),
                    'print-holiday-greetings' => __('Print, download and export the record of a holiday'),
                    'manage-holiday-photos' => __('Upload a photo for the background of a holiday email, or put the WIMS photo back'),
                    'manage-holiday-settings' => __('Change the Holiday Greetings settings: hours before the reminder, weekends, reminder channels and the sign-off'),
                    'receive-holiday-reminders' => __('Receive the reminder (email and SMS) that a holiday is coming, in addition to the Super Admins'),
                ],
            ],

            'visitor_logs' => [
                'label' => __('Visitor Logs'),
                'abilities' => [
                    // visits
                    'read-visitor-logs' => __('Open Visitor Logs: the dashboard and the visitor register (only the visits made or received by the person, unless they may see all)'),
                    'view-all-visits' => __('See every visit, not only the ones the person registered or is being visited in'),
                    'view-active-visitors' => __('See who is on the premises now (the Currently on Premises list and report)'),
                    'create-visitor-logs' => __('Register a visit: check a visitor in'),
                    'update-visitor-logs' => __('Change the details of a visit (person visited, purpose, remarks), never its times'),
                    'checkout-visitor-logs' => __('Mark a visitor as leaving (check out)'),
                    'correct-visitor-logs' => __('Correct the check-in or check-out time of a visit, and set an earlier check-in time (a reason is always recorded)'),
                    'cancel-visitor-logs' => __('Cancel a visit that was registered by mistake (a reason is always recorded)'),
                    // directory
                    'read-visitors' => __('Open the Visitor Directory and search visitors'),
                    'create-visitors' => __('Add a visitor to the directory (also from inside the visit form)'),
                    'update-visitors' => __('Change a visitor\'s details'),
                    'create-duplicate-visitors' => __('Save a visitor even when the name and phone look like an existing one'),
                    'deactivate-visitors' => __('Deactivate or reactivate a visitor'),
                    'view-visitor-sensitive' => __('See and change sensitive visitor details: identification, nationality, address and emergency notes'),
                    'view-visitor-history' => __('See the previous visits of a visitor'),
                    // incoming items
                    'read-incoming-items' => __('Open Incoming Mail & Deliveries: the register of letters, packages and documents received at reception'),
                    'create-incoming-items' => __('Register a letter, package or document received at reception'),
                    'update-incoming-items' => __('Change the details of a registered item'),
                    'update-incoming-item-status' => __('Move an item through its statuses (notified, ready for collection...)'),
                    'handover-incoming-items' => __('Hand an item over to the recipient or an authorized collector, or return it to the sender'),
                    'correct-incoming-items' => __('Correct a delivery record after the item was handed over (a reason is always recorded)'),
                    'view-confidential-incoming-items' => __('See the sender and the subject of items marked confidential'),
                    'attach-incoming-items' => __('Attach, view and download the scans and photos of an item'),
                    'view-incoming-item-history' => __('See the history of an item: every status, who changed it and when'),
                    // reports and audit
                    'read-visitor-reports' => __('Open the Visitor Logs reports'),
                    'print-visitor-reports' => __('Print, download and export the Visitor Logs reports'),
                    'view-visitor-notification-log' => __('See the Visitor Notification Log report: who was told, on which channel and whether it was delivered'),
                    'view-visitor-audit' => __('See the Audit and Correction History report'),
                    // configuration
                    'manage-visitor-options' => __('Manage the lists: visit purposes, item types, item statuses, departments and visitor categories'),
                    'manage-visitor-settings' => __('Change the Visitor Logs settings: thresholds, visitor fields, who can be visited, retention'),
                    'manage-visitor-notifications' => __('Change who is notified of visitors and incoming items, on which channels, and the messages'),
                ],
            ],

            'weekly_administration' => [
                'label' => __('Administration (Weekly)'),
                'abilities' => [
                    'read-weekly-administration' => __('Open Administration: the weekly documents, the Charity list and the reports'),
                    'create-weekly-administration' => __('Make a weekly Administration document (from the template or from zero)'),
                    'update-weekly-administration' => __('Edit an Administration document that is waiting for approval, and add or remove its charity section'),
                    'approve-weekly-administration' => __('Approve or reject an Administration document (stage 2)'),
                    'authorize-weekly-administration' => __('Authorize an approved Administration document (stage 3, the last)'),
                    'cancel-weekly-administration' => __('Cancel an Administration document, even one that is approved or authorized'),
                    'duplicate-weekly-administration' => __('Duplicate an Administration document (asks for the password of the person doing it)'),
                    'print-weekly-administration' => __('Print and download Administration documents and reports'),
                    'send-weekly-administration' => __('Send an Administration document by email to chosen people or to the Super Admins'),
                    'view-all-weekly-administration' => __('See every Administration document (otherwise only the ones the person made or is the beneficiary of)'),
                    'mark-paid-weekly-administration' => __('Mark an authorized Administration document paid: all of it, one line at a time or part of a line, and choose the user or staff member who was paid'),
                    'reverse-payments-weekly-administration' => __('Reverse a payment that was marked paid on an Administration document (with a reason)'),
                    'create-payment-voucher-from-administration' => __('Make a Payment Voucher for an authorized Administration document (it is then approved and paid in the Payment Vouchers module)'),
                    'manage-administration-items' => __('Change the Administration template and the list of items'),
                    'read-administration-charity' => __('See the Charity list (people and their monthly amounts)'),
                    'manage-administration-charity' => __('Add, change and remove people and their monthly amounts in the Charity list'),
                    'manage-administration-settings' => __('Change the Administration settings (department name, currency, charity wording)'),
                ],
            ],

            'subcontracts' => [
                'label' => __('Subcontractor Management'),
                'abilities' => [
                    'read-subcontracts' => __('Open Subcontractor Management: dashboard, register and profiles'),
                    'view-financials-subcontracts' => __('See contract values, payments and balances of subcontractors'),
                    'view-documents-subcontracts' => __('View and download subcontract documents and attachments'),
                    'view-audit-subcontracts' => __('See the audit and change history of subcontract records'),
                ],
            ],
            'subcontract_agreements' => [
                'label' => __('Subcontract Agreements and Work Items'),
                'abilities' => [
                    'read-subcontract-agreements' => __('View subcontract agreements and their work schedules'),
                    'create-subcontract-agreements' => __('Create agreement drafts and add work groups and work items'),
                    'update-subcontract-agreements' => __('Edit a draft agreement and its work schedule'),
                    'submit-subcontract-agreements' => __('Submit an agreement for review'),
                    'review-subcontract-agreements' => __('Review a submitted agreement'),
                    'approve-subcontract-agreements' => __('Approve or reject an agreement (makes it a payable commitment)'),
                    'manage-subcontract-agreements' => __('Suspend, reactivate, complete or terminate an agreement'),
                    'verify-subcontract-completion' => __('Verify (or withdraw the verification of) the completion of an agreement, which lets it be put on a contract payment request'),
                    'delete-subcontract-agreements' => __('Delete a draft agreement'),
                ],
            ],
            'subcontract_variations' => [
                'label' => __('Subcontract Variations'),
                'abilities' => [
                    'read-subcontract-variations' => __('View variations (additional works and revised costs)'),
                    'create-subcontract-variations' => __('Create and edit draft variations'),
                    'submit-subcontract-variations' => __('Submit a variation'),
                    'review-subcontract-variations' => __('Review a submitted variation'),
                    'approve-subcontract-variations' => __('Approve or reject a variation (changes the revised contract value)'),
                ],
            ],
            'subcontract_requests' => [
                'label' => __('Subcontract Payment Requests'),
                'abilities' => [
                    'read-subcontract-requests' => __('View payment requests and the payments made against them'),
                    'create-subcontract-requests' => __('Create and edit draft payment requests'),
                    'submit-subcontract-requests' => __('Submit a payment request'),
                    'review-subcontract-requests' => __('Review a request, or return it for correction'),
                    'approve-subcontract-requests' => __('Approve or reject a payment request'),
                    'initiate-subcontract-vouchers' => __('Start the Payment Voucher for an approved request (the voucher keeps its own approvals)'),
                    'override-subcontract-limits' => __('Approve above the remaining entitlement, with a reason (always recorded)'),
                ],
            ],
            'subcontract_claims' => [
                'label' => __('Subcontract Contract Payment Requests'),
                'abilities' => [
                    'read-subcontract-claims' => __('View contract payment requests (claims for several completed agreements) and what was decided and paid'),
                    'create-subcontract-claims' => __('Create and edit draft contract payment requests, and cancel your own'),
                    'submit-subcontract-claims' => __('Submit a contract payment request, and send a returned line again'),
                    'review-subcontract-claims' => __('Take a contract payment request into review, or return a line for correction'),
                    'decide-subcontract-claims' => __('Approve, reject, return or defer the lines of a contract payment request (an approved line becomes a payment request)'),
                ],
            ],

            'subcontract_import' => [
                'label' => __('Subcontract Import and Reconciliation'),
                'abilities' => [
                    'import-subcontracts' => __('Upload the historical workbook and stage it for review'),
                    'confirm-import-subcontracts' => __('Confirm a staged import (creates the agreements and legacy payments)'),
                    'reconcile-subcontracts' => __('See reconciliation differences and add notes'),
                    'approve-reconciliation-subcontracts' => __('Approve an unresolved reconciliation difference'),
                ],
            ],
            'subcontract_reports' => [
                'label' => __('Subcontract Reports'),
                'abilities' => [
                    'read-subcontract-reports' => __('Open the subcontract reports'),
                    'print-subcontract-reports' => __('Print, download and export subcontract reports and statements'),
                ],
            ],
            'subcontract_opening' => [
                'label' => __('Subcontract Opening Balances and Project Mapping'),
                'abilities' => [
                    'read-opening-subcontracts' => __('See the opening balances of the subcontractor accounts'),
                    'manage-opening-subcontracts' => __('Set an account\'s opening balance and attach its previous data (Excel, PDF, images)'),
                    'reset-opening-subcontracts' => __('Reset the subcontractor payments and history to opening balances (with a backup)'),
                    'map-projects-subcontracts' => __('Match the project names of the Excel to Project(s) / Warehouse(s) and confirm the mapping'),
                ],
            ],
            'subcontract_alerts' => [
                'label' => __('Subcontract Alerts'),
                'abilities' => [
                    'read-alerts-subcontracts' => __('See the subcontract alerts'),
                    'resolve-alerts-subcontracts' => __('Acknowledge, assign, escalate and resolve subcontract alerts, and run the check'),
                ],
            ],
            'subcontract_settings' => [
                'label' => __('Subcontract Settings'),
                'abilities' => [
                    'manage-subcontract-settings' => __('Change units, categories, numbering, limits and alerts'),
                ],
            ],

            'delivery_notes' => [
                'label' => __('Delivery Notes'),
                'abilities' => [
                    'read-delivery-notes' => __('View Delivery Notes'),
                    'create-delivery-notes' => __('Generate Delivery Notes (from Transfers / Checkins)'),
                    'print-delivery-notes' => __('Print / Download Delivery Notes'),
                ],
            ],

            'gate_passes' => [
                'label' => __('Gate Passes'),
                'abilities' => [
                    'read-gate-passes' => __('View Gate Passes'),
                    'create-gate-passes' => __('Generate / Edit Gate Passes (from Transfers / Checkins)'),
                    'print-gate-passes' => __('Print / Download Gate Passes'),
                ],
            ],

            'archive' => [
                'label' => __('Document Archive'),
                'abilities' => [
                    'read-archive' => __('View Archive (dashboard, records, history)'),
                    'create-archive' => __('Create Archive records / add attachments'),
                    'update-archive' => __('Edit Archive records'),
                    'delete-archive' => __('Delete Archive records / attachments'),
                    'preview-archive-files' => __('Preview / open Archive attachments in a new tab'),
                    'download-archive-files' => __('Download Archive attachments'),
                    'print-archive' => __('Print Archive record sheets'),
                    'read-archive-confidential' => __('See Confidential / Restricted Archive records'),
                    'view-archive-access-logs' => __('See who viewed / downloaded Archive documents'),
                    'view-archive-storage' => __('See Archive storage figures'),
                    'manage-archive-notifications' => __('Set who is notified about Archive events, and when'),
                ],
            ],

            'site_diary' => [
                'label' => __('Site Diary'),
                'abilities' => [
                    'read-site-diary' => __('View Site Diary (list, dashboard, open diaries you may see)'),
                    'view-all-site-diaries' => __('See every Site Diary of the projects the user can access (otherwise only their own)'),
                    'create-site-diary' => __('Create a Site Diary'),
                    'update-site-diary' => __('Edit own Site Diaries'),
                    'update-any-site-diary' => __('Edit any Site Diary of an accessible project'),
                    'delete-site-diary' => __('Delete own Site Diaries'),
                    'delete-any-site-diary' => __('Delete any Site Diary of an accessible project'),
                    'backdate-site-diary' => __('Record a Site Diary for a date other than today'),
                    'add-site-diary-photos' => __('Add photos to a Site Diary'),
                    'delete-site-diary-photos' => __('Remove photos from a Site Diary'),
                    'add-site-diary-videos' => __('Add videos to a Site Diary'),
                    'delete-site-diary-videos' => __('Remove videos from a Site Diary'),
                    'view-site-diary-photos' => __('View photos'),
                    'view-site-diary-videos' => __('View / play videos'),
                    'download-site-diary-media' => __('Download photos and videos'),
                    'view-site-diary-history' => __('See the history timeline of a diary'),
                    'view-site-diary-viewers' => __('See who has viewed a diary (Viewed By column and the history)'),
                    'view-site-diary-analytics' => __('See the Site Diary dashboard and analytics'),
                    'view-site-diary-reports' => __('Open Site Diary reports'),
                    'print-site-diary-reports' => __('Print / download Site Diary reports as PDF'),
                    'export-site-diary-reports' => __('Export Site Diary reports to Excel / CSV'),
                    'manage-site-diary-settings' => __('Change Site Diary settings, Google Drive connection and notifications'),
                ],
            ],

            // Management's weekly meeting schedule per site and the minutes the site engineers prepare for each meeting.
            'site_meetings' => [
                'label' => __('Site Meetings'),
                'abilities' => [
                    'read-site-meetings' => __('View the Site Meetings schedule and open meetings of the sites the user works with (or is invited to)'),
                    'view-all-site-meetings' => __('See the whole meeting schedule of every site (without it, only the sites the user is assigned to or invited to)'),
                    'view-only-site-meetings' => __('READ ONLY: can only view the schedule and the minutes and print / download them, cannot schedule, edit, drag, write minutes or change anything, whatever else is granted'),
                    'create-site-meetings' => __('Schedule a site meeting (also weekly repeats)'),
                    'update-site-meetings' => __('Edit a meeting (date, venue, people, agenda, attachments) and mark it held / postponed / cancelled'),
                    'delete-site-meetings' => __('Delete and restore site meetings'),
                    'update-site-meeting-reports' => __('Write and save the minutes of a meeting, and submit them'),
                    'finalise-site-meeting-reports' => __('Finalise (lock) the minutes of a meeting and reopen finalised minutes'),
                    'print-site-meetings' => __('Print / download the weekly schedule, a meeting sheet and the minutes'),
                    'view-site-meeting-history' => __('See the history of a meeting: who created, viewed and changed it'),
                    'manage-site-meetings-settings' => __('Change Site Meetings settings and the layout of the minutes document'),
                ],
            ],

            'wimschat' => [
                'label' => __('WIMSChat'),
                'abilities' => [
                    'view-wimschat' => __('Use WIMSChat (open the chat drawer and the WIMSChat module, read own conversations, be found by colleagues)'),
                    'start-wimschat-conversations' => __('Start a one-to-one conversation'),
                    'create-wimschat-groups' => __('Create group conversations'),
                    'manage-wimschat-groups' => __('Manage any group: rename, add / remove members, make administrators (group administrators can always manage their own group)'),
                    'send-wimschat-messages' => __('Send messages (and reply)'),
                    'edit-wimschat-messages' => __('Edit own messages (within the edit window)'),
                    'delete-wimschat-messages' => __('Delete own messages (within the delete window)'),
                    'delete-any-wimschat-message' => __('Delete anyone\'s message (moderation)'),
                    'react-wimschat-messages' => __('React to messages'),
                    'forward-wimschat-messages' => __('Forward messages to other conversations'),
                    'pin-wimschat-messages' => __('Pin / unpin messages in a conversation'),
                    'search-wimschat' => __('Search messages and conversations'),
                    'mention-wimschat-users' => __('Mention people with @'),
                    'nudge-wimschat-users' => __('Nudge people'),
                    'attach-wimschat-files' => __('Attach files and images'),
                    'view-wimschat-attachments' => __('Preview attachments'),
                    'download-wimschat-attachments' => __('Download attachments'),
                    'delete-wimschat-attachments' => __('Delete own attachments'),
                    'delete-any-wimschat-attachment' => __('Delete anyone\'s attachment (moderation)'),
                    'moderate-wimschat' => __('Moderate conversations (open any group the user belongs to as an administrator)'),
                    'manage-wimschat-settings' => __('Change WIMSChat settings: file limits, daily quotas, file types, reactions, presence, notifications'),
                    'view-wimschat-analytics' => __('See WIMSChat analytics and storage figures'),
                    'view-wimschat-logs' => __('See WIMSChat activity in System Logs'),
                ],
            ],

            'pro_menu' => [
                'label' => __('Pro menu'),
                'abilities' => [
                    'use-pro-menu' => __('Choose the Pro menu (instead of the Classic menu) in the profile menu. Every role has it by default.'),
                ],
            ],

            'archive_tree' => [
                'label' => __('Archive Tree'),
                'abilities' => [
                    'read-archive-tree' => __('Open the Archive Tree (folder panel and folder views). Folders holding Confidential / Restricted records still ask for the user\'s password.'),
                ],
            ],

            'items' => [
                'label' => __('Items'),
                'abilities' => [
                    'read-items' => __('view_x', ['x' => __('Items')]),
                    'create-items' => __('create_x', ['x' => __('Items')]),
                    'update-items' => __('update_x', ['x' => __('Items')]),
                    'delete-items' => __('delete_x', ['x' => __('Items')]),
                    'create-used-items' => __('Create USED copy of an Item'),
                    'read-item-price-history' => __('View Item Price History'),
                ],
            ],

            'contacts' => [
                'label' => __('Contacts'),
                'abilities' => [
                    'read-contacts' => __('view_x', ['x' => __('Contacts')]),
                    'create-contacts' => __('create_x', ['x' => __('Contacts')]),
                    'update-contacts' => __('update_x', ['x' => __('Contacts')]),
                    'delete-contacts' => __('delete_x', ['x' => __('Contacts')]),
                    'print-contacts' => __('Print / Download Contacts'),
                ],
            ],

            'staffs' => [
                'label' => __('RANS Staffs'),
                'abilities' => [
                    'read-staffs' => __('view_x', ['x' => __('RANS Staffs')]),
                    'create-staffs' => __('create_x', ['x' => __('RANS Staffs')]),
                    'update-staffs' => __('update_x', ['x' => __('RANS Staffs')]),
                    'delete-staffs' => __('delete_x', ['x' => __('RANS Staffs')]),
                    'print-staffs' => __('Print / Download Staff Records and Contracts'),
                ],
            ],

            // Contracts and retirement are part of the staff module, with their own abilities so a role can see staff without seeing contracts
            'staff_contracts' => [
                'label' => __('Staff Contracts and Retirement'),
                'abilities' => [
                    'read-staff-contracts' => __('View staff contracts, their documents and the contracts calendar'),
                    'create-staff-contracts' => __('Add a contract to a staff member'),
                    'update-staff-contracts' => __('Edit, renew and end staff contracts, and attach or remove their documents'),
                    'stop-staff-contracts' => __('Stop a contract because the person left or was terminated (with the reason)'),
                    'delete-staff-contracts' => __('Delete staff contracts'),
                    'print-staff-contracts' => __('Print / Download staff contract sheets'),
                    'retire-staffs' => __('Mark a staff member Retired or Retired Active, and reinstate them'),
                ],
            ],

            'categories' => [
                'label' => __('Categories'),
                'abilities' => [
                    'read-categories' => __('view_x', ['x' => __('Categories')]),
                    'create-categories' => __('create_x', ['x' => __('Categories')]),
                    'update-categories' => __('update_x', ['x' => __('Categories')]),
                    'delete-categories' => __('delete_x', ['x' => __('Categories')]),
                    'read-item-category-report' => __('View Item Category Report'),
                ],
            ],

            'stock_archives' => [
                'label' => __('Stock Archive'),
                'abilities' => [
                    'read-stock-archives' => __('Open the Stock Archive: see the kept stock reports, preview and download them, and see the history of each'),
                    'create-stock-archives' => __('Make a stock report now (it is kept in the Stock Archive and shows who made it). Deleting entries and the settings are for Super Admins only'),
                ],
            ],

            'units' => [
                'label' => __('Units'),
                'abilities' => [
                    'read-units' => __('view_x', ['x' => __('Units')]),
                    'create-units' => __('create_x', ['x' => __('Units')]),
                    'update-units' => __('update_x', ['x' => __('Units')]),
                    'delete-units' => __('delete_x', ['x' => __('Units')]),
                ],
            ],

            'warehouses' => [
                'label' => __('Warehouses'),
                'abilities' => [
                    'read-warehouses' => __('view_x', ['x' => __('Warehouses')]),
                    'create-warehouses' => __('create_x', ['x' => __('Warehouses')]),
                    'update-warehouses' => __('update_x', ['x' => __('Warehouses')]),
                    'delete-warehouses' => __('delete_x', ['x' => __('Warehouses')]),
                    'manage-my-warehouses' => __('My Warehouses (Site Engineer view)'),
                    'manage-account-settings' => __('Manage Account / Warehouse Settings'),
                ],
            ],

            'users' => [
                'label' => __('Users'),
                'abilities' => [
                    'read-users' => __('view_x', ['x' => __('Users')]),
                    'create-users' => __('create_x', ['x' => __('Users')]),
                    'update-users' => __('update_x', ['x' => __('Users')]),
                    'delete-users' => __('delete_x', ['x' => __('Users')]),
                    'audit-users' => __('View the User Audit History'),
                    'reset-password-users' => __('Reset a User Password (generates a new one)'),
                ],
            ],

            'roles' => [
                'label' => __('Roles'),
                'abilities' => [
                    'read-roles' => __('view_x', ['x' => __('Roles')]),
                    'create-roles' => __('create_x', ['x' => __('Roles')]),
                    'update-roles' => __('update_x', ['x' => __('Roles')]),
                    'delete-roles' => __('delete_x', ['x' => __('Roles')]),
                    'update-permissions' => __('Update Role Permissions'),
                ],
            ],

            'issue_returns' => [
                'label' => __('Issue & Return'),
                'abilities' => [
                    'read-issue-returns' => __('view_x', ['x' => __('Issue & Return')]),
                    'create-issue-returns' => __('create_x', ['x' => __('Issue & Return')]),
                    'update-issue-returns' => __('update_x', ['x' => __('Issue & Return')]),
                    'delete-issue-returns' => __('delete_x', ['x' => __('Issue & Return')]),
                    'return-issue-returns' => __('Process Returns'),
                    'print-issue-returns' => __('Print Issue & Return'),
                    'issue-return-reports' => __('View Issue & Return Reports'),
                ],
            ],

            // Material Requests are fully permission-driven: everything below is assigned on the Roles screen and
            // nowhere else (the Material Request Notification page only decides who is TOLD). Each workflow step has
            // its own ability; MaterialRequestWorkflowStep::STEP_PERMISSIONS maps step -> ability and
            // App\Support\MaterialRequestVisibility explains who sees which request.
            'material_requests' => [
                'label' => __('Material Requests'),
                'abilities' => [
                    'read-material-requests' => __('View Material Requests (own requests and those of the projects the user is assigned to)'),
                    'view-all-material-requests' => __('View requests of all projects and all statuses'),
                    'create-material-requests' => __('create_x', ['x' => __('Material Requests')]),
                    'update-material-requests' => __('Edit Any Material Request (until Procurement starts)'),
                    'delete-material-requests' => __('Delete Any Material Request (until it is cleared)'),
                    'approve-material-requests' => __('Approve / Reject'),
                    'quick-material-requests' => __('Raise Quick Material Requests (shorter form, own approver)'),
                    'quick-approve-material-requests' => __('Approve / Reject Quick Material Requests'),
                    'procure-material-requests' => __('Procurement: Set Procedures'),
                    'issue-material-requests' => __('Mark Partially / Fully Issued'),
                    'receive-material-requests' => __('Confirm Delivery on Site'),
                    'complete-material-requests' => __('Clear / Complete'),
                    'view-procedures-material-requests' => __('View Procedures (Read-Only)'),
                    'print-material-requests' => __('Print Material Requests'),
                    'download-material-requests' => __('Download PDF'),
                ],
            ],

            // Reminders on approved Material Requests that have not reached their final status: send them (email, SMS, app alert), see the history and who was told, print the report.
            'mr_reminders' => [
                'label' => __('Material Request Reminders'),
                'abilities' => [
                    'read-mr-reminders' => __('See the reminders sent on Material Requests and the All Requests Reminders page'),
                    'send-mr-reminders' => __('Send a reminder on an approved Material Request that has not reached its final status'),
                    'print-mr-reminders' => __('Print and download the reminders report (PDF and CSV)'),
                    'manage-mr-reminder-settings' => __('Choose who receives the reminders (roles and users) and through which channels'),
                ],
            ],

            // RANS ID & Pass Management: staff IDs, compound worker IDs, visitor passes and gate passes of people (not the goods Gate Pass of Delivery & Gate), their cards, printing and history.
            'id_passes' => [
                'label' => __('RANS ID & Pass Management'),
                'abilities' => [
                    'read-id-passes' => __('Open RANS ID & Pass Management and see its dashboard'),
                ],
            ],
            'id_staff' => [
                'label' => __('RANS ID: Staff IDs'),
                'abilities' => [
                    'read-staff-ids' => __('See the Staff IDs'),
                    'assign-staff-ids' => __('Assign a RANS Staff ID to a staff member (and renew or replace it)'),
                    'update-staff-ids' => __('Change the details and photograph of a Staff ID'),
                    'print-staff-ids' => __('Print Staff IDs'),
                    'download-staff-ids' => __('Download Staff ID PDFs'),
                    'revoke-staff-ids' => __('Suspend, revoke or reactivate Staff IDs'),
                ],
            ],
            'id_compound' => [
                'label' => __('RANS ID: Compound Worker IDs'),
                'abilities' => [
                    'read-compound-ids' => __('See the Compound Worker IDs'),
                    'assign-compound-ids' => __('Assign a Compound Worker ID to a contact (and renew or replace it)'),
                    'update-compound-ids' => __('Change the details and photograph of a Compound Worker ID'),
                    'print-compound-ids' => __('Print Compound Worker IDs'),
                    'download-compound-ids' => __('Download Compound Worker ID PDFs'),
                    'revoke-compound-ids' => __('Suspend, revoke or reactivate Compound Worker IDs'),
                ],
            ],
            'id_visitor' => [
                'label' => __('RANS ID: Visitor Passes'),
                'abilities' => [
                    'read-visitor-passes' => __('See the Visitor Passes'),
                    'assign-visitor-passes' => __('Give a Visitor Pass to a visitor at check-in and take it back (from the Visitor Logs forms too)'),
                    'create-visitor-passes' => __('Issue Visitor Passes'),
                    'update-visitor-passes' => __('Change the details of a Visitor Pass'),
                    'print-visitor-passes' => __('Print Visitor Passes'),
                    'download-visitor-passes' => __('Download Visitor Pass PDFs'),
                    'revoke-visitor-passes' => __('Suspend, revoke or reactivate Visitor Passes'),
                ],
            ],
            'id_gate' => [
                'label' => __('RANS ID: Gate Passes (people)'),
                'abilities' => [
                    'read-people-gate-passes' => __('See the Gate Passes of people and vehicles'),
                    'create-people-gate-passes' => __('Issue Gate Passes'),
                    'update-people-gate-passes' => __('Change the details of a Gate Pass'),
                    'approve-people-gate-passes' => __('Approve or reject a Gate Pass that needs approval'),
                    'print-people-gate-passes' => __('Print Gate Passes'),
                    'download-people-gate-passes' => __('Download Gate Pass PDFs'),
                    'revoke-people-gate-passes' => __('Suspend, revoke or reactivate Gate Passes'),
                ],
            ],
            'id_print_centre' => [
                'label' => __('RANS ID: Print Centre'),
                'abilities' => [
                    'access-id-print-centre' => __('Open the Print Centre'),
                    'batch-print-ids' => __('Print IDs and passes in batches (A4 sheets)'),
                    'batch-export-ids' => __('Export IDs and passes in batches as PDF'),
                    'read-id-print-history' => __('See who printed or exported which ID and when'),
                    'read-id-reports' => __('See and print the RANS ID reports'),
                ],
            ],
            'id_settings' => [
                'label' => __('RANS ID: Settings'),
                'abilities' => [
                    'manage-id-settings' => __('Change the ID settings: categories, validity, back of the card'),
                    'manage-id-templates' => __('Change the card templates (colours, icons, labels, shared back)'),
                    'manage-id-numbering' => __('Change numbering prefixes and sequences, and type an ID number by hand'),
                    'manage-id-qr-settings' => __('Change how the QR code of a card is verified'),
                    'manage-id-notifications' => __('Choose who is told about issued, expiring, suspended and revoked IDs'),
                ],
            ],

            'material_reallocations' => [
                'label' => __('Material Reallocation'),
                'abilities' => [
                    'read-material-reallocations' => __('view_x', ['x' => __('Material Reallocation')]),
                    'create-material-reallocations' => __('create_x', ['x' => __('Material Reallocation')]),
                    'update-material-reallocations' => __('update_x', ['x' => __('Material Reallocation')]),
                    'delete-material-reallocations' => __('delete_x', ['x' => __('Material Reallocation')]),
                    'submit-material-reallocations' => __('Submit for Clearance'),
                    'clear-material-reallocations' => __('PM Clearance'),
                    'allocate-material-reallocations' => __('Procurement Allocation'),
                    'dispatch-material-reallocations' => __('Dispatch'),
                    'receive-material-reallocations' => __('Warehouse Receiving'),
                    'final-approve-material-reallocations' => __('Final Approval'),
                    'request-changes-material-reallocations' => __('Request Changes'),
                    'reject-material-reallocations' => __('Reject'),
                    'cancel-material-reallocations' => __('Cancel'),
                    'print-material-reallocations' => __('Print'),
                    'download-pdf-material-reallocations' => __('Download PDF'),
                ],
            ],

            // Supplier Records is one module in the app's sidebar, but it's
            // really several distinct workflows (requesting, ordering,
            // receiving, invoicing, paying, reconciling) each with its own
            // audience — a Store Manager who can record a Goods Receipt
            // shouldn't automatically be able to approve a Payment Request.
            // So — unlike most modules above — it gets one PermissionRegistry
            // entry per submodule instead of a single lumped one.
            'supplier_records' => [
                'label' => __('Supplier Dashboard & Profiles'),
                'abilities' => [
                    'read-supplier-records' => __('View Supplier Dashboard & Profiles'),
                    'update-supplier-records' => __('Edit Supplier Financial Details'),
                ],
            ],

            'supplier_reports' => [
                'label' => __('Supplier Reports & Explorer'),
                'abilities' => [
                    'read-supplier-reports' => __('View Supplier Reports & Transaction Explorer'),
                ],
            ],

            'purchase_requests' => [
                'label' => __('Supplier Purchase Requests'),
                'abilities' => [
                    'read-purchase-requests' => __('view_x', ['x' => __('Supplier Purchase Requests')]),
                    'create-purchase-requests' => __('create_x', ['x' => __('Supplier Purchase Requests')]),
                    'update-purchase-requests' => __('update_x', ['x' => __('Supplier Purchase Requests')]),
                    'delete-purchase-requests' => __('delete_x', ['x' => __('Supplier Purchase Requests')]),
                    'approve-purchase-requests' => __('Approve / Reject Supplier Purchase Requests'),
                    'print-purchase-requests' => __('Print / Download Supplier Purchase Requests'),
                ],
            ],

            'quotations' => [
                'label' => __('Supplier Quotations'),
                'abilities' => [
                    'read-quotations' => __('view_x', ['x' => __('Supplier Quotations')]),
                    'create-quotations' => __('create_x', ['x' => __('Supplier Quotations')]),
                    'update-quotations' => __('update_x', ['x' => __('Supplier Quotations')]),
                    'delete-quotations' => __('delete_x', ['x' => __('Supplier Quotations')]),
                    'print-quotations' => __('Print / Download Supplier Quotations'),
                ],
            ],

            'purchase_orders' => [
                'label' => __('Supplier Purchase Orders'),
                'abilities' => [
                    'read-purchase-orders' => __('view_x', ['x' => __('Supplier Purchase Orders')]),
                    'create-purchase-orders' => __('create_x', ['x' => __('Supplier Purchase Orders')]),
                    'update-purchase-orders' => __('update_x', ['x' => __('Supplier Purchase Orders')]),
                    'delete-purchase-orders' => __('delete_x', ['x' => __('Supplier Purchase Orders')]),
                    'approve-purchase-orders' => __('Submit / Approve / Reject / Send / Cancel Supplier Purchase Orders'),
                    'print-purchase-orders' => __('Print / Download Supplier Purchase Orders'),
                ],
            ],

            'goods_receipts' => [
                'label' => __('Supplier Goods Receipts'),
                'abilities' => [
                    'read-goods-receipts' => __('view_x', ['x' => __('Supplier Goods Receipts')]),
                    'create-goods-receipts' => __('create_x', ['x' => __('Supplier Goods Receipts')]),
                    'update-goods-receipts' => __('update_x', ['x' => __('Supplier Goods Receipts')]),
                    'delete-goods-receipts' => __('delete_x', ['x' => __('Supplier Goods Receipts')]),
                    'print-goods-receipts' => __('Print / Download Supplier Goods Receipts'),
                ],
            ],

            'supplier_invoices' => [
                'label' => __('Supplier Invoices'),
                'abilities' => [
                    'read-supplier-invoices' => __('view_x', ['x' => __('Supplier Invoices')]),
                    'create-supplier-invoices' => __('create_x', ['x' => __('Supplier Invoices')]),
                    'update-supplier-invoices' => __('update_x', ['x' => __('Supplier Invoices')]),
                    'delete-supplier-invoices' => __('delete_x', ['x' => __('Supplier Invoices')]),
                    'approve-supplier-invoices' => __('Approve / Dispute Supplier Invoices'),
                    'print-supplier-invoices' => __('Print / Download Supplier Invoices'),
                ],
            ],

            'payment_requests' => [
                'label' => __('Supplier Payment Requests'),
                'abilities' => [
                    'read-payment-requests' => __('view_x', ['x' => __('Supplier Payment Requests')]),
                    'create-payment-requests' => __('create_x', ['x' => __('Supplier Payment Requests')]),
                    'update-payment-requests' => __('update_x', ['x' => __('Supplier Payment Requests')]),
                    'delete-payment-requests' => __('delete_x', ['x' => __('Supplier Payment Requests')]),
                    'approve-payment-requests' => __('Approve / Reject Supplier Payment Requests'),
                    'print-payment-requests' => __('Print / Download Supplier Payment Requests'),
                ],
            ],

            'payments' => [
                'label' => __('Supplier Payments'),
                'abilities' => [
                    'read-payments' => __('view_x', ['x' => __('Supplier Payments')]),
                    'create-payments' => __('Record Supplier Payments'),
                ],
            ],

            // Verifying an Opening Balance and resolving a Reconciliation
            // stay director-only (Super Admin) regardless of this
            // permission — see Ledger::verifyOpeningBalance() and
            // SupplierReconciliationController::resolve() — this only
            // covers viewing the ledger and recording entries.
            'supplier_ledger' => [
                'label' => __('Supplier Ledger & Reconciliation'),
                'abilities' => [
                    'read-supplier-ledger' => __('View Supplier Ledger & Reconciliations'),
                    'create-supplier-ledger' => __('Record Opening Balances, Manual Entries & Reconciliations'),
                    // Correcting an already-recorded Opening Balance (whether
                    // still Pending or already Verified/locked) — deliberately
                    // separate from create-supplier-ledger and gated by a
                    // password re-confirmation in the UI (see Ledger.vue),
                    // since it can retroactively change a supplier's balance.
                    // Granted only to Super Admin by default (see the seed
                    // migration) but assignable to other roles from the
                    // Role/Permission matrix, not hardcoded to a role check.
                    'edit-supplier-ledger' => __('Edit a Recorded Opening Balance'),
                ],
            ],

            // Two-stage approval mirrors the paper voucher's two signature
            // lines (Approved By / Authorised By) — approve-payment-vouchers
            // and authorise-payment-vouchers are deliberately separate so a
            // Super Admin can assign each stage to whichever role they
            // choose (e.g. Accountant vs Managing Director) without either
            // permission being hardcoded to a role name.
            'payment_vouchers' => [
                'label' => __('Payment Vouchers'),
                'abilities' => [
                    'read-payment-vouchers' => __('view_x', ['x' => __('Payment Vouchers')]),
                    'create-payment-vouchers' => __('create_x', ['x' => __('Payment Vouchers')]),
                    'update-payment-vouchers' => __('Edit / Submit Payment Vouchers'),
                    'delete-payment-vouchers' => __('delete_x', ['x' => __('Payment Vouchers')]),
                    'approve-payment-vouchers' => __('Approve / Reject / Cancel Payment Vouchers (Stage 1)'),
                    'authorise-payment-vouchers' => __('Authorise / Reject Payment Vouchers (Stage 2)'),
                    'print-payment-vouchers' => __('Print / Download Payment Vouchers'),
                    'record-payment-payment-vouchers' => __('Record Payment Completion on Payment Vouchers'),
                ],
            ],

            // LabourController opts out of the Authorizable::callAction
            // auto-check entirely (`setAbilities([])`) and gates every
            // action through explicit LabourPolicy checks instead, and each of
            // those checks is one of the permissions below (plus the list's own
            // status and, for editing, who created it): read-labour (view),
            // create-labour, update-labour, delete-labour, submit-labour,
            // clear-labour (Project Manager step), approve-labour (final
            // approval), create-payment-voucher-from-labour, print-labour.
            // Nothing else assigns who may do what.
            'labour' => [
                'label' => __('Labour'),
                'abilities' => [
                    'read-labour' => __('view_x', ['x' => __('Labour')]),
                    'create-labour' => __('create_x', ['x' => __('Labour')]),
                    'update-labour' => __('update_x', ['x' => __('Labour')]),
                    'delete-labour' => __('delete_x', ['x' => __('Labour')]),
                    'submit-labour' => __('Submit Labour List for Review'),
                    'clear-labour' => __('Clear / Return / Reject Labour List (Project Manager)'),
                    'approve-labour' => __('Approve / Return / Reject Labour List (Final Approval)'),
                    'create-payment-voucher-from-labour' => __('Create Payment Voucher from Approved Labour List'),
                    'print-labour' => __('Print / Download Labour Lists'),
                ],
            ],

            // No 'delete' ability — agreements accumulate financial history
            // and are never hard-deleted, only marked Cancelled. The
            // per-item amount-revision capability during Labour approval
            // reuses the existing 'approve-labour' ability / Super Admin
            // workflow step rather than a permission of its own.
            'workforce_agreements' => [
                'label' => __('Workforce Agreements'),
                'abilities' => [
                    'read-workforce-agreements' => __('view_x', ['x' => __('Workforce Agreements')]),
                    'create-workforce-agreements' => __('create_x', ['x' => __('Workforce Agreements')]),
                    'update-workforce-agreements' => __('update_x', ['x' => __('Workforce Agreements')]),
                    'print-workforce-agreements' => __('Print / Download Workforce Agreements'),
                ],
            ],

            'daily_site_reports' => [
                'label' => __('Daily Site Reports'),
                'abilities' => [
                    'read-daily-site-reports' => __('view_x', ['x' => __('Daily Site Reports')]),
                    'create-daily-site-reports' => __('create_x', ['x' => __('Daily Site Reports')]),
                    'update-daily-site-reports' => __('update_x', ['x' => __('Daily Site Reports')]),
                    'delete-daily-site-reports' => __('delete_x', ['x' => __('Daily Site Reports')]),
                    'archive-daily-site-reports' => __('Archive Daily Site Reports'),
                    'read-site-report-compliance' => __('View Compliance Dashboard'),
                ],
            ],

            'work_packages' => [
                'label' => __('Project Execution - Work Packages'),
                'abilities' => [
                    'read-work-packages' => __('view_x', ['x' => __('Work Packages')]),
                    'create-work-packages' => __('create_x', ['x' => __('Work Packages')]),
                    'update-work-packages' => __('update_x', ['x' => __('Work Packages')]),
                    'delete-work-packages' => __('delete_x', ['x' => __('Work Packages')]),
                    'approve-work-package-updates' => __('Approve Daily Updates'),
                    'manage-project-execution-settings' => __('Manage Project Execution Settings'),
                    'view-project-execution-analytics' => __('View Analytics'),
                    'export-project-execution-reports' => __('Export Reports'),
                ],
            ],

            'work_package_daily_updates' => [
                'label' => __('Work Package Daily Updates'),
                'abilities' => [
                    'read-work-package-daily-updates' => __('view_x', ['x' => __('Daily Updates')]),
                    'create-work-package-daily-updates' => __('create_x', ['x' => __('Daily Updates')]),
                ],
            ],

            'work_package_issues' => [
                'label' => __('Work Package Issues'),
                'abilities' => [
                    'read-work-package-issues' => __('view_x', ['x' => __('Work Package Issues')]),
                    'create-work-package-issues' => __('create_x', ['x' => __('Work Package Issues')]),
                    'update-work-package-issues' => __('update_x', ['x' => __('Work Package Issues')]),
                    'delete-work-package-issues' => __('delete_x', ['x' => __('Work Package Issues')]),
                ],
            ],

            'work_package_forecasts' => [
                'label' => __('Work Package Forecasts'),
                'abilities' => [
                    'create-work-package-material-forecasts' => __('Create Material Forecast'),
                    'delete-work-package-material-forecasts' => __('Delete Material Forecast'),
                    'create-work-package-labour-forecasts' => __('Create Labour Forecast'),
                    'delete-work-package-labour-forecasts' => __('Delete Labour Forecast'),
                    'create-work-package-equipment-forecasts' => __('Create Equipment Forecast'),
                    'delete-work-package-equipment-forecasts' => __('Delete Equipment Forecast'),
                ],
            ],

            'notices' => [
                'label' => __('Notices'),
                'abilities' => [
                    'read-notices' => __('view_x', ['x' => __('Notices')]),
                    'create-notices' => __('create_x', ['x' => __('Notices')]),
                ],
            ],

            'login_logs' => [
                'label' => __('Login Logs'),
                'abilities' => [
                    'read-login-logs' => __('view_x', ['x' => __('Login Logs')]),
                    'export-login-logs' => __('Export Login Logs'),
                    'print-login-logs' => __('Print Login Logs'),
                ],
            ],

            'download_print_logs' => [
                'label' => __('Download / Print Logs'),
                'abilities' => [
                    'read-download-print-logs' => __('view_x', ['x' => __('Download / Print Logs')]),
                ],
            ],

            'reports' => [
                'label' => __('Reports'),
                'abilities' => [
                    'read-reports' => __('view_x', ['x' => __('Reports')]),
                    'manage-executive-reports' => __('Executive Intelligence Report'),
                ],
            ],

            // SettingController's index()/store() have no explicit permission
            // check of their own (unlike most controllers) — access is
            // entirely governed by these auto-derived abilities. Only
            // Super Admin is granted them by default (see the seed
            // migration), matching the app's real-world behavior today.
            'settings' => [
                'label' => __('System Settings'),
                'abilities' => [
                    'read-settings' => __('View System Settings'),
                    'create-settings' => __('Save System Settings'),
                    'update-settings' => __('Update Terms & Conditions'),
                ],
            ],

            // The licence WIMS runs on. Super Admin only until a Super Admin grants them to a role. The Licence Manager (issuing
            // and deactivating licences) is NOT here: it is hard-coded to Dr Hajas and cannot be granted.
            'wims_licence' => [
                'label' => __('WIMS Licence'),
                'abilities' => [
                    'read-wims-licence' => __('View the WIMS Licence (status, dates, key) and print its report'),
                    'update-wims-licence' => __('Apply a new WIMS Licence (paste a key or upload a licence file)'),
                ],
            ],

            'smart_brief' => [
                'label' => __('WIMS Smart Brief'),
                'abilities' => [
                    'view-own-smart-brief' => __('Open My Smart Brief: your own daily summary and activity timeline'),
                    'view-management-smart-brief' => __('Open Management Smart Brief (the page for viewing other users and group summaries); what you can see inside still depends on the abilities below'),
                    'view-assigned-smart-briefs' => __('View the Smart Briefs of users specifically assigned to you (Access Assignments)'),
                    'view-team-smart-briefs' => __('View the Smart Briefs of users who work on the same project(s) as you'),
                    'view-all-smart-briefs' => __('View the Smart Briefs of every user (Super Admin accounts stay Super Admin only)'),
                    'view-smart-brief-timeline' => __('See the detailed activity timeline in other users\' briefs'),
                    'view-smart-brief-sessions' => __('See sign-in, sign-out and session activity in other users\' briefs'),
                    'view-smart-brief-record-changes' => __('See the before / after changes to records in other users\' briefs'),
                    'view-smart-brief-approvals' => __('See approval, rejection and submission activity in other users\' briefs'),
                    'view-smart-brief-sensitive' => __('See sensitive categories in other users\' briefs: failed sign-ins, security, impersonation and administrative activity'),
                    'view-smart-brief-reports' => __('Open the Smart Brief reports page'),
                    'export-smart-brief' => __('Print, download and export Smart Brief reports that contain other users\' activity (every export is logged)'),
                    'manage-smart-brief-access' => __('Assign and revoke who may view whose Smart Brief'),
                    'manage-smart-brief-settings' => __('Change Smart Brief defaults and limits'),
                ],
            ],

            // Weather forecasts and weather-related site risk for the projects a person can access. Nothing here authorises or prohibits site work:
            // a warning only says forecast conditions may be unsuitable and that somebody should review before proceeding.
            'weather_risk' => [
                'label' => __('Weather & Site Risk'),
                'abilities' => [
                    'read-weather' => __('View weather, forecasts and weather-related site-risk warnings for the projects the person can access'),
                    'record-weather-observations' => __('Record weather observed on site (and correct or remove the observations they recorded themselves)'),
                    'manage-weather-locations' => __('Set or correct the latitude and longitude of a project for weather purposes'),
                    'acknowledge-weather-warnings' => __('Acknowledge weather warnings (records who has seen the warning and what they decided; it never changes any work)'),
                    'manage-weather-rules' => __('Change the weather thresholds of weather-sensitive activities and mark them as reviewed'),
                    'manage-weather-settings' => __('Change the weather provider, its key, refresh times and wording options'),
                    'print-weather-reports' => __('Print, save as PDF and download the Weather & Site Risk report as CSV'),
                ],
            ],

            'signatories' => [
                'label' => __('Signatories'),
                'abilities' => [
                    'view-signatories' => __('Open Signatories: the list of registered signatories, the dashboard and each person\'s status'),
                    'view-own-signature' => __('See your own registered signature and its history'),
                    'view-other-signatures' => __('See other people\'s processed signature images (signatures are sensitive)'),
                    'view-signature-originals' => __('See the original photo or scan a signature was made from, while it is still kept'),
                    'register-own-signature' => __('Register or replace your own signature (it stays pending until verified)'),
                    'register-signatories' => __('Register a signature for another user'),
                    'register-signatories-without-confirmation' => __('Register a signature for another user without waiting for their own confirmation (they are told in the app bell instead)'),
                    'upload-signatures' => __('Upload a signature picture'),
                    'capture-signatures' => __('Capture a signature with the camera or draw it on screen'),
                    'preview-signatures' => __('Preview the processed signature before it is confirmed'),
                    'verify-own-signatures' => __('Verify and activate your own signature (everybody else still needs somebody other than the owner)'),
                    'verify-signatures' => __('Verify that a registered signature belongs to the person'),
                    'activate-signatures' => __('Activate a verified signature so it can be used on reports'),
                    'replace-signatures' => __('Replace the active signature of another user'),
                    'suspend-signatures' => __('Suspend a signature (it is not used on new reports)'),
                    'revoke-signatures' => __('Revoke a signature for good'),
                    'view-signature-history' => __('See the version history and the audit trail of a signatory'),
                    'manage-signing-roles' => __('Add, rename, order and switch off signing roles (Prepared by, Approved by ...)'),
                    'manage-signing-mappings' => __('Choose which documents carry which signing roles and when'),
                    'manage-signatory-settings' => __('Change the signature standards: ink colour, size limits, retention, report sizes'),
                    'view-signatory-audit' => __('See the Signatories audit log'),
                    'export-signatory-reports' => __('Print, download and export Signatories reports'),
                ],
            ],

            'responsive_audit' => [
                'label' => __('Responsive Audit'),
                'abilities' => [
                    'view-responsive-audit' => __('Open the Responsive Audit page and the results of earlier audits'),
                    'run-responsive-audit' => __('Run an audit from the browser (it opens WIMS pages at phone, tablet and desktop widths and records what it finds)'),
                    'manage-responsive-audit-settings' => __('Change the audit standards (smallest touch target, widths, pages left out)'),
                    'print-responsive-audit' => __('Print and download audit results'),
                ],
            ],

            'dashboard_cards' => [
                'label' => __('Dashboard Cards'),
                'abilities' => [
                    'customize-dashboard-cards' => __('Choose which shortcut cards sit on their own dashboard (Profile > Dashboard cards): only modules the person can already open, main modules and dashboards only'),
                ],
            ],

            'low_stock_alert' => [
                'label' => __('Low Stock Alert'),
                'abilities' => [
                    'manage-low-stock-alerts' => __('Choose who receives the daily Low Stock Alert (email / text message), at what time, and which materials and projects it watches'),
                ],
            ],


            'prayer_times' => [
                'label' => __('Prayer Times'),
                'abilities' => [
                    'manage-prayer-times' => __('Open the Prayer Times settings (menu and the link in the prayer drawer): switch the module on, who is locked, alert and lock minutes, the adhan and the verses shown. Only Super Admin has it until you give it to a role'),
                ],
            ],

            'activity' => [
                'label' => __('Activity Logs'),
                'abilities' => [
                    'read-activity' => __('view_x', ['x' => __('Activity Logs')]),
                ],
            ],

            'deleted_records' => [
                'label' => __('Deleted Records'),
                'abilities' => [
                    'manage-deleted-records' => __('View / Restore Deleted Records'),
                ],
            ],



            'offline_access' => [
                'label' => __('Offline Access'),
                'abilities' => [
                    'bypass-offline-restrictions' => __('Continue restricted actions (e.g. Clock In/Out) while the connection is offline'),
                ],
            ],

            'security_reminders' => [
                'label' => __('Security Reminders'),
                'abilities' => [
                    'read-security-reminders' => __('View Security Reminders (user 2FA status, send history)'),
                    'send-security-reminders' => __('Send Security Setup Reminders to Users'),
                    'print-security-reminders' => __('Print / Download Security Reminder History'),
                ],
            ],

            'project_certificates' => [
                'label' => __('Project Certificates'),
                'abilities' => [
                    'view-project-certificates' => __('View Project Certificates and project financial dashboards'),
                    'create-project-certificates' => __('Create Certificates (draft)'),
                    'edit-project-certificates' => __('Edit Draft/Submitted Certificates and Calculation Lines'),
                    'submit-project-certificates' => __('Submit a Certificate for Review'),
                    'approve-project-certificates' => __('Approve a Submitted Certificate'),
                    'issue-project-certificates' => __('Issue an Approved Certificate (generates the official PDF)'),
                    'record-payment-project-certificates' => __('Record Payments Against a Certificate'),
                    'view-financials-project-certificates' => __('View Certificate Financial Amounts (contract sum, certified, paid, outstanding)'),
                    'export-project-certificates' => __('Export / Print Certificates and Reports'),
                    'manage-settings-project-certificates' => __('Manage Project Certificate Notification & Letterhead Settings'),
                    'manage-contract-project-certificates' => __('Edit Project Contract Details and Contract Addenda'),
                    'approve-addendums-project-certificates' => __('Approve / Reject Contract Addenda'),
                ],
            ],

            'simulation_workspace' => [
                'label' => __('Simulation Workspace'),
                'abilities' => [
                    'read-simulation-import' => __('Search production records in the Import Centre (read only)'),
                    'run-simulation-import' => __('Import production records and spreadsheets into the simulation'),
                    'read-simulation-provenance' => __('See which simulation records are imported, modified or made in the simulation, and compare them'),
                    'manage-simulation-scenarios' => __('Create scenarios, save and restore checkpoints, compare and reset'),
                ],
            ],

            'simulation' => [
                'label' => __('Simulation WIMS'),
                'abilities' => [
                    'manage-simulation-accounts' => __('Create and manage the accounts that may sign in to Simulation WIMS (production side)'),
                ],
            ],

            'impersonation' => [
                'label' => __('User Impersonation'),
                'abilities' => [
                    'start-impersonation' => __('Start "Login As User" sessions'),
                    'read-impersonation' => __('View Active Impersonations and Impersonation History (including Print/Download)'),
                    'manage-impersonation' => __("Force-end another user's active impersonation session"),
                ],
            ],

            'wims_connect' => [
                'label' => __('WIMS Connect'),
                'abilities' => [
                    'read-wims-connect' => __('View WIMS Connect (dashboard, logs, recipients, gateway settings)'),
                    'manage-wims-connect' => __('Manage WIMS Connect (edit gateway settings, send test messages, rules, credentials)'),
                ],
            ],

            'wims_connect_bulk_connect' => [
                'label' => __('WIMS Connect - Bulk Connect'),
                'abilities' => [
                    'read-wims-connect-bulk-connect' => __('View Bulk Connect composer, recipients, and history'),
                    'send-wims-connect-bulk-connect' => __('Send / Schedule Bulk Connect messages'),
                    'print-wims-connect-bulk-connect' => __('Print / Download Bulk Connect History'),
                ],
            ],

            'backup' => [
                'label' => __('Backup'),
                'abilities' => [
                    'manage-backups' => __('Database Backup & Restore'),
                ],
            ],

            'maintenance' => [
                'label' => __('Maintenance'),
                'abilities' => [
                    'reset-stock' => __('Reset Stock (destructive)'),
                ],
            ],

            'system_lockdown' => [
                'label' => __('System Access Control'),
                'abilities' => [
                    'bypass-system-lockdown' => __('Bypass Maintenance Lockdown'),
                ],
            ],

            'construction_toolbox' => [
                'label' => __('Construction Toolbox'),
                'abilities' => [
                    'use-construction-toolbox' => __('Use Construction Toolbox'),
                ],
            ],

            'ai_assistant' => [
                'label' => __('ARIS'),
                'abilities' => [
                    'use-ai-assistant' => __('Use ARIS'),
                ],
            ],

            'task_reminders' => [
                'label' => __('Task Reminders'),
                'abilities' => [
                    'read-task-reminders' => __('view_x', ['x' => __('Task Reminders')]),
                    'create-task-reminders' => __('create_x', ['x' => __('Task Reminders')]),
                    'update-task-reminders' => __('update_x', ['x' => __('Task Reminders')]),
                    'delete-task-reminders' => __('delete_x', ['x' => __('Task Reminders')]),
                ],
            ],

            'material_value' => [
                'label' => __('Material Value'),
                'abilities' => [
                    'read-material-value' => __('view_x', ['x' => __('Material Value')]),
                ],
            ],

            'movement_value' => [
                'label' => __('Movement Value Report'),
                'abilities' => [
                    'read-movement-value' => __('view_x', ['x' => __('Movement Value Report')]),
                ],
            ],

            'transaction_overrides' => [
                'label' => __('Transaction Overrides'),
                'abilities' => [
                    'bypass-transaction-edit-window' => __('Bypass Edit Window (Checkins/Checkouts/Transfers)'),
                ],
            ],

            'assets' => [
                'label' => __('Assets'),
                'abilities' => [
                    'read-asset-dashboard' => __('View Asset Dashboard'),
                    'read-assets' => __('view_x', ['x' => __('Assets')]),
                    'create-assets' => __('create_x', ['x' => __('Assets')]),
                    'update-assets' => __('update_x', ['x' => __('Assets')]),
                    'delete-assets' => __('delete_x', ['x' => __('Assets')]),
                    'assign-assets' => __('Assign / Reassign Assets'),
                    'check-out-assets' => __('Check Out Assets'),
                    'check-in-assets' => __('Check In / Return Assets'),
                    'transfer-assets' => __('Transfer Assets Between Locations'),
                    'record-asset-maintenance' => __('Record Asset Maintenance'),
                    'record-asset-depreciation' => __('Post Asset Depreciation'),
                    'record-asset-appreciation' => __('Post Asset Appreciation'),
                    'dispose-assets' => __('Dispose / Write Off / Report Lost or Stolen Assets'),
                    'view-asset-financials' => __('View Asset Financial Info (Cost, Book Value, Depreciation)'),
                    'print-assets' => __('Print / Download Asset Records & Reports'),
                    'export-assets' => __('Export Asset Data'),
                    'manage-asset-categories' => __('Manage Asset Categories'),
                ],
            ],

            'compliance_records' => [
                'label' => __('Compliance Management'),
                'abilities' => [
                    'read-compliance-records' => __('view_x', ['x' => __('Compliance Records')]),
                    'create-compliance-records' => __('create_x', ['x' => __('Compliance Records')]),
                    'update-compliance-records' => __('update_x', ['x' => __('Compliance Records')]),
                    'delete-compliance-records' => __('delete_x', ['x' => __('Compliance Records')]),
                    'assign-compliance-records' => __('Assign Responsible Person / Recipients'),
                    'manage-compliance-reminders' => __('Manage Reminder Schedule'),
                    'start-compliance-renewal' => __('Start Renewal'),
                    'approve-compliance-renewal' => __('Approve Renewal'),
                    'complete-compliance-renewal' => __('Complete Renewal (Record New Certificate / Dates)'),
                    'upload-compliance-documents' => __('Upload Compliance Documents'),
                    'send-compliance-reminder' => __('Manually Send a Reminder'),
                    'manage-compliance-categories' => __('Manage Compliance Categories'),
                    'view-compliance-financials' => __('View Compliance Financial Info (Guarantee / Loan Amounts)'),
                    'export-compliance-records' => __('Export Compliance Data'),
                    'dispose-compliance-records' => __('Cancel / Suspend Compliance Records'),
                    'manage-compliance-auto-renewal' => __('Set Up Auto Renewal (Compliance)'),
                ],
            ],

            'digital_attendance' => [
                'label' => __('Digital Attendance'),
                'abilities' => [
                    'read-digital-attendance' => __('View Own Digital Attendance'),
                    'clock-in-digital-attendance' => __('Clock In'),
                    'clock-out-digital-attendance' => __('Clock Out'),
                    'read-all-digital-attendance' => __('View All Staff Attendance'),
                    'view-digital-attendance-location' => __('View Attendance Location Data'),
                    'read-digital-attendance-reports' => __('View Digital Attendance Reports'),
                    'export-digital-attendance' => __('Export Digital Attendance Data'),
                    'correct-digital-attendance' => __('Correct Attendance Records'),
                    'void-digital-attendance' => __('Void Attendance Records'),
                    'manage-digital-attendance-settings' => __('Manage Digital Attendance Settings'),
                ],
            ],

            // Dashboard widgets that are individually grantable. Granted to every role by default
            // (2026_10_05_120000_add_view_online_users_permission) and switchable per role in Roles.
            'dashboard' => [
                'label' => __('Dashboard'),
                'abilities' => [
                    'view-online-users' => __('View Online Users (dashboard panel and list)'),
                ],
            ],

            // Fewer, all-functioning abilities than the original Case
            // Tracking spec's ~30-item list — consolidated, not padded with
            // dead checkboxes. The first five below were briefly hardcoded as
            // an unconditional baseline in CaseTicketPolicy (every user gets
            // them on their own/assigned cases, no checkbox); a later request
            // asked for even those defaults to be softcoded, so they're real,
            // grantable permissions again — see the
            // 2026_09_06_150000_restore_case_tracking_default_permissions
            // migration, which grants all five to every existing role so
            // nobody's current access changes the moment this ships. Super
            // Admin can now uncheck any of them per role going forward.
            'case_tracking' => [
                'label' => __('Case Tracking'),
                'abilities' => [
                    'read-case-tracking' => __('View Own / Assigned Cases'),
                    'create-case-tracking' => __('Create / Submit Own Case'),
                    'update-case-tracking' => __('Edit Own Case (Draft / Returned)'),
                    'comment-case-tracking' => __('Comment on Own / Assigned Case'),
                    'attach-case-tracking' => __('Add / Remove Own Attachment'),
                    'read-all-case-tracking' => __('View All Cases'),
                    'update-any-case-tracking' => __('Edit Any Case'),
                    'delete-case-tracking' => __('Delete Own Draft Case'),
                    'assign-case-tracking' => __('Assign / Reassign Case'),
                    'change-status-case-tracking' => __('Change Status / Priority / Category'),
                    'resolve-case-tracking' => __('Resolve Case'),
                    'reopen-case-tracking' => __('Reopen Case'),
                    'view-analytics-case-tracking' => __('View Case Analytics'),
                    'export-case-tracking' => __('Export Case Lists / Reports'),
                    'print-case-tracking' => __('Print / Download Cases'),
                    'manage-case-settings' => __('Manage Case Categories & Priorities'),
                ],
            ],
            'task_manager' => [
                'label' => __('Task Manager'),
                'abilities' => [
                    'read-task-manager' => __('View Own / Assigned Tasks'),
                    'read-all-task-manager' => __('View All Tasks'),
                    'create-task-manager' => __('Create Tasks'),
                    'update-task-manager' => __('Edit Own / Assigned Task'),
                    'update-any-task-manager' => __('Edit Any Task'),
                    'delete-task-manager' => __('Delete Own Created Task'),
                    'assign-task-manager' => __('Assign Task'),
                    'assign-multiple-task-manager' => __('Assign Multiple People'),
                    'reassign-task-manager' => __('Reassign / Remove Assignee'),
                    'change-status-task-manager' => __('Change Task Status'),
                    'mark-complete-task-manager' => __('Mark Complete'),
                    'change-priority-task-manager' => __('Change Task Priority'),
                    'change-due-date-task-manager' => __('Change Due Date'),
                    'comment-task-manager' => __('Comment on Task'),
                    'attach-task-manager' => __('Add / Remove Attachment'),
                    'manage-checklist-task-manager' => __('Manage Checklist'),
                    'view-history-task-manager' => __('View Task Activity History'),
                    'view-reports-task-manager' => __('View Task Reports'),
                    'print-reports-task-manager' => __('Print / Download Task Reports'),
                    'manage-task-settings' => __('Manage Task Notification Settings'),
                    'global-task-manager' => __('Global: View All Creators Filter'),
                ],
            ],
            // Salary Management is split into one PermissionRegistry entry
            // per submodule — same reasoning as Supplier Records — so
            // someone who can record a salary advance isn't automatically
            // able to approve/pay a salary period, and vice versa.
            'salary_periods' => [
                'label' => __('Salary Preparation, Approval & Payments'),
                'abilities' => [
                    'read-salary-periods' => __('View Salary Periods'),
                    'create-salary-periods' => __('Prepare Individual Salary'),
                    'prepare-bulk-salary-periods' => __('Prepare Bulk Salary'),
                    'update-salary-periods' => __('Edit a Prepared Salary Period'),
                    'submit-salary-periods' => __('Submit Salary Period for Approval'),
                    'approve-salary-periods' => __('Approve / Reject Salary Period'),
                    'pay-salary-periods' => __('Pay Salary (Full / Partial)'),
                    'adjust-salary-periods' => __('Record Outstanding Balance Adjustment / Waiver'),
                    'view-salary-ledger' => __('View Employee Salary Ledger'),
                ],
            ],

            'fund_allocations' => [
                'label' => __('Funds Allocation'),
                'abilities' => [
                    'read-fund-allocations' => __('View Funds Allocation Sheets'),
                    'create-fund-allocations' => __('Prepare Funds Allocation Sheets'),
                    'update-fund-allocations' => __('Edit a Draft / Rejected Allocation'),
                    'edit-pending-fund-allocations' => __('Edit an Allocation that is Pending Approval'),
                    'delete-fund-allocations' => __('Delete Funds Allocation Sheets'),
                    'approve-fund-allocations' => __('Approve / Reject Allocation and Assign the Payer'),
                    'pay-fund-allocations' => __('Execute Payments (tick each payment off)'),
                    'print-fund-allocations' => __('Print / Download Allocation Sheets'),
                    'export-fund-allocations' => __('Export Allocation Sheets'),
                ],
            ],

            'fund_allocation_template' => [
                'label' => __('Funds Allocation Template'),
                'abilities' => [
                    'read-fund-allocation-templates' => __('Open the Funds Allocation Template'),
                    'update-fund-allocation-templates' => __('Change and Save the Funds Allocation Template'),
                    'generate-fund-allocation-templates' => __('Generate a Draft Allocation Sheet from the Template'),
                ],
            ],

            'fund_allocation_settings' => [
                'label' => __('Funds Allocation Settings & Reports'),
                'abilities' => [
                    'manage-fund-allocation-settings' => __('Manage Accounts, Categories and Payment Methods'),
                    'read-fund-allocation-reports' => __('View Funds Allocation Reports'),
                ],
            ],

            'salary_vouchers' => [
                'label' => __('Salary Vouchers'),
                'abilities' => [
                    'read-salary-vouchers' => __('View Salary Vouchers'),
                    'create-salary-vouchers' => __('Generate Individual / Bulk Salary Vouchers'),
                    'print-salary-vouchers' => __('Print / Download Salary Vouchers'),
                ],
            ],

            'salary_advances' => [
                'label' => __('Salary Advances'),
                'abilities' => [
                    'read-salary-advances' => __('View Salary Advances'),
                    'create-salary-advances' => __('Create Salary Advances'),
                    'update-salary-advances' => __('Edit Salary Advances'),
                    'delete-salary-advances' => __('Delete Salary Advances'),
                    'approve-salary-advances' => __('Approve / Reject Salary Advances'),
                ],
            ],

            'salary_loans' => [
                'label' => __('Salary Loans'),
                'abilities' => [
                    'read-salary-loans' => __('View Salary Loans'),
                    'create-salary-loans' => __('Create Salary Loans'),
                    'update-salary-loans' => __('Edit Salary Loans'),
                    'delete-salary-loans' => __('Delete Salary Loans'),
                    'approve-salary-loans' => __('Approve / Reject Salary Loans'),
                ],
            ],

            'salary_opening_balances' => [
                'label' => __('Salary Opening Balance'),
                'abilities' => [
                    'read-salary-opening-balances' => __('View Salary Opening Balances'),
                    'create-salary-opening-balances' => __('Record Salary Opening Balances'),
                    'update-salary-opening-balances' => __('Edit Salary Opening Balances'),
                    'delete-salary-opening-balances' => __('Delete Salary Opening Balances'),
                ],
            ],

            'salary_analytics' => [
                'label' => __('Salary Dashboard & Analytics'),
                'abilities' => [
                    'read-salary-analytics' => __('View Salary Dashboard & Analytics'),
                ],
            ],

            'salary_reports' => [
                'label' => __('Salary Reports'),
                'abilities' => [
                    'read-salary-reports' => __('View Salary Reports'),
                    'export-salary-reports' => __('Export / Print / Download Salary Reports'),
                ],
            ],

            'salary_settings' => [
                'label' => __('Salary Settings'),
                'abilities' => [
                    'manage-salary-settings' => __('Manage Salary Components, Statutory and Notification Settings'),
                ],
            ],
        ];
    }

    /**
     * Flat list of every permission string across all modules.
     */
    public static function allPermissionNames(): array
    {
        return collect(self::modules())
            ->flatMap(fn ($module) => array_keys($module['abilities']))
            ->unique()
            ->values()
            ->all();
    }
}
