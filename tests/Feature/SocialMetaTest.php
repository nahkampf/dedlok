<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What a link to the site unfurls as: the page's title plus the tagline, and the square picture. */
final class SocialMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_front_page_carries_open_graph_and_twitter_tags(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $html = $response->getContent();

        preg_match('#<title>(.*?)</title>#s', $html, $title);
        $expected = e(trim(html_entity_decode($title[1], ENT_QUOTES)).' - A cyberpunk browser game');

        $response->assertSee('<meta property="og:title" content="'.$expected.'">', false)
            ->assertSee('<meta name="twitter:title" content="'.$expected.'">', false)
            ->assertSee('<meta name="twitter:card" content="summary">', false);
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="http[^"]+/images/square\.jpg\?v=\d+">#', $html);
        $this->assertMatchesRegularExpression('#<meta name="twitter:image" content="http[^"]+/images/square\.jpg\?v=\d+">#', $html);
    }

    public function test_the_sign_in_page_has_them_too(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('property="og:image"', false)->assertSee(' - A cyberpunk browser game', false);
    }
}
