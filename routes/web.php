<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PublishedCalendarController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');

    Route::post('events', [EventController::class, 'store'])->name('events.store');
    Route::patch('events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::delete('events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    Route::post('events/{event}/restore', [EventController::class, 'restore'])
        ->withTrashed()
        ->name('events.restore');
});

// Public by design: a calendar app subscribes with a plain URL. Rate limited
// because the token in that URL is the only thing standing in front of it.
Route::get('feeds/{token}.ics', [PublishedCalendarController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('feeds.show');

// Public by design: visitors book without an account. Each route is limited
// per address, and the links into one booking are signed.
Route::get('book', [BookingController::class, 'index'])
    ->middleware('throttle:booking-view')
    ->name('book.index');
Route::post('book', [BookingController::class, 'store'])
    ->middleware('throttle:booking-create')
    ->name('book.store');
Route::get('book/booking/{booking}', [BookingController::class, 'manage'])
    ->middleware(['throttle:booking-view', 'signed'])
    ->name('book.manage');
Route::post('book/booking/{booking}/cancel', [BookingController::class, 'cancel'])
    ->middleware(['throttle:booking-cancel', 'signed'])
    ->name('book.cancel');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
