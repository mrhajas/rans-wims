<?php

/**
 * Defaults of the QR code settings (Settings > QR Codes). What a Super Admin saves there is stored in the settings table and
 * wins over these values. Sizes are in millimetres on paper; "preview" is how big the code is drawn on screen.
 */
return [
    'defaults' => [
        'enabled' => true,
        'on_prints' => true,            // printed reports and documents carry the scan-to-open code
        'caption' => true,              // reference + kind of record under the code on labels
        'show_company' => true,         // the company name on a label
        'preview' => 'medium',          // small | medium | large (on screen)
        'print_mm' => 40,               // the label's code size on paper: 25 .. 80
        'copies' => 1,                  // labels printed per click
        'columns' => 3,                 // labels per row on a printed sheet (1 .. 6)
        'error_correction' => 'Q',      // L | M | Q | H : how much of the code may be damaged and still scan
        'color' => '#111827',           // dark colour of the modules (keep it dark for scanning)
        'frame' => true,                // a thin rounded frame on labels
        'disabled_kinds' => [],         // kinds of record whose QR codes are switched off (QR Activation); empty = all on
    ],
    'preview_px' => ['small' => 140, 'medium' => 200, 'large' => 280],
    'token_length' => 20,
];
