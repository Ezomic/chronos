<?php

use App\Http\Controllers\Auth\CalendarOAuthController;
use App\Http\Controllers\Settings\CalendarController;
use App\Http\Controllers\Settings\ConnectedAccountController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/calendars', [CalendarController::class, 'edit'])->name('calendars.edit');
    Route::post('settings/calendars', [CalendarController::class, 'store'])->name('calendars.store');
    Route::patch('settings/calendars/{calendar}', [CalendarController::class, 'update'])->name('calendars.update');
    Route::patch('settings/calendars/{calendar}/visibility', [CalendarController::class, 'visibility'])->name('calendars.visibility');
    Route::delete('settings/calendars/{calendar}', [CalendarController::class, 'destroy'])->name('calendars.destroy');

    Route::delete('settings/connected-accounts/{account}', [ConnectedAccountController::class, 'destroy'])->name('connected-accounts.destroy');

    Route::get('auth/{provider}/redirect', [CalendarOAuthController::class, 'redirect'])->name('oauth.redirect');
    Route::get('auth/{provider}/callback', [CalendarOAuthController::class, 'callback'])->name('oauth.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
