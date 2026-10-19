<?php

return [
    'max_attachments' => (int) env('DAILY_SITE_REPORT_MAX_FILES', 5),

    'max_attachment_size_kb' => (int) env('DAILY_SITE_REPORT_MAX_FILE_KB', 10240),

    'auto_archive_after_days' => (int) env('DAILY_SITE_REPORT_AUTO_ARCHIVE_DAYS', 14),
];
