<?php

use App\Http\Controllers\Api\EventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

$events = function (): void {
    Route::post('/events', [EventController::class, 'store'])
        ->middleware('ability:events:create');

    // Reading back and changing an app's own events needs a wider ability than
    // creating them, and a token that says which app it speaks for.
    Route::middleware('ability:events:manage')->group(function () {
        Route::get('/events', [EventController::class, 'index']);
        Route::patch('/events/{event}', [EventController::class, 'update']);
        Route::delete('/events/{event}', [EventController::class, 'destroy']);
    });
};

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () use ($events) {
    Route::prefix('v1')->group($events);

    // The unversioned paths the currently deployed consumers use. Kept as an
    // alias of v1 so nothing breaks; new consumers should use /api/v1.
    $events();
});
