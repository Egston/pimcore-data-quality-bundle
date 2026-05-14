<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Resolver;

use Basilicom\DataQualityBundle\Resolver\InvalidFieldPathException;
use PHPUnit\Framework\TestCase;

final class InvalidFieldPathExceptionTest extends TestCase
{
    public function test_message_carries_failing_path_verbatim(): void
    {
        $exception = new InvalidFieldPathException('sections[].nope', 'segment "nope" does not exist on the current container');

        self::assertSame('sections[].nope', $exception->path);
        self::assertStringContainsString('sections[].nope', $exception->getMessage());
        self::assertStringContainsString('segment "nope" does not exist', $exception->getMessage());
    }

    public function test_is_a_runtime_exception(): void
    {
        $exception = new InvalidFieldPathException('x', 'y');

        self::assertInstanceOf(\RuntimeException::class, $exception);
    }
}
