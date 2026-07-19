<?php

use App\Http\Controllers\Api\EventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

Route::post('/events', [EventController::class, 'store'])
    ->middleware(['auth:sanctum', 'ability:events:create', 'throttle:60,1']);
