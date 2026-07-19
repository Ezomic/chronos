<?php

use App\Http\Controllers\CalendarController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
