<?php

declare(strict_types=1);

namespace Tests\Feature\Cyberspace;

use App\Cyberspace\BackdoorKind;
use App\Cyberspace\Backdoors;
use App\Cyberspace\SystemType;
use App\Models\Character;
use App\Models\Exchange;
use App\Models\Skill;
use App\Models\System;
use App\Models\SystemContact;
use App\Models\SystemScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** Browse known systems: what you have seen that is still there and open, filterable and combinable. */
final class KnownSystemsTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cyberspace.size', 32);
        $this->character = Character::factory()->create(['archetype' => 3]);
    }

    /** A system the character has seen: the row and the memory of it. */
    private function known(string $owner, SystemType $type, string $name, ?int $grade = null, array $system = []): System
    {
        $this->n++;
        $address = sprintf('%08X', 0xAA000000 + $this->n);
        $x = 2 + $this->n;
        $row = System::factory()->at($x, 5)->create($system + ['address' => $address, 'type' => $type, 'name' => $name, 'grade' => $grade ?? 1]);

        SystemContact::query()->create([
            'character_id' => $this->character->id, 'x' => $x, 'y' => 5, 'address' => $address, 'name' => $name,
            'type' => $type, 'owner' => $owner === '' ? null : $owner, 'seen_at' => now(), 'first_seen_at' => now(),
        ]);

        if ($grade !== null) {
            SystemScan::query()->create([
                'character_id' => $this->character->id, 'address' => $address, 'x' => $x, 'y' => 5, 'grade' => $grade,
                'passes' => 1, 'scanned_at' => now(), 'expires_at' => now()->addHours(10),
            ]);
        }

        return $row;
    }

    private function fragment(array $query = []): string
    {
        return $this->actingAs($this->character->user)->get(route('cyberspace.systems', $query))->assertOk()->getContent();
    }

    public function test_the_browser_asks_the_script_to_remember_its_filter(): void
    {
        $this->actingAs($this->character->user)->get(route('cyberspace.index'))
            ->assertOk()->assertSee('data-fragment-remember="known-systems"', false);
    }

    public function test_it_lists_what_is_still_there_and_leaves_out_what_moved_or_shut(): void
    {
        $this->known('Joint Signals Command', SystemType::Silo, 'Still Here');
        $moved = $this->known('Joint Signals Command', SystemType::Silo, 'Moved Away');
        $shut = $this->known('Joint Signals Command', SystemType::Silo, 'Shut Down');
        $this->known('Joint Signals Command', SystemType::Silo, 'Gone')->delete();

        // Relocated: a new address and position, so the sighting no longer matches anything.
        $moved->update(['x' => 30, 'y' => 30, 'address' => 'CCCCCCCC']);
        $shut->forceFill(['locked_down_until' => now()->addHours(3)])->save();

        $html = $this->fragment();

        $this->assertStringContainsString('Still Here', $html);
        $this->assertStringNotContainsString('Moved Away', $html);
        $this->assertStringNotContainsString('Shut Down', $html);
        $this->assertStringNotContainsString('>Gone<', $html);
    }

    public function test_it_is_alphabetical_on_owner_with_the_unowned_last(): void
    {
        $this->known('Zenith Corp', SystemType::Silo, 'Z One');
        $this->known('', SystemType::Silo, 'No Owner One');
        $this->known('Acme', SystemType::Silo, 'A One');

        $html = $this->fragment();

        $this->assertLessThan(strpos($html, 'Z One'), strpos($html, 'A One'));
        $this->assertLessThan(strpos($html, 'No Owner One'), strpos($html, 'Z One'));
    }

    public function test_filters_combine(): void
    {
        $this->known('Joint Signals Command', SystemType::Silo, 'JSC Silo 2', 2);
        $this->known('Joint Signals Command', SystemType::Silo, 'JSC Silo 3', 3);
        $this->known('Joint Signals Command', SystemType::Compute, 'JSC Compute 2', 2);
        $this->known('Other Owner', SystemType::Silo, 'Other Silo 2', 2);

        $html = $this->fragment(['owner' => 'Joint Signals Command', 'type' => SystemType::Silo->value, 'grade' => '2']);

        $this->assertStringContainsString('JSC Silo 2', $html);
        $this->assertStringNotContainsString('JSC Silo 3', $html);
        $this->assertStringNotContainsString('JSC Compute 2', $html);
        $this->assertStringNotContainsString('Other Silo 2', $html);
    }

    public function test_a_grade_is_only_what_a_scan_found_out(): void
    {
        // The real grade is 4, and nothing was scanned.
        $this->known('Acme', SystemType::Silo, 'Unscanned', null, ['grade' => 4]);
        $this->known('Acme', SystemType::Silo, 'Scanned', 2);

        $this->assertStringNotContainsString('Unscanned', $this->fragment(['grade' => '4']), 'the hidden grade is never filtered on');
        $this->assertStringContainsString('Unscanned', $this->fragment(['grade' => 'unknown']));
        $this->assertStringNotContainsString('Scanned</td>', str_replace('Unscanned', '', $this->fragment(['grade' => 'unknown'])));
    }

    public function test_an_exchange_shows_its_desks_grade_not_the_systems_floor(): void
    {
        $system = $this->known('Acme', SystemType::Exchange, 'Front Desk', null, ['grade' => 1]);
        Exchange::query()->create(['system_id' => $system->id, 'grade' => 3, 'agent' => 'Wren', 'min_standing' => 20]);

        $this->assertStringContainsString('Front Desk', $this->fragment(['grade' => '3']));
        $this->assertStringNotContainsString('Front Desk', $this->fragment(['grade' => '1']));
    }

    public function test_a_full_scan_is_ticked_and_every_address_has_a_card(): void
    {
        $whole = $this->known('Acme', SystemType::Silo, 'Whole', 2);
        $part = $this->known('Acme', SystemType::Silo, 'Partial', 3);
        $os = Skill::query()->first()?->id ?? Skill::factory()->create()->id;

        SystemScan::query()->where('address', $whole->address)->update([
            'os_skill_id' => $os, 'ids_detection' => 0, 'tracer_detection' => 0, 'tracer_rate' => 0, 'ice_level' => 0,
        ]);

        $html = $this->fragment();

        $this->assertSame(1, substr_count($html, 'Fully scanned" aria-label'));

        $this->assertStringNotContainsString('Backdoor installed', $html);
        (new Backdoors)->install($this->character, $whole, BackdoorKind::Siphon);
        $this->assertSame(1, substr_count($this->fragment(), 'Backdoor installed" aria-label'));

        $only = $this->fragment(['full' => '1']);
        $this->assertStringContainsString('Whole', $only);
        $this->assertStringNotContainsString('Partial', $only);
        $this->assertStringContainsString('id="sys-'.$whole->address.'"', $html);
        $this->assertStringContainsString('id="sys-'.$part->address.'"', $html);
    }

    public function test_the_composed_name_drops_its_type_lead(): void
    {
        $system = $this->known('Acme', SystemType::Silo, 'Acme 7', 2);
        $lead = SystemType::Silo->label().' - ';
        SystemContact::query()->where('address', $system->address)->update(['name' => $lead.'Acme 7']);

        $this->assertStringContainsString('>Acme 7</button>', $this->fragment());
        $this->assertStringNotContainsString($lead.'Acme 7', $this->fragment());
    }

    public function test_the_jump_and_chain_boxes_are_accordions_closed_by_default(): void
    {
        $html = $this->actingAs($this->character->user)->get(route('cyberspace.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details class="panel panel--snug">\s*<summary class="panel__hd">\s*<h2>Jump<\/h2>/', $html);
        $this->assertStringContainsString('Browse known systems', $html);
        $this->assertStringContainsString('id="known-systems"', $html);
        $this->assertSame(0, preg_match('/<details[^>]*\sopen[\s>]/', $html), 'none is open');
    }
}
