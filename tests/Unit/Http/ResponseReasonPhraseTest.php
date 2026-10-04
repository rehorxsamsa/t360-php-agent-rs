<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 007, AC 16: text důvodu ve stavovém řádku (`HTTP/1.1 422 Unprocessable Content`). */
final class ResponseReasonPhraseTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function knownStatuses(): iterable
    {
        yield '200' => [200, 'OK'];
        yield '303' => [303, 'See Other'];
        yield '403' => [403, 'Forbidden'];
        yield '404' => [404, 'Not Found'];
        yield '405' => [405, 'Method Not Allowed'];
        yield '422' => [422, 'Unprocessable Content'];
        yield '429' => [429, 'Too Many Requests'];
        yield '500' => [500, 'Internal Server Error'];
        yield '502' => [502, 'Bad Gateway'];
        yield '503' => [503, 'Service Unavailable'];
    }

    #[DataProvider('knownStatuses')]
    public function test_known_status_has_reason_phrase(int $status, string $phrase): void
    {
        self::assertSame($phrase, Response::reasonPhrase($status));
    }

    public function test_unknown_status_has_empty_reason_phrase(): void
    {
        self::assertSame('', Response::reasonPhrase(299));
        self::assertSame('', Response::reasonPhrase(799));
    }
}
