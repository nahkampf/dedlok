<?php
$c = App\Models\Character::where('handle','Noodle')->first();
$p = App\Models\Item::where('slug','ghostkey')->first()->program;
var_dump($p->qflops_cost, $p->memory_cost, $p->storage_size);
var_dump(App\Decks\Storage::used($c), App\Decks\Storage::capacity($c), App\Decks\Storage::free($c));
$d = App\Models\InventoryItem::find($c->equipped_deck_id);
$t = App\Decks\DeckTotals::forCharacter($c, $d, app(App\Stats\Resolver::class));
var_dump($t->qflops, $t->quantabits, $t->storage, $t->stored, $t->isReady());
