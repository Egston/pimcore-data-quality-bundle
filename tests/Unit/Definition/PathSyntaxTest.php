<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\PathSyntax;
use PHPUnit\Framework\TestCase;

final class PathSyntaxTest extends TestCase
{
    /** @dataProvider containerCases */
    public function test_container_paths_recognised(string $path): void
    {
        self::assertTrue(PathSyntax::isContainer($path));
    }

    /** @dataProvider scalarCases */
    public function test_scalar_paths_not_treated_as_container(string $path): void
    {
        self::assertFalse(PathSyntax::isContainer($path));
    }

    /** @return iterable<array{string}> */
    public static function containerCases(): iterable
    {
        yield 'bracket-iterator' => ['sections[].title'];
        yield 'dotted-traversal' => ['translations.verified'];
        yield 'both'             => ['sections[].brick.field'];
    }

    /** @return iterable<array{string}> */
    public static function scalarCases(): iterable
    {
        yield 'single-segment' => ['name'];
        yield 'snake-case'     => ['display_name'];
        yield 'empty'          => [''];
    }
}
