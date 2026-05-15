<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle\Tests\Unit\View;

use Basilicom\DataQualityBundle\View\DataQualityViewModel;
use PHPUnit\Framework\TestCase;

/**
 * Pins `DataQualityViewModel` accepting a nullable percentage. The Twig
 * template branches on `is null`; a coercion back to non-nullable would
 * silently render `0%` for unscored objects.
 */
final class DataQualityViewModelNullTest extends TestCase
{
    public function test_constructor_accepts_null_percentage(): void
    {
        $model = new DataQualityViewModel('Quality', null, []);

        self::assertNull($model->getPercentage());
        self::assertSame('Quality', $model->getTitle());
        self::assertSame([], $model->getGroups());
    }

    public function test_constructor_preserves_int_percentage(): void
    {
        $model = new DataQualityViewModel('Quality', 42, []);

        self::assertSame(42, $model->getPercentage());
    }

    public function test_zero_percentage_is_distinct_from_null(): void
    {
        $zero = new DataQualityViewModel('Quality', 0, []);
        $unscored = new DataQualityViewModel('Quality', null, []);

        self::assertSame(0, $zero->getPercentage());
        self::assertNull($unscored->getPercentage());
    }
}
