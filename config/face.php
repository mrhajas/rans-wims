<?php

return [
    // How alike two faces must be to count as the same person (smaller = stricter). 0.6 is the library default; 0.45 keeps look-alikes out.
    'threshold' => (float) env('FACE_LOGIN_THRESHOLD', 0.45),

    // The three frames of a sign-in must also match well ON AVERAGE: threshold minus this.
    'mean_margin' => 0.04,

    // Look-alike guard: the probe must be at least this much closer to the account being opened than to ANY other enrolled person.
    'cohort_margin' => 0.05,

    // A live frame never equals an enrolled sample exactly; closer than this means a replayed template.
    'too_perfect' => 0.03,

    // Frames taken seconds apart from a live face differ a little (never zero), and never by as much as two different people.
    'min_spread' => 0.02,
    'max_spread' => 0.45,

    // The server asks for blinks on cue: how long it waits before each cue (ms), and how fast after the cue a real blink can be seen.
    'cue_delay_min' => 1100,
    'cue_delay_max' => 2700,
    'blink_latency_min' => 110,
    'blink_latency_max' => 1100,
    'blink_duration_min' => 40,
    'blink_duration_max' => 800,

    // Blinks on cue: normal and when the sign-in looks unusual (new network, recent failed scans, a device unused for a long time).
    'cues_normal' => 2,
    'cues_risky' => 3,
    'stale_device_days' => 30,

    'nonce_ttl' => 120,
    'max_failures' => 5,
    'lock_minutes' => 15,     // doubles with every lock-out, up to lock_max_minutes
    'lock_max_minutes' => 1440,

    // Camera names that are software, not a real camera (they can feed any video, even a face swapped in real time).
    'virtual_cameras' => '\\bobs\\b|virtual|manycam|snap camera|xsplit|camtwist|splitcam|youcam|e2esoft|vcam|mmhmm|streamlabs|nvidia broadcast|epoccam|iriun|reincubate|loopback',
];
