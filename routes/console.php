<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule monitoring tasks
Schedule::command('monitor:websites --screenshot')->twiceDaily(12, 0)->withoutOverlapping();
Schedule::command('monitor:prune')->daily();

// Auto-prune monitoring reports older than 30 days
Schedule::call(function () {
    \App\Models\MonitoringReport::where('created_at', '<', now()->subDays(30))->delete();
})->daily()->name('prune-monitoring-reports')->withoutOverlapping();
