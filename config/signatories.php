<?php

/*
| WIMS Signatories. Everything an administrator may want to change lives in the Signatory Settings page (stored in the settings table, key
| `signatories`); the values below are only the starting defaults and the list of documents the module knows how to read the workflow
| history of. A document type is listed in 'documents' only when its approval history was checked to be reliable (see the Phase 1 audit).
*/

return [
    'defaults' => [
        'ink_color' => '#1b3fa0',
        'allowed_formats' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_upload_mb' => 8,
        'min_input_px' => 160,
        'max_input_px' => 6000,
        'output_width_px' => 900,
        'padding_pct' => 6,
        'sensitivity' => 1.0,
        'min_contrast' => 0.16,
        'min_ink_coverage_pct' => 0.15,
        'max_ink_coverage_pct' => 30,
        'camera_enabled' => true,
        'draw_enabled' => true,
        // who may register: 'self' = every user can register their own (pending until a verifier activates), 'admin' = only people allowed to register for others
        'self_registration' => 'self',
        'verification_required' => true,       // a different person must verify before the signature can be used
        'consent_required' => true,            // the signatory confirms the picture is theirs and may be used on the listed documents
        'reauth_sensitive' => true,            // ask for the password before verifying, activating, replacing, suspending or revoking
        'original_retention_days' => 30,       // 0 = delete the original photo as soon as the version is confirmed
        'version_retention' => 'all',          // all | N (keep the newest N processed versions per person)
        'report_signature_width_mm' => 38,
        'report_signature_height_mm' => 14,
        'date_format' => 'd M Y',
        'time_format' => 'H:i',
        'default_missing_behaviour' => 'blank', // blank | flag | block
        'show_name' => true,
        'show_designation' => true,
        'show_datetime' => true,
        // a signature registered AFTER a record was finished: true = it is still shown on that record, marked "applied after the action"; false = only a signature that was on file at the time
        'apply_to_older_records' => true,
        'show_on_draft' => false,              // signatures on a draft report: actions that did happen are shown only when this is on
    ],
    // the documents the module can sign are the adapters in app/Services/Signatories/Documents (one class per document type)
];
