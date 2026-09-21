<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ArchetypeController as AdminArchetypeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ItemController as AdminItemController;
use App\Http\Controllers\Admin\SkillController as AdminSkillController;
use App\Http\Controllers\AvailableHandlesController;
use App\Http\Controllers\CharacterCreationController;
use App\Http\Controllers\CyberspaceController;
use App\Http\Controllers\DeckController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImplantController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SkillsController;
use App\Http\Controllers\TrainingQueueController;
use App\Http\Controllers\WaitingController;
use App\Http\Middleware\ForbidSecondCharacter;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

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
    // absence of a deck to equip is a request to put the current one away.
    // NOT jacking out, which is leaving cyberspace and a different act
    // entirely - an equipped deck sits right through one.
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

    /*
     * Start or stop a program. One route for both directions, like
     * inventory.place and deck.fit: running and not running are the same
     * question about the same row, and the row already knows which way it is
     * being asked.
     */
    Route::post('/inventory/{entry}/run', [InventoryController::class, 'run'])
        ->name('inventory.run');

    // Moving a row into a container, or back out of one. One route for both
    // directions: an absent `parent` means the loose pile, which is the same
    // operation with a null target.
    Route::post('/inventory/{entry}/place', [InventoryController::class, 'place'])
        ->name('inventory.place');

    /*
     * Implants: what is installed in a body.
     *
     * One screen, and two writes that are the same act in both directions —
     * an implant goes in or it comes out, and which one is being asked for is
     * decided by the row rather than by a field.
     */
    Route::get('/implants', [ImplantController::class, 'index'])->name('implants.index');
    Route::post('/implants/{entry}/install', [ImplantController::class, 'install'])->name('implants.install');
    Route::post('/implants/{entry}/remove', [ImplantController::class, 'remove'])->name('implants.remove');

    /*
     * Cyberspace: the map, and everything done from it.
     *
     * One screen. `index` draws the grid whether or not the character is in
     * it, because the way IN is on it — a rail item that vanished when you
     * were jacked out would be a rail item nobody trusts, the same rule the
     * deck link follows.
     *
     * Every mover is a POST to its own route rather than one route with an
     * action field: stepping, jumping, recalling and jacking out are four
     * different things that happen to change the same two columns, and a
     * single endpoint switching on a string is where that stops being
     * checkable.
     */
    Route::get('/cyberspace', [CyberspaceController::class, 'index'])->name('cyberspace.index');
    Route::post('/cyberspace/jack-in', [CyberspaceController::class, 'jackIn'])->name('cyberspace.jack_in');
    Route::post('/cyberspace/jack-out', [CyberspaceController::class, 'jackOut'])->name('cyberspace.jack_out');
    Route::post('/cyberspace/step', [CyberspaceController::class, 'step'])->name('cyberspace.step');
    Route::post('/cyberspace/jump', [CyberspaceController::class, 'jump'])->name('cyberspace.jump');

    // Reading the machine underfoot. No target in the request: the position is
    // the target, because you cannot read a machine you are not standing on.
    Route::post('/cyberspace/scan', [CyberspaceController::class, 'scan'])->name('cyberspace.scan');

    // What the instrument has, for the screen that watches it work. A GET
    // because it reads — and it settles a matured pass on the way past, which
    // is the same lazy catch-up the map does rather than a write of its own.
    Route::get('/cyberspace/reading', [CyberspaceController::class, 'reading'])->name('cyberspace.reading');

    // The two standing instructions. A play style rather than a per-run
    // choice, so they are saved from the map and never from inside a run.
    Route::post('/cyberspace/thresholds', [CyberspaceController::class, 'thresholds'])->name('cyberspace.thresholds');

    /*
     * The floor under every defeat. One route, and the middleware that holds
     * somebody here is what releases them — the minute ends when they look.
     */
    Route::get('/down', [WaitingController::class, 'show'])->name('waiting.show');

    /*
     * The run. Three routes and they are the only ones a locked player may
     * reach — see App\Http\Middleware\EnsureRunIsFinished, whose allow-list
     * names exactly these.
     *
     * Egress is a POST rather than a DELETE: it is a roll that may fail and
     * cost a turn, not a deletion that either happens or does not.
     */
    Route::post('/cyberspace/run', [RunController::class, 'start'])->name('run.start');
    /*
     * The briefing. Not inside the run lock's allow-list on purpose: somebody
     * already in a run is sent to the run itself, which is the only screen
     * they may reach — planning the next one is not a thing to do from inside
     * this one.
     */
    Route::get('/run/setup', [RunController::class, 'setup'])->name('run.setup');
    Route::get('/run', [RunController::class, 'show'])->name('run.show');
    Route::post('/run/act', [RunController::class, 'act'])->name('run.act');
    Route::post('/run/egress', [RunController::class, 'egress'])->name('run.egress');

    // Stopping a read. NOT jacking out and not unloading the analyser — it
    // stops pointing the instrument at one machine and frees it for another.
    // Takes an address rather than a position, because a read survives its
    // owner walking away from the machine it is reading.
    Route::delete('/cyberspace/reading', [CyberspaceController::class, 'abandon'])->name('cyberspace.abandon');

    /*
     * A character's own skills, and the queue that trains them. It was the
     * CATALOGUE once — every skill in the game, public, because it described
     * the game rather than a person — and that is the skill editor's job now.
     *
     * A player picks a SKILL and never a level — the next one up is the only
     * entry the queue could accept, so there is nothing to choose. See
     * App\Skills\TrainingQueue.
     */
    Route::get('/skills', SkillsController::class)->name('skills.index');
    Route::post('/skills/queue', [TrainingQueueController::class, 'store'])->name('training.store');
    Route::delete('/skills/queue/{entry}', [TrainingQueueController::class, 'destroy'])->name('training.destroy');

    // Bookmarks. The recall is a POST because it moves you; the rest edit a
    // note.
    Route::post('/cyberspace/bookmark', [CyberspaceController::class, 'bookmark'])->name('cyberspace.bookmark');
    Route::post('/cyberspace/bookmark/{bookmark}/recall', [CyberspaceController::class, 'recall'])->name('cyberspace.recall');
    Route::delete('/cyberspace/bookmark/{bookmark}', [CyberspaceController::class, 'forget'])->name('cyberspace.forget');
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
