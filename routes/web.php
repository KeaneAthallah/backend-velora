<?php

use App\Http\Controllers\Api\V1\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Google's registered OAuth callback URI is the root-level path, not one under
// /api/v1. Same handler; it redirects the browser to the frontend.
Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('throttle:30,1');
