<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * A coloured button variant must bring its own notch.
 *
 * `.cut` paints the button's face with an ::after and `--cut-fill`, and that
 * pseudo-element sits ABOVE the element's own background. So a variant that
 * sets `background` and stops has its colour painted over in `--bg-2` and comes
 * out looking like an ordinary button with tinted text.
 *
 * It fails silently, and only where the notch is used — which is nearly
 * everywhere — so it survives a careful reading of the CSS and shows up in a
 * screenshot. `.btn--warn` shipped exactly like that once. This is the guard.
 */
final class ButtonCutCompanionTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__.'/../../public/assets/css/ui-kit.css');
    }

    public function test_every_button_variant_with_a_fill_sets_the_notch_fill_too(): void
    {
        $css = $this->css();

        // Up to the FIRST brace: a variant body holds no nested rules, and a
        // greedy match would sweep a one-line rule into its neighbours.
        preg_match_all('/^\.btn--([a-z]+)\s*\{([^}]*)\}/m', $css, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'no button variants found — has the kit moved?');

        $checked = 0;

        foreach ($matches as [, $name, $body]) {
            // Only the ones that paint a face. A ghost, a size or a shape
            // modifier sets no background and correctly inherits .btn.cut's.
            if (! preg_match('/background:\s*(?!transparent)\S/', $body)) {
                continue;
            }

            $checked++;

            $this->assertMatchesRegularExpression(
                '/^\.btn--'.preg_quote($name, '/').'\.cut \{[^}]*--cut-fill:/m',
                $css,
                "[.btn--{$name}] sets a background but has no [.btn--{$name}.cut] setting ".
                '--cut-fill, so it renders as a plain button with tinted text wherever the '.
                'notch is used.'
            );
        }

        $this->assertGreaterThanOrEqual(3, $checked, 'expected several filled variants to check');
    }
}
