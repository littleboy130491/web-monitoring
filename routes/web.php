<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\MonitorController;
use App\Livewire\StatusPage;

Route::get('/', function () {
    return view('status');
});
Route::get('/status', function () {
    return view('status');
});

// Monitoring routes (protected)
Route::middleware(['web'])->group(function () {
    Route::post('/monitor/{website}', [MonitorController::class, 'monitor'])->name('monitor.website');
    Route::get('/monitor/{website}/run', [MonitorController::class, 'monitorGet'])->name('monitor.website.get');
    Route::post('/monitor-all', [MonitorController::class, 'monitorAll'])->name('monitor.all');
});
