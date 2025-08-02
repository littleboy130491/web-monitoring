<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('status');
});
Route::get('/status', function () {
    return view('status');
});
