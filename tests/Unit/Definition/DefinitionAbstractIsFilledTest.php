<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\Definition;

use Basilicom\DataQualityBundle\Definition\DefinitionAbstract;
use PHPUnit\Framework\TestCase;

final class DefinitionAbstractIsFilledTest extends TestCase
{
    public function test_null_is_empty(): void
    {
        self::assertFalse(DefinitionAbstract::isFilled(null));
    }

    public function test_empty_string_is_empty(): void
    {
        self::assertFalse(DefinitionAbstract::isFilled(''));
    }

    public function test_empty_array_is_empty(): void
    {
        self::assertFalse(DefinitionAbstract::isFilled([]));
    }

    public function test_string_zero_is_filled(): void
    {
        self::assertTrue(DefinitionAbstract::isFilled('0'));
    }

    public function test_int_zero_is_filled(): void
    {
        self::assertTrue(DefinitionAbstract::isFilled(0));
    }

    public function test_false_is_filled(): void
    {
        self::assertTrue(DefinitionAbstract::isFilled(false));
    }

    public function test_whitespace_only_string_is_filled(): void
    {
        self::assertTrue(DefinitionAbstract::isFilled(' '));
    }

    public function test_array_with_null_element_is_filled(): void
    {
        self::assertTrue(DefinitionAbstract::isFilled([null]));
    }
}
