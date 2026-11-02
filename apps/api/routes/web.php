<?php

use Illuminate\Support\Facades\Route;

// This app is an API only. The customer website and admin portal are separate apps.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]));
