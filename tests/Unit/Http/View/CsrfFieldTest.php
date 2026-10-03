<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\View;

use PHPUnit\Framework\TestCase;

final class CsrfFieldTest extends TestCase
{
    public function test_csrf_field_renders_escaped_hidden_input(): void
    {
        self::assertSame(
            '<input type="hidden" name="_csrf" value="ab&quot;&lt;">',
            csrf_field('ab"<'),
        );
    }

    public function test_e_attr_is_equivalent_to_e(): void
    {
        foreach (['<b>"x"</b> & \'', '', 'č'] as $value) {
            self::assertSame(e($value), e_attr($value));
        }
        self::assertSame('&quot;', e_attr('"'));
    }
}
