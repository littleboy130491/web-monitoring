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
];
