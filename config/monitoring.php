<?php

return [
    /*
     | Email address that receives monitoring reports.
     | Set REPORT_RECIPIENT_EMAIL in your .env file.
     */
    'report_recipient' => env('REPORT_RECIPIENT_EMAIL'),

    /*
     | Default number of days to keep monitoring data before pruning.
     | Can be overridden with MONITORING_PRUNE_DAYS in your .env file.
     */
    'prune_days' => (int) env('MONITORING_PRUNE_DAYS', 30),

    /*
     | Screenshot capture timeout in seconds.
     | Some monitored sites take longer to settle in headless Chrome.
     */
    'screenshot_timeout' => (int) env('MONITORING_SCREENSHOT_TIMEOUT', 45),

    /*
     | Queue job timeout for each monitor job in seconds.
     | Keep this above request + deep scan + screenshot timeouts.
     */
    'job_timeout' => (int) env('MONITORING_JOB_TIMEOUT', 180),

    /*
     | Retry transient report email transport failures.
     | Backoff is a comma-separated list of seconds between attempts.
     */
    'report_mail_retry_attempts' => (int) env('MONITORING_REPORT_MAIL_RETRY_ATTEMPTS', 3),
    'report_mail_retry_backoff' => array_map(
        'intval',
        explode(',', env('MONITORING_REPORT_MAIL_RETRY_BACKOFF', '5,15'))
    ),
];
