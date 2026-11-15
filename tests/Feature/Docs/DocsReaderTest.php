<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Http\Controllers\DocsController;
use Tests\TestCase;

final class DocsReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = 'reader_test_'.bin2hex(random_bytes(4)).'.md';
        file_put_contents(public_path('docs/'.$this->file), "# Title\n\nSome **bold** text.\n-# a small note\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))\n\n```\n-# not small in code\n```\n");
    }

    protected function tearDown(): void
    {
        @unlink(public_path('docs/'.$this->file));
        parent::tearDown();
    }

    public function test_a_markdown_file_in_docs_is_drawn_with_small_text_and_nothing_unsafe(): void
    {
        $html = $this->get('/docs/'.$this->file)->assertOk()
            ->assertSee('Download '.$this->file)
            ->getContent();

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<p class="micro" style="color:var(--ink-2)">a small note</p>', $html);
        $this->assertStringContainsString('-# not small in code', $html, 'code is left alone');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
    }

    public function test_the_raw_file_downloads(): void
    {
        $response = $this->get('/docs/'.$this->file.'?download=1')->assertOk();

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_nothing_outside_docs_and_nothing_but_markdown_is_read(): void
    {
        foreach (['../.htaccess', '..%2F.htaccess', '../../.env', '.env', 'index.php', 'nope.md', 'a/b.md', '..md', '.hidden.md', $this->file.'.php', strtoupper($this->file)] as $name) {
            $this->get('/docs/'.$name)->assertNotFound();
            $this->get('/docs/'.$name.'?download=1')->assertNotFound();
        }

        // The controller's own check, past the route pattern.
        foreach (['../.env', '../../composer.json', 'docs/../x.md', "x.md\0.php", '.md'] as $name) {
            $this->assertSame(0, preg_match('/\A'.DocsController::NAME.'\z/', $name), $name);
        }
    }
}
