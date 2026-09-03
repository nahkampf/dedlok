<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ArchetypeController as AdminArchetypeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ItemController as AdminItemController;
use App\Http\Controllers\Admin\SkillController as AdminSkillController;
use App\Http\Controllers\AvailableHandlesController;
use App\Http\Controllers\CharacterCreationController;
use App\Http\Controllers\DeckController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\SkillTreeController;
use App\Http\Middleware\ForbidSecondCharacter;
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
    /*
     * Character creation, and the one place ForbidSecondCharacter applies. It
     * is the mirror of RequireCharacter, which runs globally and lets these two
     * through: a player without a character may go nowhere else, and a player
     * with one may not come back here. The POST is guarded as well as the form
     * — characters.user_id is unique, so an unguarded second submission is a
     * 500 rather than a refusal.
     */
    Route::middleware(ForbidSecondCharacter::class)->group(function () {
        Route::get('/character/create', [CharacterCreationController::class, 'create'])->name('character.create');
        Route::post('/character', [CharacterCreationController::class, 'store'])->name('character.store');
    });

    // The stash. RequireCharacter has already sent anyone without a character
    // to the creation screen, so these can assume one exists.
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

    /*
     * The deck, without having to know which one.
     *
     * What the rail links to: a player thinks "my deck", not "inventory row
     * 3", and only the character knows which row that is. Resolving it here
     * rather than in the nav means the answer — including "you have not
     * equipped one" — is given once, in a place that can explain it, instead
     * of being encoded in whether a menu item is there.
     */
    Route::get('/deck', [DeckController::class, 'index'])->name('deck.index');

    // A deck you own, as the thing rather than as a spec line. The row and
    // not the item: two players holding the same model will have different
    // modules in them once fitting exists, and only the row can say so.
    Route::get('/deck/{entry}', [DeckController::class, 'show'])->name('deck.show');

    // One route for both directions, the way inventory.place is: an absent
    // `module` means empty the socket. Fitting and unfitting are the same
    // question about the same socket, answered with a name or without one.
    Route::post('/deck/{entry}/socket/{slot}', [DeckController::class, 'fit'])->name('deck.fit');

    // Emptying every socket at once. Destroys nothing — the modules go back
    // to the stash — so there is no confirmation in front of it.
    Route::post('/deck/{entry}/strip', [DeckController::class, 'strip'])->name('deck.strip');

    // Which deck is live. One route for both directions, like deck.fit: the
    // absence of a deck to equip is a request to jack out.
    Route::post('/deck/{entry}/equip', [DeckController::class, 'equip'])->name('deck.equip');
    Route::post('/deck/unequip', [DeckController::class, 'unequip'])->name('deck.unequip');

    // Spending a skillbook is destructive and not idempotent, so it is a POST
    // with a confirmation in front of it.
    Route::post('/inventory/{entry}/inject', [InventoryController::class, 'inject'])
        ->name('inventory.inject');

    /*
     * Destroying something you own. Real and irreversible, and the
     * confirmation in front of it is the only brake — the same decision the
     * admin item editor took, for the same reason: an undo nobody built is
     * worse than a delete that says what it costs.
     */
    Route::post('/inventory/{entry}/destroy', [InventoryController::class, 'destroy'])
        ->name('inventory.destroy');

    // Fit a module into the equipped deck, wherever it goes. The player picked
    // the module and not the socket, so the fitter finds one.
    Route::post('/inventory/{entry}/fit', [InventoryController::class, 'fit'])
        ->name('inventory.fit');

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

        /*
         * Archetypes are edited, never created or destroyed. Their ids are
         * written into character rows and are permanent, so the roster arrives
         * by migration and this screen tunes what it hands out — see
         * App\Http\Controllers\Admin\ArchetypeController.
         */
        Route::resource('archetypes', AdminArchetypeController::class)
            ->only(['index', 'edit', 'update']);
    });
