<?php

declare(strict_types=1);

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\Admin\ArchetypeController as AdminArchetypeController;
use App\Http\Controllers\Admin\BetaKeyController as AdminBetaKeyController;
use App\Http\Controllers\Admin\CharacterController as AdminCharacterController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DistrictController as AdminDistrictController;
use App\Http\Controllers\Admin\EncounterController as AdminEncounterController;
use App\Http\Controllers\Admin\EntityOfferController as AdminEntityOfferController;
use App\Http\Controllers\Admin\ItemController as AdminItemController;
use App\Http\Controllers\Admin\LocationController as AdminLocationController;
use App\Http\Controllers\Admin\NpcController as AdminNpcController;
use App\Http\Controllers\Admin\SkillController as AdminSkillController;
use App\Http\Controllers\Admin\SupporterController as AdminSupporterController;
use App\Http\Controllers\AvailableHandlesController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CharacterCreationController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CraftingController;
use App\Http\Controllers\CurrentProcessesController;
use App\Http\Controllers\CyberspaceController;
use App\Http\Controllers\DeckController;
use App\Http\Controllers\DecryptionController;
use App\Http\Controllers\DiscordInviteController;
use App\Http\Controllers\EncounterController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\ExchangeController;
use App\Http\Controllers\FightController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HunterController;
use App\Http\Controllers\ImplantController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\KofiWebhookController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PatchNotesController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SkillsController;
use App\Http\Controllers\SoundController;
use App\Http\Controllers\StandingController;
use App\Http\Controllers\SupportersController;
use App\Http\Controllers\TasksController;
use App\Http\Controllers\TrainingQueueController;
use App\Http\Controllers\WaitingController;
use App\Http\Controllers\WalletController;
use App\Http\Middleware\ForbidSecondCharacter;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Everyone who supports the game. Public, like the front page.
// Ko-fi's membership webhook. Public, CSRF-exempt, authenticated by its verification token.
Route::post('/kofi_webhook', KofiWebhookController::class)->name('kofi.webhook');

Route::get('/supporters', [SupportersController::class, 'index'])->name('supporters.index');

// The whole pool in one response; the client randomises locally so repeated
// presses cost no requests at all. Read-only, so GET and no CSRF. Still
// throttled: it is public and unauthenticated.
Route::get('/handles/available', AvailableHandlesController::class)
    ->middleware('throttle:30,1')
    ->name('handles.available');

// Character creation. RequireCharacter sends every signed-in player here until
// they have one, so these two must stay reachable without a character.
Route::middleware('auth')->group(function () {
    // Closing the patch notes on the front page. See PatchNotesController.
    Route::post('/patch-notes/seen', PatchNotesController::class)->name('patch-notes.seen');

    // Closing the Discord invitation shown on first sign-in. See DiscordInviteController.
    Route::post('/discord/seen', DiscordInviteController::class)->name('discord.seen');

    // The topbar speaker. See SoundController; every lock's allow-list names it.
    Route::post('/preferences/sound', SoundController::class)->name('sound.update');

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

    // Spending an imprint is destructive and not idempotent, so it is a POST
    // with a confirmation in front of it.
    Route::post('/inventory/{entry}/inject', [InventoryController::class, 'inject'])
        ->name('inventory.inject');

    // Putting armour on, or taking it off: one route for both, the way run and place are. A piece
    // that is worn is asked to come off; one that is not is asked to go on.
    Route::post('/inventory/{entry}/wear', [InventoryController::class, 'wear'])
        ->name('inventory.wear');

    // Picking a weapon up or putting it down: one route for both, like wear. Which hand it fills is
    // its class's business, so the player picks the weapon and not the hand.
    Route::post('/inventory/{entry}/wield', [InventoryController::class, 'wield'])
        ->name('inventory.wield');

    // Rounds in and out of a weapon's magazine. A weapon takes one calibre, so the player picks the round.
    Route::post('/inventory/{entry}/load', [InventoryController::class, 'load'])->name('inventory.load');
    Route::post('/inventory/{entry}/unload', [InventoryController::class, 'unload'])->name('inventory.unload');

    // Taking one. The same shape as injecting an imprint — one copy spent, one
    // consequence — except that the consequence is in your blood for a while
    // and the books remember it afterwards.
    Route::post('/inventory/{entry}/take', [InventoryController::class, 'take'])
        ->name('inventory.take');

    // Reading a scan file into your own records. Spends the file, like every
    // other door on this screen that turns an object into something you know
    // or something you are — see App\Cyberspace\ScanImporter.
    Route::post('/inventory/{entry}/read', [InventoryController::class, 'read'])
        ->name('inventory.read');

    // Decryption: queue a file to be broken, or take one out. Startable while jacked in; the file being worked on
    // cannot be called off from in there. See App\Decryption\DecryptionQueue.
    Route::post('/inventory/{entry}/decrypt', [DecryptionController::class, 'store'])
        ->name('decryption.store');
    // Crafting (CRAFTING.md): start a build from a design in the stash (components are spent now), or give one up.
    Route::post('/inventory/{entry}/craft', [CraftingController::class, 'store'])->name('crafting.store');
    Route::delete('/crafting/{craft}', [CraftingController::class, 'destroy'])->name('crafting.destroy');

    Route::post('/decryption/reorder', [DecryptionController::class, 'reorder'])
        ->name('decryption.reorder');
    Route::delete('/decryption/{entry}', [DecryptionController::class, 'destroy'])
        ->name('decryption.destroy');

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
    // The Running modal's list of what could be started, fetched when it opens (it is in every page's layout).
    // The top bar's Current processes modal: everything in flight for the character, fetched when it opens.
    Route::get('/processes/current', [CurrentProcessesController::class, 'index'])->name('processes.current');
    Route::get('/programs/startable', [InventoryController::class, 'startable'])->name('programs.startable');
    Route::post('/inventory/{entry}/run', [InventoryController::class, 'run'])
        ->name('inventory.run');

    // Use one copy of a consumable (a medkit): it is spent and its effect lands at once.
    Route::post('/inventory/{entry}/use', [InventoryController::class, 'consume'])->name('inventory.use');

    // On or off the belt: one route for both, like inventory.wear.
    Route::post('/inventory/{entry}/belt', [InventoryController::class, 'belt'])->name('inventory.belt');

    // Moving a row into a container, or back out of one. One route for both
    // directions: an absent `parent` means the loose pile, which is the same
    // operation with a null target.
    Route::post('/inventory/{entry}/place', [InventoryController::class, 'place'])
        ->name('inventory.place');

    /*
     * Implants: what is installed in a body.
     *
     * One screen, and READ ONLY. Fitting and removing are surgery now, done at a
     * clinic that offers it (`city.operate`) — see CITY.md.
     */
    Route::get('/implants', [ImplantController::class, 'index'])->name('implants.index');
    Route::get('/equipment', [EquipmentController::class, 'index'])->name('equipment.index');

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
    Route::post('/cyberspace/spot/{encounter}', [CyberspaceController::class, 'spot'])->name('cyberspace.spot');
    Route::post('/cyberspace/traverse', [CyberspaceController::class, 'traverse'])->name('cyberspace.traverse');
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
    /*
     * An entity's front desk. No id in the route: the desk somebody is
     * standing on is the desk they can use, which is what makes the walk to a
     * high-grade agent worth making.
     */
    Route::get('/exchange', [ExchangeController::class, 'show'])->name('exchange.show');

    // Spending loyalty at the desk you are standing on. Like the board, there
    // is no id for the DESK in the route: being there is the permission.
    Route::post('/exchange/store/{offer}', [ExchangeController::class, 'redeem'])->name('exchange.redeem');
    Route::post('/exchange/sell/{row}', [ExchangeController::class, 'sell'])->name('exchange.sell');

    /*
     * Work. There is no index and never will be: a board belongs to a DESK,
     * and a desk is reached by standing on it — which is the whole of the
     * permission, the same call the run briefing makes.
     */
    Route::post('/missions/{mission}/accept', [MissionController::class, 'accept'])->name('missions.accept');
    Route::delete('/missions/{mission}', [MissionController::class, 'decline'])->name('missions.decline');
    Route::delete('/missions/{mission}/abandon', [MissionController::class, 'abandon'])->name('missions.abandon');
    Route::post('/missions/{mission}/complete', [MissionController::class, 'complete'])->name('missions.complete');

    Route::get('/run/setup', [RunController::class, 'setup'])->name('run.setup');
    Route::get('/run', [RunController::class, 'show'])->name('run.show');
    Route::post('/run/act', [RunController::class, 'act'])->name('run.act');
    Route::post('/run/egress', [RunController::class, 'egress'])->name('run.egress');

    /*
     * Cyberspace combat. Two routes, and they are the only ones a hunted
     * player may reach — see App\Http\Middleware\EnsureHuntIsResolved. No
     * setup route: a hunter does not wait for a briefing, and there is
     * nothing to choose beyond pressing Fight.
     */
    /*
     * A narrative encounter. Three routes, and the only ones a player in one may
     * reach — see App\Http\Middleware\EnsureEncounterIsResolved.
     */
    Route::get('/encounter', [EncounterController::class, 'show'])->name('encounter.show');
    Route::post('/encounter/choose', [EncounterController::class, 'choose'])->name('encounter.choose');
    Route::post('/encounter/leave', [EncounterController::class, 'leave'])->name('encounter.leave');

    // A fight. The only two routes a player in one may reach — see
    // App\Http\Middleware\EnsureFightIsResolved.
    Route::get('/fight', [FightController::class, 'show'])->name('fight.show');
    Route::post('/fight/act', [FightController::class, 'act'])->name('fight.act');

    Route::get('/hunt', [HunterController::class, 'show'])->name('hunt.show');
    Route::post('/hunt/act', [HunterController::class, 'act'])->name('hunt.act');

    // Stopping a read. NOT jacking out and not unloading the analyser — it
    // stops pointing the instrument at one machine and frees it for another.
    // Takes an address rather than a position, because a read survives its
    // owner walking away from the machine it is reading.
    Route::delete('/cyberspace/reading', [CyberspaceController::class, 'abandon'])->name('cyberspace.abandon');

    // Turning a finished read into something somebody can carry and sell. It
    // SPENDS the read, which is why it is a press rather than something that
    // happens when the last pass lands — see App\Cyberspace\ScanExporter.
    Route::post('/cyberspace/reading/export', [CyberspaceController::class, 'export'])->name('cyberspace.export');

    /*
     * A character's own skills, and the queue that trains them. It was the
     * CATALOGUE once — every skill in the game, public, because it described
     * the game rather than a person — and that is the skill editor's job now.
     *
     * A player picks a SKILL and never a level — the next one up is the only
     * entry the queue could accept, so there is nothing to choose. See
     * App\Skills\TrainingQueue.
     */
    /*
     * The character sheet, and the window three other screens now live in.
     *
     * Skills, implants and achievements kept their URLs and their controllers
     * and became TABS of this one — which is a nav of links rather than a
     * client-side switch, so opening the sheet draws one panel instead of
     * running four screens' worth of queries to show one of them.
     */
    // Tasks & Missions: the exchange jobs you have taken, with the journal and quests still to come.
    Route::get('/tasks', TasksController::class)->name('tasks.index');

    Route::get('/character', [CharacterController::class, 'index'])->name('character.index');
    Route::post('/character/avatar', [CharacterController::class, 'avatar'])->middleware('throttle:10,10')->name('character.avatar');
    Route::delete('/character/avatar', [CharacterController::class, 'removeAvatar'])->name('character.avatar.remove');
    Route::post('/character/bio', [CharacterController::class, 'bio'])->name('character.bio');

    Route::get('/standing', [StandingController::class, 'index'])->name('standing.index');

    Route::get('/skills', SkillsController::class)->name('skills.index');

    /*
     * The market, and the only place money leaves a character.
     *
     * The shelf is what the restock pass left, never the catalogue with stock
     * counts laid over it — an item nobody can buy today is not something a
     * shop is holding. It is also how a player finds out a skill exists, which
     * is why a skill off the market is content found rather than bought.
     */
    Route::get('/market', [MarketController::class, 'index'])->name('market.index');
    Route::post('/market/{item}', [MarketController::class, 'buy'])->name('market.buy');

    /*
     * The other half of the market: players selling to each other. An offer is
     * one ROW out of one stash rather than a catalogue entry, which is why
     * listing is reached from the item's own card and buying is reached from
     * the offer. See App\Market\OfferBook.
     */
    Route::post('/inventory/{entry}/list', [MarketController::class, 'list'])->name('market.list');
    Route::delete('/market/offers/{offer}', [MarketController::class, 'withdraw'])->name('market.withdraw');
    Route::post('/market/offers/{offer}', [MarketController::class, 'take'])->name('market.take');

    // The City: a map of districts, and behind each a map of its places.
    Route::get('/city', [CityController::class, 'index'])->name('city.index');
    Route::get('/city/{district}', [CityController::class, 'district'])->name('city.district');
    Route::post('/city/{district}/travel', [CityController::class, 'travel'])->name('city.travel');
    Route::get('/city/place/{slug}', [PlaceController::class, 'show'])->name('city.place');
    Route::post('/city/place/{slug}/buy/{item}', [PlaceController::class, 'buy'])->name('city.buy');
    Route::post('/city/place/{slug}/buy-and-fit/{item}', [PlaceController::class, 'buyAndFit'])->name('city.buy_and_fit');
    Route::post('/city/place/{slug}/operate/{row}/{operation}', [PlaceController::class, 'operate'])->name('city.operate');
    Route::post('/city/place/{slug}/encounter/{encounter}', [PlaceController::class, 'encounter'])->name('city.place.encounter');
    Route::post('/city/place/{slug}/sell/{row}', [PlaceController::class, 'sell'])->name('city.sell');
    Route::post('/city/place/{slug}/treat/{service}', [PlaceController::class, 'treat'])->name('city.treat');
    Route::post('/city-explore', [CityController::class, 'explore'])->name('city.explore');
    Route::post('/city-cancel', [CityController::class, 'cancel'])->name('city.cancel');

    // The wallet: the ledger of every movement of creds, and sending some on.
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/transfer', [WalletController::class, 'transfer'])->name('wallet.transfer');

    /*
     * Achievements. THE ONE SCREEN THAT BELONGS TO THE ACCOUNT rather than to
     * the character — it still sits behind RequireCharacter, because getting
     * to any screen at all means having one, but nothing on it is a
     * character's.
     */
    Route::get('/achievements', [AchievementController::class, 'index'])->name('achievements.index');

    /*
     * The inbox. Also the account's rather than the character's, and for the
     * same reason the rail is: what happened while nobody was looking belongs
     * to the person who was not looking.
     *
     * Reading is its own POST rather than something opening the screen does —
     * a badge cleared by arriving is a badge cleared for messages nobody read.
     */
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

    // A message to another player. Throttled: it is the one way to make the game write to somebody else.
    Route::get('/messages/new', [MessageController::class, 'create'])->name('messages.create');
    Route::post('/messages', [MessageController::class, 'store'])->middleware('throttle:15,10')->name('messages.store');
    Route::get('/messages/recipients', [MessageController::class, 'recipients'])->middleware('throttle:120,1')->name('messages.recipients');
    // Polled every few seconds from every page (`ui-kit.js`): the rail's badge, and the sound for a new message.
    Route::get('/notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::post('/notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
    // Opening one is reading it, and it writes, so it is a POST like the other.
    Route::post('/notifications/{message}/open', [NotificationController::class, 'open'])->name('notifications.open');
    // Deleting: one from its opened body, or the ticked ones (`ids[]`) from the top of the page.
    Route::delete('/notifications', [NotificationController::class, 'destroyMany'])->name('notifications.destroy_many');
    Route::delete('/notifications/{message}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    // Unfolding one in the inbox reads it without leaving the page; sent by `ui-kit.js`.
    Route::post('/notifications/{message}/mark', [NotificationController::class, 'mark'])->name('notifications.mark');
    Route::post('/skills/queue', [TrainingQueueController::class, 'store'])->name('training.store');
    Route::post('/skills/queue/order', [TrainingQueueController::class, 'reorder'])->name('training.reorder');
    Route::delete('/skills/queue/{entry}', [TrainingQueueController::class, 'destroy'])->name('training.destroy');

    /*
     * The same queue, filled in from the other end: name a skill and the level
     * you want it AT, and the prerequisites and the levels in between are
     * worked out and queued in order. Every entry still goes through
     * TrainingQueue::enqueue(), so the plan decides the order and nothing
     * else — see App\Skills\TrainingPlanner.
     */
    Route::post('/skills/plan', [TrainingQueueController::class, 'plan'])->name('training.plan');

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
        Route::resource('districts', AdminDistrictController::class)->except(['show']);
        Route::resource('locations', AdminLocationController::class)->except(['show']);
        Route::resource('encounters', AdminEncounterController::class)->except(['show']);
        Route::resource('npcs', AdminNpcController::class)->except(['show']);

        // Grants: creds, and a full refill of HP or SI.
        Route::get('characters', [AdminCharacterController::class, 'index'])->name('characters.index');
        Route::post('characters/{character}/creds', [AdminCharacterController::class, 'creds'])->name('characters.creds');
        Route::post('characters/{character}/refill/{resource}', [AdminCharacterController::class, 'refill'])->name('characters.refill');

        // Who is a supporter, and the button that makes somebody one.
        Route::get('supporters', [AdminSupporterController::class, 'index'])->name('supporters.index');
        Route::get('supporters/history', [AdminSupporterController::class, 'history'])->name('supporters.history');
        Route::post('supporters/kofi', [AdminSupporterController::class, 'connect'])->name('supporters.connect');
        Route::put('supporters/{user}', [AdminSupporterController::class, 'update'])->name('supporters.update');

        // ALPHA TESTING (config/features.php): the beta keys. 404 while the flag is off.
        Route::get('beta-keys', [AdminBetaKeyController::class, 'index'])->name('beta_keys.index');
        Route::post('beta-keys', [AdminBetaKeyController::class, 'store'])->name('beta_keys.store');
        Route::post('beta-keys/{betaKey}/given', [AdminBetaKeyController::class, 'given'])->name('beta_keys.given');

        /*
         * Archetypes are edited, never created or destroyed. Their ids are
         * written into character rows and are permanent, so the roster arrives
         * by migration and this screen tunes what it hands out — see
         * App\Http\Controllers\Admin\ArchetypeController.
         */
        Route::resource('archetypes', AdminArchetypeController::class)
            ->only(['index', 'edit', 'update']);

        /*
         * The loyalty shelves. Entities are listed and edited, never created
         * or destroyed — systems point at an owner, so an entity cannot be
         * deleted without orphaning a district of the grid, and the roster
         * arrives by migration for the reason the archetypes do.
         *
         * What is edited here is what they SELL. A shelf belongs to an entity,
         * so the offers are nested under one rather than living in a flat list
         * whose first column would be the only one that mattered.
         */
        Route::get('entities', [AdminEntityOfferController::class, 'index'])->name('entities.index');
        Route::get('entities/{entity}', [AdminEntityOfferController::class, 'edit'])->name('entities.edit');
        Route::post('entities/{entity}/offers', [AdminEntityOfferController::class, 'store'])->name('entities.offers.store');
        Route::put('entities/{entity}', [AdminEntityOfferController::class, 'update'])->name('entities.update');
        Route::put('entities/{entity}/buys', [AdminEntityOfferController::class, 'updateBuys'])->name('entities.buys.update');
        Route::put('entities/{entity}/offers/{offer}', [AdminEntityOfferController::class, 'updateOffer'])->name('entities.offers.update');
        Route::delete('entities/{entity}/offers/{offer}', [AdminEntityOfferController::class, 'destroy'])->name('entities.offers.destroy');
    });
