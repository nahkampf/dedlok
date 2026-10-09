<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HelpPageTest extends TestCase
{
    public function test_the_help_page_is_public_and_every_group_and_topic_has_an_anchor(): void
    {
        $page = $this->get('/help')->assertOk();

        foreach (config('strings.help.groups') as $group) {
            $page->assertSee('id="'.$group['id'].'"', false);

            foreach ($group['topics'] as $topic) {
                $page->assertSee('id="'.$topic['id'].'"', false);
            }
        }

        $page->assertSee('id="glossary"', false);
    }
}
