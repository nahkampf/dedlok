<?php

declare(strict_types=1);

use App\Http\Controllers\AvailableHandlesController;
use App\Http\Controllers\CharacterCreationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Locale is a POST because it mutates state; there is no locale segment in URLs.
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

// The whole pool in one response; the client randomises locally so repeated
// presses cost no requests at all. Read-only, so GET and no CSRF. Still
// throttled: it is public and unauthenticated.
Route::get('/handles/available', AvailableHandlesController::class)
    ->middleware('throttle:30,1')
    ->name('handles.available');

// Character creation. RequireCharacter sends every signed-in player here until
// they have one, so these two must stay reachable without a character.
Route::middleware('auth')->group(function () {
    Route::get('/character/create', [CharacterCreationController::class, 'create'])->name('character.create');
    Route::post('/character', [CharacterCreationController::class, 'store'])->name('character.store');
});
