<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    public function test_a_missing_page_wears_the_game_look_and_names_its_code(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('404')->assertSee('logo-560', false)->assertSee('Back to main')->assertSee('I cannot find the requested resource.');
    }
}
