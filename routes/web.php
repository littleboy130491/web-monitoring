<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StatusController;

Route::get('/', [StatusController::class, 'index']);
Route::get('/status', [StatusController::class, 'index']);
