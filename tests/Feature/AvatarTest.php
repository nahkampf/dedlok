<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** A supporter's own picture: centre-cropped to 300x400 and found by the usual convention. */
final class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Character $character;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/avatar-test-'.uniqid();
        mkdir($this->dir);
        $this->app->usePublicPath($this->dir);

        $this->user = User::factory()->create();
        $this->character = Character::factory()->create(array_merge(array_fill_keys(Character::ATTRIBUTES, 20), ['user_id' => $this->user->id, 'archetype' => 3]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/images/characters/*') ?: []);
        parent::tearDown();
    }

    private function upload(int $w, int $h): UploadedFile
    {
        return UploadedFile::fake()->image('me.png', $w, $h);
    }

    private function path(): string
    {
        return $this->dir."/images/characters/{$this->character->id}.jpg";
    }

    public function test_a_supporter_uploads_and_it_is_cropped_to_300_by_400(): void
    {
        $this->user->forceFill(['is_supporter' => true])->save();

        $this->actingAs($this->user)->post(route('character.avatar'), ['avatar' => $this->upload(1000, 500)])->assertSessionHas('status');

        $this->assertSame([300, 400], array_slice(getimagesize($this->path()), 0, 2));
        $this->actingAs($this->user)->get(route('character.index'))->assertSee("images/characters/{$this->character->id}.jpg");
    }

    public function test_a_small_or_tall_picture_is_cropped_the_same_way(): void
    {
        $this->user->forceFill(['is_supporter' => true])->save();

        $this->actingAs($this->user)->post(route('character.avatar'), ['avatar' => $this->upload(60, 400)])->assertSessionHas('status');

        $this->assertSame([300, 400], array_slice(getimagesize($this->path()), 0, 2));
    }

    public function test_somebody_who_is_not_a_supporter_cannot_upload(): void
    {
        $this->actingAs($this->user)->post(route('character.avatar'), ['avatar' => $this->upload(300, 400)])->assertForbidden();

        $this->assertFileDoesNotExist($this->path());
    }

    public function test_a_non_image_is_refused(): void
    {
        $this->user->forceFill(['is_supporter' => true])->save();

        $this->actingAs($this->user)->post(route('character.avatar'), ['avatar' => UploadedFile::fake()->createWithContent('x.png', 'not a picture')])
            ->assertSessionHasErrors('avatar');

        $this->assertFileDoesNotExist($this->path());
    }

    public function test_it_can_be_removed(): void
    {
        $this->user->forceFill(['is_supporter' => true])->save();
        $this->actingAs($this->user)->post(route('character.avatar'), ['avatar' => $this->upload(300, 400)]);

        $this->actingAs($this->user)->delete(route('character.avatar.remove'))->assertSessionHas('status');

        $this->assertFileDoesNotExist($this->path());
    }
}
