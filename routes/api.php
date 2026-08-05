<?php

use App\Http\Controllers\Api\EventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/events', [EventController::class, 'store'])
        ->middleware('ability:events:create');

    // Reading back and changing an app's own events needs a wider ability than
    // creating them, and a token that says which app it speaks for.
    Route::middleware('ability:events:manage')->group(function () {
        Route::get('/events', [EventController::class, 'index']);
        Route::patch('/events/{event}', [EventController::class, 'update']);
        Route::delete('/events/{event}', [EventController::class, 'destroy']);
    });
});
