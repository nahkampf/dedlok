<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Text;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClinicPartialTest extends TestCase
{
    #[Test]
    public function partial_cost_label_has_both_placeholders(): void
    {
        $label = Text::get('city.clinic.partial_label', ['value' => '{value}', 'total' => '{total}']);

        $this->assertSame('{value} points · {total} ¤', $label);
    }
}
