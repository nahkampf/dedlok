<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ItemController as AdminItemController;
use App\Http\Controllers\Admin\SkillController as AdminSkillController;
use App\Http\Controllers\AvailableHandlesController;
use App\Http\Controllers\CharacterCreationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\SkillTreeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// The skill catalogue. Public: it describes the game, not a character.
Route::get('/skills', SkillTreeController::class)->name('skills.index');

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

    // The stash. RequireCharacter has already sent anyone without a character
    // to the creation screen, so these can assume one exists.
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

    // Spending a skillbook is destructive and not idempotent, so it is a POST
    // with a confirmation in front of it.
    Route::post('/inventory/{entry}/inject', [InventoryController::class, 'inject'])
        ->name('inventory.inject');

    // Moving a row into a container, or back out of one. One route for both
    // directions: an absent `parent` means the loose pile, which is the same
    // operation with a null target.
    Route::post('/inventory/{entry}/place', [InventoryController::class, 'place'])
        ->name('inventory.place');
});

/*
 * The admin tools: the item editor and the skill editor.
 *
 * `can:admin` is the whole gate — one Gate rather than a role table, because
 * there are two kinds of account and a permissions system for a set of size
 * two would be a framework standing in for an if. RequireCharacter lets these
 * through by name prefix: they edit the game, not a character, so demanding
 * one would be a rule about the wrong subject.
 *
 * No `show` route on either resource. The edit form IS the detail view, and a
 * read-only copy of it would be a second place to add every new column.
 */
Route::middleware(['auth', 'can:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('index');

        Route::resource('items', AdminItemController::class)->except(['show']);
        Route::resource('skills', AdminSkillController::class)->except(['show']);
    });
