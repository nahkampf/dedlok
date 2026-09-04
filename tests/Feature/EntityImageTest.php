<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Decks\Hardware;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use App\Models\Skill;
use App\Models\User;
use App\Programs\ProgramKind;
use App\Support\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pictures are found by convention, not stored on a row.
 *
 * Drop `public/images/<kind>/<id>.jpg` in and it appears; delete it and the
 * default comes back. Nothing to migrate, nothing to seed, and no column that
 * can disagree with the filesystem.
 */
final class EntityImageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Where a test may make folders. Never a real kind: `_default.jpg` is a
     * file that ships, and a test writing one would delete it on the way out.
     */
    private const SCRATCH = 'test-images';

    private string $dir;

    /** @var list<string> */
    private array $written = [];

    /** @var list<string> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = public_path(Image::ROOT.'/archetypes');
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            @unlink($file);
        }

        foreach (array_reverse($this->made) as $dir) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    private function write(string $name): void
    {
        $this->writeIn('archetypes', $name);
    }

    /**
     * A picture in a folder made for the test and taken away again.
     *
     * Never inside a real kind's folder: `_default.jpg` is a file that SHIPS,
     * and a test that writes one has just deleted it in tearDown.
     */
    private function writeIn(string $kind, string $name): void
    {
        $path = public_path(Image::ROOT);

        foreach (explode('/', $kind) as $segment) {
            $path .= "/{$segment}";

            if (! is_dir($path)) {
                mkdir($path, 0o755);
                $this->made[] = $path;
            }
        }

        $file = "{$path}/{$name}.jpg";
        file_put_contents($file, 'not really a jpeg');
        $this->written[] = $file;
    }

    /** The default ships with the kind, so a thing with no picture still has one. */
    public function test_a_thing_with_no_picture_falls_back_to_the_default(): void
    {
        $url = Image::card('archetypes', 'no_such_archetype');

        $this->assertNotNull($url);
        $this->assertStringContainsString('_default.jpg', $url);
    }

    public function test_its_own_picture_wins_over_the_default(): void
    {
        $this->write('9001');

        $url = Image::card('archetypes', 9001);

        $this->assertNotNull($url);
        $this->assertStringContainsString('/images/archetypes/9001.jpg', $url);
        $this->assertStringNotContainsString('_default', $url);
    }

    /**
     * Stamped like every other public asset. Nothing renames a file when its
     * contents change, so a replaced picture nobody sees for a week is the same
     * bug the stylesheet had.
     */
    public function test_the_url_carries_the_files_own_mtime(): void
    {
        $this->assertMatchesRegularExpression('/\?v=\d+$/', (string) Image::card('archetypes', 'nothing'));
    }

    public function test_a_kind_with_no_folder_has_no_picture_at_all(): void
    {
        $this->assertNull(Image::card('nothing_of_the_sort', 1));
        $this->assertNull(Image::full('nothing_of_the_sort', 1));
    }

    /**
     * The id is built into a path, and it comes from the database rather than
     * a request — but a filename made by concatenation is exactly the shape
     * that reads `../../.env` the first time somebody passes it player input.
     */
    public function test_a_traversing_id_is_refused_rather_than_resolved(): void
    {
        // The id is dropped and the DEFAULT is served — a bad id is a thing
        // with no picture, which already has an answer. What must never happen
        // is the path being built and walked.
        $url = (string) Image::card('archetypes', '../../.env');

        $this->assertStringContainsString('_default.jpg', $url);
        $this->assertStringNotContainsString('..', $url);

        // A traversing KIND has nowhere to fall back TO, so it is nothing.
        $this->assertNull(Image::card('../../storage', 1));
    }

    // ---- one folder per type ---------------------------------------------

    /**
     * An item's picture lives under its TYPE, not under one folder holding
     * every item in the game — `items/cpu/12.jpg`. Item::imageType() names it.
     */
    public function test_an_items_picture_is_found_in_its_types_folder(): void
    {
        $this->writeIn(self::SCRATCH.'/cpu', '9001');

        $url = (string) Image::card(self::SCRATCH.'/cpu', 9001);

        $this->assertStringContainsString('/images/'.self::SCRATCH.'/cpu/9001.jpg', $url);
    }

    /**
     * The DEFAULT walks back up the path, so one picture in `items/` stands in
     * for every type nobody has drawn yet — and a type that draws its own
     * overrides it by existing.
     */
    public function test_a_type_with_no_picture_of_its_own_falls_back_up_the_path(): void
    {
        $this->writeIn(self::SCRATCH, Image::DEFAULT);

        $this->assertStringContainsString(
            '/images/'.self::SCRATCH.'/_default.jpg',
            (string) Image::card(self::SCRATCH.'/cpu', 9001)
        );

        $this->writeIn(self::SCRATCH.'/cpu', Image::DEFAULT);

        $this->assertStringContainsString(
            '/images/'.self::SCRATCH.'/cpu/_default.jpg',
            (string) Image::card(self::SCRATCH.'/cpu', 9001)
        );
    }

    /**
     * Only the default walks up. A picture filed one level above its type is
     * filed in the wrong place, and finding it there would teach that habit.
     */
    public function test_an_items_own_picture_is_never_looked_for_outside_its_folder(): void
    {
        $this->writeIn(self::SCRATCH, '9001');

        $this->assertNull(Image::card(self::SCRATCH.'/cpu', 9001));
    }

    /** One bad step refuses the whole path rather than the part before it. */
    public function test_a_traversing_segment_refuses_the_whole_path(): void
    {
        $this->writeIn(self::SCRATCH, Image::DEFAULT);

        $this->assertNull(Image::card(self::SCRATCH.'/../archetypes', 1));
        $this->assertNull(Image::card(self::SCRATCH.'/', 1));
    }

    /**
     * The filing system is the vocabulary the card already speaks: hardware,
     * then software, then the book, then the inventory it lives in.
     */
    public function test_an_item_files_itself_by_type(): void
    {
        $skill = Skill::factory()->create();

        $this->assertSame(
            'items/cpu',
            Item::factory()->module(Hardware::Cpu)->create()->imageKind()
        );
        $this->assertSame(
            'items/deck',
            Item::factory()->cyberdeck()->create()->imageKind()
        );
        $this->assertSame(
            'items/icebreaker',
            Item::factory()->program(ProgramKind::Icebreaker)->create()->imageKind()
        );
        // Data on the card, a book in the filing cabinet: there is one book
        // per skill and they all look alike.
        $this->assertSame(
            'items/skillbook',
            Item::factory()->skillbookFor($skill)->create()->imageKind()
        );
        $this->assertSame(
            'items/physical',
            Item::factory()->physical()->create()->imageKind()
        );
    }

    /**
     * The item folder ships the picture every type falls back to, so an item
     * nobody has drawn still has one. Deleting it is not a tidy-up.
     */
    public function test_the_item_folder_ships_a_default(): void
    {
        $dir = public_path(Image::ROOT.'/'.Item::IMAGE_ROOT);

        $this->assertFileExists("{$dir}/".Image::DEFAULT.'.jpg');
        $this->assertFileExists("{$dir}/".Image::DEFAULT.'_full.jpg');

        // And one folder per type to file real pictures into.
        foreach (Item::imageTypes() as $type) {
            $this->assertDirectoryExists("{$dir}/{$type}");
        }
    }

    /** A folder that has to be remembered is one that is missing when needed. */
    public function test_every_kind_of_thing_there_is_has_a_type(): void
    {
        $types = Item::imageTypes();

        foreach (Hardware::cases() as $hardware) {
            $this->assertContains($hardware->value, $types);
        }

        foreach (ProgramKind::cases() as $kind) {
            $this->assertContains($kind->value, $types);
        }

        $this->assertContains(Item::IMAGE_SKILLBOOK, $types);

        foreach (Item::CONTAINERS as $container) {
            $this->assertContains($container, $types);
        }
    }

    // ---- the screen ------------------------------------------------------

    public function test_the_creation_screen_shows_one_picture_per_archetype(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('character.create'));

        $response->assertOk()
            ->assertSee('class="entity-image', false)
            ->assertSee('/images/archetypes/', false)
            // A full version exists for the default, so every card opens one.
            ->assertSee('_default_full.jpg', false)
            ->assertSee('class="modal modal--image"', false);
    }

    /**
     * The card is a <label> wrapping a radio, so a control inside it must not
     * activate that radio. ui-kit.js's data-open handler is where that is
     * settled, once, for every dialog opener in the kit.
     */
    public function test_opening_a_picture_does_not_pick_the_archetype(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/ui-kit.js'));

        $this->assertMatchesRegularExpression(
            '/on\("click", "\[data-open\]".*?e\.preventDefault\(\);/s',
            $js,
            'a dialog opener inside a <label> would otherwise also toggle its input'
        );
    }

    /**
     * The stash shows the picture on the card, and the dialog it opens beside
     * the listing — never inside the card. The card is cloned into the hover
     * inspector and into a modal, and a <dialog> cloned with it is a second
     * copy of itself in the top layer: the copy that wins is the one in a
     * hidden layer, so the picture opens onto nothing.
     */
    public function test_an_items_picture_opens_a_dialog_that_is_not_inside_its_card(): void
    {
        $character = Character::factory()->create(array_merge(
            array_fill_keys(Character::ATTRIBUTES, 10),
            ['archetype' => 3],
        ));
        $entry = InventoryItem::factory()
            ->ownedBy($character)
            ->of(Item::factory()->physical()->create())
            ->create();

        $html = $this->actingAs($character->user)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('/images/items/_default.jpg', false)
            ->assertSee('data-open="#image-'.$entry->id.'"', false)
            ->assertSee('id="image-'.$entry->id.'"', false)
            ->getContent();

        // The template is what gets cloned. The dialog must not be in it.
        preg_match('/<template id="detail-'.$entry->id.'">(.*?)<\/template>/s', (string) $html, $m);

        $this->assertNotEmpty($m, 'the stash should render the item card into a template');
        $this->assertStringContainsString('class="entity-image', $m[1]);
        $this->assertStringNotContainsString('modal--image', $m[1]);
    }
}
