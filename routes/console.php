<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule monitoring tasks
Schedule::command('monitor:websites')->twiceDaily(12, 0)->withoutOverlapping();
Schedule::command('monitor:prune')->daily();
