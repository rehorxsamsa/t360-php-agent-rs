<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\View;

use PHPUnit\Framework\TestCase;

final class EscapeTest extends TestCase
{
    public function test_escapes_html_special_characters_and_quotes(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &apos;',
            e('<script>alert("x")</script> & \''),
        );
    }

    public function test_invalid_utf8_is_substituted_not_emptied(): void
    {
        self::assertNotSame('', e("abc\xB1\x31"));
        self::assertStringStartsWith('abc', e("abc\xB1\x31"));
    }

    public function test_null_becomes_empty_string_and_numbers_are_cast(): void
    {
        self::assertSame('', e(null));
        self::assertSame('42', e(42));
        self::assertSame('1.5', e(1.5));
    }
}
